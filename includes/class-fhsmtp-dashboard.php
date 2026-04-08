<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FHSMTP_Dashboard {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
        add_action( 'wp_ajax_fhsmtp_dashboard_test', array( $this, 'ajax_quick_test' ) );
    }

    public function add_dashboard_widget() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        wp_add_dashboard_widget(
            'fhsmtp_dashboard_widget',
            'FisHotel SMTP',
            array( $this, 'render_widget' )
        );
    }

    public function render_widget() {
        $stats      = FHSMTP_Logger::get_24h_stats();
        $conn_stats = get_option( 'fhsmtp_connection_stats', array() );
        $backup     = get_option( 'fhsmtp_backup_settings', array() );

        // Primary health
        $primary_total = ( $conn_stats['primary_success'] ?? 0 ) + ( $conn_stats['primary_fail'] ?? 0 );
        $primary_rate  = $primary_total > 0 ? round( ( $conn_stats['primary_success'] / $primary_total ) * 100, 1 ) : 0;
        $primary_dot   = $primary_total === 0 ? 'grey' : ( $primary_rate > 90 ? 'green' : ( $primary_rate > 50 ? 'yellow' : 'red' ) );

        ?>
        <div class="fhsmtp-widget">
            <h3>Last 24 Hours</h3>
            <div class="fhsmtp-widget-stats">
                <div class="fhsmtp-widget-stat">
                    <span class="fhsmtp-widget-number"><?php echo esc_html( $stats['sent'] ); ?></span>
                    <span class="fhsmtp-widget-label">Sent</span>
                </div>
                <div class="fhsmtp-widget-stat">
                    <span class="fhsmtp-widget-number fhsmtp-text-red"><?php echo esc_html( $stats['failed'] ); ?></span>
                    <span class="fhsmtp-widget-label">Failed</span>
                </div>
                <div class="fhsmtp-widget-stat">
                    <span class="fhsmtp-widget-number"><?php echo esc_html( $stats['success_rate'] ); ?>%</span>
                    <span class="fhsmtp-widget-label">Success</span>
                </div>
            </div>

            <h3>Connection Health</h3>
            <p>
                <span class="fhsmtp-status-dot fhsmtp-status-<?php echo $primary_dot; ?>"></span>
                <strong>Primary:</strong>
                <?php echo $primary_total > 0 ? esc_html( $primary_rate . '%' ) : 'No data'; ?>
            </p>
            <?php if ( ! empty( $backup['backup_enabled'] ) && $backup['backup_enabled'] === '1' ) :
                $backup_total = ( $conn_stats['backup_success'] ?? 0 ) + ( $conn_stats['backup_fail'] ?? 0 );
                $backup_rate  = $backup_total > 0 ? round( ( $conn_stats['backup_success'] / $backup_total ) * 100, 1 ) : 0;
                $backup_dot   = $backup_total === 0 ? 'grey' : ( $backup_rate > 90 ? 'green' : ( $backup_rate > 50 ? 'yellow' : 'red' ) );
            ?>
                <p>
                    <span class="fhsmtp-status-dot fhsmtp-status-<?php echo $backup_dot; ?>"></span>
                    <strong>Backup:</strong>
                    <?php echo $backup_total > 0 ? esc_html( $backup_rate . '%' ) : 'Standby'; ?>
                </p>
            <?php endif; ?>

            <hr>
            <p>
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=fhsmtp-email-logs' ) ); ?>">View Email Logs</a>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url( admin_url( 'options-general.php?page=fishotel-smtp' ) ); ?>">Settings</a>
            </p>

            <div class="fhsmtp-widget-test">
                <input type="email" id="fhsmtp-dash-test-to" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="widefat" style="margin-bottom:5px;">
                <button type="button" id="fhsmtp-dash-send-test" class="button button-secondary">Quick Test Email</button>
                <span id="fhsmtp-dash-test-result"></span>
            </div>
        </div>
        <?php
    }

    public function ajax_quick_test() {
        check_ajax_referer( 'fhsmtp_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $to = sanitize_email( $_POST['to'] ?? '' );
        if ( ! is_email( $to ) ) {
            wp_send_json_error( 'Invalid email.' );
        }

        $result = FHSMTP_Mailer::send_test_email( $to );
        if ( $result['success'] ) {
            wp_send_json_success( $result['message'] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }
}
