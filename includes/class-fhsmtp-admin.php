<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FHSMTP_Admin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_fhsmtp_send_test', array( $this, 'ajax_send_test' ) );
        add_action( 'wp_ajax_fhsmtp_test_backup', array( $this, 'ajax_test_backup' ) );
        add_action( 'wp_ajax_fhsmtp_get_log_detail', array( $this, 'ajax_get_log_detail' ) );
        add_action( 'wp_ajax_fhsmtp_resend_email', array( $this, 'ajax_resend_email' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
    }

    public function add_menu_pages() {
        add_options_page(
            'FisHotel SMTP',
            'FisHotel SMTP',
            'manage_options',
            'fishotel-smtp',
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            'options-general.php',
            'Email Logs',
            '',
            'manage_options',
            'fhsmtp-email-logs',
            array( $this, 'render_logs_page' )
        );
    }

    public function register_settings() {
        register_setting( 'fhsmtp_connection', 'fhsmtp_settings', array(
            'sanitize_callback' => array( $this, 'sanitize_connection_settings' ),
        ) );
        register_setting( 'fhsmtp_logging', 'fhsmtp_log_settings', array(
            'sanitize_callback' => array( $this, 'sanitize_log_settings' ),
        ) );
        register_setting( 'fhsmtp_backup', 'fhsmtp_backup_settings', array(
            'sanitize_callback' => array( $this, 'sanitize_backup_settings' ),
        ) );
        register_setting( 'fhsmtp_alerts', 'fhsmtp_alert_settings', array(
            'sanitize_callback' => array( $this, 'sanitize_alert_settings' ),
        ) );
        register_setting( 'fhsmtp_email_controls_group', 'fhsmtp_email_controls', array(
            'sanitize_callback' => array( $this, 'sanitize_email_controls' ),
        ) );
        register_setting( 'fhsmtp_misc', 'fhsmtp_misc_settings', array(
            'sanitize_callback' => array( $this, 'sanitize_misc_settings' ),
        ) );
    }

    public function sanitize_connection_settings( $input ) {
        $sanitized = array();
        $sanitized['smtp_host']       = sanitize_text_field( $input['smtp_host'] ?? '' );
        $sanitized['smtp_port']       = absint( $input['smtp_port'] ?? 587 );
        $sanitized['smtp_encryption'] = in_array( $input['smtp_encryption'] ?? 'tls', array( 'tls', 'ssl', 'none' ), true ) ? $input['smtp_encryption'] : 'tls';
        $sanitized['smtp_username']   = sanitize_text_field( $input['smtp_username'] ?? '' );
        $sanitized['from_email']      = sanitize_email( $input['from_email'] ?? '' );
        $sanitized['from_name']       = sanitize_text_field( $input['from_name'] ?? '' );
        $sanitized['region']          = sanitize_text_field( $input['region'] ?? 'us-east-1' );

        $sanitized['force_from_email'] = ! empty( $input['force_from_email'] ) ? '1' : '0';

        // Only update password if provided (don't blank it out)
        $current = get_option( 'fhsmtp_settings', array() );
        $sanitized['smtp_password'] = ! empty( $input['smtp_password'] ) ? $input['smtp_password'] : ( $current['smtp_password'] ?? '' );

        return $sanitized;
    }

    public function sanitize_log_settings( $input ) {
        return array(
            'logging_enabled' => ! empty( $input['logging_enabled'] ) ? '1' : '0',
            'retention_days'  => absint( $input['retention_days'] ?? 30 ),
        );
    }

    public function sanitize_backup_settings( $input ) {
        $sanitized = array();
        $sanitized['backup_enabled']    = ! empty( $input['backup_enabled'] ) ? '1' : '0';
        $sanitized['backup_host']       = sanitize_text_field( $input['backup_host'] ?? '' );
        $sanitized['backup_port']       = absint( $input['backup_port'] ?? 587 );
        $sanitized['backup_encryption'] = in_array( $input['backup_encryption'] ?? 'tls', array( 'tls', 'ssl', 'none' ), true ) ? $input['backup_encryption'] : 'tls';
        $sanitized['backup_username']   = sanitize_text_field( $input['backup_username'] ?? '' );

        $current = get_option( 'fhsmtp_backup_settings', array() );
        $sanitized['backup_password'] = ! empty( $input['backup_password'] ) ? $input['backup_password'] : ( $current['backup_password'] ?? '' );

        return $sanitized;
    }

    public function sanitize_alert_settings( $input ) {
        return array(
            'alerts_enabled'          => ! empty( $input['alerts_enabled'] ) ? '1' : '0',
            'threshold'               => max( 1, absint( $input['threshold'] ?? 3 ) ),
            'alert_email_recipients'  => sanitize_text_field( $input['alert_email_recipients'] ?? '' ),
            'slack_webhook_url'       => esc_url_raw( $input['slack_webhook_url'] ?? '' ),
            'discord_webhook_url'     => esc_url_raw( $input['discord_webhook_url'] ?? '' ),
            'custom_webhook_url'      => esc_url_raw( $input['custom_webhook_url'] ?? '' ),
            'custom_webhook_template' => sanitize_textarea_field( $input['custom_webhook_template'] ?? '' ),
        );
    }

    public function sanitize_email_controls( $input ) {
        $keys = array(
            'notify_moderator', 'notify_post_author',
            'admin_email_change_attempt', 'admin_email_changed',
            'reset_password_request', 'password_reset_success',
            'password_changed', 'email_change_attempt', 'email_changed',
            'user_confirmed_action', 'admin_erased_data', 'admin_sent_export_link',
            'auto_plugin_update_email', 'auto_theme_update_email',
            'auto_core_update_email', 'auto_update_debug_email',
            'new_user_admin_notify', 'new_user_user_notify',
        );
        $sanitized = array();
        foreach ( $keys as $key ) {
            $sanitized[ $key ] = ! empty( $input[ $key ] ) ? '1' : '0';
        }
        return $sanitized;
    }

    public function sanitize_misc_settings( $input ) {
        return array(
            'do_not_send'           => ! empty( $input['do_not_send'] ) ? '1' : '0',
            'hide_delivery_errors'  => ! empty( $input['hide_delivery_errors'] ) ? '1' : '0',
            'hide_dashboard_widget' => ! empty( $input['hide_dashboard_widget'] ) ? '1' : '0',
            'remove_data_on_delete' => ! empty( $input['remove_data_on_delete'] ) ? '1' : '0',
        );
    }

    public function admin_notices() {
        $misc = get_option( 'fhsmtp_misc_settings', array() );
        if ( ! empty( $misc['hide_delivery_errors'] ) && $misc['hide_delivery_errors'] === '1' ) {
            return;
        }

        $settings = get_option( 'fhsmtp_settings', array() );
        if ( empty( $settings['smtp_host'] ) && ! defined( 'FHSMTP_SMTP_HOST' ) ) {
            $url = admin_url( 'options-general.php?page=fishotel-smtp' );
            echo '<div class="notice notice-warning"><p>';
            echo '<strong>FisHotel SMTP:</strong> SMTP is not configured. ';
            echo '<a href="' . esc_url( $url ) . '">Configure now</a>.';
            echo '</p></div>';
        }
    }

    // -------------------------------------------------------------------------
    // Settings page render
    // -------------------------------------------------------------------------

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'connection';
        $tabs = array(
            'connection'     => 'Connection',
            'logging'        => 'Logging',
            'backup'         => 'Backup',
            'alerts'         => 'Alerts',
            'email_controls' => 'Email Controls',
            'misc'           => 'Misc',
        );

        ?>
        <div class="wrap">
            <h1>FisHotel SMTP Settings</h1>

            <nav class="nav-tab-wrapper fhsmtp-tabs">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', $slug, admin_url( 'options-general.php?page=fishotel-smtp' ) ) ); ?>"
                       class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=fhsmtp-email-logs' ) ); ?>"
                   class="nav-tab">
                    Email Logs
                </a>
            </nav>

            <?php
            switch ( $active_tab ) {
                case 'logging':
                    $this->render_logging_tab();
                    break;
                case 'backup':
                    $this->render_backup_tab();
                    break;
                case 'alerts':
                    $this->render_alerts_tab();
                    break;
                case 'email_controls':
                    $this->render_email_controls_tab();
                    break;
                case 'misc':
                    $this->render_misc_tab();
                    break;
                default:
                    $this->render_connection_tab();
                    break;
            }
            ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Connection Tab
    // -------------------------------------------------------------------------

    private function render_connection_tab() {
        $settings = get_option( 'fhsmtp_settings', array() );
        $regions  = FHSMTP_Mailer::get_ses_regions();
        $stats    = get_option( 'fhsmtp_connection_stats', array() );

        $has_const_user = defined( 'FHSMTP_SMTP_USER' );
        $has_const_pass = defined( 'FHSMTP_SMTP_PASS' );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'fhsmtp_connection' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Connection Status</th>
                    <td>
                        <?php
                        $primary_total = ( $stats['primary_success'] ?? 0 ) + ( $stats['primary_fail'] ?? 0 );
                        if ( $primary_total > 0 ) {
                            $rate = round( ( $stats['primary_success'] / $primary_total ) * 100, 1 );
                            $dot  = $rate > 90 ? 'green' : ( $rate > 50 ? 'yellow' : 'red' );
                            echo '<span class="fhsmtp-status-dot fhsmtp-status-' . $dot . '"></span> ';
                            echo esc_html( $rate . '% success (' . $stats['primary_success'] . ' sent, ' . $stats['primary_fail'] . ' failed)' );
                        } else {
                            echo '<span class="fhsmtp-status-dot fhsmtp-status-grey"></span> No emails sent yet';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_region">AWS SES Region</label></th>
                    <td>
                        <select name="fhsmtp_settings[region]" id="fhsmtp_region">
                            <option value="">-- Custom SMTP (not SES) --</option>
                            <?php foreach ( $regions as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['region'] ?? '', $key ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Select a region to auto-fill the SES SMTP host, or choose Custom to enter your own host.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_host">SMTP Host</label></th>
                    <td>
                        <input type="text" name="fhsmtp_settings[smtp_host]" id="fhsmtp_host"
                               value="<?php echo esc_attr( $settings['smtp_host'] ?? '' ); ?>"
                               class="regular-text" <?php echo defined( 'FHSMTP_SMTP_HOST' ) ? 'disabled' : ''; ?>>
                        <?php if ( defined( 'FHSMTP_SMTP_HOST' ) ) : ?>
                            <p class="description">Defined in wp-config.php</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_port">SMTP Port</label></th>
                    <td>
                        <input type="number" name="fhsmtp_settings[smtp_port]" id="fhsmtp_port"
                               value="<?php echo esc_attr( $settings['smtp_port'] ?? '587' ); ?>"
                               class="small-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Encryption</th>
                    <td>
                        <label><input type="radio" name="fhsmtp_settings[smtp_encryption]" value="tls" <?php checked( $settings['smtp_encryption'] ?? 'tls', 'tls' ); ?>> TLS</label>&nbsp;&nbsp;
                        <label><input type="radio" name="fhsmtp_settings[smtp_encryption]" value="ssl" <?php checked( $settings['smtp_encryption'] ?? '', 'ssl' ); ?>> SSL</label>&nbsp;&nbsp;
                        <label><input type="radio" name="fhsmtp_settings[smtp_encryption]" value="none" <?php checked( $settings['smtp_encryption'] ?? '', 'none' ); ?>> None</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_user">SMTP Username</label></th>
                    <td>
                        <input type="text" name="fhsmtp_settings[smtp_username]" id="fhsmtp_user"
                               value="<?php echo esc_attr( $has_const_user ? '********' : ( $settings['smtp_username'] ?? '' ) ); ?>"
                               class="regular-text" <?php echo $has_const_user ? 'disabled' : ''; ?>>
                        <?php if ( $has_const_user ) : ?>
                            <p class="description">Defined in wp-config.php</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_pass">SMTP Password</label></th>
                    <td>
                        <input type="password" name="fhsmtp_settings[smtp_password]" id="fhsmtp_pass"
                               value="" placeholder="<?php echo ! empty( $settings['smtp_password'] ) || $has_const_pass ? '********' : ''; ?>"
                               class="regular-text" <?php echo $has_const_pass ? 'disabled' : ''; ?>>
                        <?php if ( $has_const_pass ) : ?>
                            <p class="description">Defined in wp-config.php</p>
                        <?php else : ?>
                            <p class="description">Leave blank to keep current password.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_from_email">From Email</label></th>
                    <td>
                        <input type="email" name="fhsmtp_settings[from_email]" id="fhsmtp_from_email"
                               value="<?php echo esc_attr( $settings['from_email'] ?? '' ); ?>"
                               class="regular-text">
                        <p class="description">Must be a verified sender in Amazon SES.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_from_name">From Name</label></th>
                    <td>
                        <input type="text" name="fhsmtp_settings[from_name]" id="fhsmtp_from_name"
                               value="<?php echo esc_attr( $settings['from_name'] ?? '' ); ?>"
                               class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Force From Email</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fhsmtp_settings[force_from_email]" value="1"
                                <?php checked( $settings['force_from_email'] ?? '0', '1' ); ?>>
                            Force the From Email and From Name for all outgoing emails
                        </label>
                        <p class="description">If checked, the From Email and From Name above will be used for <strong>all</strong> emails, ignoring values set by other plugins.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Connection Settings' ); ?>
        </form>

        <hr>
        <h2>Send Test Email</h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="fhsmtp_test_to">Recipient</label></th>
                <td>
                    <input type="email" id="fhsmtp_test_to" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text">
                    <button type="button" id="fhsmtp-send-test" class="button button-secondary">Send Test Email</button>
                    <span id="fhsmtp-test-result"></span>
                </td>
            </tr>
        </table>
        <?php
    }

    // -------------------------------------------------------------------------
    // Logging Tab
    // -------------------------------------------------------------------------

    private function render_logging_tab() {
        $settings = get_option( 'fhsmtp_log_settings', array() );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'fhsmtp_logging' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Enable Logging</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fhsmtp_log_settings[logging_enabled]" value="1"
                                <?php checked( $settings['logging_enabled'] ?? '1', '1' ); ?>>
                            Log all outgoing emails
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_retention">Retention Period</label></th>
                    <td>
                        <input type="number" name="fhsmtp_log_settings[retention_days]" id="fhsmtp_retention"
                               value="<?php echo esc_attr( $settings['retention_days'] ?? '30' ); ?>"
                               class="small-text" min="1" max="365"> days
                        <p class="description">Logs older than this will be automatically deleted daily.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Logging Settings' ); ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Backup Tab
    // -------------------------------------------------------------------------

    private function render_backup_tab() {
        $settings = get_option( 'fhsmtp_backup_settings', array() );
        $stats    = get_option( 'fhsmtp_connection_stats', array() );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'fhsmtp_backup' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Enable Backup</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fhsmtp_backup_settings[backup_enabled]" value="1"
                                <?php checked( $settings['backup_enabled'] ?? '0', '1' ); ?>>
                            Enable backup SMTP connection for automatic failover
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Backup Status</th>
                    <td>
                        <?php
                        $backup_total = ( $stats['backup_success'] ?? 0 ) + ( $stats['backup_fail'] ?? 0 );
                        if ( $backup_total > 0 ) {
                            $rate = round( ( $stats['backup_success'] / $backup_total ) * 100, 1 );
                            $dot  = $rate > 90 ? 'green' : ( $rate > 50 ? 'yellow' : 'red' );
                            echo '<span class="fhsmtp-status-dot fhsmtp-status-' . $dot . '"></span> ';
                            echo esc_html( $rate . '% success (' . $stats['backup_success'] . ' sent, ' . $stats['backup_fail'] . ' failed)' );
                        } else {
                            echo '<span class="fhsmtp-status-dot fhsmtp-status-grey"></span> Backup not yet used';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_backup_host">SMTP Host</label></th>
                    <td>
                        <input type="text" name="fhsmtp_backup_settings[backup_host]" id="fhsmtp_backup_host"
                               value="<?php echo esc_attr( $settings['backup_host'] ?? '' ); ?>"
                               class="regular-text" placeholder="smtp.example.com">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_backup_port">SMTP Port</label></th>
                    <td>
                        <input type="number" name="fhsmtp_backup_settings[backup_port]" id="fhsmtp_backup_port"
                               value="<?php echo esc_attr( $settings['backup_port'] ?? '587' ); ?>"
                               class="small-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row">Encryption</th>
                    <td>
                        <label><input type="radio" name="fhsmtp_backup_settings[backup_encryption]" value="tls" <?php checked( $settings['backup_encryption'] ?? 'tls', 'tls' ); ?>> TLS</label>&nbsp;&nbsp;
                        <label><input type="radio" name="fhsmtp_backup_settings[backup_encryption]" value="ssl" <?php checked( $settings['backup_encryption'] ?? '', 'ssl' ); ?>> SSL</label>&nbsp;&nbsp;
                        <label><input type="radio" name="fhsmtp_backup_settings[backup_encryption]" value="none" <?php checked( $settings['backup_encryption'] ?? '', 'none' ); ?>> None</label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_backup_user">Username</label></th>
                    <td>
                        <input type="text" name="fhsmtp_backup_settings[backup_username]" id="fhsmtp_backup_user"
                               value="<?php echo esc_attr( $settings['backup_username'] ?? '' ); ?>"
                               class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_backup_pass">Password</label></th>
                    <td>
                        <input type="password" name="fhsmtp_backup_settings[backup_password]" id="fhsmtp_backup_pass"
                               value="" placeholder="<?php echo ! empty( $settings['backup_password'] ) ? '********' : ''; ?>"
                               class="regular-text">
                        <p class="description">Leave blank to keep current password.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Backup Settings' ); ?>
        </form>

        <hr>
        <h2>Test Backup Connection</h2>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="fhsmtp_backup_test_to">Recipient</label></th>
                <td>
                    <input type="email" id="fhsmtp_backup_test_to" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text">
                    <button type="button" id="fhsmtp-test-backup" class="button button-secondary">Test Backup Connection</button>
                    <span id="fhsmtp-backup-test-result"></span>
                </td>
            </tr>
        </table>
        <?php
    }

    // -------------------------------------------------------------------------
    // Alerts Tab
    // -------------------------------------------------------------------------

    private function render_alerts_tab() {
        $settings = get_option( 'fhsmtp_alert_settings', array() );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'fhsmtp_alerts' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Enable Failure Alerts</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fhsmtp_alert_settings[alerts_enabled]" value="1"
                                <?php checked( $settings['alerts_enabled'] ?? '0', '1' ); ?>>
                            Send alerts when email sending fails
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_threshold">Failure Threshold</label></th>
                    <td>
                        <input type="number" name="fhsmtp_alert_settings[threshold]" id="fhsmtp_threshold"
                               value="<?php echo esc_attr( $settings['threshold'] ?? '3' ); ?>"
                               class="small-text" min="1"> consecutive failures
                        <p class="description">Alert triggers after this many consecutive failures. Rate limited to 1 alert per 15 minutes.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_alert_emails">Email Recipients</label></th>
                    <td>
                        <input type="text" name="fhsmtp_alert_settings[alert_email_recipients]" id="fhsmtp_alert_emails"
                               value="<?php echo esc_attr( $settings['alert_email_recipients'] ?? '' ); ?>"
                               class="regular-text" placeholder="admin@example.com, ops@example.com">
                        <p class="description">Comma-separated email addresses. Alert emails are sent via PHP mail() to avoid loops.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_slack_url">Slack Webhook URL</label></th>
                    <td>
                        <input type="url" name="fhsmtp_alert_settings[slack_webhook_url]" id="fhsmtp_slack_url"
                               value="<?php echo esc_attr( $settings['slack_webhook_url'] ?? '' ); ?>"
                               class="regular-text" placeholder="https://hooks.slack.com/services/...">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_discord_url">Discord Webhook URL</label></th>
                    <td>
                        <input type="url" name="fhsmtp_alert_settings[discord_webhook_url]" id="fhsmtp_discord_url"
                               value="<?php echo esc_attr( $settings['discord_webhook_url'] ?? '' ); ?>"
                               class="regular-text" placeholder="https://discord.com/api/webhooks/...">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_custom_url">Custom Webhook URL</label></th>
                    <td>
                        <input type="url" name="fhsmtp_alert_settings[custom_webhook_url]" id="fhsmtp_custom_url"
                               value="<?php echo esc_attr( $settings['custom_webhook_url'] ?? '' ); ?>"
                               class="regular-text" placeholder="https://your-endpoint.com/webhook">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="fhsmtp_custom_tpl">Custom Webhook Template</label></th>
                    <td>
                        <textarea name="fhsmtp_alert_settings[custom_webhook_template]" id="fhsmtp_custom_tpl"
                                  rows="5" class="large-text code"
                                  placeholder='{"text":"SMTP failure on {{site_name}}: {{error}}", "count": {{count}}}'><?php echo esc_textarea( $settings['custom_webhook_template'] ?? '' ); ?></textarea>
                        <p class="description">JSON template. Variables: <code>{{site_name}}</code>, <code>{{error}}</code>, <code>{{count}}</code>, <code>{{timestamp}}</code>, <code>{{recipient}}</code></p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Alert Settings' ); ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Email Controls Tab
    // -------------------------------------------------------------------------

    private function render_email_controls_tab() {
        $s = get_option( 'fhsmtp_email_controls', array() );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'fhsmtp_email_controls_group' ); ?>

            <p class="description" style="margin-bottom:15px;">
                Control which WordPress notification emails are sent. Unchecked emails will be silently blocked.
            </p>

            <div class="fhsmtp-email-controls">

                <h3>Comments</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Awaiting Moderation</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[notify_moderator]" value="1" <?php checked( $s['notify_moderator'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">Comment is awaiting moderation. Sent to the site admin and post author if they can edit comments.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Published</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[notify_post_author]" value="1" <?php checked( $s['notify_post_author'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">Comment has been published. Sent to the post author.</p>
                        </td>
                    </tr>
                </table>

                <h3>Change of Admin Email</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Site Admin Email Change Attempt</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[admin_email_change_attempt]" value="1" <?php checked( $s['admin_email_change_attempt'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">Change of site admin email address was attempted. Sent to the proposed new email address.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Site Admin Email Changed</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[admin_email_changed]" value="1" <?php checked( $s['admin_email_changed'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">Site admin email address was changed. Sent to the old site admin email address.</p>
                        </td>
                    </tr>
                </table>

                <h3>Change of User Email or Password</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Reset Password Request</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[reset_password_request]" value="1" <?php checked( $s['reset_password_request'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">User requested a password reset via "Lost your password?". Sent to the user.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Password Reset Successfully</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[password_reset_success]" value="1" <?php checked( $s['password_reset_success'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">User reset their password from the password reset link. Sent to the site admin.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Password Changed</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[password_changed]" value="1" <?php checked( $s['password_changed'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">User changed their password. Sent to the user.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Email Change Attempt</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[email_change_attempt]" value="1" <?php checked( $s['email_change_attempt'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">User attempted to change their email address. Sent to the proposed new email address.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Email Changed</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[email_changed]" value="1" <?php checked( $s['email_changed'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">User changed their email address. Sent to the user.</p>
                        </td>
                    </tr>
                </table>

                <h3>Personal Data Requests</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">User Confirmed Export / Erasure Request</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[user_confirmed_action]" value="1" <?php checked( $s['user_confirmed_action'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">User clicked a confirmation link in personal data export or erasure request email. Sent to the site admin.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Admin Erased Data</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[admin_erased_data]" value="1" <?php checked( $s['admin_erased_data'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">Site admin clicked "Erase Personal Data" button next to a confirmed data erasure request. Sent to the requester.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Admin Sent Link to Export Data</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[admin_sent_export_link]" value="1" <?php checked( $s['admin_sent_export_link'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">Site admin clicked "Email Data" button next to a confirmed data export request. Sent to the requester. <strong>Disabling this will prevent users from receiving their data export.</strong></p>
                        </td>
                    </tr>
                </table>

                <h3>Automatic Updates</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Plugin Status</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[auto_plugin_update_email]" value="1" <?php checked( $s['auto_plugin_update_email'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">Completion or failure of a background automatic plugin update. Sent to the site admin.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Theme Status</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[auto_theme_update_email]" value="1" <?php checked( $s['auto_theme_update_email'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">Completion or failure of a background automatic theme update. Sent to the site admin.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WP Core Status</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[auto_core_update_email]" value="1" <?php checked( $s['auto_core_update_email'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">Completion or failure of a background automatic core update. Sent to the site admin.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Full Log</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[auto_update_debug_email]" value="1" <?php checked( $s['auto_update_debug_email'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">Full log of background update results. Only sent when using a development version of WordPress.</p>
                        </td>
                    </tr>
                </table>

                <h3>New User</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Created (Admin)</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[new_user_admin_notify]" value="1" <?php checked( $s['new_user_admin_notify'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">A new user was created. Sent to the site admin.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Created (User)</th>
                        <td>
                            <label><input type="checkbox" name="fhsmtp_email_controls[new_user_user_notify]" value="1" <?php checked( $s['new_user_user_notify'] ?? '1', '1' ); ?>> Enable</label>
                            <p class="description">A new user was created. Sent to the new user.</p>
                        </td>
                    </tr>
                </table>

            </div>

            <?php submit_button( 'Save Email Controls' ); ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Misc Tab
    // -------------------------------------------------------------------------

    private function render_misc_tab() {
        $settings = get_option( 'fhsmtp_misc_settings', array() );
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'fhsmtp_misc' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">Do Not Send</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fhsmtp_misc_settings[do_not_send]" value="1"
                                <?php checked( $settings['do_not_send'] ?? '0', '1' ); ?>>
                            Stop sending all emails
                        </label>
                        <p class="description fhsmtp-warning">Warning: This will prevent ALL WordPress emails from being sent, including password resets and admin notifications. Test emails sent from FisHotel SMTP settings will still work.</p>
                        <p class="description">Some plugins (like BuddyPress and Events Manager) use their own email delivery solutions and may not be affected by this option.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Hide Email Delivery Errors</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fhsmtp_misc_settings[hide_delivery_errors]" value="1"
                                <?php checked( $settings['hide_delivery_errors'] ?? '0', '1' ); ?>>
                            Hide warnings alerting of email delivery errors
                        </label>
                        <p class="description">This is not recommended and should only be done for staging or development sites.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Hide Dashboard Widget</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fhsmtp_misc_settings[hide_dashboard_widget]" value="1"
                                <?php checked( $settings['hide_dashboard_widget'] ?? '0', '1' ); ?>>
                            Hide the FisHotel SMTP Dashboard Widget
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Uninstall FisHotel SMTP</th>
                    <td>
                        <label>
                            <input type="checkbox" name="fhsmtp_misc_settings[remove_data_on_delete]" value="1"
                                <?php checked( $settings['remove_data_on_delete'] ?? '0', '1' ); ?>>
                            Remove ALL FisHotel SMTP data upon plugin deletion
                        </label>
                        <p class="description fhsmtp-warning">All settings, email logs, and database tables will be permanently removed. This cannot be undone.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Save Misc Settings' ); ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Email Logs Page
    // -------------------------------------------------------------------------

    public function render_logs_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $log_table = new FHSMTP_Log_Table();
        $log_table->prepare_items();

        ?>
        <div class="wrap">
            <h1>
                FisHotel SMTP Email Logs
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=fishotel-smtp' ) ); ?>" class="page-title-action">Settings</a>
                <a href="#" id="fhsmtp-export-csv" class="page-title-action">Export CSV</a>
            </h1>

            <form method="get">
                <input type="hidden" name="page" value="fhsmtp-email-logs">
                <?php
                $log_table->search_box( 'Search Emails', 'fhsmtp-search' );
                $log_table->display();
                ?>
            </form>
        </div>

        <!-- Email Detail Modal -->
        <div id="fhsmtp-email-modal" class="fhsmtp-modal" style="display:none;">
            <div class="fhsmtp-modal-content">
                <span class="fhsmtp-modal-close">&times;</span>
                <h2>Email Details</h2>
                <div id="fhsmtp-modal-body"></div>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX Handlers
    // -------------------------------------------------------------------------

    public function ajax_send_test() {
        check_ajax_referer( 'fhsmtp_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $to = sanitize_email( $_POST['to'] ?? '' );
        if ( ! is_email( $to ) ) {
            wp_send_json_error( 'Invalid email address.' );
        }

        $result = FHSMTP_Mailer::send_test_email( $to, 'primary' );
        if ( $result['success'] ) {
            wp_send_json_success( $result['message'] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    public function ajax_test_backup() {
        check_ajax_referer( 'fhsmtp_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $to = sanitize_email( $_POST['to'] ?? '' );
        if ( ! is_email( $to ) ) {
            wp_send_json_error( 'Invalid email address.' );
        }

        $result = FHSMTP_Failover::send_with_backup_only( $to );
        if ( $result['success'] ) {
            wp_send_json_success( $result['message'] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    public function ajax_get_log_detail() {
        check_ajax_referer( 'fhsmtp_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id  = absint( $_POST['log_id'] ?? 0 );
        $log = FHSMTP_Logger::get_log( $id );

        if ( ! $log ) {
            wp_send_json_error( 'Log not found.' );
        }

        wp_send_json_success( array(
            'id'              => $log->id,
            'created_at'      => $log->created_at,
            'to_email'        => esc_html( $log->to_email ),
            'from_email'      => esc_html( $log->from_email ),
            'subject'         => esc_html( $log->subject ),
            'message'         => esc_html( $log->message ),
            'headers'         => esc_html( $log->headers ),
            'attachments'     => esc_html( $log->attachments ),
            'status'          => $log->status,
            'error_message'   => esc_html( $log->error_message ),
            'connection_type' => esc_html( $log->connection_type ),
        ) );
    }

    public function ajax_resend_email() {
        check_ajax_referer( 'fhsmtp_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $id  = absint( $_POST['log_id'] ?? 0 );
        $log = FHSMTP_Logger::get_log( $id );

        if ( ! $log ) {
            wp_send_json_error( 'Log not found.' );
        }

        $headers = ! empty( $log->headers ) ? explode( "\n", $log->headers ) : array();
        $result  = wp_mail( $log->to_email, $log->subject, $log->message, $headers );

        if ( $result ) {
            wp_send_json_success( 'Email resent successfully.' );
        } else {
            wp_send_json_error( 'Failed to resend email.' );
        }
    }
}
