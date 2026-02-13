<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YAWP_Backup {

    const LOCK_KEY       = 'yawp_backup_running';
    const LOCK_TTL       = 900; // 15 minutes
    const DISK_REQ       = 419430400; // ~400 MB
    const RETENTION_DAYS = 90;

    private $log = '';

    /**
     * Run a full backup.
     */
    public function run_full() {
        return $this->run( 'full' );
    }

    /**
     * Run an incremental backup (only files changed since last backup).
     */
    public function run_incremental() {
        return $this->run( 'incremental' );
    }

    /**
     * Public S3 client getter — used by the scheduler for marker files.
     */
    public function get_s3_public() {
        return $this->get_s3();
    }

    private function log( $message ) {
        $this->log .= '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . "\n";
    }

    private function run( $type ) {
        $this->log = '';
        $this->log( 'Starting ' . $type . ' backup.' );

        if ( get_transient( self::LOCK_KEY ) ) {
            return new WP_Error( 'yawp_backup', 'A backup is already running.' );
        }
        set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

        // Clean up any stale backup temp dirs from previous crashed runs.
        $this->cleanup_stale_tmp_dirs();

        $this->healthcheck( 'start' );

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 );
        }

        $timestamp = gmdate( 'Y-m-d_His' );
        $tmp_base  = $this->get_tmp_base();
        $tmp_dir   = $tmp_base . '/yawp-backup-' . $timestamp;

        // Register a shutdown function so temp files are cleaned even on fatal/OOM.
        register_shutdown_function( [ $this, 'emergency_cleanup' ], $tmp_dir );

        try {
            // Disk space check.
            $free = @disk_free_space( $tmp_base );
            if ( false !== $free && $free < self::DISK_REQ ) {
                return $this->fail( 'Insufficient disk space. Need ~400 MB, have ' . size_format( $free ) . '.' );
            }

            if ( ! mkdir( $tmp_dir, 0700 ) ) {
                return $this->fail( 'Cannot create temp directory.' );
            }

            // 1. Export database.
            $this->log( 'Exporting database.' );
            $db_file = $tmp_dir . '/database.sql';
            $exporter = new YAWP_DB_Export();
            $result   = $exporter->export( $db_file );
            if ( is_wp_error( $result ) ) {
                return $this->fail( $result->get_error_message() );
            }
            $this->log( 'Database exported (' . size_format( filesize( $db_file ) ) . ').' );

            // 2. Build tar.
            $this->log( 'Building tar archive.' );
            $archive = $tmp_dir . '/backup.tar.gz';
            $tar_cmd = $this->build_tar_command( $type, $archive, $tmp_dir );
            $output  = [];
            $retval  = 0;
            exec( $tar_cmd . ' 2>&1', $output, $retval );
            // Exit 1 = "file changed as we read it" — normal on a live site.
            if ( $retval > 1 ) {
                return $this->fail( 'tar failed (exit ' . $retval . '): ' . implode( "\n", $output ) );
            }

            if ( ! file_exists( $archive ) ) {
                return $this->fail( 'Archive was not created.' );
            }
            $this->log( 'Archive created (' . size_format( filesize( $archive ) ) . ').' );

            // 3. Upload to S3.
            $this->log( 'Uploading to S3.' );
            $s3  = $this->get_s3();
            if ( is_wp_error( $s3 ) ) {
                return $this->fail( $s3->get_error_message() );
            }

            $prefix = trim( get_option( 'yawp_s3_prefix', '' ), '/' );
            $s3_key = ( $prefix ? $prefix . '/' : '' ) . $type . '/' . $timestamp . '.tar.gz';

            $upload = $s3->upload( $s3_key, $archive, self::RETENTION_DAYS );
            if ( is_wp_error( $upload ) ) {
                return $this->fail( $upload->get_error_message() );
            }
            $this->log( 'Upload complete: ' . $s3_key );

            $file_size = filesize( $archive );

            // 4. Upload README if it doesn't exist yet.
            $this->maybe_upload_readme( $s3, $prefix );

            // 5. Update manifest on S3.
            $this->log( 'Updating manifest.' );
            $manifest_key = ( $prefix ? $prefix . '/' : '' ) . 'manifest.json';
            $manifest = [
                'last_backup'  => $timestamp,
                'last_type'    => $type,
                'last_s3_key'  => $s3_key,
                'last_size'    => $file_size,
            ];
            $s3->put_json( $manifest_key, wp_json_encode( $manifest ) );

            // 6. Record in wp_options.
            $now = current_time( 'mysql', true );
            update_option( 'yawp_last_backup_time', $now, false );
            if ( 'full' === $type ) {
                update_option( 'yawp_last_full_backup_time', $now, false );
            }

            $this->add_history_entry( [
                'date'   => $now,
                'type'   => $type,
                'size'   => $file_size,
                's3_key' => $s3_key,
                'status' => 'success',
            ]);

            delete_option( 'yawp_last_error' );

            $this->log( ucfirst( $type ) . ' backup completed successfully.' );
            $this->healthcheck( 'success', $this->log );

            return true;

        } catch ( \Throwable $e ) {
            return $this->fail( 'Exception: ' . $e->getMessage() );
        } finally {
            $this->cleanup( $tmp_dir );
            delete_transient( self::LOCK_KEY );
        }
    }

    private function maybe_upload_readme( $s3, $prefix ) {
        $readme_key = ( $prefix ? $prefix . '/' : '' ) . 'README.txt';

        // Check if README already exists by listing with exact prefix.
        $objects = $s3->list_objects( $readme_key );
        if ( ! is_wp_error( $objects ) ) {
            foreach ( $objects as $obj ) {
                if ( $obj['Key'] === $readme_key ) {
                    return; // Already exists.
                }
            }
        }

        $this->log( 'Uploading README.txt.' );

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

S3 Configuration
----------------
The IAM user needs the following permissions on this bucket:

  s3:PutObject
  s3:GetObject
  s3:ListBucket
  s3:AbortMultipartUpload
  s3:ListMultipartUploadParts

Bucket settings:
- Object Lock: Enabled (COMPLIANCE mode, 90-day retention)
- Versioning: Enabled (required by Object Lock)

Example bucket name:  my-site-backups-123456789012
Example prefix:       backups

The prefix determines the folder within the bucket. A typical setup:
  Bucket: my-company-wordpress-backups-123456789012
  Prefix: my-site-name

This gives S3 paths like:
  my-site-name/full/2026-02-11_030000.tar.gz
  my-site-name/incremental/2026-02-12_030000.tar.gz

Scheduling
----------
YAWP runs a daily check (default 03:00 UTC). The logic:

1. No full backup exists    → run full backup
2. Full backup interval elapsed → run full backup
3. Someone logged in today  → run incremental backup
4. No login detected        → skip (write .skipped marker)

IMPORTANT: Incremental backups ONLY run when a WordPress user has
logged in since the last backup. If nobody logs in, no incremental
is created — only a .skipped marker file to confirm the job ran.

Objects in this bucket are protected by S3 Object Lock (COMPLIANCE
mode) and cannot be deleted or modified during the retention period.
TXT;

        $s3->put_text( $readme_key, $readme );
    }

    private function build_tar_command( $type, $archive, $tmp_dir ) {
        $webroot = rtrim( get_option( 'yawp_webroot', '/var/www/html' ), '/' );
        $excludes = '--exclude=wp-content/cache --exclude=wp-content/plugins/yawp --exclude=wp-content/yawp-tmp --exclude=.git --exclude=.claude';

        if ( 'incremental' === $type ) {
            $last = get_option( 'yawp_last_backup_time', '' );
            $newer = $last ? '--newer="' . $last . '"' : '';
            return sprintf(
                'tar czf %s -C %s database.sql -C %s %s %s .',
                escapeshellarg( $archive ),
                escapeshellarg( $tmp_dir ),
                escapeshellarg( $webroot ),
                $excludes,
                $newer
            );
        }

        return sprintf(
            'tar czf %s -C %s database.sql -C %s %s .',
            escapeshellarg( $archive ),
            escapeshellarg( $tmp_dir ),
            escapeshellarg( $webroot ),
            $excludes
        );
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

    private function fail( $message ) {
        $this->log( 'FAILED: ' . $message );
        $this->healthcheck( 'fail', $this->log );
        update_option( 'yawp_last_error', $message, false );
        $this->add_history_entry( [
            'date'   => current_time( 'mysql', true ),
            'type'   => 'error',
            'size'   => 0,
            's3_key' => '',
            'status' => $message,
        ]);
        delete_transient( self::LOCK_KEY );
        return new WP_Error( 'yawp_backup', $message );
    }

    public function healthcheck( $event, $log = '' ) {
        $base = rtrim( get_option( 'yawp_healthchecks_url', '' ), '/' );
        if ( empty( $base ) ) {
            return;
        }

        // Healthchecks.io convention: /start, /fail, or bare URL for success.
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

    private function cleanup( $dir ) {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $files as $file ) {
            if ( $file->isDir() ) {
                rmdir( $file->getRealPath() );
            } else {
                unlink( $file->getRealPath() );
            }
        }
        rmdir( $dir );
    }

    /**
     * Emergency cleanup registered as a shutdown function.
     * Runs even on fatal errors/OOM to prevent leftover temp files
     * from exhausting shared hosting tmpfs and crashing the site.
     */
    public function emergency_cleanup( $dir ) {
        if ( is_dir( $dir ) ) {
            $this->cleanup( $dir );
        }
    }

    /**
     * Remove any stale yawp-backup-* directories older than the lock TTL.
     * Checks both /tmp and the disk-backed temp base.
     */
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
                    $this->cleanup( $dir );
                }
            }
        }
    }

    /**
     * Return a disk-backed temp directory for backup staging.
     * Avoids /tmp which may be RAM-backed (tmpfs) on shared hosting,
     * where large archives can exhaust the cgroup memory limit.
     */
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
}
