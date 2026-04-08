<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FHSMTP_CSV_Export {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_fhsmtp_export_csv', array( $this, 'export' ) );
    }

    public function export() {
        check_ajax_referer( 'fhsmtp_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        global $wpdb;
        $table = FHSMTP_Logger::get_table_name();

        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $_GET['filter_status'] ) ) {
            $status = sanitize_text_field( $_GET['filter_status'] );
            if ( in_array( $status, array( 'sent', 'failed' ), true ) ) {
                $where[]  = 'status = %s';
                $values[] = $status;
            }
        }

        if ( ! empty( $_GET['s'] ) ) {
            $search   = '%' . $wpdb->esc_like( sanitize_text_field( $_GET['s'] ) ) . '%';
            $where[]  = '(to_email LIKE %s OR subject LIKE %s)';
            $values[] = $search;
            $values[] = $search;
        }

        $where_sql = implode( ' AND ', $where );
        $query     = "SELECT id, created_at, to_email, from_email, subject, status, error_message, connection_type FROM $table WHERE $where_sql ORDER BY created_at DESC";

        if ( ! empty( $values ) ) {
            $results = $wpdb->get_results( $wpdb->prepare( $query, ...$values ), ARRAY_A );
        } else {
            $results = $wpdb->get_results( $query, ARRAY_A );
        }

        $filename = 'fishotel-smtp-logs-' . date( 'Y-m-d' ) . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // BOM for Excel
        fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        // Header row
        fputcsv( $output, array( 'ID', 'Date', 'To', 'From', 'Subject', 'Status', 'Error', 'Connection' ) );

        foreach ( $results as $row ) {
            fputcsv( $output, $row );
        }

        fclose( $output );
        exit;
    }
}
