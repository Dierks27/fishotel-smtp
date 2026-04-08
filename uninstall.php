<?php
/**
 * FisHotel SMTP Uninstall
 * Removes all plugin data when the plugin is deleted via WordPress admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop email logs table
$table = $wpdb->prefix . 'fhsmtp_email_logs';
$wpdb->query( "DROP TABLE IF EXISTS $table" );

// Remove all plugin options
$options = array(
    'fhsmtp_settings',
    'fhsmtp_log_settings',
    'fhsmtp_backup_settings',
    'fhsmtp_alert_settings',
    'fhsmtp_connection_stats',
    'fhsmtp_consecutive_failures',
);
foreach ( $options as $option ) {
    delete_option( $option );
}

// Remove transients
delete_transient( 'fhsmtp_last_alert_time' );
delete_transient( 'fhsmtp_update_check' );

// Clear scheduled cron
wp_clear_scheduled_hook( 'fhsmtp_cleanup_logs' );
