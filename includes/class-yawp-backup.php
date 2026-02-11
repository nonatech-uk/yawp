<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YAWP_Backup {

    const LOCK_KEY  = 'yawp_backup_running';
    const LOCK_TTL  = 900; // 15 minutes
    const DISK_REQ  = 419430400; // ~400 MB

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

    private function run( $type ) {
        if ( get_transient( self::LOCK_KEY ) ) {
            return new WP_Error( 'yawp_backup', 'A backup is already running.' );
        }
        set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 600 );
        }

        $timestamp = gmdate( 'Y-m-d_His' );
        $tmp_dir   = '/tmp/yawp-backup-' . $timestamp;

        try {
            // Disk space check.
            $free = @disk_free_space( '/tmp' );
            if ( false !== $free && $free < self::DISK_REQ ) {
                return $this->fail( 'Insufficient disk space. Need ~400 MB, have ' . size_format( $free ) . '.' );
            }

            if ( ! mkdir( $tmp_dir, 0700 ) ) {
                return $this->fail( 'Cannot create temp directory.' );
            }

            // 1. Export database.
            $db_file = $tmp_dir . '/database.sql';
            $exporter = new YAWP_DB_Export();
            $result   = $exporter->export( $db_file );
            if ( is_wp_error( $result ) ) {
                return $this->fail( $result->get_error_message() );
            }

            // 2. Build tar.
            $archive = $tmp_dir . '/backup.tar.gz';
            $tar_cmd = $this->build_tar_command( $type, $archive, $tmp_dir );
            $output  = [];
            $retval  = 0;
            exec( $tar_cmd . ' 2>&1', $output, $retval );
            if ( $retval !== 0 ) {
                return $this->fail( 'tar failed (exit ' . $retval . '): ' . implode( "\n", $output ) );
            }

            if ( ! file_exists( $archive ) ) {
                return $this->fail( 'Archive was not created.' );
            }

            // 3. Upload to S3.
            $s3  = $this->get_s3();
            if ( is_wp_error( $s3 ) ) {
                return $this->fail( $s3->get_error_message() );
            }

            $prefix = trim( get_option( 'yawp_s3_prefix', '' ), '/' );
            $s3_key = ( $prefix ? $prefix . '/' : '' ) . $type . '/' . $timestamp . '.tar.gz';
            $retention = (int) get_option( 'yawp_retention_days', 90 );

            $upload = $s3->upload( $s3_key, $archive, $retention );
            if ( is_wp_error( $upload ) ) {
                return $this->fail( $upload->get_error_message() );
            }

            $file_size = filesize( $archive );

            // 4. Update manifest on S3.
            $manifest_key = ( $prefix ? $prefix . '/' : '' ) . 'manifest.json';
            $manifest = [
                'last_backup'  => $timestamp,
                'last_type'    => $type,
                'last_s3_key'  => $s3_key,
                'last_size'    => $file_size,
            ];
            $s3->put_json( $manifest_key, wp_json_encode( $manifest ) );

            // 5. Record in wp_options.
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

            return true;

        } catch ( \Throwable $e ) {
            return $this->fail( 'Exception: ' . $e->getMessage() );
        } finally {
            $this->cleanup( $tmp_dir );
            delete_transient( self::LOCK_KEY );
        }
    }

    private function build_tar_command( $type, $archive, $tmp_dir ) {
        $webroot = '/var/www/html';
        $excludes = '--exclude=wp-content/cache --exclude=wp-content/plugins/yawp';

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
}
