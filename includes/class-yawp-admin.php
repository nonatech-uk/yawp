<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class YAWP_Admin {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_yawp_test_connection', [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_yawp_run_backup', [ $this, 'ajax_run_backup' ] );
    }

    public function add_menu() {
        add_options_page(
            'YAWP Backup',
            'YAWP Backup',
            'manage_options',
            'yawp',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'settings_page_yawp' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'yawp-admin', YAWP_URL . 'assets/admin.css', [], YAWP_VERSION );
    }

    public function register_settings() {
        $fields = [
            'yawp_s3_access_key'        => 'sanitize_text_field',
            'yawp_s3_secret_key'        => [ $this, 'sanitize_secret' ],
            'yawp_s3_region'            => 'sanitize_text_field',
            'yawp_s3_bucket'            => 'sanitize_text_field',
            'yawp_s3_prefix'            => 'sanitize_text_field',
            'yawp_retention_days'       => 'absint',
            'yawp_full_backup_interval' => 'absint',
        ];
        foreach ( $fields as $name => $sanitize ) {
            register_setting( 'yawp_settings', $name, [ 'sanitize_callback' => $sanitize ] );
        }
    }

    public function sanitize_secret( $value ) {
        if ( empty( $value ) || $value === '••••••••' ) {
            return get_option( 'yawp_s3_secret_key' );
        }
        return self::encrypt_secret( $value );
    }

    // ──────────────────────────────────────────────
    // Encryption helpers (XSalsa20-Poly1305)
    // ──────────────────────────────────────────────

    private static function encryption_key() {
        return hash( 'sha256', SECURE_AUTH_SALT, true );
    }

    public static function encrypt_secret( $plaintext ) {
        $key   = self::encryption_key();
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = sodium_crypto_secretbox( $plaintext, $nonce, $key );
        return base64_encode( $nonce . $cipher );
    }

    public static function decrypt_secret( $stored ) {
        if ( empty( $stored ) ) {
            return '';
        }
        $decoded = base64_decode( $stored, true );
        if ( false === $decoded ) {
            return '';
        }
        $key   = self::encryption_key();
        $nonce = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $cipher = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
        return false === $plain ? '' : $plain;
    }

    // ──────────────────────────────────────────────
    // AJAX handlers
    // ──────────────────────────────────────────────

    public function ajax_test_connection() {
        check_ajax_referer( 'yawp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $s3 = $this->get_s3_from_options();
        if ( is_wp_error( $s3 ) ) {
            wp_send_json_error( $s3->get_error_message() );
        }

        $result = $s3->test_connection();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( 'Connection successful.' );
    }

    public function ajax_run_backup() {
        check_ajax_referer( 'yawp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $type   = isset( $_POST['backup_type'] ) ? sanitize_text_field( $_POST['backup_type'] ) : 'full';
        $backup = new YAWP_Backup();
        $result = ( 'incremental' === $type ) ? $backup->run_incremental() : $backup->run_full();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( ucfirst( $type ) . ' backup completed.' );
    }

    private function get_s3_from_options() {
        $access_key = get_option( 'yawp_s3_access_key', '' );
        $secret_key = self::decrypt_secret( get_option( 'yawp_s3_secret_key', '' ) );
        $region     = get_option( 'yawp_s3_region', 'eu-west-2' );
        $bucket     = get_option( 'yawp_s3_bucket', '' );

        if ( ! $access_key || ! $secret_key || ! $bucket ) {
            return new WP_Error( 'yawp_admin', 'S3 credentials not fully configured.' );
        }
        return new YAWP_S3( $access_key, $secret_key, $region, $bucket );
    }

    // ──────────────────────────────────────────────
    // Settings page
    // ──────────────────────────────────────────────

    public function render_page() {
        $regions = [
            'us-east-1'      => 'US East (N. Virginia)',
            'us-east-2'      => 'US East (Ohio)',
            'us-west-1'      => 'US West (N. California)',
            'us-west-2'      => 'US West (Oregon)',
            'eu-west-1'      => 'EU (Ireland)',
            'eu-west-2'      => 'EU (London)',
            'eu-central-1'   => 'EU (Frankfurt)',
            'ap-southeast-1' => 'Asia Pacific (Singapore)',
            'ap-northeast-1' => 'Asia Pacific (Tokyo)',
        ];

        $current_region = get_option( 'yawp_s3_region', 'eu-west-2' );
        $has_secret     = '' !== get_option( 'yawp_s3_secret_key', '' );
        $login_date     = get_option( 'yawp_login_date', '' );
        $last_backup    = get_option( 'yawp_last_backup_time', 'Never' );
        $last_full      = get_option( 'yawp_last_full_backup_time', 'Never' );
        $last_error     = get_option( 'yawp_last_error', '' );
        $next_scheduled = wp_next_scheduled( 'yawp_daily_check' );
        $history        = json_decode( get_option( 'yawp_backup_history', '[]' ), true );
        $nonce          = wp_create_nonce( 'yawp_nonce' );
        ?>
        <div class="wrap">
            <h1>YAWP Backup</h1>

            <div class="yawp-status-box">
                <h2>Status</h2>
                <table class="form-table yawp-status-table">
                    <tr><th>Last backup</th><td><?php echo esc_html( $last_backup ); ?></td></tr>
                    <tr><th>Last full backup</th><td><?php echo esc_html( $last_full ); ?></td></tr>
                    <tr><th>Login flag</th><td><?php echo $login_date ? esc_html( $login_date ) : 'Not set (no login today)'; ?></td></tr>
                    <tr><th>Next scheduled check</th><td><?php echo $next_scheduled ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_scheduled ) ) ) : 'Not scheduled'; ?></td></tr>
                    <?php if ( $last_error ) : ?>
                    <tr><th>Last error</th><td class="yawp-error"><?php echo esc_html( $last_error ); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'yawp_settings' ); ?>
                <h2>S3 Settings</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="yawp_s3_access_key">AWS Access Key ID</label></th>
                        <td><input type="text" id="yawp_s3_access_key" name="yawp_s3_access_key"
                            value="<?php echo esc_attr( get_option( 'yawp_s3_access_key' ) ); ?>" class="regular-text" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th><label for="yawp_s3_secret_key">AWS Secret Access Key</label></th>
                        <td><input type="password" id="yawp_s3_secret_key" name="yawp_s3_secret_key"
                            value="<?php echo $has_secret ? '••••••••' : ''; ?>" class="regular-text" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th><label for="yawp_s3_region">AWS Region</label></th>
                        <td>
                            <select id="yawp_s3_region" name="yawp_s3_region">
                                <?php foreach ( $regions as $code => $label ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current_region, $code ); ?>><?php echo esc_html( $label . ' — ' . $code ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="yawp_s3_bucket">S3 Bucket</label></th>
                        <td><input type="text" id="yawp_s3_bucket" name="yawp_s3_bucket"
                            value="<?php echo esc_attr( get_option( 'yawp_s3_bucket' ) ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="yawp_s3_prefix">S3 Prefix</label></th>
                        <td><input type="text" id="yawp_s3_prefix" name="yawp_s3_prefix"
                            value="<?php echo esc_attr( get_option( 'yawp_s3_prefix' ) ); ?>" class="regular-text" />
                            <p class="description">e.g. <code>pitstop</code> — no leading/trailing slashes.</p></td>
                    </tr>
                    <tr>
                        <th><label for="yawp_retention_days">Retention (days)</label></th>
                        <td><input type="number" id="yawp_retention_days" name="yawp_retention_days" min="1"
                            value="<?php echo esc_attr( get_option( 'yawp_retention_days', 90 ) ); ?>" class="small-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="yawp_full_backup_interval">Full backup every X days</label></th>
                        <td><input type="number" id="yawp_full_backup_interval" name="yawp_full_backup_interval" min="0"
                            value="<?php echo esc_attr( get_option( 'yawp_full_backup_interval', 0 ) ); ?>" class="small-text" />
                            <p class="description">0 = only run full backup manually or on first activation.</p></td>
                    </tr>
                </table>
                <?php submit_button( 'Save Settings' ); ?>
            </form>

            <h2>Actions</h2>
            <div class="yawp-actions">
                <button class="button" id="yawp-test-connection">Test S3 Connection</button>
                <button class="button button-primary" id="yawp-run-full">Run Full Backup</button>
                <button class="button" id="yawp-run-incremental">Run Incremental Backup</button>
                <span id="yawp-action-status"></span>
            </div>

            <?php if ( ! empty( $history ) ) : ?>
            <h2>Backup History</h2>
            <table class="widefat yawp-history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Size</th>
                        <th>S3 Key</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $history as $entry ) : ?>
                    <tr class="<?php echo 'error' === $entry['type'] ? 'yawp-row-error' : ''; ?>">
                        <td><?php echo esc_html( $entry['date'] ); ?></td>
                        <td><?php echo esc_html( $entry['type'] ); ?></td>
                        <td><?php echo $entry['size'] ? esc_html( size_format( $entry['size'] ) ) : '—'; ?></td>
                        <td><code><?php echo esc_html( $entry['s3_key'] ); ?></code></td>
                        <td><?php echo esc_html( $entry['status'] ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <script>
        jQuery(function($) {
            var nonce = '<?php echo esc_js( $nonce ); ?>';
            var $status = $('#yawp-action-status');

            function setStatus(msg, isError) {
                $status.text(msg).css('color', isError ? '#d63638' : '#00a32a');
            }

            $('#yawp-test-connection').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this).prop('disabled', true);
                setStatus('Testing...', false);
                $.post(ajaxurl, { action: 'yawp_test_connection', nonce: nonce }, function(resp) {
                    setStatus(resp.success ? resp.data : resp.data, !resp.success);
                    $btn.prop('disabled', false);
                }).fail(function() {
                    setStatus('Request failed.', true);
                    $btn.prop('disabled', false);
                });
            });

            function runBackup(type) {
                return function(e) {
                    e.preventDefault();
                    if (!confirm('Run ' + type + ' backup now?')) return;
                    var $btn = $(this).prop('disabled', true);
                    setStatus('Running ' + type + ' backup...', false);
                    $.post(ajaxurl, { action: 'yawp_run_backup', nonce: nonce, backup_type: type }, function(resp) {
                        setStatus(resp.success ? resp.data : resp.data, !resp.success);
                        $btn.prop('disabled', false);
                        if (resp.success) location.reload();
                    }).fail(function() {
                        setStatus('Request failed.', true);
                        $btn.prop('disabled', false);
                    });
                };
            }

            $('#yawp-run-full').on('click', runBackup('full'));
            $('#yawp-run-incremental').on('click', runBackup('incremental'));
        });
        </script>
        <?php
    }
}
