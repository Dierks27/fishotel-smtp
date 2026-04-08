<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FHSMTP_Logger {

    private static $instance = null;
    private static $current_email = array();

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $log_settings = get_option( 'fhsmtp_log_settings', array() );
        if ( empty( $log_settings['logging_enabled'] ) || $log_settings['logging_enabled'] !== '1' ) {
            return;
        }

        add_filter( 'wp_mail', array( $this, 'capture_email_before_send' ), 999 );
        add_action( 'wp_mail_succeeded', array( $this, 'log_success' ) );
        add_action( 'wp_mail_failed', array( $this, 'log_failure' ) );
        add_action( 'fhsmtp_cleanup_logs', array( $this, 'cleanup_old_logs' ) );
    }

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'fhsmtp_email_logs';
    }

    public static function create_table() {
        global $wpdb;
        $table   = self::get_table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            to_email varchar(255) NOT NULL DEFAULT '',
            from_email varchar(255) NOT NULL DEFAULT '',
            subject varchar(255) NOT NULL DEFAULT '',
            message longtext NOT NULL,
            headers text NOT NULL,
            attachments text NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'sent',
            error_message text NOT NULL,
            connection_type varchar(20) NOT NULL DEFAULT 'primary',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY status (status),
            KEY created_at (created_at),
            KEY to_email (to_email)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Capture email data before sending via wp_mail filter.
     * This filter receives and must return the wp_mail args array.
     */
    public function capture_email_before_send( $args ) {
        self::$current_email = array(
            'to'          => is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : $args['to'],
            'subject'     => $args['subject'] ?? '',
            'message'     => $args['message'] ?? '',
            'headers'     => is_array( $args['headers'] ?? '' ) ? implode( "\n", $args['headers'] ) : ( $args['headers'] ?? '' ),
            'attachments' => is_array( $args['attachments'] ?? array() ) ? implode( ', ', $args['attachments'] ) : '',
        );
        return $args;
    }

    public function log_success( $mail_data ) {
        if ( empty( self::$current_email ) ) {
            return;
        }

        $settings = FHSMTP_Mailer::instance()->get_settings();
        $from     = $settings['from_email'] ?? get_option( 'admin_email' );

        $connection_type = 'primary';
        if ( ! empty( $GLOBALS['fhsmtp_connection_used'] ) ) {
            $connection_type = $GLOBALS['fhsmtp_connection_used'];
        }

        $this->insert_log(
            self::$current_email['to'],
            $from,
            self::$current_email['subject'],
            self::$current_email['message'],
            self::$current_email['headers'],
            self::$current_email['attachments'],
            'sent',
            '',
            $connection_type
        );

        self::$current_email = array();
    }

    public function log_failure( $wp_error ) {
        if ( ! is_wp_error( $wp_error ) ) {
            return;
        }

        $error_data = $wp_error->get_error_data();
        $to         = $error_data['to'][0] ?? ( self::$current_email['to'] ?? '' );
        $subject    = $error_data['subject'] ?? ( self::$current_email['subject'] ?? '' );
        $from       = self::$current_email['from'] ?? get_option( 'admin_email' );

        $connection_type = 'primary';
        if ( ! empty( $GLOBALS['fhsmtp_connection_used'] ) ) {
            $connection_type = $GLOBALS['fhsmtp_connection_used'];
        }

        $this->insert_log(
            is_array( $to ) ? implode( ', ', $to ) : $to,
            $from,
            $subject,
            self::$current_email['message'] ?? '',
            self::$current_email['headers'] ?? '',
            self::$current_email['attachments'] ?? '',
            'failed',
            $wp_error->get_error_message(),
            $connection_type
        );

        self::$current_email = array();
    }

    private function insert_log( $to, $from, $subject, $message, $headers, $attachments, $status, $error, $connection_type ) {
        global $wpdb;
        $wpdb->insert(
            self::get_table_name(),
            array(
                'to_email'        => $to,
                'from_email'      => $from,
                'subject'         => $subject,
                'message'         => $message,
                'headers'         => $headers,
                'attachments'     => $attachments,
                'status'          => $status,
                'error_message'   => $error,
                'connection_type' => $connection_type,
                'created_at'      => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    public function cleanup_old_logs() {
        global $wpdb;
        $log_settings   = get_option( 'fhsmtp_log_settings', array() );
        $retention_days  = absint( $log_settings['retention_days'] ?? 30 );

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM " . self::get_table_name() . " WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $retention_days
        ) );
    }

    /**
     * Get a single log entry by ID.
     */
    public static function get_log( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::get_table_name() . " WHERE id = %d",
            $id
        ) );
    }

    /**
     * Delete log entries by IDs.
     */
    public static function delete_logs( $ids ) {
        global $wpdb;
        $ids        = array_map( 'absint', (array) $ids );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM " . self::get_table_name() . " WHERE id IN ($placeholders)",
            ...$ids
        ) );
    }

    /**
     * Get stats for the last 24 hours.
     */
    public static function get_24h_stats() {
        global $wpdb;
        $table = self::get_table_name();
        $since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

        $total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE created_at >= %s", $since ) );
        $sent   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = 'sent' AND created_at >= %s", $since ) );
        $failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = 'failed' AND created_at >= %s", $since ) );

        return array(
            'total'        => $total,
            'sent'         => $sent,
            'failed'       => $failed,
            'success_rate' => $total > 0 ? round( ( $sent / $total ) * 100, 1 ) : 0,
        );
    }
}
