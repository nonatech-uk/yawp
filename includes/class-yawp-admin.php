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
        add_action( 'wp_ajax_yawp_backup_status', [ $this, 'ajax_backup_status' ] );
        add_action( 'wp_ajax_yawp_clear_lock', [ $this, 'ajax_clear_lock' ] );
        add_action( 'wp_ajax_yawp_list_backups', [ $this, 'ajax_list_backups' ] );
        add_action( 'wp_ajax_yawp_restore_backup', [ $this, 'ajax_restore_backup' ] );
        add_action( 'wp_ajax_yawp_restore_status', [ $this, 'ajax_restore_status' ] );
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
            'yawp_webroot'              => 'sanitize_text_field',
            'yawp_full_backup_interval' => 'absint',
            'yawp_healthchecks_url'     => 'esc_url_raw',
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
        // Backup is now async — just confirm it started.
        wp_send_json_success( ucfirst( $type ) . ' backup started.' );
    }

    public function ajax_backup_status() {
        check_ajax_referer( 'yawp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        // Reserve memory so the shutdown handler can still send a JSON
        // response if PHP hits the memory limit during a backup step.
        $reserved = str_repeat( "\0", 65536 );

        register_shutdown_function( function () use ( &$reserved ) {
            $error = error_get_last();
            if ( $error && in_array( $error['type'], [ E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
                $reserved = null; // Free the 64 KB reserve.
                if ( ! headers_sent() ) {
                    header( 'Content-Type: application/json; charset=UTF-8' );
                }
                $msg = 'PHP fatal during backup step: ' . $error['message'];
                // Record the error so the history table shows what happened.
                update_option( 'yawp_last_error', $msg, false );
                echo wp_json_encode( [ 'success' => false, 'data' => $msg ] );
            }
        } );

        $status = YAWP_Backup::get_status();
        // Directly run the next step if the backup is still in progress.
        // This makes the browser's 5-second poll the primary driver instead
        // of relying on WP-Cron's spawn_cron() which has a 60-second lock
        // and is unreliable on restricted shared hosting.
        if ( ! empty( $status['running'] ) ) {
            $backup = new YAWP_Backup();
            $backup->process_step();
            // Re-read status after the step executed.
            $status = YAWP_Backup::get_status();
        }
        wp_send_json_success( $status );
    }

    public function ajax_clear_lock() {
        check_ajax_referer( 'yawp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }
        YAWP_Backup::cancel();
        delete_transient( 'yawp_restore_running' );
        wp_send_json_success( 'Lock cleared.' );
    }

    public function ajax_list_backups() {
        check_ajax_referer( 'yawp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $s3 = $this->get_s3_from_options();
        if ( is_wp_error( $s3 ) ) {
            wp_send_json_error( $s3->get_error_message() );
        }

        $restore = new YAWP_Restore( $s3 );
        $backups = $restore->list_backups();
        if ( is_wp_error( $backups ) ) {
            wp_send_json_error( $backups->get_error_message() );
        }
        wp_send_json_success( $backups );
    }

    public function ajax_restore_backup() {
        check_ajax_referer( 'yawp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $s3_key  = isset( $_POST['s3_key'] ) ? sanitize_text_field( $_POST['s3_key'] ) : '';
        $new_url = isset( $_POST['new_url'] ) ? sanitize_text_field( $_POST['new_url'] ) : '';
        $new_url = rtrim( trim( $new_url ), '/' );

        if ( '' === $s3_key ) {
            wp_send_json_error( 'No backup key specified.' );
        }

        $s3 = $this->get_s3_from_options();
        if ( is_wp_error( $s3 ) ) {
            wp_send_json_error( $s3->get_error_message() );
        }

        $restore = new YAWP_Restore( $s3 );
        $result  = $restore->restore( $s3_key, $new_url );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        $msg = 'Restore completed successfully.';
        if ( '' !== $new_url ) {
            $msg .= ' Note: you may need to re-enter your S3 credentials (encryption keys differ between sites).';
            $msg .= ' If this site uses Elementor, run Elementor → Tools → Replace URL (old → new domain) then Clear Files & Data to fix any remaining URLs in Elementor page data.';
        }
        wp_send_json_success( $msg );
    }

    public function ajax_restore_status() {
        check_ajax_referer( 'yawp_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }
        $raw = get_option( 'yawp_restore_progress', '' );
        if ( '' === $raw ) {
            wp_send_json_success( [ 'running' => false ] );
        }
        $progress = json_decode( $raw, true );
        if ( ! is_array( $progress ) ) {
            wp_send_json_success( [ 'running' => false ] );
        }
        $progress['running'] = true;
        wp_send_json_success( $progress );
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
                    <tr><th>Login flag</th><td><?php echo $login_date ? esc_html( $login_date ) : 'Not set (no login since last backup)'; ?></td></tr>
                    <tr><th>Next scheduled check</th><td><?php echo $next_scheduled ? esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next_scheduled ) ) ) : 'Not scheduled'; ?></td></tr>
                    <?php if ( $last_error ) : ?>
                    <tr><th>Last backup error</th><td class="yawp-error"><?php echo esc_html( $last_error ); ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'yawp_settings' ); ?>
                <h2>S3 Settings</h2>
                <p class="description">Configure your S3 bucket connection. Remember to <strong>Save Settings</strong> before using Test Connection or running backups.</p>
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
                        <th><label for="yawp_webroot">WordPress Root Path</label></th>
                        <td><input type="text" id="yawp_webroot" name="yawp_webroot"
                            value="<?php echo esc_attr( get_option( 'yawp_webroot', '' ) ); ?>" class="regular-text"
                            placeholder="<?php echo esc_attr( rtrim( ABSPATH, '/' ) ); ?>" />
                            <p class="description">Absolute path to the WordPress installation. Leave blank to auto-detect.<br>
                            Detected: <code><?php echo esc_html( rtrim( ABSPATH, '/' ) ); ?></code></p></td>
                    </tr>
                    <tr>
                        <th><label for="yawp_full_backup_interval">Full backup every X days</label></th>
                        <td><input type="number" id="yawp_full_backup_interval" name="yawp_full_backup_interval" min="0"
                            value="<?php echo esc_attr( get_option( 'yawp_full_backup_interval', 0 ) ); ?>" class="small-text" />
                            <p class="description"><code>0</code> = only on first activation or manually. Incrementals run daily but <strong>only when a user has logged in</strong>.</p></td>
                    </tr>
                    <tr>
                        <th><label for="yawp_healthchecks_url">Healthchecks URL</label></th>
                        <td><input type="url" id="yawp_healthchecks_url" name="yawp_healthchecks_url"
                            value="<?php echo esc_attr( get_option( 'yawp_healthchecks_url', '' ) ); ?>" class="regular-text" />
                            <p class="description">Optional. Full URL to ping on backup start/finish. Leave blank to disable.</p></td>
                    </tr>
                </table>
                <?php submit_button( 'Save Settings' ); ?>
            </form>

            <h2>Actions</h2>
            <p class="description"><strong>Note:</strong> You must save settings before testing the connection or running a backup.</p>
            <div class="yawp-actions">
                <button class="button" id="yawp-test-connection">Test S3 Connection</button>
                <button class="button button-primary" id="yawp-run-full">Run Full Backup</button>
                <button class="button" id="yawp-run-incremental">Run Incremental Backup</button>
                <button class="button" id="yawp-clear-lock" title="Clear stale lock if a previous backup crashed">Clear Lock</button>
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

            <div class="yawp-restore-section">
                <h2>Restore</h2>
                <p class="description">Restore a backup from S3. This will overwrite the database and files on this site.</p>
                <table class="form-table">
                    <tr>
                        <th><label for="yawp-restore-new-url">New Site URL</label></th>
                        <td><input type="text" id="yawp-restore-new-url" class="regular-text"
                            placeholder="Leave blank to keep original URL" />
                            <p class="description">If restoring to a different domain, enter the full URL (e.g. <code>https://staging.example.com</code>).</p></td>
                    </tr>
                </table>
                <div class="yawp-actions">
                    <button class="button" id="yawp-list-backups">List Available Backups</button>
                    <span id="yawp-restore-status"></span>
                </div>
                <div id="yawp-restore-list"></div>
            </div>
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

            var pollTimer = null;

            function stepLabel(step, progress) {
                switch (step) {
                    case 'init':     return 'Exporting database\u2026';
                    case 'scan':     return 'Scanning files\u2026';
                    case 'archive':  return 'Archiving files (' + (progress || '') + ')\u2026';
                    case 'compress': return 'Compressing archive\u2026';
                    case 'upload':   return 'Uploading to S3\u2026';
                    case 'finish':   return 'Finalising\u2026';
                    default:         return step + '\u2026';
                }
            }

            function pollStatus() {
                $.post(ajaxurl, { action: 'yawp_backup_status', nonce: nonce }, function(resp) {
                    if (!resp.success) {
                        stopPoll();
                        setStatus(resp.data || 'Status check failed.', true);
                        enableBackupButtons();
                        return;
                    }
                    var s = resp.data;
                    if (s.running) {
                        setStatus(stepLabel(s.step, s.progress), false);
                    } else if (s.step === 'done') {
                        stopPoll();
                        setStatus(s.message || 'Backup complete!', false);
                        enableBackupButtons();
                        setTimeout(function() { location.reload(); }, 2000);
                    } else if (s.step === 'error') {
                        stopPoll();
                        setStatus('Backup failed: ' + (s.error || 'Unknown error'), true);
                        enableBackupButtons();
                    } else {
                        // Idle — no backup in progress.
                        stopPoll();
                        enableBackupButtons();
                    }
                }).fail(function() {
                    stopPoll();
                    setStatus('Status poll failed.', true);
                    enableBackupButtons();
                });
            }

            function startPoll() {
                if (pollTimer) return;
                pollTimer = setInterval(pollStatus, 5000);
            }

            function stopPoll() {
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            }

            function enableBackupButtons() {
                $('#yawp-run-full, #yawp-run-incremental').prop('disabled', false);
            }

            function runBackup(type) {
                return function(e) {
                    e.preventDefault();
                    if (!confirm('Run ' + type + ' backup now?')) return;
                    $('#yawp-run-full, #yawp-run-incremental').prop('disabled', true);
                    setStatus('Starting ' + type + ' backup\u2026', false);
                    $.post(ajaxurl, { action: 'yawp_run_backup', nonce: nonce, backup_type: type }, function(resp) {
                        if (resp.success) {
                            setStatus(resp.data, false);
                            startPoll();
                        } else {
                            setStatus(resp.data, true);
                            enableBackupButtons();
                        }
                    }).fail(function() {
                        setStatus('Request failed.', true);
                        enableBackupButtons();
                    });
                };
            }

            $('#yawp-run-full').on('click', runBackup('full'));
            $('#yawp-run-incremental').on('click', runBackup('incremental'));

            // If a backup is already running when the page loads, start polling.
            (function checkInitialStatus() {
                $.post(ajaxurl, { action: 'yawp_backup_status', nonce: nonce }, function(resp) {
                    if (resp.success && resp.data && resp.data.running) {
                        $('#yawp-run-full, #yawp-run-incremental').prop('disabled', true);
                        setStatus(stepLabel(resp.data.step, resp.data.progress), false);
                        startPoll();
                    }
                });
            })();

            $('#yawp-clear-lock').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this).prop('disabled', true);
                $.post(ajaxurl, { action: 'yawp_clear_lock', nonce: nonce }, function(resp) {
                    setStatus(resp.success ? resp.data : resp.data, !resp.success);
                    $btn.prop('disabled', false);
                }).fail(function() {
                    setStatus('Request failed.', true);
                    $btn.prop('disabled', false);
                });
            });

            // ── Restore ──

            var $restoreStatus = $('#yawp-restore-status');
            var $restoreList   = $('#yawp-restore-list');

            function setRestoreStatus(msg, isError) {
                $restoreStatus.text(msg).css('color', isError ? '#d63638' : '#00a32a');
            }

            function escHtml(s) {
                var d = document.createElement('div');
                d.appendChild(document.createTextNode(s));
                return d.innerHTML;
            }

            function escAttr(s) {
                return s.replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            }

            function formatSize(bytes) {
                if (!bytes || bytes === 0) return '—';
                var units = ['B','KB','MB','GB'];
                var i = 0;
                var b = bytes;
                while (b >= 1024 && i < units.length - 1) { b /= 1024; i++; }
                return b.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
            }

            $('#yawp-list-backups').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this).prop('disabled', true);
                setRestoreStatus('Loading backups...', false);
                $restoreList.empty();
                $.post(ajaxurl, { action: 'yawp_list_backups', nonce: nonce }, function(resp) {
                    $btn.prop('disabled', false);
                    if (!resp.success) {
                        setRestoreStatus(resp.data, true);
                        return;
                    }
                    setRestoreStatus('', false);
                    var backups = resp.data;
                    if (!backups.length) {
                        $restoreList.html('<p>No backups found.</p>');
                        return;
                    }
                    var html = '<table class="widefat yawp-restore-table"><thead><tr>' +
                        '<th>Date</th><th>Type</th><th>Size</th><th>S3 Key</th><th></th>' +
                        '</tr></thead><tbody>';
                    for (var i = 0; i < backups.length; i++) {
                        var b = backups[i];
                        html += '<tr>' +
                            '<td>' + escHtml(b.date) + '</td>' +
                            '<td>' + escHtml(b.type) + '</td>' +
                            '<td>' + formatSize(b.size) + '</td>' +
                            '<td><code>' + escHtml(b.s3_key) + '</code></td>' +
                            '<td><button class="button yawp-restore-btn" data-key="' + escAttr(b.s3_key) + '">Restore</button></td>' +
                            '</tr>';
                    }
                    html += '</tbody></table>';
                    $restoreList.html(html);
                }).fail(function() {
                    setRestoreStatus('Request failed.', true);
                    $btn.prop('disabled', false);
                });
            });

            $restoreList.on('click', '.yawp-restore-btn', function(e) {
                e.preventDefault();
                var key    = $(this).data('key');
                var newUrl = $.trim($('#yawp-restore-new-url').val());

                var msg = 'WARNING: This will overwrite the database and files on this site.\n\n' +
                    'Backup: ' + key + '\n';
                if (newUrl) {
                    msg += 'New URL: ' + newUrl + '\n';
                }
                msg += '\nYour session will be invalidated and you will need to log in again.\n\nContinue?';

                if (!confirm(msg)) return;

                // Disable all restore buttons.
                $restoreList.find('.yawp-restore-btn').prop('disabled', true);
                setRestoreStatus('Restoring... starting...', false);

                // Poll for restore progress.
                var restorePoll = setInterval(function() {
                    $.post(ajaxurl, {
                        action: 'yawp_restore_status',
                        nonce:  nonce
                    }, function(resp) {
                        if (!resp.success || !resp.data || !resp.data.running) return;
                        var d = resp.data;
                        if (d.step === 'files') {
                            setRestoreStatus('Restoring files: part ' + d.current + ' / ' + d.total + ' (' + d.part + ')', false);
                        } else if (d.step === 'database') {
                            setRestoreStatus('Importing database...', false);
                        } else if (d.step === 'rewrite') {
                            setRestoreStatus('Rewriting URLs...', false);
                        }
                    });
                }, 3000);

                $.post(ajaxurl, {
                    action:  'yawp_restore_backup',
                    nonce:   nonce,
                    s3_key:  key,
                    new_url: newUrl
                }, function(resp) {
                    clearInterval(restorePoll);
                    if (resp.success) {
                        var loginUrl = (newUrl || '') + '/wp-login.php';
                        if (!newUrl) loginUrl = '/wp-login.php';
                        setRestoreStatus(resp.data + ' Redirecting to login in 10s...', false);
                        setTimeout(function() { window.location.href = loginUrl; }, 10000);
                    } else {
                        setRestoreStatus(resp.data, true);
                        $restoreList.find('.yawp-restore-btn').prop('disabled', false);
                    }
                }).fail(function() {
                    clearInterval(restorePoll);
                    setRestoreStatus('Request failed (connection lost - restore may still be running).', true);
                    $restoreList.find('.yawp-restore-btn').prop('disabled', false);
                });
            });
        });
        </script>
        <?php
    }
}
