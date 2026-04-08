<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles blocking specific WordPress notification emails
 * and miscellaneous settings (Do Not Send, etc.).
 *
 * Reads stored toggles from fhsmtp_email_controls and fhsmtp_misc_settings
 * and conditionally hooks into WP filters to suppress emails.
 */
class FHSMTP_Email_Controls {

    private static $instance = null;
    private $controls = array();
    private $misc     = array();

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->controls = get_option( 'fhsmtp_email_controls', array() );
        $this->misc     = get_option( 'fhsmtp_misc_settings', array() );

        $this->register_misc_hooks();
        $this->register_email_control_hooks();
    }

    // -------------------------------------------------------------------------
    // Misc Hooks
    // -------------------------------------------------------------------------

    private function register_misc_hooks() {
        // Do Not Send — block ALL emails except test emails
        if ( $this->misc_enabled( 'do_not_send' ) ) {
            add_filter( 'pre_wp_mail', array( $this, 'block_all_emails' ), 1 );
        }
    }

    /**
     * Block all outgoing emails via pre_wp_mail (WP 5.9+).
     * Test emails set $GLOBALS['fhsmtp_is_test_email'] to bypass this.
     */
    public function block_all_emails( $null ) {
        if ( ! empty( $GLOBALS['fhsmtp_is_test_email'] ) ) {
            return $null; // Allow test emails through
        }
        return false; // Block the email
    }

    // -------------------------------------------------------------------------
    // Email Control Hooks — only registered when the toggle is OFF ('0')
    // -------------------------------------------------------------------------

    private function register_email_control_hooks() {

        // --- Comments ---
        if ( $this->is_blocked( 'notify_moderator' ) ) {
            add_filter( 'notify_moderator', '__return_false', 999 );
        }
        if ( $this->is_blocked( 'notify_post_author' ) ) {
            add_filter( 'notify_post_author', '__return_false', 999 );
        }

        // --- Change of Admin Email ---
        if ( $this->is_blocked( 'admin_email_change_attempt' ) ) {
            add_filter( 'send_site_admin_email_change_email', '__return_false', 999 );
        }
        if ( $this->is_blocked( 'admin_email_changed' ) ) {
            add_filter( 'site_admin_email_change_email', array( $this, 'block_email_by_empty_to' ), 999 );
        }

        // --- Change of User Email or Password ---
        if ( $this->is_blocked( 'reset_password_request' ) ) {
            add_filter( 'retrieve_password_message', '__return_empty_string', 999 );
        }
        if ( $this->is_blocked( 'password_reset_success' ) ) {
            add_filter( 'wp_password_change_notification_email', array( $this, 'block_email_by_empty_to' ), 999 );
        }
        if ( $this->is_blocked( 'password_changed' ) ) {
            add_filter( 'send_password_change_email', '__return_false', 999 );
        }
        if ( $this->is_blocked( 'email_change_attempt' ) ) {
            add_filter( 'send_email_change_email', '__return_false', 999 );
        }
        if ( $this->is_blocked( 'email_changed' ) ) {
            add_filter( 'email_change_email', array( $this, 'block_email_by_empty_to' ), 999 );
        }

        // --- Personal Data Requests ---
        if ( $this->is_blocked( 'user_confirmed_action' ) ) {
            add_filter( 'user_confirmed_action_email', array( $this, 'block_email_by_empty_to' ), 999 );
        }
        if ( $this->is_blocked( 'admin_erased_data' ) ) {
            add_filter( 'wp_privacy_personal_data_email_content', '__return_empty_string', 999 );
        }
        if ( $this->is_blocked( 'admin_sent_export_link' ) ) {
            add_filter( 'wp_privacy_send_personal_data_export_email', '__return_false', 999 );
        }

        // --- Automatic Updates ---
        if ( $this->is_blocked( 'auto_plugin_update_email' ) ) {
            add_filter( 'auto_plugin_update_send_email', '__return_false', 999 );
        }
        if ( $this->is_blocked( 'auto_theme_update_email' ) ) {
            add_filter( 'auto_theme_update_send_email', '__return_false', 999 );
        }
        if ( $this->is_blocked( 'auto_core_update_email' ) ) {
            add_filter( 'auto_core_update_send_email', '__return_false', 999 );
        }
        if ( $this->is_blocked( 'auto_update_debug_email' ) ) {
            add_filter( 'automatic_updates_send_debug_email', '__return_false', 999 );
        }

        // --- New User ---
        if ( $this->is_blocked( 'new_user_admin_notify' ) ) {
            // WP 6.1+ filter
            add_filter( 'wp_send_new_user_notification_to_admin', '__return_false', 999 );
            // Fallback for older WP versions
            add_filter( 'wp_new_user_notification_email_admin', array( $this, 'block_email_by_empty_to' ), 999 );
        }
        if ( $this->is_blocked( 'new_user_user_notify' ) ) {
            // WP 6.1+ filter
            add_filter( 'wp_send_new_user_notification_to_user', '__return_false', 999 );
            // Fallback for older WP versions
            add_filter( 'wp_new_user_notification_email', array( $this, 'block_email_by_empty_to' ), 999 );
        }
    }

    // -------------------------------------------------------------------------
    // Helper callbacks
    // -------------------------------------------------------------------------

    /**
     * Block an email by setting its 'to' field to empty.
     * Used for filters that pass an email array (with keys like 'to', 'subject', etc.).
     */
    public function block_email_by_empty_to( $email_data ) {
        if ( is_array( $email_data ) ) {
            $email_data['to'] = '';
        }
        return $email_data;
    }

    /**
     * Check if a specific email control is blocked (toggle is '0' or missing defaults to '1').
     */
    private function is_blocked( $key ) {
        // Default to '1' (enabled/sending) if key doesn't exist yet
        $value = $this->controls[ $key ] ?? '1';
        return $value === '0';
    }

    /**
     * Check if a misc setting is enabled.
     */
    private function misc_enabled( $key ) {
        return ( $this->misc[ $key ] ?? '0' ) === '1';
    }
}
