<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YAWP_Scheduler {

    public function __construct() {
        add_action( 'yawp_daily_check', [ $this, 'daily_check' ] );
        add_action( YAWP_Backup::CRON_HOOK, [ $this, 'run_backup_step' ] );
    }

    /**
     * WP-Cron callback for each chunked backup step.
     */
    public function run_backup_step() {
        $backup = new YAWP_Backup();
        $backup->process_step();
    }

    /**
     * Daily check — decides whether to run a full or incremental backup.
     */
    public function daily_check() {
        $backup = new YAWP_Backup();

        // 1. No full backup ever taken → run full.
        $last_full = get_option( 'yawp_last_full_backup_time', '' );
        if ( empty( $last_full ) ) {
            $backup->run_full();
            return;
        }

        // 2. Full backup interval elapsed → run full.
        $interval = (int) get_option( 'yawp_full_backup_interval', 0 );
        if ( $interval > 0 ) {
            $elapsed = ( time() - strtotime( $last_full ) ) / 86400;
            if ( $elapsed >= $interval ) {
                $backup->run_full();
                return;
            }
        }

        // 3. Login flag set → run incremental, then clear flag.
        $login_date = get_option( 'yawp_login_date', '' );
        if ( ! empty( $login_date ) ) {
            $result = $backup->run_incremental();
            if ( ! is_wp_error( $result ) ) {
                delete_option( 'yawp_login_date' );
            }
            return;
        }

        // 4. Nothing to do — write skip marker, still ping healthchecks.
        $backup->healthcheck( 'start' );
        $s3 = $backup->get_s3_public();
        if ( ! is_wp_error( $s3 ) ) {
            $prefix    = trim( get_option( 'yawp_s3_prefix', '' ), '/' );
            $timestamp = gmdate( 'Y-m-d_His' );
            $key       = ( $prefix ? $prefix . '/' : '' ) . 'incremental/' . $timestamp . '.skipped';
            $s3->put_text( $key, 'No login detected. Backup skipped at ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC.' );
        }
        $backup->healthcheck( 'success', 'No login detected — backup skipped.' );
    }
}
