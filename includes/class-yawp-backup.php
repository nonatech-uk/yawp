<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Chunked backup engine using WP-Cron steps.
 *
 * Each step runs as a separate PHP request via wp_schedule_single_event(),
 * keeping execution time under shared-hosting limits (~30-60 s).
 *
 * State machine: init → archive (×N) → compress → upload → finish
 */
class YAWP_Backup {

    const LOCK_KEY       = 'yawp_backup_running';
    const LOCK_TTL       = 1800; // 30 minutes (longer now — multi-step)
    const STATE_KEY      = 'yawp_backup_state';
    const CRON_HOOK      = 'yawp_backup_step';
    const RETENTION_DAYS = 0;
    const BATCH_SIZE     = 100; // files per archive step

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
        $state = get_option( self::STATE_KEY, '' );
        if ( ! empty( $state ) ) {
            $state = json_decode( $state, true );
            if ( is_array( $state ) && ! empty( $state['tmp_dir'] ) ) {
                self::cleanup_dir( $state['tmp_dir'] );
            }
        }
        delete_option( self::STATE_KEY );
        delete_transient( self::LOCK_KEY );
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    // ──────────────────────────────────────────────
    // Entry point
    // ──────────────────────────────────────────────

    private function start( $type ) {
        if ( get_transient( self::LOCK_KEY ) ) {
            return new WP_Error( 'yawp_backup', 'A backup is already running.' );
        }
        set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

        // Clean up any stale temp dirs.
        $this->cleanup_stale_tmp_dirs();

        $this->healthcheck( 'start' );

        $timestamp = gmdate( 'Y-m-d_His' );
        $tmp_base  = $this->get_tmp_base();
        $tmp_dir   = $tmp_base . '/yawp-backup-' . $timestamp;

        $prefix = trim( get_option( 'yawp_s3_prefix', '' ), '/' );
        $s3_key = ( $prefix ? $prefix . '/' : '' ) . $type . '/' . $timestamp . '.tar.gz';

        $state = [
            'step'        => 'init',
            'type'        => $type,
            'timestamp'   => $timestamp,
            'tmp_dir'     => $tmp_dir,
            'tar_path'    => $tmp_dir . '/backup.tar',
            'archive_path'=> $tmp_dir . '/backup.tar.gz',
            'db_file'     => $tmp_dir . '/database.sql',
            'file_list'   => $tmp_dir . '/filelist.txt',
            'file_cursor' => 0,
            'file_count'  => 0,
            's3_key'      => $s3_key,
            'log'         => '',
        ];

        $this->save_state( $state );

        // Schedule the first step 1 s in the future so WP-Cron doesn't
        // deduplicate it with the current request's timestamp.
        wp_schedule_single_event( time() + 1, self::CRON_HOOK );
        spawn_cron();

        return true;
    }

    // ──────────────────────────────────────────────
    // Cron step dispatcher
    // ──────────────────────────────────────────────

    /**
     * Called by WP-Cron. Reads state, executes current step, saves state,
     * schedules next step.
     */
    public function process_step() {
        $state = $this->load_state();
        if ( ! $state ) {
            return; // No backup in progress.
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        try {
            switch ( $state['step'] ) {
                case 'init':
                    $state = $this->step_init( $state );
                    break;
                case 'archive':
                    $state = $this->step_archive( $state );
                    break;
                case 'compress':
                    $state = $this->step_compress( $state );
                    break;
                case 'upload':
                    $state = $this->step_upload( $state );
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

        // Schedule next step if still running.
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

        $tmp_dir = $state['tmp_dir'];
        if ( ! mkdir( $tmp_dir, 0700, true ) ) {
            return $this->fail_state( $state, 'Cannot create temp directory.' );
        }

        // Export database.
        $state = $this->log_state( $state, 'Exporting database.' );
        $exporter = new YAWP_DB_Export();
        $result   = $exporter->export( $state['db_file'] );
        if ( is_wp_error( $result ) ) {
            return $this->fail_state( $state, $result->get_error_message() );
        }
        $state = $this->log_state( $state, 'Database exported (' . size_format( filesize( $state['db_file'] ) ) . ').' );

        // Build file list.
        $state = $this->log_state( $state, 'Building file list.' );
        $webroot = rtrim( $this->get_webroot(), '/' );
        if ( ! is_dir( $webroot ) ) {
            return $this->fail_state( $state, 'Webroot does not exist: ' . $webroot );
        }

        $since = 0;
        if ( 'incremental' === $state['type'] ) {
            $last = get_option( 'yawp_last_backup_time', '' );
            if ( $last ) {
                $since = strtotime( $last );
            }
        }

        $file_list = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $webroot,
                RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

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
                continue; // PharData creates dirs implicitly.
            }

            if ( ! $file->isFile() || ! $file->isReadable() ) {
                continue;
            }

            if ( $since > 0 && $file->getMTime() < $since ) {
                continue;
            }

            $file_list[] = $rel_path;
        }

        $count = count( $file_list );
        $state = $this->log_state( $state, "Found {$count} files to archive." );

        // Save file list to temp file (avoid bloating wp_options).
        file_put_contents( $state['file_list'], implode( "\n", $file_list ) );

        // Create initial tar with just the database dump.
        $phar = new PharData( $state['tar_path'] );
        $phar->addFile( $state['db_file'], 'database.sql' );
        unset( $phar );

        $state['file_cursor'] = 0;
        $state['file_count']  = $count;
        $state['webroot']     = $webroot;
        $state['step']        = 'archive';

        return $state;
    }

    private function step_archive( $state ) {
        $cursor  = $state['file_cursor'];
        $count   = $state['file_count'];
        $webroot = $state['webroot'];

        if ( $cursor >= $count ) {
            $state['step'] = 'compress';
            $state = $this->log_state( $state, 'All files archived.' );
            return $state;
        }

        // Read file list from temp file.
        $all_files = file( $state['file_list'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
        if ( false === $all_files ) {
            return $this->fail_state( $state, 'Cannot read file list.' );
        }

        $batch_end = min( $cursor + self::BATCH_SIZE, $count );
        $batch = array_slice( $all_files, $cursor, self::BATCH_SIZE );

        $phar = new PharData( $state['tar_path'] );
        $added = 0;

        foreach ( $batch as $rel_path ) {
            $full_path = $webroot . '/' . $rel_path;
            if ( ! is_file( $full_path ) || ! is_readable( $full_path ) ) {
                continue; // File may have been deleted since list was built.
            }
            try {
                $phar->addFile( $full_path, $rel_path );
                $added++;
            } catch ( \Throwable $e ) {
                // Skip individual file errors (permissions, etc.)
                continue;
            }
        }

        unset( $phar );

        $state['file_cursor'] = $batch_end;
        $state = $this->log_state( $state, "Archived files {$cursor}-{$batch_end} of {$count} ({$added} added)." );

        // Stay on 'archive' step — will loop back.
        return $state;
    }

    private function step_compress( $state ) {
        $state = $this->log_state( $state, 'Compressing archive.' );

        $tar_path     = $state['tar_path'];
        $archive_path = $state['archive_path'];

        if ( ! file_exists( $tar_path ) ) {
            return $this->fail_state( $state, 'Tar file not found for compression.' );
        }

        // Remove any prior .tar.gz.
        if ( file_exists( $archive_path ) ) {
            @unlink( $archive_path );
        }

        $phar = new PharData( $tar_path );
        $phar->compress( Phar::GZ );
        unset( $phar );

        // Clean up uncompressed tar.
        @unlink( $tar_path );

        if ( ! file_exists( $archive_path ) ) {
            return $this->fail_state( $state, 'Compression failed — .tar.gz not created.' );
        }

        $size = filesize( $archive_path );
        $state = $this->log_state( $state, 'Archive compressed (' . size_format( $size ) . ').' );
        $state['archive_size'] = $size;
        $state['step'] = 'upload';

        return $state;
    }

    private function step_upload( $state ) {
        $state = $this->log_state( $state, 'Uploading to S3.' );

        $s3 = $this->get_s3();
        if ( is_wp_error( $s3 ) ) {
            return $this->fail_state( $state, $s3->get_error_message() );
        }

        $upload = $s3->stream_upload( $state['s3_key'], $state['archive_path'], self::RETENTION_DAYS );
        if ( is_wp_error( $upload ) ) {
            return $this->fail_state( $state, $upload->get_error_message() );
        }

        $state = $this->log_state( $state, 'Upload complete: ' . $state['s3_key'] );

        // Upload README if needed.
        $prefix = trim( get_option( 'yawp_s3_prefix', '' ), '/' );
        $this->maybe_upload_readme( $s3, $prefix );

        $state['step'] = 'finish';
        return $state;
    }

    private function step_finish( $state ) {
        $s3 = $this->get_s3();

        // Update manifest.
        if ( ! is_wp_error( $s3 ) ) {
            $prefix = trim( get_option( 'yawp_s3_prefix', '' ), '/' );
            $manifest_key = ( $prefix ? $prefix . '/' : '' ) . 'manifest.json';
            $manifest = [
                'last_backup'  => $state['timestamp'],
                'last_type'    => $state['type'],
                'last_s3_key'  => $state['s3_key'],
                'last_size'    => $state['archive_size'] ?? 0,
            ];
            $s3->put_json( $manifest_key, wp_json_encode( $manifest ) );
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
            'size'   => $state['archive_size'] ?? 0,
            's3_key' => $state['s3_key'],
            'status' => 'success',
        ]);

        delete_option( 'yawp_last_error' );

        $state = $this->log_state( $state, ucfirst( $state['type'] ) . ' backup completed successfully.' );
        $this->healthcheck( 'success', $state['log'] );

        // Clean up.
        self::cleanup_dir( $state['tmp_dir'] );
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
        $state['log'] .= '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . "\n";
        return $state;
    }

    private function fail_state( $state, $message ) {
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

        // Clean up temp files.
        if ( ! empty( $state['tmp_dir'] ) ) {
            self::cleanup_dir( $state['tmp_dir'] );
        }
        delete_transient( self::LOCK_KEY );

        return $state;
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

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

    private static function cleanup_dir( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                @rmdir( $file->getRealPath() );
            } else {
                @unlink( $file->getRealPath() );
            }
        }
        @rmdir( $dir );
    }

    private function cleanup_stale_tmp_dirs() {
        $search = [ '/tmp/yawp-backup-*' ];
        $tmp_base = $this->get_tmp_base();
        if ( '/tmp' !== $tmp_base ) {
            $search[] = $tmp_base . '/yawp-backup-*';
        }
        $cutoff = time() - self::LOCK_TTL;
        foreach ( $search as $pattern ) {
            $dirs = glob( $pattern );
            if ( ! $dirs ) {
                continue;
            }
            foreach ( $dirs as $dir ) {
                if ( is_dir( $dir ) && @filemtime( $dir ) < $cutoff ) {
                    self::cleanup_dir( $dir );
                }
            }
        }
    }

    private function get_tmp_base() {
        if ( defined( 'WP_CONTENT_DIR' ) ) {
            $dir = WP_CONTENT_DIR . '/yawp-tmp';
            if ( ! is_dir( $dir ) ) {
                @mkdir( $dir, 0700 );
                @file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
                @file_put_contents( $dir . '/index.php', "<?php // silence\n" );
            }
            if ( is_dir( $dir ) && is_writable( $dir ) ) {
                return $dir;
            }
        }
        return '/tmp';
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

Structure
---------
{$pfx}/full/YYYY-MM-DD_HHMMSS.tar.gz         Full site backup
{$pfx}/incremental/YYYY-MM-DD_HHMMSS.tar.gz   Incremental (changed files + full DB)
{$pfx}/incremental/YYYY-MM-DD_HHMMSS.skipped  Marker — scheduled job ran, no changes detected
{$pfx}/manifest.json                           Last backup metadata
{$pfx}/README.txt                              This file

Each .tar.gz archive contains:
- database.sql        Full database dump
- ./                  WordPress webroot files (relative paths)

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
