<?php
/**
 * FisHotel SMTP Uninstall
 * Removes all plugin data when the plugin is deleted via WordPress admin,
 * but only if the "Remove All Data on Delete" option is enabled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$misc = get_option( 'fhsmtp_misc_settings', array() );

if ( empty( $misc['remove_data_on_delete'] ) || $misc['remove_data_on_delete'] !== '1' ) {
    return; // Preserve all data for potential reinstall
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
    'fhsmtp_email_controls',
    'fhsmtp_misc_settings',
);
foreach ( $options as $option ) {
    delete_option( $option );
}

// Remove transients
delete_transient( 'fhsmtp_last_alert_time' );
delete_transient( 'fhsmtp_update_check' );

// Clear scheduled cron
wp_clear_scheduled_hook( 'fhsmtp_cleanup_logs' );
