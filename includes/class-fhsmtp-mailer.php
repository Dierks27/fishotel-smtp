<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FHSMTP_Mailer {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'phpmailer_init', array( $this, 'configure_phpmailer' ), 99 );

        $settings = get_option( 'fhsmtp_settings', array() );
        $force    = ( $settings['force_from_email'] ?? '0' ) === '1';
        $priority = $force ? PHP_INT_MAX : 99;

        add_filter( 'wp_mail_from', array( $this, 'set_from_email' ), $priority );
        add_filter( 'wp_mail_from_name', array( $this, 'set_from_name' ), $priority );

        // When forcing, also override in phpmailer_init at max priority
        if ( $force ) {
            add_action( 'phpmailer_init', array( $this, 'force_from_in_phpmailer' ), PHP_INT_MAX );
        }
    }

    public function get_settings() {
        $settings = get_option( 'fhsmtp_settings', array() );

        // wp-config.php constants override DB values
        $constant_map = array(
            'FHSMTP_SMTP_HOST'       => 'smtp_host',
            'FHSMTP_SMTP_PORT'       => 'smtp_port',
            'FHSMTP_SMTP_ENCRYPTION' => 'smtp_encryption',
            'FHSMTP_SMTP_USER'       => 'smtp_username',
            'FHSMTP_SMTP_PASS'       => 'smtp_password',
            'FHSMTP_FROM_EMAIL'      => 'from_email',
            'FHSMTP_FROM_NAME'       => 'from_name',
            'FHSMTP_REGION'          => 'region',
        );

        foreach ( $constant_map as $constant => $key ) {
            if ( defined( $constant ) ) {
                $settings[ $key ] = constant( $constant );
            }
        }

        return $settings;
    }

    public function configure_phpmailer( $phpmailer ) {
        $settings = $this->get_settings();

        if ( empty( $settings['smtp_host'] ) ) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host       = $settings['smtp_host'];
        $phpmailer->Port       = intval( $settings['smtp_port'] );
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Username   = $settings['smtp_username'];
        $phpmailer->Password   = $settings['smtp_password'];

        switch ( $settings['smtp_encryption'] ) {
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
    }

    public function set_from_email( $from_email ) {
        $settings = $this->get_settings();
        if ( ! empty( $settings['from_email'] ) ) {
            return $settings['from_email'];
        }
        return $from_email;
    }

    public function set_from_name( $from_name ) {
        $settings = $this->get_settings();
        if ( ! empty( $settings['from_name'] ) ) {
            return $settings['from_name'];
        }
        return $from_name;
    }

    /**
     * Force From address in phpmailer_init at max priority.
     * Ensures our From overrides anything set by other plugins.
     */
    public function force_from_in_phpmailer( $phpmailer ) {
        $settings = $this->get_settings();
        if ( ! empty( $settings['from_email'] ) ) {
            $phpmailer->From   = $settings['from_email'];
            $phpmailer->Sender = $settings['from_email'];
        }
        if ( ! empty( $settings['from_name'] ) ) {
            $phpmailer->FromName = $settings['from_name'];
        }
    }

    /**
     * Get the SES SMTP host for a given AWS region.
     */
    public static function ses_host_for_region( $region ) {
        return 'email-smtp.' . $region . '.amazonaws.com';
    }

    /**
     * Available AWS SES regions.
     */
    public static function get_ses_regions() {
        return array(
            'us-east-1'      => 'US East (N. Virginia)',
            'us-east-2'      => 'US East (Ohio)',
            'us-west-1'      => 'US West (N. California)',
            'us-west-2'      => 'US West (Oregon)',
            'af-south-1'     => 'Africa (Cape Town)',
            'ap-south-1'     => 'Asia Pacific (Mumbai)',
            'ap-northeast-1' => 'Asia Pacific (Tokyo)',
            'ap-northeast-2' => 'Asia Pacific (Seoul)',
            'ap-northeast-3' => 'Asia Pacific (Osaka)',
            'ap-southeast-1' => 'Asia Pacific (Singapore)',
            'ap-southeast-2' => 'Asia Pacific (Sydney)',
            'ca-central-1'   => 'Canada (Central)',
            'eu-central-1'   => 'Europe (Frankfurt)',
            'eu-west-1'      => 'Europe (Ireland)',
            'eu-west-2'      => 'Europe (London)',
            'eu-west-3'      => 'Europe (Paris)',
            'eu-south-1'     => 'Europe (Milan)',
            'eu-north-1'     => 'Europe (Stockholm)',
            'me-south-1'     => 'Middle East (Bahrain)',
            'sa-east-1'      => 'South America (Sao Paulo)',
        );
    }

    /**
     * Send a test email using current settings. Returns array with success/error.
     */
    public static function send_test_email( $to, $connection = 'primary' ) {
        // Flag to bypass "Do Not Send" in misc settings
        $GLOBALS['fhsmtp_is_test_email'] = true;

        $subject = sprintf(
            '[%s] FisHotel SMTP Test Email (%s)',
            get_bloginfo( 'name' ),
            $connection
        );
        $message = sprintf(
            "This is a test email sent from FisHotel SMTP plugin.\n\nConnection: %s\nSite: %s\nTime: %s",
            ucfirst( $connection ),
            get_bloginfo( 'url' ),
            current_time( 'mysql' )
        );

        $result = wp_mail( $to, $subject, $message );
        unset( $GLOBALS['fhsmtp_is_test_email'] );

        if ( $result ) {
            return array( 'success' => true, 'message' => 'Test email sent successfully.' );
        }

        global $phpmailer;
        $error = '';
        if ( isset( $phpmailer ) && is_object( $phpmailer ) ) {
            $error = $phpmailer->ErrorInfo;
        }
        return array( 'success' => false, 'message' => 'Failed to send test email. ' . $error );
    }
}
