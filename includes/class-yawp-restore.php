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
     * List .tar.gz backups from S3, sorted newest-first.
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
        foreach ( $objects as $obj ) {
            $key = $obj['Key'];
            if ( '.tar.gz' !== substr( $key, -7 ) ) {
                continue;
            }

            // Determine type from path: "…/full/…" or "…/incremental/…"
            $type = 'unknown';
            if ( false !== strpos( $key, '/full/' ) ) {
                $type = 'full';
            } elseif ( false !== strpos( $key, '/incremental/' ) ) {
                $type = 'incremental';
            }

            // Parse date from filename: YYYY-MM-DD_HHMMSS.tar.gz
            $basename = basename( $key );
            $date     = '';
            if ( preg_match( '/(\d{4}-\d{2}-\d{2}_\d{6})/', $basename, $m ) ) {
                $date = str_replace( '_', ' ', $m[1] );
                // Format: "2025-01-15 031200" → "2025-01-15 03:12:00"
                $date = substr( $date, 0, 13 ) . ':' . substr( $date, 13, 2 ) . ':' . substr( $date, 15, 2 );
            }

            $backups[] = [
                's3_key'        => $key,
                'size'          => $obj['Size'],
                'last_modified' => $obj['LastModified'],
                'type'          => $type,
                'date'          => $date,
            ];
        }

        // Sort newest-first by date.
        usort( $backups, function ( $a, $b ) {
            return strcmp( $b['date'], $a['date'] );
        } );

        return $backups;
    }

    // ──────────────────────────────────────────────
    // Restore
    // ──────────────────────────────────────────────

    /**
     * Restore a backup archive from S3.
     *
     * @param string $s3_key  Full S3 key of the .tar.gz archive.
     * @param string $new_url Optional new site URL (scheme + host, no trailing slash).
     * @return true|WP_Error
     */
    public function restore( $s3_key, $new_url = '' ) {
        global $wpdb;

        // Acquire transient lock — prevent concurrent restores.
        if ( false !== get_transient( 'yawp_restore_running' ) ) {
            return new WP_Error( 'yawp_restore', 'A restore is already in progress.' );
        }
        if ( false !== get_transient( 'yawp_backup_running' ) ) {
            return new WP_Error( 'yawp_restore', 'A backup is currently running. Please wait for it to finish.' );
        }
        set_transient( 'yawp_restore_running', time(), 1800 );

        @set_time_limit( 1800 );

        // Disk space check — require at least 500 MB free in /tmp.
        $free = @disk_free_space( '/tmp' );
        if ( false !== $free && $free < 524288000 ) {
            delete_transient( 'yawp_restore_running' );
            return new WP_Error( 'yawp_restore', 'Insufficient disk space in /tmp (need at least 500 MB).' );
        }

        $tmp_dir      = '/tmp/yawp-restore-' . time() . '/';
        $archive_path = $tmp_dir . 'archive.tar.gz';

        try {
            if ( ! mkdir( $tmp_dir, 0755, true ) ) {
                return new WP_Error( 'yawp_restore', 'Cannot create temp directory.' );
            }

            $webroot      = rtrim( ABSPATH, '/' );
            $tar_excludes = '--exclude=database.sql --exclude=wp-config.php --exclude=wp-content/plugins/yawp';

            // ── Build the chain of archives to extract files from ──
            // For an incremental: full → each incremental up to and including
            // the selected one. Each incremental only has files changed since
            // the previous backup, so we must layer them all in order.
            // The DB always comes from the selected (final) archive.
            $file_chain = $this->build_restore_chain( $s3_key );
            if ( is_wp_error( $file_chain ) ) {
                return $file_chain;
            }

            // ── Download and extract files from each archive in order ──
            foreach ( $file_chain as $i => $chain_key ) {
                $chain_archive = $tmp_dir . 'chain_' . $i . '.tar.gz';
                $result = $this->s3->get_object( $chain_key, $chain_archive );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }

                $output = [];
                $cmd = sprintf(
                    'tar xzf %s -C %s %s 2>&1',
                    escapeshellarg( $chain_archive ),
                    escapeshellarg( $webroot ),
                    $tar_excludes
                );
                exec( $cmd, $output, $exit_code );
                if ( 0 !== $exit_code ) {
                    return new WP_Error( 'yawp_restore', 'Failed to extract files from ' . basename( $chain_key ) . ': ' . implode( "\n", $output ) );
                }

                // Keep the last archive for DB extraction, delete the rest.
                if ( $chain_key !== $s3_key ) {
                    @unlink( $chain_archive );
                } else {
                    $archive_path = $chain_archive;
                }
            }

            // ── Extract database.sql (always from the selected archive) ──
            $output = [];
            $cmd = sprintf(
                'tar xzf %s -C %s database.sql 2>&1',
                escapeshellarg( $archive_path ),
                escapeshellarg( $tmp_dir )
            );
            exec( $cmd, $output, $exit_code );
            if ( 0 !== $exit_code ) {
                return new WP_Error( 'yawp_restore', 'Failed to extract database.sql: ' . implode( "\n", $output ) );
            }

            $sql_file = $tmp_dir . 'database.sql';
            if ( ! file_exists( $sql_file ) ) {
                return new WP_Error( 'yawp_restore', 'database.sql not found in archive.' );
            }

            // ── Save current YAWP settings ──
            $saved_options = [];
            $yawp_options  = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'yawp_%'",
                ARRAY_A
            );
            foreach ( $yawp_options as $row ) {
                $saved_options[ $row['option_name'] ] = $row['option_value'];
            }

            // ── Import database.sql ──
            $import_result = $this->import_sql( $sql_file );
            if ( is_wp_error( $import_result ) ) {
                return $import_result;
            }

            // ── Re-apply saved YAWP settings ──
            foreach ( $saved_options as $name => $value ) {
                update_option( $name, $value, false );
            }

            // ── URL rewrite ──
            if ( '' !== $new_url ) {
                $old_url = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'" );
                if ( $old_url && $old_url !== $new_url ) {
                    $rewrite_result = $this->rewrite_urls( $old_url, $new_url );
                    if ( is_wp_error( $rewrite_result ) ) {
                        return $rewrite_result;
                    }
                }
            }

            // ── Flush rewrite rules ──
            delete_option( 'rewrite_rules' );

            return true;

        } finally {
            // Cleanup.
            if ( is_dir( $tmp_dir ) ) {
                exec( 'rm -rf ' . escapeshellarg( $tmp_dir ) );
            }
            delete_transient( 'yawp_restore_running' );
        }
    }

    // ──────────────────────────────────────────────
    // Restore chain helpers
    // ──────────────────────────────────────────────

    /**
     * Build the ordered list of S3 keys to extract files from.
     *
     * For a full backup: just that key.
     * For an incremental: preceding full → every incremental between that
     * full and the selected one (inclusive), sorted oldest-first.
     *
     * @param string $s3_key The selected backup key.
     * @return array|WP_Error Ordered list of S3 keys.
     */
    private function build_restore_chain( $s3_key ) {
        if ( false === strpos( $s3_key, '/incremental/' ) ) {
            return [ $s3_key ];
        }

        $backups = $this->list_backups();
        if ( is_wp_error( $backups ) ) {
            return $backups;
        }

        // Sort oldest-first for chain building.
        usort( $backups, function ( $a, $b ) {
            return strcmp( $a['date'], $b['date'] );
        } );

        // Find the selected backup's date.
        $selected_date = '';
        foreach ( $backups as $b ) {
            if ( $b['s3_key'] === $s3_key ) {
                $selected_date = $b['date'];
                break;
            }
        }

        if ( '' === $selected_date ) {
            return new WP_Error( 'yawp_restore', 'Selected backup not found in listing.' );
        }

        // Find the most recent full backup that predates the selected incremental.
        $full_key  = '';
        $full_date = '';
        foreach ( $backups as $b ) {
            if ( 'full' === $b['type'] && $b['date'] <= $selected_date ) {
                $full_key  = $b['s3_key'];
                $full_date = $b['date'];
            }
        }

        if ( '' === $full_key ) {
            return new WP_Error( 'yawp_restore', 'No full backup found before this incremental. Restore a full backup first.' );
        }

        // Build chain: full, then each incremental after the full up to
        // and including the selected one.
        $chain = [ $full_key ];
        foreach ( $backups as $b ) {
            if ( 'incremental' === $b['type'] && $b['date'] > $full_date && $b['date'] <= $selected_date ) {
                $chain[] = $b['s3_key'];
            }
        }

        return $chain;
    }

    // ──────────────────────────────────────────────
    // SQL import
    // ──────────────────────────────────────────────

    /**
     * Import a SQL dump file line-by-line, accumulating until trailing semicolon.
     *
     * @param string $file_path Path to .sql file.
     * @return true|WP_Error
     */
    private function import_sql( $file_path ) {
        global $wpdb;

        $fh = fopen( $file_path, 'r' );
        if ( ! $fh ) {
            return new WP_Error( 'yawp_restore', 'Cannot open SQL file.' );
        }

        $buffer = '';
        $wpdb->query( 'SET foreign_key_checks = 0' );

        while ( false !== ( $line = fgets( $fh ) ) ) {
            $trimmed = trim( $line );

            // Skip comments and empty lines.
            if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) ) {
                continue;
            }

            $buffer .= $line;

            // Execute when we hit a statement-ending semicolon.
            if ( ';' === substr( $trimmed, -1 ) ) {
                $wpdb->query( $buffer );
                $buffer = '';
            }
        }

        // Execute any remaining buffered SQL.
        if ( '' !== trim( $buffer ) ) {
            $wpdb->query( $buffer );
        }

        $wpdb->query( 'SET foreign_key_checks = 1' );

        fclose( $fh );
        return true;
    }

    // ──────────────────────────────────────────────
    // URL rewrite (serialization-safe)
    // ──────────────────────────────────────────────

    /**
     * Replace all occurrences of $old_url with $new_url across the entire database,
     * handling serialized PHP data correctly.
     *
     * @param string $old_url The original site URL.
     * @param string $new_url The new site URL.
     * @return true|WP_Error
     */
    public function rewrite_urls( $old_url, $new_url ) {
        global $wpdb;

        // Normalize — strip trailing slashes.
        $old_url = rtrim( $old_url, '/' );
        $new_url = rtrim( $new_url, '/' );

        if ( $old_url === $new_url ) {
            return true;
        }

        // Update siteurl and home directly.
        $wpdb->update( $wpdb->options, [ 'option_value' => $new_url ], [ 'option_name' => 'siteurl' ] );
        $wpdb->update( $wpdb->options, [ 'option_value' => $new_url ], [ 'option_name' => 'home' ] );

        // Build search/replace pairs including scheme variants.
        $replacements = $this->build_replacement_pairs( $old_url, $new_url );

        // Iterate all tables.
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( empty( $tables ) ) {
            return true;
        }

        foreach ( $tables as $table ) {
            $this->rewrite_table( $table, $replacements );
        }

        return true;
    }

    /**
     * Build search/replace pairs covering http, https, and schemeless variants.
     */
    private function build_replacement_pairs( $old_url, $new_url ) {
        $pairs = [];

        // Exact URL as-is.
        $pairs[] = [ $old_url, $new_url ];

        // Opposite scheme variant.
        if ( 0 === strpos( $old_url, 'https://' ) ) {
            $old_http = 'http://' . substr( $old_url, 8 );
            $new_http = 'http://' . substr( $new_url, 8 );
            $pairs[]  = [ $old_http, $new_http ];
        } elseif ( 0 === strpos( $old_url, 'http://' ) ) {
            $old_https = 'https://' . substr( $old_url, 7 );
            $new_https = 'https://' . substr( $new_url, 7 );
            $pairs[]   = [ $old_https, $new_https ];
        }

        // Schemeless (e.g. //old.example.com → //new.example.com).
        $old_schemeless = preg_replace( '#^https?:#', '', $old_url );
        $new_schemeless = preg_replace( '#^https?:#', '', $new_url );
        if ( $old_schemeless !== $new_schemeless ) {
            $pairs[] = [ $old_schemeless, $new_schemeless ];
        }

        return $pairs;
    }

    /**
     * Rewrite URLs in a single table, handling serialized data.
     */
    private function rewrite_table( $table, $replacements ) {
        global $wpdb;

        // Identify text columns.
        $text_columns = [];
        $columns      = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
        foreach ( $columns as $col ) {
            if ( preg_match( '/varchar|text|longtext|mediumtext/i', $col['Type'] ) ) {
                $text_columns[] = $col['Field'];
            }
        }

        if ( empty( $text_columns ) ) {
            return;
        }

        // Discover primary key for targeted UPDATEs.
        $pk_col  = null;
        $pk_rows = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
        if ( ! empty( $pk_rows ) ) {
            $pk_col = $pk_rows[0]['Column_name'];
        }

        // Build WHERE clause to find rows containing any old URL.
        $old_strings = array_column( $replacements, 0 );
        $where_parts = [];
        foreach ( $text_columns as $col ) {
            foreach ( $old_strings as $old ) {
                $where_parts[] = $wpdb->prepare( "`{$col}` LIKE %s", '%' . $wpdb->esc_like( $old ) . '%' );
            }
        }
        $where = implode( ' OR ', $where_parts );

        // Select columns for reading — primary key + text columns.
        $select_cols = $text_columns;
        if ( $pk_col && ! in_array( $pk_col, $select_cols, true ) ) {
            array_unshift( $select_cols, $pk_col );
        }
        $select_str = implode( ', ', array_map( function ( $c ) { return "`{$c}`"; }, $select_cols ) );

        // Batch processing.
        $offset = 0;
        $batch  = 500;

        while ( true ) {
            $rows = $wpdb->get_results(
                "SELECT {$select_str} FROM `{$table}` WHERE {$where} LIMIT {$batch} OFFSET {$offset}",
                ARRAY_A
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $updates = [];

                foreach ( $text_columns as $col ) {
                    if ( ! isset( $row[ $col ] ) || null === $row[ $col ] ) {
                        continue;
                    }

                    $original = $row[ $col ];
                    $modified = $this->replace_in_value( $original, $replacements );

                    if ( $modified !== $original ) {
                        $updates[ $col ] = $modified;
                    }
                }

                if ( empty( $updates ) ) {
                    continue;
                }

                if ( $pk_col && isset( $row[ $pk_col ] ) ) {
                    $wpdb->update( $table, $updates, [ $pk_col => $row[ $pk_col ] ] );
                } else {
                    // Fallback: update by matching old column values.
                    $where_row = [];
                    foreach ( $text_columns as $col ) {
                        if ( isset( $row[ $col ] ) ) {
                            $where_row[ $col ] = $row[ $col ];
                        }
                    }
                    $wpdb->update( $table, $updates, $where_row );
                }
            }

            // If we got fewer rows than the batch, we're done.
            if ( count( $rows ) < $batch ) {
                break;
            }

            $offset += $batch;
        }
    }

    /**
     * Replace URLs in a value, handling serialized data.
     */
    private function replace_in_value( $value, $replacements ) {
        $unserialized = @maybe_unserialize( $value );

        if ( is_serialized( $value ) ) {
            // Walk the unserialized structure and replace strings.
            $unserialized = $this->recursive_replace( $unserialized, $replacements );
            return maybe_serialize( $unserialized );
        }

        // Plain string — simple replacement.
        foreach ( $replacements as $pair ) {
            $value = str_replace( $pair[0], $pair[1], $value );
        }

        return $value;
    }

    /**
     * Recursively walk a data structure, replacing URL strings.
     */
    private function recursive_replace( $data, $replacements ) {
        if ( is_string( $data ) ) {
            foreach ( $replacements as $pair ) {
                $data = str_replace( $pair[0], $pair[1], $data );
            }
            return $data;
        }

        if ( is_array( $data ) ) {
            foreach ( $data as $key => $val ) {
                $data[ $key ] = $this->recursive_replace( $val, $replacements );
            }
            return $data;
        }

        if ( is_object( $data ) ) {
            foreach ( get_object_vars( $data ) as $prop => $val ) {
                $data->$prop = $this->recursive_replace( $val, $replacements );
            }
            return $data;
        }

        return $data;
    }
}
