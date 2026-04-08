<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FHSMTP_Failover {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $backup = get_option( 'fhsmtp_backup_settings', array() );
        if ( ! empty( $backup['backup_enabled'] ) && $backup['backup_enabled'] === '1' ) {
            // Remove the default mailer hook and replace with failover logic
            remove_action( 'phpmailer_init', array( FHSMTP_Mailer::instance(), 'configure_phpmailer' ), 99 );
            add_action( 'phpmailer_init', array( $this, 'configure_with_failover' ), 99 );
            add_action( 'wp_mail_failed', array( $this, 'handle_primary_failure' ), 5 );
        }
    }

    /**
     * Configure PHPMailer with primary connection settings.
     * The failover happens via wp_mail_failed hook.
     */
    public function configure_with_failover( $phpmailer ) {
        // If we're currently in a backup retry, configure backup instead
        if ( ! empty( $GLOBALS['fhsmtp_use_backup'] ) ) {
            $this->configure_backup( $phpmailer );
            return;
        }

        // Otherwise use primary settings
        FHSMTP_Mailer::instance()->configure_phpmailer( $phpmailer );
        $GLOBALS['fhsmtp_connection_used'] = 'primary';
    }

    /**
     * Configure PHPMailer with backup connection settings.
     */
    private function configure_backup( $phpmailer ) {
        $backup = get_option( 'fhsmtp_backup_settings', array() );

        if ( empty( $backup['backup_host'] ) ) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host     = $backup['backup_host'];
        $phpmailer->Port     = intval( $backup['backup_port'] ?? 587 );
        $phpmailer->SMTPAuth = true;
        $phpmailer->Username = $backup['backup_username'] ?? '';
        $phpmailer->Password = $backup['backup_password'] ?? '';

        switch ( $backup['backup_encryption'] ?? 'tls' ) {
            case 'tls':
                $phpmailer->SMTPSecure = 'tls';
                break;
            case 'ssl':
                $phpmailer->SMTPSecure = 'ssl';
                break;
            default:
                $phpmailer->SMTPSecure = '';
                $phpmailer->SMTPAutoTLS = false;
                break;
        }

        $GLOBALS['fhsmtp_connection_used'] = 'backup';
    }

    /**
     * When primary fails and backup is enabled, retry with backup connection.
     */
    public function handle_primary_failure( $wp_error ) {
        // Prevent infinite loops — only retry once
        if ( ! empty( $GLOBALS['fhsmtp_use_backup'] ) || ! empty( $GLOBALS['fhsmtp_failover_attempted'] ) ) {
            // Backup also failed — update stats
            self::update_stats( 'backup', false );
            return;
        }

        // Primary failed — update primary stats
        self::update_stats( 'primary', false );

        $backup = get_option( 'fhsmtp_backup_settings', array() );
        if ( empty( $backup['backup_enabled'] ) || $backup['backup_enabled'] !== '1' || empty( $backup['backup_host'] ) ) {
            return;
        }

        // Wait 2 seconds before retry
        sleep( 2 );

        // Get the original email data from the error
        $error_data = $wp_error->get_error_data();
        if ( empty( $error_data ) ) {
            return;
        }

        $to      = $error_data['to'] ?? array();
        $subject = $error_data['subject'] ?? '';
        $message = $error_data['message'] ?? '';
        $headers = $error_data['headers'] ?? array();
        $attachments = $error_data['attachments'] ?? array();

        // Mark that we're using backup to prevent loops
        $GLOBALS['fhsmtp_use_backup'] = true;
        $GLOBALS['fhsmtp_failover_attempted'] = true;

        $result = wp_mail( $to, $subject, $message, $headers, $attachments );

        if ( $result ) {
            self::update_stats( 'backup', true );
        }

        // Clean up globals
        unset( $GLOBALS['fhsmtp_use_backup'] );
        unset( $GLOBALS['fhsmtp_failover_attempted'] );
    }

    /**
     * Track success/failure per connection in wp_options.
     */
    public static function update_stats( $connection, $success ) {
        $stats = get_option( 'fhsmtp_connection_stats', array(
            'primary_success' => 0,
            'primary_fail'    => 0,
            'backup_success'  => 0,
            'backup_fail'     => 0,
        ) );

        $key = $connection . '_' . ( $success ? 'success' : 'fail' );
        $stats[ $key ] = ( $stats[ $key ] ?? 0 ) + 1;

        update_option( 'fhsmtp_connection_stats', $stats );

        // Also notify alerts system on failure
        if ( ! $success ) {
            do_action( 'fhsmtp_email_failed', $connection );
        } else {
            do_action( 'fhsmtp_email_succeeded', $connection );
        }
    }

    /**
     * Send a test email using only the backup connection.
     * Used by the admin "Test Backup" button.
     */
    public static function send_with_backup_only( $to ) {
        $GLOBALS['fhsmtp_use_backup']    = true;
        $GLOBALS['fhsmtp_is_test_email'] = true;

        $subject = sprintf( '[%s] FisHotel SMTP Test (Backup)', get_bloginfo( 'name' ) );
        $message = sprintf(
            "This is a test email sent via the BACKUP SMTP connection.\n\nSite: %s\nTime: %s",
            get_bloginfo( 'url' ),
            current_time( 'mysql' )
        );

        $result = wp_mail( $to, $subject, $message );
        unset( $GLOBALS['fhsmtp_use_backup'] );
        unset( $GLOBALS['fhsmtp_is_test_email'] );

        if ( $result ) {
            return array( 'success' => true, 'message' => 'Backup test email sent successfully.' );
        }

        global $phpmailer;
        $error = isset( $phpmailer ) ? $phpmailer->ErrorInfo : '';
        return array( 'success' => false, 'message' => 'Backup test failed. ' . $error );
    }

    /**
     * Hook into successful wp_mail to track primary stats.
     * This runs when failover is NOT active and primary succeeds.
     */
    public static function track_primary_success() {
        if ( empty( $GLOBALS['fhsmtp_use_backup'] ) && empty( $GLOBALS['fhsmtp_failover_attempted'] ) ) {
            self::update_stats( 'primary', true );
        }
    }
}

// Track primary successes
add_action( 'wp_mail_succeeded', array( 'FHSMTP_Failover', 'track_primary_success' ), 5 );
