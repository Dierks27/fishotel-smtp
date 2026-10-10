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
        }

        // WHY (boards-smtp-wpit-11): primary-failure stats and the fhsmtp_email_failed action
        // (which drives all alerts) live in handle_primary_failure, so it must run even when
        // Backup SMTP is off. The backup send itself stays gated on backup_enabled inside it.
        add_action( 'wp_mail_failed', array( $this, 'handle_primary_failure' ), 5 );

        // WHY (boards-smtp-wpit-12): clear the failover-outcome flags after the logger
        // (priority 10) has consumed them, so they never leak into the next send.
        add_action( 'wp_mail_failed', array( $this, 'cleanup_failover_flags' ), 100 );
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
     * Public so send_with_backup_only() can hook it directly for the backup test.
     */
    public function configure_backup( $phpmailer ) {
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
            // WHY: this hook runs unconditionally now (wpit-11) and "Test Backup Connection" also
            // sets fhsmtp_use_backup (wpit-15), so only a REAL failover counts as a backup failure.
            // A failed interactive backup test must not feed the Backup stats or the failure alerts.
            if ( ! empty( $GLOBALS['fhsmtp_failover_in_progress'] ) ) {
                // Backup also failed — update stats
                self::update_stats( 'backup', false );

                // WHY (boards-smtp-wpit-12): keep the backup error so the outer log_failure can
                // record both outcomes on the single failed_both row.
                if ( is_wp_error( $wp_error ) ) {
                    $GLOBALS['fhsmtp_failover_backup_error'] = $wp_error->get_error_message();
                }
            }
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
        // Tells the logger that the nested wp_mail() below is the same email, not a new one.
        $GLOBALS['fhsmtp_failover_in_progress'] = true;

        // WHY (boards-smtp-wpit-13): PHPMailer::smtpConnect() reuses an already-open socket, so
        // after a post-connect rejection (MAIL FROM / DATA / timeout) the "backup" send would go
        // back out over the PRIMARY connection. Close it so the nested wp_mail() opens a fresh
        // connection with the backup host/credentials set in configure_backup().
        // Use SMTP::close() (just drop the socket) rather than smtpClose(): its QUIT round-trip
        // waits for a reply and can block for another full SMTP timeout on a primary that went
        // silent mid-DATA. close() is a no-op when nothing is connected.
        global $phpmailer;
        if ( isset( $phpmailer ) && is_object( $phpmailer ) && method_exists( $phpmailer, 'getSMTPInstance' ) ) {
            $phpmailer->getSMTPInstance()->close();
        }

        $result = wp_mail( $to, $subject, $message, $headers, $attachments );

        if ( $result ) {
            self::update_stats( 'backup', true );
        }

        // WHY (boards-smtp-wpit-12): the outer wp_mail_failed logger (priority 10) reads this to
        // write ONE row (sent_via_backup / failed_both) instead of a second 'failed' row.
        $GLOBALS['fhsmtp_failover_result'] = $result ? 'sent' : 'failed';

        // Clean up globals
        unset( $GLOBALS['fhsmtp_use_backup'] );
        unset( $GLOBALS['fhsmtp_failover_attempted'] );
        unset( $GLOBALS['fhsmtp_failover_in_progress'] );
    }

    /**
     * Runs last on wp_mail_failed: drop the per-send failover outcome flags.
     */
    public function cleanup_failover_flags() {
        // The nested backup send fires wp_mail_failed too; its error must survive until the
        // OUTER wp_mail_failed (same email) has been logged.
        if ( ! empty( $GLOBALS['fhsmtp_failover_in_progress'] ) ) {
            return;
        }
        unset( $GLOBALS['fhsmtp_failover_result'] );
        unset( $GLOBALS['fhsmtp_failover_backup_error'] );
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
        $backup = get_option( 'fhsmtp_backup_settings', array() );

        // WHY (boards-smtp-wpit-15): configure_with_failover (the only reader of fhsmtp_use_backup)
        // is only hooked when Backup is enabled, so this test used to go out through the PRIMARY
        // host and report success. Refuse when nothing is configured, and hook the backup
        // configuration explicitly for the duration of the test.
        if ( empty( $backup['backup_host'] ) ) {
            return array(
                'success' => false,
                'message' => 'Backup SMTP is not configured. Enter the backup SMTP host and credentials, click "Save Backup Settings", then test again.',
            );
        }

        $self         = self::instance();
        $mailer       = FHSMTP_Mailer::instance();
        $enabled      = ! empty( $backup['backup_enabled'] ) && $backup['backup_enabled'] === '1';
        $had_failover = false !== has_action( 'phpmailer_init', array( $self, 'configure_with_failover' ) );

        remove_action( 'phpmailer_init', array( $mailer, 'configure_phpmailer' ), 99 );
        remove_action( 'phpmailer_init', array( $self, 'configure_with_failover' ), 99 );
        add_action( 'phpmailer_init', array( $self, 'configure_backup' ), 99 );

        $GLOBALS['fhsmtp_use_backup']    = true;
        $GLOBALS['fhsmtp_is_test_email'] = true;

        $subject = sprintf( '[%s] FisHotel SMTP Test (Backup)', get_bloginfo( 'name' ) );
        $message = sprintf(
            "This is a test email sent via the BACKUP SMTP connection.\n\nBackup host: %s\nSite: %s\nTime: %s",
            $backup['backup_host'],
            get_bloginfo( 'url' ),
            current_time( 'mysql' )
        );

        $result = wp_mail( $to, $subject, $message );
        unset( $GLOBALS['fhsmtp_use_backup'] );
        unset( $GLOBALS['fhsmtp_is_test_email'] );
        unset( $GLOBALS['fhsmtp_connection_used'] );

        // Restore the normal phpmailer_init wiring.
        remove_action( 'phpmailer_init', array( $self, 'configure_backup' ), 99 );
        if ( $had_failover ) {
            add_action( 'phpmailer_init', array( $self, 'configure_with_failover' ), 99 );
        } else {
            add_action( 'phpmailer_init', array( $mailer, 'configure_phpmailer' ), 99 );
        }

        if ( $result ) {
            $msg = 'Backup test email sent successfully via ' . esc_html( $backup['backup_host'] ) . '.';
            if ( ! $enabled ) {
                $msg .= ' Note: Backup SMTP is not enabled, so it will not be used for failover until you tick "Enable Backup" and save.';
            }
            return array( 'success' => true, 'message' => $msg );
        }

        global $phpmailer;
        $error = ( isset( $phpmailer ) && is_object( $phpmailer ) ) ? $phpmailer->ErrorInfo : '';
        return array( 'success' => false, 'message' => 'Backup test failed via ' . esc_html( $backup['backup_host'] ) . '. ' . esc_html( $error ) );
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
