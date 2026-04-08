<?php
/**
 * Plugin Name: FisHotel SMTP
 * Plugin URI: https://github.com/Dierks27/fishotel-smtp
 * Description: Custom SMTP mailer with Amazon SES support, backup failover, email logging, and failure alerts.
 * Version: 1.1.2
 * Author: FisHotel
 * Author URI: https://github.com/Dierks27
 * License: GPL-2.0+
 * Text Domain: fishotel-smtp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FHSMTP_VERSION', '1.1.2' );
define( 'FHSMTP_PLUGIN_FILE', __FILE__ );
define( 'FHSMTP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FHSMTP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FHSMTP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

final class FisHotel_SMTP {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->includes();
        $this->init_hooks();
    }

    private function includes() {
        require_once FHSMTP_PLUGIN_DIR . 'includes/class-fhsmtp-mailer.php';
        require_once FHSMTP_PLUGIN_DIR . 'includes/class-fhsmtp-admin.php';
        require_once FHSMTP_PLUGIN_DIR . 'includes/class-fhsmtp-logger.php';
        require_once FHSMTP_PLUGIN_DIR . 'includes/class-fhsmtp-log-table.php';
        require_once FHSMTP_PLUGIN_DIR . 'includes/class-fhsmtp-failover.php';
        require_once FHSMTP_PLUGIN_DIR . 'includes/class-fhsmtp-alerts.php';
        require_once FHSMTP_PLUGIN_DIR . 'includes/class-fhsmtp-dashboard.php';
        require_once FHSMTP_PLUGIN_DIR . 'includes/class-fhsmtp-updater.php';
        require_once FHSMTP_PLUGIN_DIR . 'includes/class-fhsmtp-csv-export.php';
        require_once FHSMTP_PLUGIN_DIR . 'includes/class-fhsmtp-email-controls.php';
    }

    private function init_hooks() {
        register_activation_hook( FHSMTP_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( FHSMTP_PLUGIN_FILE, array( $this, 'deactivate' ) );

        add_action( 'plugins_loaded', array( $this, 'init_components' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_filter( 'plugin_row_meta', array( $this, 'add_check_update_link' ), 10, 2 );
        add_action( 'admin_init', array( $this, 'handle_check_update' ) );
    }

    public function init_components() {
        FHSMTP_Mailer::instance();
        FHSMTP_Admin::instance();
        FHSMTP_Logger::instance();
        FHSMTP_Failover::instance();
        FHSMTP_Alerts::instance();
        FHSMTP_Dashboard::instance();
        FHSMTP_CSV_Export::instance();
        FHSMTP_Email_Controls::instance();

        new FHSMTP_Updater(
            'Dierks27/fishotel-smtp',
            FHSMTP_PLUGIN_FILE,
            FHSMTP_VERSION
        );
    }

    public function activate() {
        FHSMTP_Logger::create_table();

        $defaults = array(
            'smtp_host'       => '',
            'smtp_port'       => '587',
            'smtp_encryption' => 'tls',
            'smtp_username'   => '',
            'smtp_password'   => '',
            'from_email'      => get_option( 'admin_email' ),
            'from_name'       => get_option( 'blogname' ),
            'region'          => 'us-east-1',
            'force_from_email'=> '0',
        );
        if ( ! get_option( 'fhsmtp_settings' ) ) {
            add_option( 'fhsmtp_settings', $defaults );
        }

        $log_defaults = array(
            'logging_enabled'  => '1',
            'retention_days'   => '30',
        );
        if ( ! get_option( 'fhsmtp_log_settings' ) ) {
            add_option( 'fhsmtp_log_settings', $log_defaults );
        }

        $backup_defaults = array(
            'backup_enabled'    => '0',
            'backup_host'       => '',
            'backup_port'       => '587',
            'backup_encryption' => 'tls',
            'backup_username'   => '',
            'backup_password'   => '',
        );
        if ( ! get_option( 'fhsmtp_backup_settings' ) ) {
            add_option( 'fhsmtp_backup_settings', $backup_defaults );
        }

        $alert_defaults = array(
            'alerts_enabled'         => '0',
            'threshold'              => '3',
            'alert_email_recipients' => get_option( 'admin_email' ),
            'slack_webhook_url'      => '',
            'discord_webhook_url'    => '',
            'custom_webhook_url'     => '',
            'custom_webhook_template'=> '',
        );
        if ( ! get_option( 'fhsmtp_alert_settings' ) ) {
            add_option( 'fhsmtp_alert_settings', $alert_defaults );
        }

        if ( ! get_option( 'fhsmtp_email_controls' ) ) {
            add_option( 'fhsmtp_email_controls', array(
                'notify_moderator'           => '1',
                'notify_post_author'         => '1',
                'admin_email_change_attempt' => '1',
                'admin_email_changed'        => '1',
                'reset_password_request'     => '1',
                'password_reset_success'     => '1',
                'password_changed'           => '1',
                'email_change_attempt'       => '1',
                'email_changed'              => '1',
                'user_confirmed_action'      => '1',
                'admin_erased_data'          => '1',
                'admin_sent_export_link'     => '1',
                'auto_plugin_update_email'   => '1',
                'auto_theme_update_email'    => '1',
                'auto_core_update_email'     => '1',
                'auto_update_debug_email'    => '1',
                'new_user_admin_notify'      => '1',
                'new_user_user_notify'       => '1',
            ) );
        }

        if ( ! get_option( 'fhsmtp_misc_settings' ) ) {
            add_option( 'fhsmtp_misc_settings', array(
                'do_not_send'           => '0',
                'hide_delivery_errors'  => '0',
                'hide_dashboard_widget' => '0',
                'remove_data_on_delete' => '0',
            ) );
        }

        if ( ! get_option( 'fhsmtp_connection_stats' ) ) {
            add_option( 'fhsmtp_connection_stats', array(
                'primary_success' => 0,
                'primary_fail'    => 0,
                'backup_success'  => 0,
                'backup_fail'     => 0,
            ) );
        }

        if ( ! wp_next_scheduled( 'fhsmtp_cleanup_logs' ) ) {
            wp_schedule_event( time(), 'daily', 'fhsmtp_cleanup_logs' );
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook( 'fhsmtp_cleanup_logs' );
    }

    public function enqueue_admin_assets( $hook ) {
        $plugin_pages = array(
            'settings_page_fishotel-smtp',
            'settings_page_fhsmtp-email-logs',
            'index.php',
        );
        if ( ! in_array( $hook, $plugin_pages, true ) ) {
            return;
        }
        wp_enqueue_style(
            'fhsmtp-admin',
            FHSMTP_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            FHSMTP_VERSION
        );
        wp_enqueue_script(
            'fhsmtp-admin',
            FHSMTP_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            FHSMTP_VERSION,
            true
        );
        wp_localize_script( 'fhsmtp-admin', 'fhsmtp', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'fhsmtp_nonce' ),
        ) );
    }

    public function handle_check_update() {
        if ( empty( $_GET['fhsmtp_check_update'] ) ) {
            return;
        }
        if ( ! current_user_can( 'update_plugins' ) || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'fhsmtp_check_update' ) ) {
            return;
        }

        delete_transient( 'fhsmtp_update_check' );
        delete_site_transient( 'update_plugins' );

        wp_safe_redirect( admin_url( 'plugins.php?fhsmtp_updated_check=1' ) );
        exit;
    }

    public function add_check_update_link( $links, $file ) {
        if ( plugin_basename( FHSMTP_PLUGIN_FILE ) !== $file ) {
            return $links;
        }

        $check_url = wp_nonce_url(
            admin_url( 'plugins.php?fhsmtp_check_update=1' ),
            'fhsmtp_check_update'
        );
        $links[] = sprintf( '<a href="%s">Check for Updates</a>', esc_url( $check_url ) );

        return $links;
    }
}

function fhsmtp() {
    return FisHotel_SMTP::instance();
}
fhsmtp();
