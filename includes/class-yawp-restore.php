<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YAWP_Restore {

    private $s3;
    private $prefix;

    public function __construct( YAWP_S3 $s3 ) {
        $this->s3     = $s3;
        $this->prefix = get_option( 'yawp_s3_prefix', '' );
    }

    // ──────────────────────────────────────────────
    // List available backups
    // ──────────────────────────────────────────────

    /**
     * List v2 chunked backups from S3, sorted newest-first.
     *
     * @return array|WP_Error
     */
    public function list_backups() {
        $prefix = $this->prefix;
        if ( '' !== $prefix ) {
            $prefix = rtrim( $prefix, '/' ) . '/';
        }

        $objects = $this->s3->list_objects( $prefix );
        if ( is_wp_error( $objects ) ) {
            return $objects;
        }

        $backups = [];

        // Find per-backup manifest.json files (not the top-level one).
        foreach ( $objects as $obj ) {
            $key = $obj['Key'];
            if ( '/manifest.json' !== substr( $key, -14 ) ) {
                continue;
            }
            // Skip the top-level manifest.
            if ( $key === $prefix . 'manifest.json' ) {
                continue;
            }

            $dir = dirname( $key );

            $type = 'unknown';
            if ( false !== strpos( $dir, '/full/' ) ) {
                $type = 'full';
            } elseif ( false !== strpos( $dir, '/incremental/' ) ) {
                $type = 'incremental';
            }

            $basename = basename( $dir );
            $date     = '';
            if ( preg_match( '/(\d{4}-\d{2}-\d{2}_\d{6})/', $basename, $m ) ) {
                $date = str_replace( '_', ' ', $m[1] );
                $date = substr( $date, 0, 13 ) . ':' . substr( $date, 13, 2 ) . ':' . substr( $date, 15, 2 );
            }

            // Sum sizes of all objects in this directory.
            $total_size = 0;
            foreach ( $objects as $o ) {
                if ( 0 === strpos( $o['Key'], $dir . '/' ) ) {
                    $total_size += $o['Size'];
                }
            }

            $backups[] = [
                's3_key'        => $key,
                'size'          => $total_size,
                'last_modified' => $obj['LastModified'],
                'type'          => $type,
                'date'          => $date,
            ];
        }

        // Sort newest-first.
        usort( $backups, function ( $a, $b ) {
            return strcmp( $b['date'], $a['date'] );
        } );

        return $backups;
    }

    // ──────────────────────────────────────────────
    // Restore
    // ──────────────────────────────────────────────

    /**
     * Restore a v2 chunked backup from S3.
     *
     * @param string $s3_key  Full S3 key of the manifest.json.
     * @param string $new_url Optional new site URL.
     * @return true|WP_Error
     */
    public function restore( $s3_key, $new_url = '' ) {
        global $wpdb;

        if ( false !== get_transient( 'yawp_restore_running' ) ) {
            return new WP_Error( 'yawp_restore', 'A restore is already in progress.' );
        }
        if ( false !== get_transient( 'yawp_backup_running' ) ) {
            return new WP_Error( 'yawp_restore', 'A backup is currently running. Please wait for it to finish.' );
        }
        set_transient( 'yawp_restore_running', time(), 1800 );

        @set_time_limit( 1800 );

        $tmp_dir = sys_get_temp_dir() . '/yawp-restore-' . time() . '/';

        try {
            if ( ! mkdir( $tmp_dir, 0755, true ) ) {
                return new WP_Error( 'yawp_restore', 'Cannot create temp directory.' );
            }

            // Download and parse manifest.
            $manifest_path = $tmp_dir . 'manifest.json';
            $result = $this->s3->get_object( $s3_key, $manifest_path );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $manifest = json_decode( file_get_contents( $manifest_path ), true );
            if ( ! is_array( $manifest ) || empty( $manifest['parts'] ) || empty( $manifest['database'] ) ) {
                return new WP_Error( 'yawp_restore', 'Invalid backup manifest.' );
            }

            $webroot      = rtrim( ABSPATH, '/' );
            $tar_excludes = "--overwrite --exclude=wp-config.php --exclude=wp-content/plugins/yawp --exclude=wp-content/object-cache.php --exclude='.claude' --exclude='.git'";

            // If incremental, build the full chain (full + incrementals).
            if ( 'incremental' === ( $manifest['type'] ?? '' ) ) {
                $chain = $this->build_restore_chain( $s3_key );
                if ( is_wp_error( $chain ) ) {
                    return $chain;
                }
            } else {
                $chain = [ $manifest ];
            }

            // Extract file parts from each backup in the chain.
            foreach ( $chain as $chain_manifest ) {
                foreach ( $chain_manifest['parts'] as $part_key ) {
                    $part_path = $tmp_dir . 'part.tar.gz';
                    $result = $this->s3->get_object( $part_key, $part_path );
                    if ( is_wp_error( $result ) ) {
                        return $result;
                    }

                    $output = [];
                    $cmd = sprintf(
                        'tar xzf %s -C %s %s 2>&1',
                        escapeshellarg( $part_path ),
                        escapeshellarg( $webroot ),
                        $tar_excludes
                    );
                    exec( $cmd, $output, $exit_code );
                    if ( $exit_code >= 2 ) {
                        return new WP_Error( 'yawp_restore', 'Failed to extract ' . basename( $part_key ) . ': ' . implode( "\n", $output ) );
                    }
                    @unlink( $part_path );
                }
            }

            // Download and import database (always from the selected backup).
            $db_path = $tmp_dir . 'database.sql';
            $result  = $this->s3->get_object( $manifest['database'], $db_path );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            // Save YAWP settings.
            $saved_options = [];
            $yawp_options  = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'yawp_%'",
                ARRAY_A
            );
            foreach ( $yawp_options as $row ) {
                $saved_options[ $row['option_name'] ] = $row['option_value'];
            }

            $import_result = $this->import_sql( $db_path );
            if ( is_wp_error( $import_result ) ) {
                return $import_result;
            }

            $this->fix_placeholder_hashes();

            foreach ( $saved_options as $name => $value ) {
                update_option( $name, $value, false );
            }

            if ( '' !== $new_url ) {
                $old_url = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'" );
                if ( $old_url && $old_url !== $new_url ) {
                    $rewrite_result = $this->rewrite_urls( $old_url, $new_url );
                    if ( is_wp_error( $rewrite_result ) ) {
                        return $rewrite_result;
                    }
                }
            }

            delete_option( 'rewrite_rules' );
            return true;

        } finally {
            if ( is_dir( $tmp_dir ) ) {
                exec( 'rm -rf ' . escapeshellarg( $tmp_dir ) );
            }
            delete_transient( 'yawp_restore_running' );
        }
    }

    /**
     * Build ordered restore chain for incremental backups.
     */
    private function build_restore_chain( $manifest_key ) {
        $backups = $this->list_backups();
        if ( is_wp_error( $backups ) ) {
            return $backups;
        }

        $selected_date = '';
        foreach ( $backups as $b ) {
            if ( $b['s3_key'] === $manifest_key ) {
                $selected_date = $b['date'];
                break;
            }
        }

        if ( '' === $selected_date ) {
            return new WP_Error( 'yawp_restore', 'Selected backup not found.' );
        }

        usort( $backups, function ( $a, $b ) {
            return strcmp( $a['date'], $b['date'] );
        } );

        // Find most recent full before selected.
        $full_backup = null;
        foreach ( $backups as $b ) {
            if ( 'full' === $b['type'] && $b['date'] <= $selected_date ) {
                $full_backup = $b;
            }
        }

        if ( ! $full_backup ) {
            return new WP_Error( 'yawp_restore', 'No full backup found before this incremental.' );
        }

        $chain = [];
        $chain[] = $this->download_manifest( $full_backup['s3_key'] );

        foreach ( $backups as $b ) {
            if ( 'incremental' === $b['type'] &&
                 $b['date'] > $full_backup['date'] && $b['date'] <= $selected_date ) {
                $chain[] = $this->download_manifest( $b['s3_key'] );
            }
        }

        foreach ( $chain as $item ) {
            if ( is_wp_error( $item ) ) {
                return $item;
            }
        }

        return $chain;
    }

    private function download_manifest( $manifest_key ) {
        $tmp = tempnam( sys_get_temp_dir(), 'yawp-manifest-' );
        $result = $this->s3->get_object( $manifest_key, $tmp );
        if ( is_wp_error( $result ) ) {
            @unlink( $tmp );
            return $result;
        }
        $manifest = json_decode( file_get_contents( $tmp ), true );
        @unlink( $tmp );
        if ( ! is_array( $manifest ) ) {
            return new WP_Error( 'yawp_restore', 'Invalid manifest: ' . $manifest_key );
        }
        return $manifest;
    }

    // ──────────────────────────────────────────────
    // SQL import
    // ──────────────────────────────────────────────

    private function import_sql( $file_path ) {
        $dbh = $this->get_raw_dbh();
        if ( ! $dbh ) {
            return new WP_Error( 'yawp_restore', 'Could not open database connection for SQL import.' );
        }

        $fh = fopen( $file_path, 'r' );
        if ( ! $fh ) {
            mysqli_close( $dbh );
            return new WP_Error( 'yawp_restore', 'Cannot open SQL file.' );
        }

        $buffer = '';
        mysqli_query( $dbh, 'SET foreign_key_checks = 0' );

        while ( false !== ( $line = fgets( $fh ) ) ) {
            $trimmed = trim( $line );
            if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) ) {
                continue;
            }
            $buffer .= $line;
            if ( ';' === substr( $trimmed, -1 ) ) {
                mysqli_query( $dbh, $buffer );
                $buffer = '';
            }
        }

        if ( '' !== trim( $buffer ) ) {
            mysqli_query( $dbh, $buffer );
        }

        mysqli_query( $dbh, 'SET foreign_key_checks = 1' );
        fclose( $fh );
        mysqli_close( $dbh );
        return true;
    }

    // ──────────────────────────────────────────────
    // URL rewrite
    // ──────────────────────────────────────────────

    public function rewrite_urls( $old_url, $new_url ) {
        global $wpdb;

        $old_url = rtrim( $old_url, '/' );
        $new_url = rtrim( $new_url, '/' );

        if ( $old_url === $new_url ) {
            return true;
        }

        $pairs = [ [ $old_url, $new_url ] ];

        if ( 0 === strpos( $old_url, 'https://' ) ) {
            $pairs[] = [ 'http://' . substr( $old_url, 8 ), 'http://' . substr( $new_url, 8 ) ];
        } elseif ( 0 === strpos( $old_url, 'http://' ) ) {
            $pairs[] = [ 'https://' . substr( $old_url, 7 ), 'https://' . substr( $new_url, 7 ) ];
        }

        $old_schemeless = preg_replace( '#^https?:#', '', $old_url );
        $new_schemeless = preg_replace( '#^https?:#', '', $new_url );
        if ( $old_schemeless !== $new_schemeless ) {
            $pairs[] = [ $old_schemeless, $new_schemeless ];
        }

        $dbh = $this->get_raw_dbh();
        if ( ! $dbh ) {
            return new WP_Error( 'yawp_restore', 'Could not open database connection for URL rewrite.' );
        }

        $tables = $wpdb->get_col( 'SHOW TABLES' );

        foreach ( $tables as $table ) {
            $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
            $text_cols = [];
            foreach ( $columns as $col ) {
                if ( preg_match( '/varchar|text|longtext|mediumtext/i', $col['Type'] ) ) {
                    $text_cols[] = $col['Field'];
                }
            }

            if ( empty( $text_cols ) ) {
                continue;
            }

            foreach ( $pairs as $pair ) {
                $old_esc = mysqli_real_escape_string( $dbh, $pair[0] );
                $new_esc = mysqli_real_escape_string( $dbh, $pair[1] );

                foreach ( $text_cols as $col ) {
                    mysqli_query( $dbh,
                        "UPDATE `{$table}` SET `{$col}` = REPLACE(`{$col}`, '{$old_esc}', '{$new_esc}') WHERE `{$col}` LIKE '%{$old_esc}%'"
                    );
                }
            }
        }

        mysqli_close( $dbh );
        return true;
    }

    private function fix_placeholder_hashes() {
        $dbh = $this->get_raw_dbh();
        if ( ! $dbh ) {
            return;
        }

        $tables_result = mysqli_query( $dbh, 'SHOW TABLES' );
        while ( $row = mysqli_fetch_row( $tables_result ) ) {
            $table = $row[0];

            $cols_result = mysqli_query( $dbh, "SHOW COLUMNS FROM `{$table}`" );
            while ( $col = mysqli_fetch_assoc( $cols_result ) ) {
                if ( ! preg_match( '/varchar|text|longtext|mediumtext/i', $col['Type'] ) ) {
                    continue;
                }
                $field = $col['Field'];

                $search = mysqli_query( $dbh,
                    "SELECT DISTINCT SUBSTRING(`{$field}`, LOCATE('{', `{$field}`), 66) AS hash_str " .
                    "FROM `{$table}` WHERE `{$field}` REGEXP '\\\\{[0-9a-f]{64}\\\\}' LIMIT 1"
                );

                if ( $search && $found = mysqli_fetch_assoc( $search ) ) {
                    $hash = mysqli_real_escape_string( $dbh, $found['hash_str'] );
                    mysqli_query( $dbh,
                        "UPDATE `{$table}` SET `{$field}` = REPLACE(`{$field}`, '{$hash}', '%') " .
                        "WHERE `{$field}` LIKE '%{$hash}%'"
                    );
                }
            }
        }

        mysqli_close( $dbh );
    }

    private function get_raw_dbh() {
        $host = DB_HOST;
        $port = 3306;

        if ( strpos( $host, ':' ) !== false ) {
            list( $host, $port ) = explode( ':', $host, 2 );
            $port = (int) $port;
        }

        $dbh = mysqli_init();

        if ( defined( 'MYSQL_CLIENT_FLAGS' ) && ( MYSQL_CLIENT_FLAGS & MYSQLI_CLIENT_SSL ) ) {
            mysqli_ssl_set( $dbh, null, null, null, null, null );
        }

        if ( ! mysqli_real_connect( $dbh, $host, DB_USER, DB_PASSWORD, DB_NAME, $port, null, defined( 'MYSQL_CLIENT_FLAGS' ) ? MYSQL_CLIENT_FLAGS : 0 ) ) {
            return false;
        }

        mysqli_set_charset( $dbh, DB_CHARSET );
        return $dbh;
    }
}
