<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YAWP_Login {

    public function __construct() {
        add_action( 'wp_login', [ $this, 'record_login' ] );
    }

    public function record_login() {
        update_option( 'yawp_login_date', gmdate( 'Y-m-d' ), false );
    }
}
