<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FHSMTP_Alerts {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'fhsmtp_email_failed', array( $this, 'on_failure' ) );
        add_action( 'fhsmtp_email_succeeded', array( $this, 'on_success' ) );
        add_action( 'admin_notices', array( $this, 'unconfigured_alerts_notice' ) );
    }

    /**
     * On any email failure, increment consecutive failure counter and maybe alert.
     */
    public function on_failure( $connection ) {
        $settings = get_option( 'fhsmtp_alert_settings', array() );
        if ( empty( $settings['alerts_enabled'] ) || $settings['alerts_enabled'] !== '1' ) {
            return;
        }

        $count = (int) get_option( 'fhsmtp_consecutive_failures', 0 );
        $count++;
        update_option( 'fhsmtp_consecutive_failures', $count );

        $threshold = max( 1, (int) ( $settings['threshold'] ?? 3 ) );

        if ( $count >= $threshold ) {
            $this->maybe_send_alerts( $count, $connection );
        }
    }

    /**
     * On success, reset the consecutive failure counter.
     */
    public function on_success( $connection ) {
        update_option( 'fhsmtp_consecutive_failures', 0 );
    }

    /**
     * Send alerts if not rate-limited (max 1 per 15 minutes).
     */
    private function maybe_send_alerts( $count, $connection ) {
        $last_alert = get_transient( 'fhsmtp_last_alert_time' );
        if ( $last_alert ) {
            return; // Rate limited
        }

        // Set rate limit: 15 minutes
        set_transient( 'fhsmtp_last_alert_time', time(), 15 * MINUTE_IN_SECONDS );

        $settings = get_option( 'fhsmtp_alert_settings', array() );

        // Get last error from logs
        global $wpdb;
        $table = FHSMTP_Logger::get_table_name();
        $last_log = $wpdb->get_row( "SELECT * FROM $table WHERE status = 'failed' ORDER BY created_at DESC LIMIT 1" );

        $context = array(
            'site_name' => get_bloginfo( 'name' ),
            'count'     => $count,
            'error'     => $last_log ? $last_log->error_message : 'Unknown error',
            'recipient' => $last_log ? $last_log->to_email : 'Unknown',
            'timestamp' => current_time( 'mysql' ),
            'logs_url'  => admin_url( 'options-general.php?page=fhsmtp-email-logs' ),
            'connection'=> $connection,
        );

        // Send via each enabled method
        if ( ! empty( $settings['alert_email_recipients'] ) ) {
            $this->send_email_alert( $settings['alert_email_recipients'], $context );
        }
        if ( ! empty( $settings['slack_webhook_url'] ) ) {
            $this->send_slack_alert( $settings['slack_webhook_url'], $context );
        }
        if ( ! empty( $settings['discord_webhook_url'] ) ) {
            $this->send_discord_alert( $settings['discord_webhook_url'], $context );
        }
        if ( ! empty( $settings['custom_webhook_url'] ) ) {
            $this->send_custom_webhook( $settings['custom_webhook_url'], $settings['custom_webhook_template'] ?? '', $context );
        }
    }

    /**
     * Send alert via PHP mail() to avoid using the broken SMTP connection.
     */
    private function send_email_alert( $recipients, $context ) {
        $emails  = array_map( 'trim', explode( ',', $recipients ) );
        $subject = sprintf( 'SMTP Alert: Email Sending Failed on %s', $context['site_name'] );
        $body    = sprintf(
            "FisHotel SMTP Failure Alert\n\n" .
            "Site: %s\n" .
            "Consecutive Failures: %d\n" .
            "Connection: %s\n" .
            "Last Error: %s\n" .
            "Failed Recipient: %s\n" .
            "Time: %s\n\n" .
            "View logs: %s",
            $context['site_name'],
            $context['count'],
            $context['connection'],
            $context['error'],
            $context['recipient'],
            $context['timestamp'],
            $context['logs_url']
        );

        $headers = 'From: ' . get_option( 'admin_email' ) . "\r\n";

        foreach ( $emails as $email ) {
            if ( is_email( $email ) ) {
                // Use PHP mail() directly to bypass our SMTP (which is failing)
                mail( $email, $subject, $body, $headers );
            }
        }
    }

    /**
     * Slack Block Kit alert.
     */
    private function send_slack_alert( $webhook_url, $context ) {
        $payload = array(
            'blocks' => array(
                array(
                    'type' => 'header',
                    'text' => array(
                        'type' => 'plain_text',
                        'text' => 'SMTP Failure Alert',
                    ),
                ),
                array(
                    'type' => 'section',
                    'fields' => array(
                        array( 'type' => 'mrkdwn', 'text' => '*Site:*\n' . $context['site_name'] ),
                        array( 'type' => 'mrkdwn', 'text' => '*Failures:*\n' . $context['count'] . ' consecutive' ),
                        array( 'type' => 'mrkdwn', 'text' => '*Connection:*\n' . ucfirst( $context['connection'] ) ),
                        array( 'type' => 'mrkdwn', 'text' => '*Last Recipient:*\n' . $context['recipient'] ),
                    ),
                ),
                array(
                    'type' => 'section',
                    'text' => array(
                        'type' => 'mrkdwn',
                        'text' => '*Error:*\n```' . $context['error'] . '```',
                    ),
                ),
                array(
                    'type' => 'actions',
                    'elements' => array(
                        array(
                            'type' => 'button',
                            'text' => array( 'type' => 'plain_text', 'text' => 'View Logs' ),
                            'url'  => $context['logs_url'],
                        ),
                    ),
                ),
            ),
        );

        wp_remote_post( $webhook_url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 10,
        ) );
    }

    /**
     * Discord embed alert.
     */
    private function send_discord_alert( $webhook_url, $context ) {
        $payload = array(
            'embeds' => array(
                array(
                    'title'       => 'SMTP Failure Alert',
                    'description' => sprintf( '%d consecutive email failures on **%s**', $context['count'], $context['site_name'] ),
                    'color'       => 15158332, // Red
                    'fields'      => array(
                        array( 'name' => 'Connection', 'value' => ucfirst( $context['connection'] ), 'inline' => true ),
                        array( 'name' => 'Failed Recipient', 'value' => $context['recipient'], 'inline' => true ),
                        array( 'name' => 'Error', 'value' => '```' . substr( $context['error'], 0, 500 ) . '```' ),
                    ),
                    'timestamp' => gmdate( 'c' ),
                    'footer'    => array( 'text' => 'FisHotel SMTP' ),
                ),
            ),
        );

        wp_remote_post( $webhook_url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 10,
        ) );
    }

    /**
     * Custom webhook with template variable substitution.
     */
    private function send_custom_webhook( $webhook_url, $template, $context ) {
        if ( empty( $template ) ) {
            $template = wp_json_encode( array(
                'event'     => 'smtp_failure',
                'site_name' => '{{site_name}}',
                'error'     => '{{error}}',
                'count'     => '{{count}}',
                'timestamp' => '{{timestamp}}',
            ) );
        }

        $replacements = array(
            '{{site_name}}' => $context['site_name'],
            '{{error}}'     => $context['error'],
            '{{count}}'     => (string) $context['count'],
            '{{timestamp}}' => $context['timestamp'],
            '{{recipient}}' => $context['recipient'],
        );

        $body = str_replace( array_keys( $replacements ), array_values( $replacements ), $template );

        wp_remote_post( $webhook_url, array(
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => $body,
            'timeout' => 10,
        ) );
    }

    /**
     * Show admin notice if failures are happening but alerts aren't configured.
     */
    public function unconfigured_alerts_notice() {
        $failures = (int) get_option( 'fhsmtp_consecutive_failures', 0 );
        if ( $failures < 3 ) {
            return;
        }

        $settings = get_option( 'fhsmtp_alert_settings', array() );
        if ( ! empty( $settings['alerts_enabled'] ) && $settings['alerts_enabled'] === '1' ) {
            return;
        }

        $url = admin_url( 'options-general.php?page=fishotel-smtp&tab=alerts' );
        echo '<div class="notice notice-error"><p>';
        echo '<strong>FisHotel SMTP:</strong> ' . esc_html( $failures ) . ' consecutive email failures detected. ';
        echo '<a href="' . esc_url( $url ) . '">Configure failure alerts</a> to get notified.';
        echo '</p></div>';
    }
}
