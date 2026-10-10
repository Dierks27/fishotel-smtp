<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FHSMTP_Logger {

    private static $instance = null;
    private static $current_email = array();
    // Outcome of the most recent Retry/Resend send, recorded by log_failure() (null = no failure).
    private static $retry_outcome = null;

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
        add_action( 'fhsmtp_retry_failed_emails', array( $this, 'retry_failed_emails' ) );
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
            retry_count smallint unsigned NOT NULL DEFAULT 0,
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
        // WHY (boards-smtp-wpit-12): the backup failover re-enters wp_mail() with the SAME email;
        // keep the outer capture so the single failover row carries the full body.
        if ( ! empty( $GLOBALS['fhsmtp_failover_in_progress'] ) ) {
            return $args;
        }
        if ( ! empty( $GLOBALS['fhsmtp_is_retry'] ) ) {
            self::$current_email = array();
            return $args;
        }
        self::$current_email = array(
            'to'          => is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : $args['to'],
            'subject'     => $args['subject'] ?? '',
            'message'     => $args['message'] ?? '',
            'headers'     => is_array( $args['headers'] ?? '' ) ? implode( "\n", $args['headers'] ) : ( $args['headers'] ?? '' ),
            'attachments' => self::attachments_to_string( $args['attachments'] ?? array() ),
        );
        return $args;
    }

    /**
     * Normalise wp_mail()'s attachments argument (array, or newline-separated string) into the
     * comma-separated path list stored on the log row.
     * WHY (boards-smtp-wpit-14): Retry/Resend re-attach from this column, so every path must be stored.
     */
    private static function attachments_to_string( $attachments ) {
        if ( ! is_array( $attachments ) ) {
            $attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
        }
        $attachments = array_filter( array_map( 'trim', array_map( 'strval', $attachments ) ) );
        return implode( ', ', $attachments );
    }

    public function log_success( $mail_data ) {
        // WHY (boards-smtp-wpit-12): nested backup send succeeded; the outer wp_mail_failed
        // handler writes the single 'sent_via_backup' row for this email.
        if ( ! empty( $GLOBALS['fhsmtp_failover_in_progress'] ) ) {
            return;
        }
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

        // WHY (boards-smtp-wpit-12): this is the nested backup send failing; the outer handler
        // (same email) records the combined 'failed_both' outcome once.
        if ( ! empty( $GLOBALS['fhsmtp_failover_in_progress'] ) ) {
            return;
        }

        $error_data = $wp_error->get_error_data();
        $to         = $error_data['to'] ?? ( self::$current_email['to'] ?? '' );
        $subject    = $error_data['subject'] ?? ( self::$current_email['subject'] ?? '' );
        $from       = self::$current_email['from'] ?? get_option( 'admin_email' );
        $status     = 'failed';
        $error_msg  = $wp_error->get_error_message();

        $connection_type = 'primary';
        if ( ! empty( $GLOBALS['fhsmtp_connection_used'] ) ) {
            $connection_type = $GLOBALS['fhsmtp_connection_used'];
        }

        // WHY (boards-smtp-wpit-12): FHSMTP_Failover ran a backup send for this email; flag the
        // outcome once on this row instead of logging the nested send separately.
        if ( ! empty( $GLOBALS['fhsmtp_failover_result'] ) ) {
            $connection_type = 'backup';
            if ( 'sent' === $GLOBALS['fhsmtp_failover_result'] ) {
                $status    = 'sent_via_backup';
                $error_msg = 'Primary failed: ' . $error_msg . ' (delivered via backup SMTP)';
            } else {
                $status       = 'failed_both';
                $backup_error = $GLOBALS['fhsmtp_failover_backup_error'] ?? '';
                $error_msg    = 'Primary: ' . $error_msg . ' | Backup: ' . ( '' !== $backup_error ? $backup_error : 'unknown error' );
            }
        }

        // WHY (boards-smtp-wpit-10): a Retry/Resend updates its existing row (see
        // resend_log_entry); inserting a new, bodiless 'failed' row here made the retry
        // queue grow forever.
        if ( ! empty( $GLOBALS['fhsmtp_is_retry'] ) ) {
            self::$retry_outcome = array(
                'status'          => $status,
                'error'           => $error_msg,
                'connection_type' => $connection_type,
            );
            self::$current_email = array();
            return;
        }

        // wp_mail() attaches the full mail data to the WP_Error; use it if nothing was captured.
        $message     = self::$current_email['message'] ?? ( $error_data['message'] ?? '' );
        $headers     = self::$current_email['headers'] ?? ( is_array( $error_data['headers'] ?? '' ) ? implode( "\n", $error_data['headers'] ) : ( $error_data['headers'] ?? '' ) );
        $attachments = self::$current_email['attachments'] ?? self::attachments_to_string( $error_data['attachments'] ?? array() );

        self::$current_email = array();

        // WHY (boards-smtp-wpit-10): an empty-body failure can never be resent, but it has already
        // been counted in stats/alerts, so it must still appear in the log; resend_log_entry()
        // refuses it and the retry cron parks it at max retries instead of looping on it.

        $this->insert_log(
            is_array( $to ) ? implode( ', ', $to ) : $to,
            $from,
            $subject,
            $message,
            $headers,
            $attachments,
            $status,
            $error_msg,
            $connection_type
        );
    }

    /**
     * Statuses that count as delivered / as failed. Failover writes its own statuses
     * (sent_via_backup / failed_both) so the outcome is visible in the log.
     */
    public static function sent_statuses() {
        return array( 'sent', 'sent_via_backup' );
    }

    public static function failed_statuses() {
        return array( 'failed', 'failed_both' );
    }

    public static function status_label( $status ) {
        $labels = array(
            'sent'            => 'Sent',
            'failed'          => 'Failed',
            'sent_via_backup' => 'Sent via backup',
            'failed_both'     => 'Failed (primary + backup)',
        );
        return $labels[ $status ] ?? ucfirst( (string) $status );
    }

    /**
     * SQL fragment "status IN ('a','b')" for a sent/failed filter (values are our own constants).
     */
    public static function status_in_sql( $filter ) {
        $statuses = 'sent' === $filter ? self::sent_statuses() : self::failed_statuses();
        return "status IN ('" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "')";
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

        $sent_in   = self::status_in_sql( 'sent' );
        $failed_in = self::status_in_sql( 'failed' );

        $total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE created_at >= %s", $since ) );
        $sent   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $sent_in AND created_at >= %s", $since ) );
        $failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $failed_in AND created_at >= %s", $since ) );

        return array(
            'total'        => $total,
            'sent'         => $sent,
            'failed'       => $failed,
            'success_rate' => $total > 0 ? round( ( $sent / $total ) * 100, 1 ) : 0,
        );
    }

    /**
     * Retry failed emails. Called by WP-Cron.
     */
    public function retry_failed_emails() {
        $log_settings = get_option( 'fhsmtp_log_settings', array() );
        if ( empty( $log_settings['retry_enabled'] ) || $log_settings['retry_enabled'] !== '1' ) {
            return;
        }

        $max_retries = absint( $log_settings['retry_max'] ?? 3 );

        global $wpdb;
        $table = self::get_table_name();

        $failed_in = self::status_in_sql( 'failed' );
        $failed    = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE $failed_in AND retry_count < %d ORDER BY created_at ASC LIMIT 10",
            $max_retries
        ) );

        if ( empty( $failed ) ) {
            return;
        }

        foreach ( $failed as $log ) {
            $outcome = self::resend_log_entry( $log );

            // WHY (boards-smtp-wpit-10/-14): a row that can never be sent (empty body, missing
            // attachment file) must not be picked up again every cron run; park it at max
            // retries with the reason so Jeff can see it in the log.
            if ( ! empty( $outcome['blocked'] ) ) {
                $wpdb->update(
                    $table,
                    array( 'error_message' => $outcome['message'], 'retry_count' => $max_retries ),
                    array( 'id' => $log->id ),
                    array( '%s', '%d' ),
                    array( '%d' )
                );
            }
        }
    }

    /**
     * Attachment paths stored on a log row that can be re-attached.
     * Returns an array of paths (possibly empty), or false when any listed file is gone.
     */
    public static function get_resendable_attachments( $log ) {
        $paths = array_filter( array_map( 'trim', explode( ',', (string) ( $log->attachments ?? '' ) ) ) );
        if ( empty( $paths ) ) {
            return array();
        }
        foreach ( $paths as $path ) {
            if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                return false;
            }
        }
        return array_values( $paths );
    }

    /**
     * Re-send a logged email and update THAT row in place (used by the cron retry and the
     * admin "Resend" link). Returns array( 'success' => bool, 'blocked' => bool, 'message' => string );
     * 'blocked' means the send was refused before reaching SMTP and the row was not touched.
     *
     * WHY (boards-smtp-wpit-10): one row per original email; retries update attempt count,
     * last error and status instead of inserting new (bodiless) rows.
     * WHY (boards-smtp-wpit-14): attachments are re-attached, and a resend is refused when a
     * file is gone rather than sending "see attached" with nothing attached.
     */
    public static function resend_log_entry( $log ) {
        global $wpdb;
        $table = self::get_table_name();

        if ( '' === trim( (string) $log->message ) ) {
            return array(
                'success' => false,
                'blocked' => true,
                'message' => 'Cannot resend: the logged message body is empty.',
            );
        }

        $attachments = self::get_resendable_attachments( $log );
        if ( false === $attachments ) {
            return array(
                'success' => false,
                'blocked' => true,
                'message' => 'Cannot resend: attachment file no longer exists on the server (' . $log->attachments . '). Re-send it from the original source instead.',
            );
        }

        $headers = ! empty( $log->headers ) ? explode( "\n", $log->headers ) : array();

        // Flag so the logger updates this row instead of inserting a new one.
        $GLOBALS['fhsmtp_is_retry'] = true;
        self::$retry_outcome        = null;
        $result = wp_mail( $log->to_email, $log->subject, $log->message, $headers, $attachments );
        unset( $GLOBALS['fhsmtp_is_retry'] );

        $outcome             = self::$retry_outcome;
        self::$retry_outcome = null;

        // wp_mail() returns false even when the backup failover delivered the message.
        $via_backup = is_array( $outcome ) && 'sent_via_backup' === $outcome['status'];
        $sent       = $result || $via_backup;

        if ( $sent ) {
            $status     = $via_backup ? 'sent_via_backup' : 'sent';
            $error      = $via_backup ? $outcome['error'] : '';
            $connection = $via_backup ? 'backup' : ( $GLOBALS['fhsmtp_connection_used'] ?? 'primary' );
        } else {
            $status     = is_array( $outcome ) ? $outcome['status'] : 'failed';
            $error      = is_array( $outcome ) ? $outcome['error'] : '';
            $connection = is_array( $outcome ) ? $outcome['connection_type'] : $log->connection_type;
            if ( '' === $error ) {
                global $phpmailer;
                $error = ( isset( $phpmailer ) && is_object( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) )
                    ? $phpmailer->ErrorInfo
                    : 'Send was blocked before reaching SMTP (is "Do Not Send" enabled in Misc settings?).';
            }
        }

        $wpdb->update(
            $table,
            array(
                'status'          => $status,
                'error_message'   => $error,
                'connection_type' => $connection,
                'retry_count'     => (int) $log->retry_count + 1,
            ),
            array( 'id' => $log->id ),
            array( '%s', '%s', '%s', '%d' ),
            array( '%d' )
        );

        return array(
            'success' => $sent,
            'blocked' => false,
            'message' => $sent ? 'Email resent successfully.' : 'Resend failed: ' . $error,
        );
    }
}
