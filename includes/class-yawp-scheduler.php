<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YAWP_Scheduler {

    public function __construct() {
        add_action( 'yawp_daily_check', [ $this, 'daily_check' ] );
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

        // 4. Nothing to do.
    }
}
