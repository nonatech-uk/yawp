<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Chunked backup engine — disk-staged, streaming, resumable.
 *
 * Each archive step streams one batch of files into an independent gzip
 * member written under wp-content/yawp-tmp/<timestamp>/current/. Once the
 * cumulative size of staged fragments reaches TARGET_PART_SIZE, a close_part
 * step concatenates them (concatenated gzip members form a valid gzip file
 * per RFC 1952 §2.2), appends the tar EOF marker as a final member, uploads
 * the combined part via multipart streaming S3, and deletes the local copy.
 *
 * Memory stays flat at a few MB regardless of site size because tar entries
 * are streamed directly into a gzopen'd file handle — never concatenated in
 * memory, never gzencode'd as a single blob.
 *
 * State machine: init → scan → archive ⇄ close_part → finish
 *
 * Backup format in S3 (unchanged from v2):
 *   {prefix}/{type}/{timestamp}/database.sql.gz
 *   {prefix}/{type}/{timestamp}/part-0001.tar.gz
 *   {prefix}/{type}/{timestamp}/part-0002.tar.gz
 *   ...
 *   {prefix}/{type}/{timestamp}/manifest.json
 */
class YAWP_Backup {

    const LOCK_KEY         = 'yawp_backup_running';
    const LOCK_TTL         = 7200; // 2 hours (large sites take time)
    const STATE_KEY        = 'yawp_backup_state';
    const FILE_LIST_KEY    = 'yawp_backup_filelist';
    const CRON_HOOK        = 'yawp_backup_step';
    const RETENTION_DAYS   = 0;
    const BATCH_SIZE       = 250;       // files per archive step — bounds worst-case part-size overshoot to ~one batch
    const TARGET_PART_SIZE = 268435456; // 256 MB — close and upload a part when staged fragments exceed this
    const FREAD_CHUNK      = 65536;     // 64 KB per fread when streaming a file into gzip

    /**
     * Directories/files to exclude from backups (relative to webroot).
     */
    private static $excludes = [
        'wp-content/cache',
        'wp-content/plugins/yawp',
        'wp-content/yawp-tmp',
        'wp-content/object-cache.php',
        '.git',
        '.claude',
        '.opcache',
    ];

    /**
     * Memoised merge of the built-in excludes and the user's
     * comma-separated list from the yawp_extra_excludes setting.
     * Parsed once per PHP request (each cron step is a fresh request).
     */
    private static $combined_excludes = null;

    // ──────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────

    /**
     * Kick off a full backup (async — returns immediately).
     */
    public function run_full() {
        return $this->start( 'full' );
    }

    /**
     * Kick off an incremental backup (async — returns immediately).
     */
    public function run_incremental() {
        return $this->start( 'incremental' );
    }

    /**
     * Public S3 client getter — used by the scheduler for marker files.
     */
    public function get_s3_public() {
        return $this->get_s3();
    }

    /**
     * Get current backup status for the UI.
     */
    public static function get_status() {
        $state = get_option( self::STATE_KEY, '' );
        if ( empty( $state ) ) {
            return [ 'running' => false ];
        }
        $state = json_decode( $state, true );
        if ( ! is_array( $state ) ) {
            return [ 'running' => false ];
        }

        $status = [
            'running' => true,
            'step'    => $state['step'] ?? 'unknown',
            'type'    => $state['type'] ?? '',
        ];

        // Progress info for archive / close_part steps.
        if ( 'archive' === $status['step'] || 'close_part' === $status['step'] ) {
            $total   = $state['file_count'] ?? 0;
            $cursor  = $state['file_cursor'] ?? 0;
            $status['progress'] = $cursor . '/' . $total . ' files';
            if ( 'close_part' === $status['step'] ) {
                $status['progress'] .= ' — uploading part ' . ( ( $state['part_num'] ?? 0 ) + 1 );
            }
        }

        if ( 'error' === $status['step'] ) {
            $status['running'] = false;
            $status['error']   = $state['error'] ?? 'Unknown error';
        }

        if ( 'done' === $status['step'] ) {
            $status['running'] = false;
            $status['message'] = 'Backup completed successfully.';
        }

        return $status;
    }

    /**
     * Cancel a running backup and clean up.
     */
    public static function cancel() {
        // Read state before clearing it so we know which staging dir to nuke.
        $raw = get_option( self::STATE_KEY, '' );
        if ( ! empty( $raw ) ) {
            $state = json_decode( $raw, true );
            if ( is_array( $state ) && ! empty( $state['staging_dir'] ) ) {
                self::rrmdir( $state['staging_dir'] );
            }
        }
        delete_option( self::STATE_KEY );
        self::delete_file_list_chunks();
        delete_transient( self::LOCK_KEY );
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Delete all chunked file list options from the database.
     */
    private static function delete_file_list_chunks() {
        $num_chunks = (int) get_option( self::FILE_LIST_KEY . '_chunks', 0 );
        for ( $i = 0; $i < $num_chunks; $i++ ) {
            delete_option( self::FILE_LIST_KEY . '_' . $i );
        }
        delete_option( self::FILE_LIST_KEY . '_chunks' );
        // Also delete legacy single-blob key in case it exists.
        delete_option( self::FILE_LIST_KEY );
    }

    // ──────────────────────────────────────────────
    // Entry point
    // ──────────────────────────────────────────────

    private function start( $type ) {
        if ( get_transient( self::LOCK_KEY ) ) {
            return new WP_Error( 'yawp_backup', 'A backup is already running.' );
        }
        set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

        // Pick up after any prior backup that crashed without cleanup.
        self::sweep_orphan_staging();

        $this->healthcheck( 'start' );
        yawp_log( 'info', 'Backup started', [ 'type' => $type ] );

        $timestamp = gmdate( 'Y-m-d_His' );
        $prefix    = trim( get_option( 'yawp_s3_prefix', '' ), '/' );
        $s3_dir    = ( $prefix ? $prefix . '/' : '' ) . $type . '/' . $timestamp;

        $state = [
            'step'              => 'init',
            'type'              => $type,
            'timestamp'         => $timestamp,
            's3_dir'            => $s3_dir,
            'staging_dir'       => '',
            'file_cursor'       => 0,
            'file_count'        => 0,
            'part_num'          => 0,
            'batch_seq'         => 0,
            'current_part_size' => 0,
            'total_size'        => 0,
            'skipped_count'     => 0,
            'log'               => '',
        ];

        $this->save_state( $state );

        // Schedule the first step.
        wp_schedule_single_event( time() + 1, self::CRON_HOOK );
        spawn_cron();

        return true;
    }

    // ──────────────────────────────────────────────
    // Cron step dispatcher
    // ──────────────────────────────────────────────

    /**
     * Called by WP-Cron or directly from the status poll AJAX.
     * Reads state, executes current step, saves state.
     */
    public function process_step() {
        $state = $this->load_state();
        if ( ! $state ) {
            return; // No backup in progress.
        }

        // Already finished — don't run again (prevents duplicate history
        // entries when both AJAX poll and WP-Cron fire simultaneously).
        if ( in_array( $state['step'], [ 'done', 'error' ], true ) ) {
            return;
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        try {
            switch ( $state['step'] ) {
                case 'init':
                    $state = $this->step_init( $state );
                    break;
                case 'scan':
                    $state = $this->step_scan( $state );
                    break;
                case 'archive':
                    $state = $this->step_archive( $state );
                    break;
                case 'close_part':
                    $state = $this->step_close_part( $state );
                    break;
                case 'finish':
                    $state = $this->step_finish( $state );
                    break;
                default:
                    $state = $this->fail_state( $state, 'Unknown step: ' . $state['step'] );
                    break;
            }
        } catch ( \Throwable $e ) {
            $state = $this->fail_state( $state, 'Exception: ' . $e->getMessage() );
        }

        $this->save_state( $state );

        // Schedule next step as fallback for unattended cron runs.
        if ( ! in_array( $state['step'], [ 'done', 'error' ], true ) ) {
            wp_schedule_single_event( time() + 1, self::CRON_HOOK );
            spawn_cron();
        }
    }

    // ──────────────────────────────────────────────
    // Steps
    // ──────────────────────────────────────────────

    private function step_init( $state ) {
        $state = $this->log_state( $state, 'Starting ' . $state['type'] . ' backup.' );

        // Create the staging directory under wp-content/yawp-tmp/.
        $staging = self::create_staging_dir( $state['timestamp'] );
        if ( is_wp_error( $staging ) ) {
            return $this->fail_state( $state, $staging->get_error_message() );
        }
        $state['staging_dir'] = $staging;

        // Preflight: need headroom for the active part's fragments plus
        // its concatenated copy (uploaded then deleted). The threshold is
        // checked between batches, so a fat batch can overshoot
        // TARGET_PART_SIZE — reserve 3× to absorb that overshoot safely.
        $need = 3 * self::TARGET_PART_SIZE;
        $free = @disk_free_space( WP_CONTENT_DIR );
        if ( false !== $free && $free < $need ) {
            return $this->fail_state( $state, sprintf(
                'Not enough free disk space under wp-content (%s free, %s required).',
                size_format( (int) $free ),
                size_format( $need )
            ) );
        }
        $state = $this->log_state( $state, 'Staging dir ready (' . $staging . ').' );

        // Export database to a temp file, upload to S3, delete local.
        $state = $this->log_state( $state, 'Exporting database.' );
        $db_tmp = tempnam( sys_get_temp_dir(), 'yawp-db-' );
        $exporter = new YAWP_DB_Export();
        $result   = $exporter->export( $db_tmp );
        if ( is_wp_error( $result ) ) {
            @unlink( $db_tmp );
            return $this->fail_state( $state, $result->get_error_message() );
        }

        $db_size_raw = filesize( $db_tmp );
        $state = $this->log_state( $state, 'Database exported (' . size_format( $db_size_raw ) . ').' );

        // Gzip the SQL dump before uploading.
        $gz_tmp = $db_tmp . '.gz';
        $gz_ok  = false;
        $in     = fopen( $db_tmp, 'rb' );
        $out    = gzopen( $gz_tmp, 'wb6' );
        if ( $in && $out ) {
            while ( ! feof( $in ) ) {
                gzwrite( $out, fread( $in, 131072 ) );
            }
            gzclose( $out );
            fclose( $in );
            $gz_ok = true;
        } else {
            if ( $in )  fclose( $in );
            if ( $out ) gzclose( $out );
        }
        @unlink( $db_tmp );

        if ( ! $gz_ok ) {
            @unlink( $gz_tmp );
            return $this->fail_state( $state, 'Failed to gzip database dump.' );
        }

        $db_size = filesize( $gz_tmp );
        $state = $this->log_state( $state, 'Database compressed (' . size_format( $db_size ) . ').' );

        // Upload database dump to S3.
        $s3 = $this->get_s3();
        if ( is_wp_error( $s3 ) ) {
            @unlink( $gz_tmp );
            return $this->fail_state( $state, $s3->get_error_message() );
        }

        $db_key = $state['s3_dir'] . '/database.sql.gz';
        $upload = $s3->stream_upload( $db_key, $gz_tmp, self::RETENTION_DAYS );
        @unlink( $gz_tmp );
        if ( is_wp_error( $upload ) ) {
            return $this->fail_state( $state, 'DB upload failed: ' . $upload->get_error_message() );
        }
        $state = $this->log_state( $state, 'Database uploaded to S3.' );

        // Store webroot and DB size for subsequent steps.
        $webroot = rtrim( $this->get_webroot(), '/' );
        if ( ! is_dir( $webroot ) ) {
            return $this->fail_state( $state, 'Webroot does not exist: ' . $webroot );
        }

        $state['webroot']    = $webroot;
        $state['total_size'] = $db_size;
        $state['step']       = 'scan';

        return $state;
    }

    /**
     * Scan the webroot and stream the file list directly to wp_options
     * in BATCH_SIZE chunks.  This avoids accumulating 50k+ paths in a
     * single PHP array — critical on memory-constrained shared hosting.
     */
    private function step_scan( $state ) {
        $state = $this->log_state( $state, 'Building file list.' );
        $webroot = $state['webroot'];

        $since = 0;
        if ( 'incremental' === $state['type'] ) {
            $last = get_option( 'yawp_last_backup_time', '' );
            if ( $last ) {
                $since = strtotime( $last );
            }
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $webroot,
                RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        // Stream file paths directly to wp_options in BATCH_SIZE chunks
        // instead of accumulating the entire list in memory first.
        $batch       = [];
        $chunk_index = 0;
        $count       = 0;

        foreach ( $iterator as $file ) {
            $real_path = $file->getRealPath();
            if ( false === $real_path ) {
                continue;
            }

            $rel_path = ltrim( substr( $real_path, strlen( $webroot ) ), '/' );
            if ( '' === $rel_path ) {
                continue;
            }

            if ( $this->is_excluded( $rel_path ) ) {
                continue;
            }

            if ( 'wp-config.php' === $rel_path ) {
                continue;
            }

            if ( $file->isDir() ) {
                continue;
            }

            if ( ! $file->isFile() || ! $file->isReadable() ) {
                continue;
            }

            if ( $since > 0 && $file->getMTime() < $since ) {
                continue;
            }

            $batch[] = $rel_path;
            $count++;

            if ( count( $batch ) >= self::BATCH_SIZE ) {
                update_option( self::FILE_LIST_KEY . '_' . $chunk_index, wp_json_encode( $batch ), false );
                $chunk_index++;
                $batch = [];
            }
        }

        // Save any remaining files in the last partial batch.
        if ( ! empty( $batch ) ) {
            update_option( self::FILE_LIST_KEY . '_' . $chunk_index, wp_json_encode( $batch ), false );
            $chunk_index++;
        }
        update_option( self::FILE_LIST_KEY . '_chunks', $chunk_index, false );

        $state = $this->log_state( $state, "Found {$count} files to archive." );

        $state['file_cursor'] = 0;
        $state['file_count']  = $count;
        $state['step']        = 'archive';

        return $state;
    }

    /**
     * Archive one batch of files as an independent gzip member on disk.
     *
     * Writes wp-content/yawp-tmp/<ts>/current/batch-NNNNN.tar.gz. When the
     * cumulative size of fragments under current/ exceeds TARGET_PART_SIZE
     * (or the file list is exhausted), transitions to close_part so the
     * fragments get concatenated and uploaded.
     */
    private function step_archive( $state ) {
        $cursor  = $state['file_cursor'];
        $count   = $state['file_count'];
        $webroot = $state['webroot'];

        // File list exhausted — flush any remaining fragments, then finish.
        if ( $cursor >= $count ) {
            if ( $state['current_part_size'] > 0 ) {
                $state['step'] = 'close_part';
                $state = $this->log_state( $state, 'All files staged — closing final part.' );
            } else {
                $state['step'] = 'finish';
                $state = $this->log_state( $state, 'All files archived and uploaded.' );
            }
            return $state;
        }

        $chunk_index = intdiv( $cursor, self::BATCH_SIZE );
        $raw = get_option( self::FILE_LIST_KEY . '_' . $chunk_index, '' );
        if ( empty( $raw ) ) {
            return $this->fail_state( $state, 'File list chunk ' . $chunk_index . ' not found in database.' );
        }
        $batch = json_decode( $raw, true );
        if ( ! is_array( $batch ) ) {
            return $this->fail_state( $state, 'File list chunk ' . $chunk_index . ' corrupted.' );
        }

        $batch_end = min( $cursor + count( $batch ), $count );
        $seq       = $state['batch_seq'] + 1;
        $frag_path = $state['staging_dir'] . '/current/' . sprintf( 'batch-%05d.tar.gz', $seq );

        try {
            $gz = @gzopen( $frag_path, 'wb6' );
            if ( ! $gz ) {
                return $this->fail_state( $state, 'Could not open fragment for writing: ' . $frag_path );
            }

            $added   = 0;
            $skipped = 0;

            foreach ( $batch as $rel_path ) {
                $full_path = $webroot . '/' . $rel_path;
                if ( ! is_file( $full_path ) || ! is_readable( $full_path ) ) {
                    $this->record_skip( $state, $rel_path, 'unreadable' );
                    $skipped++;
                    continue;
                }
                $fsize = @filesize( $full_path );
                if ( false === $fsize ) {
                    $this->record_skip( $state, $rel_path, 'filesize-failed' );
                    $skipped++;
                    continue;
                }
                $ok = $this->write_tar_file_to_gz( $gz, $rel_path, $full_path, (int) $fsize );
                if ( ! $ok ) {
                    $this->record_skip( $state, $rel_path, 'path-too-long' );
                    $skipped++;
                    continue;
                }
                $added++;
            }

            gzclose( $gz );

            $state['skipped_count'] = ( $state['skipped_count'] ?? 0 ) + $skipped;

            if ( 0 === $added ) {
                // All skipped — drop the empty fragment and advance cursor.
                @unlink( $frag_path );
                $state['file_cursor'] = $batch_end;
                $state = $this->log_state( $state, "Batch {$cursor}-{$batch_end}: 0 files (all {$skipped} skipped)." );
                return $state;
            }

            $frag_size                   = (int) @filesize( $frag_path );
            $state['file_cursor']        = $batch_end;
            $state['batch_seq']          = $seq;
            $state['current_part_size'] += $frag_size;

            $state = $this->log_state( $state, sprintf(
                'Staged batch %d: files %d-%d (%d added%s, %s — part %d now %s).',
                $seq,
                $cursor,
                $batch_end,
                $added,
                $skipped > 0 ? ", {$skipped} skipped" : '',
                size_format( $frag_size ),
                $state['part_num'] + 1,
                size_format( $state['current_part_size'] )
            ) );

            // Close the part if we've hit the size threshold or drained
            // the file list.
            if ( $state['current_part_size'] >= self::TARGET_PART_SIZE || $state['file_cursor'] >= $count ) {
                $state['step'] = 'close_part';
            }

        } catch ( \Throwable $e ) {
            @unlink( $frag_path );
            return $this->fail_state( $state, 'Archive step exception: ' . $e->getMessage() );
        }

        return $state;
    }

    /**
     * Concatenate all batch-*.tar.gz fragments under current/ into one
     * part-NNNN.tar.gz, append the tar EOF marker as a final gzip member,
     * upload via multipart S3, delete the local copy. Fragments form a
     * valid gzip file by concatenation (RFC 1952 §2.2).
     */
    private function step_close_part( $state ) {
        $part_num   = $state['part_num'] + 1;
        $current    = $state['staging_dir'] . '/current';
        $part_path  = $state['staging_dir'] . '/' . sprintf( 'part-%04d.tar.gz', $part_num );

        $fragments = glob( $current . '/batch-*.tar.gz' );
        if ( false === $fragments || empty( $fragments ) ) {
            // Nothing to do — shouldn't happen, but recover gracefully.
            $state['step'] = $state['file_cursor'] >= $state['file_count'] ? 'finish' : 'archive';
            $state = $this->log_state( $state, 'close_part: no fragments present, skipping.' );
            return $state;
        }
        sort( $fragments, SORT_STRING );

        // Remove any stale output from a prior crashed close_part.
        if ( file_exists( $part_path ) ) {
            @unlink( $part_path );
        }

        try {
            $out = @fopen( $part_path, 'wb' );
            if ( ! $out ) {
                return $this->fail_state( $state, 'Could not open part file: ' . $part_path );
            }

            foreach ( $fragments as $frag ) {
                $in = @fopen( $frag, 'rb' );
                if ( ! $in ) {
                    fclose( $out );
                    @unlink( $part_path );
                    return $this->fail_state( $state, 'Could not read fragment: ' . $frag );
                }
                while ( ! feof( $in ) ) {
                    $chunk = fread( $in, self::FREAD_CHUNK );
                    if ( false === $chunk || '' === $chunk ) {
                        break;
                    }
                    if ( false === fwrite( $out, $chunk ) ) {
                        fclose( $in );
                        fclose( $out );
                        @unlink( $part_path );
                        return $this->fail_state( $state, 'Write failed concatenating ' . basename( $frag ) );
                    }
                }
                fclose( $in );
            }

            // Append the tar EOF marker (1024 null bytes) as its own gzip
            // member. Generate via gzopen to a throwaway file and append.
            $eof_path = $current . '/_eof.gz';
            $eg       = @gzopen( $eof_path, 'wb6' );
            if ( ! $eg ) {
                fclose( $out );
                @unlink( $part_path );
                return $this->fail_state( $state, 'Could not build EOF marker.' );
            }
            gzwrite( $eg, pack( 'a1024', '' ) );
            gzclose( $eg );
            $eg_in = @fopen( $eof_path, 'rb' );
            if ( $eg_in ) {
                while ( ! feof( $eg_in ) ) {
                    $chunk = fread( $eg_in, self::FREAD_CHUNK );
                    if ( false === $chunk || '' === $chunk ) {
                        break;
                    }
                    fwrite( $out, $chunk );
                }
                fclose( $eg_in );
            }
            @unlink( $eof_path );

            fclose( $out );

            $part_size = (int) @filesize( $part_path );
            if ( $part_size <= 0 ) {
                return $this->fail_state( $state, 'Part file is empty after concat: ' . $part_path );
            }

            $s3 = $this->get_s3();
            if ( is_wp_error( $s3 ) ) {
                @unlink( $part_path );
                return $this->fail_state( $state, $s3->get_error_message() );
            }

            $part_key = sprintf( '%s/part-%04d.tar.gz', $state['s3_dir'], $part_num );
            $upload   = $s3->stream_upload( $part_key, $part_path, self::RETENTION_DAYS );
            @unlink( $part_path );
            if ( is_wp_error( $upload ) ) {
                return $this->fail_state( $state, 'Upload failed for part ' . $part_num . ': ' . $upload->get_error_message() );
            }

            // Clean up the fragments now that they're durably in S3.
            foreach ( $fragments as $frag ) {
                @unlink( $frag );
            }

            $state['part_num']          = $part_num;
            $state['batch_seq']         = 0;
            $state['current_part_size'] = 0;
            $state['total_size']       += $part_size;
            $state = $this->log_state( $state, sprintf(
                'Uploaded part %d (%s, %d fragments).',
                $part_num,
                size_format( $part_size ),
                count( $fragments )
            ) );

            $state['step'] = $state['file_cursor'] >= $state['file_count'] ? 'finish' : 'archive';

        } catch ( \Throwable $e ) {
            @unlink( $part_path );
            return $this->fail_state( $state, 'Close-part exception: ' . $e->getMessage() );
        }

        return $state;
    }

    private function step_finish( $state ) {
        $s3 = $this->get_s3();

        // Build and upload manifest for this backup.
        $parts = [];
        for ( $i = 1; $i <= $state['part_num']; $i++ ) {
            $parts[] = sprintf( '%s/part-%04d.tar.gz', $state['s3_dir'], $i );
        }

        $skipped_count  = (int) ( $state['skipped_count'] ?? 0 );
        $archived_count = max( 0, (int) $state['file_count'] - $skipped_count );

        $manifest = [
            'version'        => 2,
            'type'           => $state['type'],
            'timestamp'      => $state['timestamp'],
            'file_count'     => $state['file_count'],
            'archived_count' => $archived_count,
            'skipped_count'  => $skipped_count,
            'part_count'     => $state['part_num'],
            'total_size'     => $state['total_size'],
            'database'       => $state['s3_dir'] . '/database.sql.gz',
            'parts'          => $parts,
        ];
        if ( $skipped_count > 0 ) {
            $manifest['skipped_log'] = $state['s3_dir'] . '/skipped.txt';
        }

        if ( ! is_wp_error( $s3 ) ) {
            // Upload skipped log first so the manifest's reference is valid.
            $skipped_path = ! empty( $state['staging_dir'] ) ? $state['staging_dir'] . '/skipped.txt' : '';
            if ( $skipped_path && is_file( $skipped_path ) && @filesize( $skipped_path ) > 0 ) {
                $s3->stream_upload( $state['s3_dir'] . '/skipped.txt', $skipped_path, self::RETENTION_DAYS );
            }

            // Upload backup manifest.
            $manifest_key = $state['s3_dir'] . '/manifest.json';
            $s3->put_text( $manifest_key, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );

            // Also write the top-level manifest for backward compat.
            $prefix = trim( get_option( 'yawp_s3_prefix', '' ), '/' );
            $top_manifest_key = ( $prefix ? $prefix . '/' : '' ) . 'manifest.json';
            $top_manifest = [
                'last_backup'  => $state['timestamp'],
                'last_type'    => $state['type'],
                'last_s3_key'  => $state['s3_dir'] . '/manifest.json',
                'last_size'    => $state['total_size'],
                'format'       => 'chunked-v2',
            ];
            $s3->put_json( $top_manifest_key, wp_json_encode( $top_manifest ) );

            // Upload README if needed.
            $this->maybe_upload_readme( $s3, $prefix );
        }

        // Record in wp_options.
        $now = current_time( 'mysql', true );
        update_option( 'yawp_last_backup_time', $now, false );
        if ( 'full' === $state['type'] ) {
            update_option( 'yawp_last_full_backup_time', $now, false );
        }

        $this->add_history_entry( [
            'date'   => $now,
            'type'   => $state['type'],
            'size'   => $state['total_size'],
            's3_key' => $state['s3_dir'] . '/',
            'status' => 'success',
        ]);

        delete_option( 'yawp_last_error' );

        // Clean up file list chunks from wp_options.
        self::delete_file_list_chunks();

        // Remove the staging directory — everything is durably in S3 now.
        if ( ! empty( $state['staging_dir'] ) ) {
            self::rrmdir( $state['staging_dir'] );
        }

        $skipped_suffix = $skipped_count > 0 ? ", {$skipped_count} files skipped" : '';
        $state = $this->log_state( $state, ucfirst( $state['type'] ) . ' backup completed successfully (' . $state['part_num'] . ' parts, ' . size_format( $state['total_size'] ) . $skipped_suffix . ').' );
        yawp_log( 'info', 'Backup completed', [
            'type'    => $state['type'],
            'parts'   => $state['part_num'],
            'size'    => $state['total_size'],
            'skipped' => $skipped_count,
        ] );
        $this->healthcheck( 'success', $state['log'] );

        delete_transient( self::LOCK_KEY );

        $state['step'] = 'done';
        return $state;
    }

    // ──────────────────────────────────────────────
    // State management
    // ──────────────────────────────────────────────

    private function save_state( $state ) {
        update_option( self::STATE_KEY, wp_json_encode( $state ), false );
    }

    private function load_state() {
        $raw = get_option( self::STATE_KEY, '' );
        if ( empty( $raw ) ) {
            return null;
        }
        $state = json_decode( $raw, true );
        return is_array( $state ) ? $state : null;
    }

    private function log_state( $state, $message ) {
        // Keep log from growing too large (trim to last 10 KB).
        $entry = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . "\n";
        $state['log'] .= $entry;
        if ( strlen( $state['log'] ) > 10000 ) {
            $state['log'] = "…(trimmed)\n" . substr( $state['log'], -9000 );
        }
        return $state;
    }

    private function fail_state( $state, $message ) {
        yawp_log( 'error', 'Backup failed: ' . $message, [ 'type' => $state['type'], 'step' => $state['step'] ?? 'unknown' ] );
        $state = $this->log_state( $state, 'FAILED: ' . $message );
        $state['step']  = 'error';
        $state['error'] = $message;

        $this->healthcheck( 'fail', $state['log'] );
        update_option( 'yawp_last_error', $message, false );

        $this->add_history_entry( [
            'date'   => current_time( 'mysql', true ),
            'type'   => 'error',
            'size'   => 0,
            's3_key' => '',
            'status' => $message,
        ]);

        // Best-effort: upload the skipped log before nuking the staging dir
        // so partial-run evidence survives. Silent on any S3 error — this
        // is already a failure path.
        if ( ! empty( $state['staging_dir'] ) && ! empty( $state['s3_dir'] ) ) {
            $skipped_path = $state['staging_dir'] . '/skipped.txt';
            if ( is_file( $skipped_path ) && @filesize( $skipped_path ) > 0 ) {
                $s3 = $this->get_s3();
                if ( ! is_wp_error( $s3 ) ) {
                    $s3->stream_upload( $state['s3_dir'] . '/skipped.txt', $skipped_path, self::RETENTION_DAYS );
                }
            }
        }

        // Clean up.
        self::delete_file_list_chunks();
        if ( ! empty( $state['staging_dir'] ) ) {
            self::rrmdir( $state['staging_dir'] );
        }
        delete_transient( self::LOCK_KEY );

        return $state;
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Build a 512-byte POSIX/ustar tar header for a regular file.
     *
     * Returns the header bytes, or '' if the path is too long for ustar
     * (prefix 155 + name 100 = 255 chars max, with split on a '/' boundary).
     */
    private function tar_header( $name, $size ) {
        $prefix = '';
        if ( strlen( $name ) > 100 ) {
            $split = strrpos( substr( $name, 0, strlen( $name ) - 1 ), '/' );
            if ( false !== $split && $split <= 155 && ( strlen( $name ) - $split - 1 ) <= 100 ) {
                $prefix = substr( $name, 0, $split );
                $name   = substr( $name, $split + 1 );
            } else {
                return '';
            }
        }

        $header = pack(
            'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
            $name,                          // name (100)
            '0000644',                      // mode (8)
            '0000000',                      // uid (8)
            '0000000',                      // gid (8)
            sprintf( '%011o', $size ),      // size in octal (12)
            sprintf( '%011o', time() ),     // mtime in octal (12)
            '        ',                     // checksum placeholder — 8 spaces (8)
            '0',                            // typeflag: regular file (1)
            '',                             // linkname (100)
            'ustar',                        // magic (6)
            '00',                           // version (2)
            '',                             // uname (32)
            '',                             // gname (32)
            '',                             // devmajor (8)
            '',                             // devminor (8)
            $prefix,                        // prefix (155)
            ''                              // padding (12)
        );

        $checksum = 0;
        for ( $i = 0; $i < 512; $i++ ) {
            $checksum += ord( $header[ $i ] );
        }
        $checksum = sprintf( '%06o', $checksum ) . "\0 ";
        return substr_replace( $header, $checksum, 148, 8 );
    }

    /**
     * Null-padding to round a data payload of $size bytes up to the next
     * 512-byte tar boundary.
     */
    private function tar_pad( $size ) {
        $padding = ( 512 - ( $size % 512 ) ) % 512;
        return $padding > 0 ? pack( 'a' . $padding, '' ) : '';
    }

    /**
     * Stream a single file's tar entry (header + body + padding) into an
     * open gzip handle. Peak memory is one FREAD_CHUNK plus zlib's window.
     *
     * Returns true on success, false if the path is too long for ustar or
     * the file can't be opened. If the file's on-disk size changes during
     * read, the declared header size wins: extra bytes are dropped, missing
     * bytes are zero-padded, so the tar stays well-formed.
     */
    private function write_tar_file_to_gz( $gz, $rel_path, $full_path, $size ) {
        $header = $this->tar_header( $rel_path, $size );
        if ( '' === $header ) {
            return false;
        }
        $fh = @fopen( $full_path, 'rb' );
        if ( ! $fh ) {
            return false;
        }
        gzwrite( $gz, $header );

        $remaining = $size;
        while ( $remaining > 0 ) {
            $want  = $remaining < self::FREAD_CHUNK ? $remaining : self::FREAD_CHUNK;
            $chunk = fread( $fh, $want );
            if ( false === $chunk || '' === $chunk ) {
                break;
            }
            gzwrite( $gz, $chunk );
            $remaining -= strlen( $chunk );
        }
        fclose( $fh );

        // File shrank mid-read? Zero-pad to declared size so the next tar
        // header lands on the correct offset.
        while ( $remaining > 0 ) {
            $pad = $remaining < self::FREAD_CHUNK ? $remaining : self::FREAD_CHUNK;
            gzwrite( $gz, str_repeat( "\0", $pad ) );
            $remaining -= $pad;
        }

        gzwrite( $gz, $this->tar_pad( $size ) );
        return true;
    }

    /**
     * Append a single line to the staging skipped.txt log. Each line is
     * tab-separated: <reason>\t<rel_path>. Uploaded to S3 alongside the
     * manifest at finish so skips are evidenceable after the fact.
     */
    private function record_skip( $state, $rel_path, $reason ) {
        if ( empty( $state['staging_dir'] ) ) {
            return;
        }
        $line = $reason . "\t" . $rel_path . "\n";
        @file_put_contents( $state['staging_dir'] . '/skipped.txt', $line, FILE_APPEND );
    }

    /**
     * Root directory for on-disk staging. Each running backup owns a
     * timestamped subdirectory here; see step_init() for creation and
     * cancel()/fail_state()/step_finish() for cleanup.
     */
    private static function staging_root() {
        return rtrim( WP_CONTENT_DIR, '/' ) . '/yawp-tmp';
    }

    /**
     * Recursively delete a directory and everything below it. Used by
     * cleanup paths. Silently tolerates already-gone files.
     */
    private static function rrmdir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $items = @scandir( $dir );
        if ( false === $items ) {
            return;
        }
        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $path = $dir . '/' . $item;
            if ( is_dir( $path ) && ! is_link( $path ) ) {
                self::rrmdir( $path );
            } else {
                @unlink( $path );
            }
        }
        @rmdir( $dir );
    }

    /**
     * Remove staging subdirectories older than LOCK_TTL. Called from
     * start() — picks up after any backup that crashed without cleanup.
     */
    private static function sweep_orphan_staging() {
        $root = self::staging_root();
        if ( ! is_dir( $root ) ) {
            return;
        }
        $cutoff = time() - self::LOCK_TTL;
        $items  = @scandir( $root );
        if ( false === $items ) {
            return;
        }
        foreach ( $items as $item ) {
            if ( '.' === $item || '..' === $item ) {
                continue;
            }
            $path = $root . '/' . $item;
            if ( is_dir( $path ) && ! is_link( $path ) ) {
                $mtime = @filemtime( $path );
                if ( false !== $mtime && $mtime < $cutoff ) {
                    self::rrmdir( $path );
                }
            }
        }
    }

    /**
     * Create the timestamped staging directory and its guards. Returns
     * the absolute path on success, or a WP_Error on failure.
     */
    private static function create_staging_dir( $timestamp ) {
        $root = self::staging_root();
        if ( ! wp_mkdir_p( $root ) ) {
            return new WP_Error( 'yawp_backup', 'Could not create ' . $root );
        }
        // Guard the root — web-inaccessible even before any backup runs.
        $htaccess = $root . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule .* - [F,L]\n</IfModule>\n" );
        }
        $index = $root . '/index.html';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, '' );
        }

        $dir = $root . '/' . $timestamp;
        if ( ! wp_mkdir_p( $dir . '/current' ) ) {
            return new WP_Error( 'yawp_backup', 'Could not create ' . $dir . '/current' );
        }
        return $dir;
    }

    private function is_excluded( $rel_path ) {
        foreach ( $this->get_excludes() as $exclude ) {
            if ( $rel_path === $exclude || 0 === strpos( $rel_path, $exclude . '/' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Return the effective exclude list — built-ins plus any
     * user-configured entries from the yawp_extra_excludes setting.
     */
    private function get_excludes() {
        if ( null !== self::$combined_excludes ) {
            return self::$combined_excludes;
        }
        $extra = get_option( 'yawp_extra_excludes', '' );
        $user  = [];
        if ( is_string( $extra ) && '' !== $extra ) {
            foreach ( explode( ',', $extra ) as $item ) {
                $item = trim( $item );
                $item = trim( $item, "/\\" );
                if ( '' === $item || false !== strpos( $item, '..' ) ) {
                    continue;
                }
                $user[] = $item;
            }
        }
        self::$combined_excludes = array_merge( self::$excludes, $user );
        return self::$combined_excludes;
    }

    private function get_webroot() {
        $webroot = get_option( 'yawp_webroot', '' );
        if ( empty( $webroot ) || '/var/www/html' === $webroot ) {
            if ( defined( 'ABSPATH' ) && is_dir( ABSPATH ) ) {
                if ( ! empty( $webroot ) && is_dir( $webroot ) ) {
                    return $webroot;
                }
                return rtrim( ABSPATH, '/' );
            }
        }
        return $webroot;
    }

    private function get_s3() {
        $access_key = get_option( 'yawp_s3_access_key', '' );
        $secret_key = YAWP_Admin::decrypt_secret( get_option( 'yawp_s3_secret_key', '' ) );
        $region     = get_option( 'yawp_s3_region', 'eu-west-2' );
        $bucket     = get_option( 'yawp_s3_bucket', '' );

        if ( ! $access_key || ! $secret_key || ! $bucket ) {
            return new WP_Error( 'yawp_backup', 'S3 credentials are not configured.' );
        }

        return new YAWP_S3( $access_key, $secret_key, $region, $bucket );
    }

    private function add_history_entry( $entry ) {
        $history = json_decode( get_option( 'yawp_backup_history', '[]' ), true );
        if ( ! is_array( $history ) ) {
            $history = [];
        }
        array_unshift( $history, $entry );
        $history = array_slice( $history, 0, 50 );
        update_option( 'yawp_backup_history', wp_json_encode( $history ), false );
    }

    public function healthcheck( $event, $log = '' ) {
        $base = rtrim( get_option( 'yawp_healthchecks_url', '' ), '/' );
        if ( empty( $base ) ) {
            return;
        }

        $suffix = '';
        if ( 'start' === $event ) {
            $suffix = '/start';
        } elseif ( 'fail' === $event ) {
            $suffix = '/fail';
        }

        wp_remote_post( $base . $suffix, [
            'body'    => substr( $log, -10000 ),
            'headers' => [ 'Content-Type' => 'text/plain' ],
            'timeout' => 10,
        ] );
    }

    private function maybe_upload_readme( $s3, $prefix ) {
        $readme_key = ( $prefix ? $prefix . '/' : '' ) . 'README.txt';

        $objects = $s3->list_objects( $readme_key );
        if ( ! is_wp_error( $objects ) ) {
            foreach ( $objects as $obj ) {
                if ( $obj['Key'] === $readme_key ) {
                    return;
                }
            }
        }

        $siteurl = get_option( 'siteurl' );
        $date    = gmdate( 'Y-m-d H:i:s' ) . ' UTC';
        $pfx     = $prefix ?: '{prefix}';

        $readme = <<<TXT
YAWP Backup Repository
=======================

This bucket contains WordPress backups created by the YAWP plugin.
https://github.com/nonatech-uk/yawp

Source site: {$siteurl}
Created:     {$date}

Structure (v2 — chunked)
------------------------
{$pfx}/{type}/{timestamp}/database.sql.gz       Gzipped database dump
{$pfx}/{type}/{timestamp}/part-0001.tar.gz      File archive part 1
{$pfx}/{type}/{timestamp}/part-0002.tar.gz      File archive part 2
  ...
{$pfx}/{type}/{timestamp}/manifest.json         Backup manifest (lists all parts)
{$pfx}/manifest.json                            Last backup metadata

Each part-*.tar.gz contains a subset of the WordPress webroot files
(relative paths). Extract all parts in order to reconstruct the full site.

Excluded from archives:
- wp-config.php              Preserves target site's DB credentials and salts
- wp-content/plugins/yawp    The plugin itself (install separately)
- wp-content/object-cache.php  Drop-in cache config (site-specific)
- wp-content/cache           Cache files
- .git                       Version control data
- .claude                    IDE config

Restoring
---------
1. Fresh WordPress install on target server
2. Install and activate the YAWP plugin
3. Go to Settings > YAWP Backup
4. Enter S3 credentials and save settings
5. Use the Restore section to list and restore a backup
6. If restoring to a different domain, enter the new URL before restoring
TXT;

        $s3->put_text( $readme_key, $readme );
    }
}
