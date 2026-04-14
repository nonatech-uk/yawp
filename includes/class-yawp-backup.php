<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Chunked backup engine — no temp files persist between requests.
 *
 * IONOS shared hosting aggressively cleans up temp directories between
 * PHP requests, so we cannot accumulate a tar archive across steps.
 * Instead, each step creates a small tar.gz from a batch of files,
 * uploads it directly to S3, then deletes the local copy.
 *
 * State machine: init → archive (×N) → finish
 *
 * Backup format in S3:
 *   {prefix}/{type}/{timestamp}/database.sql.gz
 *   {prefix}/{type}/{timestamp}/part-0001.tar.gz
 *   {prefix}/{type}/{timestamp}/part-0002.tar.gz
 *   ...
 *   {prefix}/{type}/{timestamp}/manifest.json
 *
 * Also writes a single combined .tar.gz key to manifest for backward-
 * compatible listing by the restore class.
 */
class YAWP_Backup {

    const LOCK_KEY       = 'yawp_backup_running';
    const LOCK_TTL       = 7200; // 2 hours (large sites take time)
    const STATE_KEY      = 'yawp_backup_state';
    const FILE_LIST_KEY  = 'yawp_backup_filelist';
    const CRON_HOOK      = 'yawp_backup_step';
    const RETENTION_DAYS = 0;
    const BATCH_SIZE     = 200; // files per archive step
    const MAX_FILE_SIZE  = 52428800; // 50 MB — skip larger files to avoid PharData truncation

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

        // Progress info for archive step.
        if ( 'archive' === $status['step'] ) {
            $total   = $state['file_count'] ?? 0;
            $cursor  = $state['file_cursor'] ?? 0;
            $status['progress'] = $cursor . '/' . $total . ' files';
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

        $this->healthcheck( 'start' );
        yawp_log( 'info', 'Backup started', [ 'type' => $type ] );

        $timestamp = gmdate( 'Y-m-d_His' );
        $prefix    = trim( get_option( 'yawp_s3_prefix', '' ), '/' );
        $s3_dir    = ( $prefix ? $prefix . '/' : '' ) . $type . '/' . $timestamp;

        $state = [
            'step'        => 'init',
            'type'        => $type,
            'timestamp'   => $timestamp,
            's3_dir'      => $s3_dir,
            'file_cursor' => 0,
            'file_count'  => 0,
            'part_num'    => 0,
            'total_size'  => 0,
            'log'         => '',
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

    private function step_archive( $state ) {
        $cursor  = $state['file_cursor'];
        $count   = $state['file_count'];
        $webroot = $state['webroot'];

        if ( $cursor >= $count ) {
            $state['step'] = 'finish';
            $state = $this->log_state( $state, 'All files archived and uploaded.' );
            return $state;
        }

        // Read only this batch's chunk from wp_options (not the entire file list).
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

        // Build tar in memory (no PharData — it corrupts on IONOS shared hosting).
        $part_num = $state['part_num'] + 1;

        try {
            $tar_data = '';
            $added    = 0;
            $skipped  = 0;

            foreach ( $batch as $rel_path ) {
                $full_path = $webroot . '/' . $rel_path;
                if ( ! is_file( $full_path ) || ! is_readable( $full_path ) ) {
                    continue;
                }
                $fsize = @filesize( $full_path );
                if ( false === $fsize || $fsize > self::MAX_FILE_SIZE ) {
                    $skipped++;
                    continue;
                }
                $contents = @file_get_contents( $full_path );
                if ( false === $contents ) {
                    $skipped++;
                    continue;
                }
                $entry = $this->tar_entry( $rel_path, $contents );
                unset( $contents );
                if ( '' === $entry ) {
                    $skipped++; // Path too long for ustar.
                    continue;
                }
                $tar_data .= $entry;
                $added++;
            }

            if ( 0 === $added ) {
                $state['file_cursor'] = $batch_end;
                $state = $this->log_state( $state, "Batch {$cursor}-{$batch_end}: 0 files (all skipped, {$skipped} oversize/unreadable)." );
                return $state;
            }

            // Two 512-byte null blocks = end-of-archive marker.
            $tar_data .= pack( 'a1024', '' );

            $gz_data = gzencode( $tar_data, 6 );
            unset( $tar_data );

            if ( false === $gz_data ) {
                return $this->fail_state( $state, "Compression failed for batch starting at {$cursor}." );
            }

            $gz_path = tempnam( sys_get_temp_dir(), 'yawp-gz-' );
            file_put_contents( $gz_path, $gz_data );
            $part_size = strlen( $gz_data );
            unset( $gz_data );

            // Upload this part to S3.
            $s3 = $this->get_s3();
            if ( is_wp_error( $s3 ) ) {
                @unlink( $gz_path );
                return $this->fail_state( $state, $s3->get_error_message() );
            }

            $part_key = sprintf( '%s/part-%04d.tar.gz', $state['s3_dir'], $part_num );
            $upload   = $s3->stream_upload( $part_key, $gz_path, self::RETENTION_DAYS );
            @unlink( $gz_path );

            if ( is_wp_error( $upload ) ) {
                return $this->fail_state( $state, 'Upload failed for part ' . $part_num . ': ' . $upload->get_error_message() );
            }

            $state['file_cursor'] = $batch_end;
            $state['part_num']    = $part_num;
            $state['total_size'] += $part_size;
            $state = $this->log_state( $state, "Part {$part_num}: files {$cursor}-{$batch_end} ({$added} added, " . size_format( $part_size ) . ').' );

        } catch ( \Throwable $e ) {
            return $this->fail_state( $state, 'Archive step exception: ' . $e->getMessage() );
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

        $manifest = [
            'version'    => 2,
            'type'       => $state['type'],
            'timestamp'  => $state['timestamp'],
            'file_count' => $state['file_count'],
            'part_count' => $state['part_num'],
            'total_size' => $state['total_size'],
            'database'   => $state['s3_dir'] . '/database.sql',
            'parts'      => $parts,
        ];

        if ( ! is_wp_error( $s3 ) ) {
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

        $state = $this->log_state( $state, ucfirst( $state['type'] ) . ' backup completed successfully (' . $state['part_num'] . ' parts, ' . size_format( $state['total_size'] ) . ').' );
        yawp_log( 'info', 'Backup completed', [ 'type' => $state['type'], 'parts' => $state['part_num'], 'size' => $state['total_size'] ] );
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

        // Clean up.
        self::delete_file_list_chunks();
        delete_transient( self::LOCK_KEY );

        return $state;
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Build a single POSIX/ustar tar entry (header + data) in memory.
     *
     * Returns a binary string that can be concatenated to form a valid tar.
     * No temp files, no PharData — immune to IONOS temp-dir corruption.
     */
    private function tar_entry( $name, $data ) {
        $size = strlen( $data );

        // ustar supports prefix (155) + name (100) = 255 chars max.
        // Split long paths at a '/' boundary into prefix + name.
        $prefix = '';
        if ( strlen( $name ) > 100 ) {
            // Find last '/' that leaves name <= 100 and prefix <= 155.
            $split = strrpos( substr( $name, 0, strlen( $name ) - 1 ), '/' );
            if ( false !== $split && $split <= 155 && ( strlen( $name ) - $split - 1 ) <= 100 ) {
                $prefix = substr( $name, 0, $split );
                $name   = substr( $name, $split + 1 );
            } else {
                // Path too long for ustar — skip this file.
                return '';
            }
        }

        // Tar header: 512 bytes, ustar format.
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

        // Compute checksum: sum of all unsigned bytes, with checksum field as spaces.
        $checksum = 0;
        for ( $i = 0; $i < 512; $i++ ) {
            $checksum += ord( $header[ $i ] );
        }
        $checksum = sprintf( '%06o', $checksum ) . "\0 ";
        $header   = substr_replace( $header, $checksum, 148, 8 );

        // Data padded to 512-byte boundary.
        $padding = ( 512 - ( $size % 512 ) ) % 512;

        return $header . $data . ( $padding > 0 ? pack( 'a' . $padding, '' ) : '' );
    }

    private function is_excluded( $rel_path ) {
        foreach ( self::$excludes as $exclude ) {
            if ( $rel_path === $exclude || 0 === strpos( $rel_path, $exclude . '/' ) ) {
                return true;
            }
        }
        return false;
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
