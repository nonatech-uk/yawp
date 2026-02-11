<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YAWP_DB_Export {

    /**
     * Export the entire database to a SQL file using $wpdb.
     */
    public function export( $output_path ) {
        global $wpdb;

        $fh = fopen( $output_path, 'w' );
        if ( ! $fh ) {
            return new WP_Error( 'yawp_db', 'Cannot open output file: ' . $output_path );
        }

        fwrite( $fh, "-- YAWP Database Export\n" );
        fwrite( $fh, "-- Date: " . gmdate( 'Y-m-d H:i:s' ) . " UTC\n" );
        fwrite( $fh, "-- WordPress DB: " . DB_NAME . "\n\n" );
        fwrite( $fh, "SET NAMES utf8mb4;\n" );
        fwrite( $fh, "SET foreign_key_checks = 0;\n\n" );

        $tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( ! $tables ) {
            fclose( $fh );
            return new WP_Error( 'yawp_db', 'No tables found in database.' );
        }

        foreach ( $tables as $table ) {
            $result = $this->export_table( $fh, $table );
            if ( is_wp_error( $result ) ) {
                fclose( $fh );
                return $result;
            }
        }

        fwrite( $fh, "SET foreign_key_checks = 1;\n" );
        fclose( $fh );

        return true;
    }

    private function export_table( $fh, $table ) {
        global $wpdb;

        fwrite( $fh, "-- --------------------------------------------------------\n" );
        fwrite( $fh, "-- Table: {$table}\n" );
        fwrite( $fh, "-- --------------------------------------------------------\n\n" );

        // Structure.
        $create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
        if ( ! $create || ! isset( $create[1] ) ) {
            return new WP_Error( 'yawp_db', "Cannot get CREATE TABLE for {$table}." );
        }

        fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" );
        fwrite( $fh, $create[1] . ";\n\n" );

        // Data in batches.
        fwrite( $fh, "LOCK TABLES `{$table}` WRITE;\n" );

        $offset    = 0;
        $batch     = 1000;
        $columns   = null;

        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $batch, $offset ),
                ARRAY_N
            );

            if ( empty( $rows ) ) {
                break;
            }

            // Get column names once.
            if ( null === $columns ) {
                $col_info = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );
                $columns  = array_map( function ( $c ) {
                    return '`' . $c->Field . '`';
                }, $col_info );
            }

            $values = [];
            foreach ( $rows as $row ) {
                $escaped = array_map( function ( $val ) use ( $wpdb ) {
                    if ( null === $val ) {
                        return 'NULL';
                    }
                    return "'" . $wpdb->_real_escape( $val ) . "'";
                }, $row );
                $values[] = '(' . implode( ',', $escaped ) . ')';
            }

            fwrite( $fh, 'INSERT INTO `' . $table . '` (' . implode( ',', $columns ) . ') VALUES ' . "\n" );
            fwrite( $fh, implode( ",\n", $values ) . ";\n" );

            $offset += $batch;
        }

        fwrite( $fh, "UNLOCK TABLES;\n\n" );

        return true;
    }
}
