<?php
/**
 * Plugin Name: YAWP
 * Plugin URI: https://github.com/nonatech-uk/yawp
 * Description: Yet Another WordPress (Backup) Plugin — incremental S3 backups with Object Lock.
 * Version: 1.1.0
 * Author: Nonatech UK
 * License: GPLv2 or later
 * Update URI: false
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'YAWP_VERSION', '1.1.0' );
define( 'YAWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'YAWP_URL', plugin_dir_url( __FILE__ ) );
define( 'YAWP_GITHUB_REPO', 'nonatech-uk/yawp' );

require_once YAWP_PATH . 'includes/class-yawp-s3.php';
require_once YAWP_PATH . 'includes/class-yawp-db-export.php';
require_once YAWP_PATH . 'includes/class-yawp-backup.php';
require_once YAWP_PATH . 'includes/class-yawp-restore.php';
require_once YAWP_PATH . 'includes/class-yawp-admin.php';
require_once YAWP_PATH . 'includes/class-yawp-login.php';
require_once YAWP_PATH . 'includes/class-yawp-scheduler.php';
require_once YAWP_PATH . 'includes/class-yawp-updater.php';

final class YAWP {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        new YAWP_Login();
        new YAWP_Scheduler();
        new YAWP_Admin();
        new YAWP_Updater();
    }
}

register_activation_hook( __FILE__, function () {
    $defaults = [
        'yawp_s3_access_key'       => '',
        'yawp_s3_secret_key'       => '',
        'yawp_s3_region'           => 'eu-west-2',
        'yawp_s3_bucket'           => 'nonatech-zermatt-ski-service-backups-017147418967',
        'yawp_s3_prefix'           => 'backups',
        'yawp_retention_days'      => 90,
        'yawp_full_backup_interval' => 0,
        'yawp_backup_history'      => '[]',
    ];
    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( $key ) ) {
            add_option( $key, $value, '', false );
        }
    }
    if ( ! wp_next_scheduled( 'yawp_daily_check' ) ) {
        $tomorrow_3am = strtotime( 'tomorrow 03:00:00 UTC' );
        wp_schedule_event( $tomorrow_3am, 'daily', 'yawp_daily_check' );
    }
});

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'yawp_daily_check' );
});

add_action( 'plugins_loaded', [ 'YAWP', 'instance' ] );
