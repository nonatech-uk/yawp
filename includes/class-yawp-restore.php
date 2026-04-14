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
            if ( false !== strpos( $dir, '/full/' ) || 0 === strpos( $dir, 'full/' ) || 'full' === $dir ) {
                $type = 'full';
            } elseif ( false !== strpos( $dir, '/incremental/' ) || 0 === strpos( $dir, 'incremental/' ) || 'incremental' === $dir ) {
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
        delete_option( 'yawp_restore_progress' );

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
            $tar_excludes = "--overwrite --exclude=wp-config.php --exclude=.htaccess --exclude=wp-content/plugins/yawp --exclude=wp-content/object-cache.php --exclude='.claude' --exclude='.git'";

            // If incremental, build the full chain (full + incrementals).
            if ( 'incremental' === ( $manifest['type'] ?? '' ) ) {
                $chain = $this->build_restore_chain( $s3_key );
                if ( is_wp_error( $chain ) ) {
                    return $chain;
                }
            } else {
                $chain = [ $manifest ];
            }

            // Count total parts across the chain for progress.
            $total_parts = 0;
            foreach ( $chain as $chain_manifest ) {
                $total_parts += count( $chain_manifest['parts'] );
            }
            $current_part = 0;

            // Extract file parts from each backup in the chain.
            foreach ( $chain as $chain_manifest ) {
                foreach ( $chain_manifest['parts'] as $part_key ) {
                    $current_part++;
                    update_option( 'yawp_restore_progress', wp_json_encode( [
                        'step'    => 'files',
                        'current' => $current_part,
                        'total'   => $total_parts,
                        'part'    => basename( $part_key ),
                    ] ), false );

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
            update_option( 'yawp_restore_progress', wp_json_encode( [
                'step'    => 'database',
                'current' => $total_parts,
                'total'   => $total_parts,
            ] ), false );

            $db_s3_key  = $manifest['database'];
            $is_gz      = ( '.gz' === substr( $db_s3_key, -3 ) );
            $db_dl_path = $tmp_dir . ( $is_gz ? 'database.sql.gz' : 'database.sql' );
            $db_path    = $tmp_dir . 'database.sql';

            $result = $this->s3->get_object( $db_s3_key, $db_dl_path );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            // Decompress if gzipped.
            if ( $is_gz ) {
                $gz_in  = gzopen( $db_dl_path, 'rb' );
                $sql_out = fopen( $db_path, 'wb' );
                if ( ! $gz_in || ! $sql_out ) {
                    if ( $gz_in )   gzclose( $gz_in );
                    if ( $sql_out ) fclose( $sql_out );
                    return new WP_Error( 'yawp_restore', 'Failed to decompress database dump.' );
                }
                while ( ! gzeof( $gz_in ) ) {
                    fwrite( $sql_out, gzread( $gz_in, 131072 ) );
                }
                gzclose( $gz_in );
                fclose( $sql_out );
                @unlink( $db_dl_path );
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

            // Rewrite table prefix if source and target differ.
            $prefix_result = $this->rewrite_table_prefix();
            if ( is_wp_error( $prefix_result ) ) {
                return $prefix_result;
            }

            $this->fix_placeholder_hashes();

            foreach ( $saved_options as $name => $value ) {
                update_option( $name, $value, false );
            }

            if ( '' !== $new_url ) {
                update_option( 'yawp_restore_progress', wp_json_encode( [
                    'step'    => 'rewrite',
                    'current' => $total_parts,
                    'total'   => $total_parts,
                ] ), false );

                $old_url = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'" );
                if ( $old_url && $old_url !== $new_url ) {
                    $rewrite_result = $this->rewrite_urls( $old_url, $new_url );
                    if ( is_wp_error( $rewrite_result ) ) {
                        return $rewrite_result;
                    }

                    // Rewrite URLs in static files (Elementor Google Fonts CSS, etc.).
                    $this->rewrite_file_urls( $webroot, $old_url, $new_url );
                }
            }

            delete_option( 'rewrite_rules' );

            // Clear Elementor CSS cache (DB + files) so it regenerates from fresh DB data.
            $this->clear_elementor_cache( $webroot );

            delete_option( 'yawp_restore_progress' );
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
        mysqli_query( $dbh, 'SET max_allowed_packet = 67108864' ); // 64 MB
        $errors = [];

        while ( false !== ( $line = fgets( $fh ) ) ) {
            $trimmed = trim( $line );
            if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) ) {
                continue;
            }
            $buffer .= $line;
            if ( ';' === substr( $trimmed, -1 ) ) {
                $ok = mysqli_query( $dbh, $buffer );
                if ( ! $ok ) {
                    $err = mysqli_error( $dbh );
                    $snippet = substr( $buffer, 0, 120 );
                    $errors[] = $err . ' | ' . $snippet;
                }
                $buffer = '';
            }
        }

        if ( '' !== trim( $buffer ) ) {
            $ok = mysqli_query( $dbh, $buffer );
            if ( ! $ok ) {
                $errors[] = mysqli_error( $dbh );
            }
        }

        mysqli_query( $dbh, 'SET foreign_key_checks = 1' );
        fclose( $fh );
        mysqli_close( $dbh );

        if ( ! empty( $errors ) ) {
            yawp_log( 'warning', 'Restore SQL errors', [ 'count' => count( $errors ), 'sample' => $errors[0] ?? '' ] );
        }

        return true;
    }

    // ──────────────────────────────────────────────
    // Table prefix rewrite
    // ──────────────────────────────────────────────

    /**
     * Detect the source table prefix from imported tables and rename
     * to match the local $table_prefix if they differ.
     */
    private function rewrite_table_prefix() {
        global $wpdb, $table_prefix;

        $dbh = $this->get_raw_dbh();
        if ( ! $dbh ) {
            return new WP_Error( 'yawp_restore', 'Cannot connect to database for prefix rewrite.' );
        }

        // Find the source prefix by looking for a known WP table.
        $result = mysqli_query( $dbh, 'SHOW TABLES' );
        if ( ! $result ) {
            mysqli_close( $dbh );
            return true; // Nothing to do.
        }

        $source_prefix = '';
        $all_tables    = [];
        while ( $row = mysqli_fetch_row( $result ) ) {
            $all_tables[] = $row[0];
            // Detect source prefix from the options table.
            if ( '' === $source_prefix && preg_match( '/^(.+)options$/', $row[0], $m ) ) {
                $source_prefix = $m[1];
            }
        }

        if ( '' === $source_prefix || $source_prefix === $table_prefix ) {
            mysqli_close( $dbh );
            return true; // Prefixes match, nothing to do.
        }

        yawp_log( 'info', 'Rewriting table prefix', [ 'from' => $source_prefix, 'to' => $table_prefix ] );

        // Rename all tables with the source prefix.
        $sp_len = strlen( $source_prefix );
        foreach ( $all_tables as $tbl ) {
            if ( 0 === strpos( $tbl, $source_prefix ) ) {
                $new_name = $table_prefix . substr( $tbl, $sp_len );
                if ( $tbl !== $new_name ) {
                    mysqli_query( $dbh, "DROP TABLE IF EXISTS `{$new_name}`" );
                    mysqli_query( $dbh, "RENAME TABLE `{$tbl}` TO `{$new_name}`" );
                }
            }
        }

        // Update option_name entries that embed the prefix (e.g. {prefix}user_roles).
        $options_table = $table_prefix . 'options';
        $sp_esc = mysqli_real_escape_string( $dbh, $source_prefix );
        $tp_esc = mysqli_real_escape_string( $dbh, $table_prefix );
        mysqli_query( $dbh,
            "UPDATE `{$options_table}` SET `option_name` = CONCAT('{$tp_esc}', SUBSTRING(`option_name`, " . ( $sp_len + 1 ) . ")) WHERE `option_name` LIKE '{$sp_esc}%' AND `option_name` NOT LIKE '{$tp_esc}%'"
        );

        // Update usermeta meta_key entries that embed the prefix (e.g. {prefix}capabilities).
        $usermeta_table = $table_prefix . 'usermeta';
        mysqli_query( $dbh,
            "UPDATE `{$usermeta_table}` SET `meta_key` = CONCAT('{$tp_esc}', SUBSTRING(`meta_key`, " . ( $sp_len + 1 ) . ")) WHERE `meta_key` LIKE '{$sp_esc}%' AND `meta_key` NOT LIKE '{$tp_esc}%'"
        );

        // Drop leftover source-prefixed tables (in case rename left duplicates).
        $result2 = mysqli_query( $dbh, 'SHOW TABLES' );
        if ( $result2 ) {
            while ( $row = mysqli_fetch_row( $result2 ) ) {
                if ( 0 === strpos( $row[0], $source_prefix ) && 0 !== strpos( $row[0], $table_prefix ) ) {
                    mysqli_query( $dbh, "DROP TABLE IF EXISTS `{$row[0]}`" );
                }
            }
        }

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
            $pk_cols   = [];
            foreach ( $columns as $col ) {
                if ( preg_match( '/varchar|text|longtext|mediumtext/i', $col['Type'] ) ) {
                    $text_cols[] = $col['Field'];
                }
                if ( 'PRI' === $col['Key'] ) {
                    $pk_cols[] = $col['Field'];
                }
            }

            if ( empty( $text_cols ) ) {
                continue;
            }

            // Build WHERE clause to find rows containing old URLs.
            foreach ( $pairs as $pair ) {
                $old_esc = mysqli_real_escape_string( $dbh, $pair[0] );

                foreach ( $text_cols as $col ) {
                    // Check if any rows contain the old URL in this column.
                    $check = mysqli_query( $dbh,
                        "SELECT COUNT(*) FROM `{$table}` WHERE `{$col}` LIKE '%{$old_esc}%' LIMIT 1"
                    );
                    if ( ! $check ) {
                        continue;
                    }
                    $count_row = mysqli_fetch_row( $check );
                    if ( ! $count_row || 0 === (int) $count_row[0] ) {
                        continue;
                    }

                    // If no primary key, fall back to simple SQL REPLACE.
                    if ( empty( $pk_cols ) ) {
                        $new_esc = mysqli_real_escape_string( $dbh, $pair[1] );
                        mysqli_query( $dbh,
                            "UPDATE `{$table}` SET `{$col}` = REPLACE(`{$col}`, '{$old_esc}', '{$new_esc}') WHERE `{$col}` LIKE '%{$old_esc}%'"
                        );
                        continue;
                    }

                    // Fetch rows with old URL and do serialization-aware replacement.
                    $pk_select = implode( ',', array_map( function( $c ) { return "`{$c}`"; }, $pk_cols ) );
                    $result = mysqli_query( $dbh,
                        "SELECT {$pk_select}, `{$col}` FROM `{$table}` WHERE `{$col}` LIKE '%{$old_esc}%'"
                    );
                    if ( ! $result ) {
                        continue;
                    }

                    while ( $row = mysqli_fetch_assoc( $result ) ) {
                        $value     = $row[ $col ];
                        $new_value = $this->serialized_replace( $pair[0], $pair[1], $value );

                        if ( $new_value === $value ) {
                            continue;
                        }

                        $new_esc = mysqli_real_escape_string( $dbh, $new_value );
                        $where_parts = [];
                        foreach ( $pk_cols as $pk ) {
                            $where_parts[] = "`{$pk}` = '" . mysqli_real_escape_string( $dbh, $row[ $pk ] ) . "'";
                        }
                        mysqli_query( $dbh,
                            "UPDATE `{$table}` SET `{$col}` = '{$new_esc}' WHERE " . implode( ' AND ', $where_parts ) . " LIMIT 1"
                        );
                    }
                    mysqli_free_result( $result );
                }
            }
        }

        mysqli_close( $dbh );
        return true;
    }

    /**
     * Search-and-replace that handles PHP serialized data.
     *
     * If the value is serialized, recursively walk the data structure,
     * replace strings, and re-serialize (fixing string length prefixes).
     * If not serialized, do a plain str_replace.
     */
    private function serialized_replace( $search, $replace, $value ) {
        // Try to unserialize.
        $unserialized = @unserialize( $value );
        if ( false !== $unserialized || 'b:0;' === $value ) {
            $replaced = $this->walk_replace( $search, $replace, $unserialized );
            return serialize( $replaced );
        }

        // Try JSON (Elementor uses JSON in postmeta too).
        $decoded = json_decode( $value, true );
        if ( null !== $decoded && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
            $replaced = $this->walk_replace( $search, $replace, $decoded );
            return wp_json_encode( $replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        }

        // Plain string.
        return str_replace( $search, $replace, $value );
    }

    /**
     * Recursively walk a data structure and replace strings.
     */
    private function walk_replace( $search, $replace, $data ) {
        if ( is_string( $data ) ) {
            return str_replace( $search, $replace, $data );
        }
        if ( is_array( $data ) ) {
            $result = [];
            foreach ( $data as $key => $val ) {
                $new_key = is_string( $key ) ? str_replace( $search, $replace, $key ) : $key;
                $result[ $new_key ] = $this->walk_replace( $search, $replace, $val );
            }
            return $result;
        }
        if ( is_object( $data ) ) {
            foreach ( get_object_vars( $data ) as $prop => $val ) {
                $data->$prop = $this->walk_replace( $search, $replace, $val );
            }
            return $data;
        }
        return $data;
    }

    /**
     * Clear Elementor's CSS cache — both DB postmeta and generated files.
     * Forces Elementor to regenerate CSS from the actual DB settings on next load.
     */
    private function clear_elementor_cache( $webroot ) {
        // Delete _elementor_css postmeta entries (DB-level cache).
        $dbh = $this->get_raw_dbh();
        if ( $dbh ) {
            global $table_prefix;
            $pm = $table_prefix . 'postmeta';
            mysqli_query( $dbh, "DELETE FROM `{$pm}` WHERE `meta_key` = '_elementor_css'" );
            // Also clear Elementor's file generation flags.
            $opts = $table_prefix . 'options';
            mysqli_query( $dbh, "DELETE FROM `{$opts}` WHERE `option_name` LIKE '_elementor_global_css%'" );
            mysqli_query( $dbh, "DELETE FROM `{$opts}` WHERE `option_name` LIKE 'elementor_css_print_method%'" );
            mysqli_close( $dbh );
        }

        // Delete generated CSS files on disk.
        $css_dir = $webroot . '/wp-content/uploads/elementor/css';
        if ( is_dir( $css_dir ) ) {
            $files = glob( $css_dir . '/*.css' );
            if ( $files ) {
                foreach ( $files as $file ) {
                    @unlink( $file );
                }
            }
        }
    }

    /**
     * Rewrite URLs in static files on disk (e.g. Elementor Google Fonts CSS).
     *
     * These files contain hardcoded absolute URLs that the DB rewrite doesn't touch.
     */
    private function rewrite_file_urls( $webroot, $old_url, $new_url ) {
        $old_url = rtrim( $old_url, '/' );
        $new_url = rtrim( $new_url, '/' );

        // Directories containing files that may embed absolute URLs.
        $scan_dirs = [
            $webroot . '/wp-content/uploads/elementor/google-fonts/css',
            $webroot . '/wp-content/uploads/elementor/css',
        ];

        foreach ( $scan_dirs as $dir ) {
            if ( ! is_dir( $dir ) ) {
                continue;
            }
            $files = glob( $dir . '/*.css' );
            if ( ! $files ) {
                continue;
            }
            foreach ( $files as $file ) {
                $content = file_get_contents( $file );
                if ( false === $content || false === strpos( $content, $old_url ) ) {
                    continue;
                }
                $new_content = str_replace( $old_url, $new_url, $content );
                file_put_contents( $file, $new_content );
            }
        }

        // Also rewrite Elementor's custom-frontend CSS if present.
        $custom_css = $webroot . '/wp-content/uploads/elementor/custom-frontend.min.css';
        if ( file_exists( $custom_css ) ) {
            $content = file_get_contents( $custom_css );
            if ( false !== $content && false !== strpos( $content, $old_url ) ) {
                file_put_contents( $custom_css, str_replace( $old_url, $new_url, $content ) );
            }
        }
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
