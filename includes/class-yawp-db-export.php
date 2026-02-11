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

        // Use raw mysqli to avoid $wpdb->_real_escape() corrupting %
        // characters via WordPress placeholder escaping.
        $dbh = $this->get_raw_dbh();
        if ( ! $dbh ) {
            return new WP_Error( 'yawp_db', 'Cannot open database connection for export.' );
        }

        $fh = fopen( $output_path, 'w' );
        if ( ! $fh ) {
            mysqli_close( $dbh );
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
            mysqli_close( $dbh );
            return new WP_Error( 'yawp_db', 'No tables found in database.' );
        }

        foreach ( $tables as $table ) {
            $result = $this->export_table( $fh, $dbh, $table );
            if ( is_wp_error( $result ) ) {
                fclose( $fh );
                mysqli_close( $dbh );
                return $result;
            }
        }

        fwrite( $fh, "SET foreign_key_checks = 1;\n" );
        fclose( $fh );
        mysqli_close( $dbh );

        return true;
    }

    private function export_table( $fh, $dbh, $table ) {
        $create_result = mysqli_query( $dbh, "SHOW CREATE TABLE `{$table}`" );
        $create = $create_result ? mysqli_fetch_row( $create_result ) : null;
        if ( ! $create || ! isset( $create[1] ) ) {
            return new WP_Error( 'yawp_db', "Cannot get CREATE TABLE for {$table}." );
        }

        fwrite( $fh, "-- --------------------------------------------------------\n" );
        fwrite( $fh, "-- Table: {$table}\n" );
        fwrite( $fh, "-- --------------------------------------------------------\n\n" );

        fwrite( $fh, "DROP TABLE IF EXISTS `{$table}`;\n" );
        fwrite( $fh, $create[1] . ";\n\n" );

        // Data in batches.
        fwrite( $fh, "LOCK TABLES `{$table}` WRITE;\n" );

        $offset  = 0;
        $batch   = 1000;
        $columns = null;

        while ( true ) {
            $result = mysqli_query( $dbh, "SELECT * FROM `{$table}` LIMIT {$batch} OFFSET {$offset}" );
            if ( ! $result || 0 === mysqli_num_rows( $result ) ) {
                break;
            }

            // Get column names once.
            if ( null === $columns ) {
                $fields  = mysqli_fetch_fields( $result );
                $columns = array_map( function ( $f ) {
                    return '`' . $f->name . '`';
                }, $fields );
            }

            $values = [];
            while ( $row = mysqli_fetch_row( $result ) ) {
                $escaped = array_map( function ( $val ) use ( $dbh ) {
                    if ( null === $val ) {
                        return 'NULL';
                    }
                    return "'" . mysqli_real_escape_string( $dbh, $val ) . "'";
                }, $row );
                $values[] = '(' . implode( ',', $escaped ) . ')';
            }

            fwrite( $fh, 'INSERT INTO `' . $table . '` (' . implode( ',', $columns ) . ') VALUES ' . "\n" );
            fwrite( $fh, implode( ",\n", $values ) . ";\n" );

            mysqli_free_result( $result );
            $offset += $batch;
        }

        fwrite( $fh, "UNLOCK TABLES;\n\n" );

        return true;
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
