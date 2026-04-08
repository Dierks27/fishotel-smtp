<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class FHSMTP_Log_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( array(
            'singular' => 'email_log',
            'plural'   => 'email_logs',
            'ajax'     => false,
        ) );
    }

    public function get_columns() {
        return array(
            'cb'              => '<input type="checkbox" />',
            'created_at'      => 'Date',
            'to_email'        => 'To',
            'subject'         => 'Subject',
            'status'          => 'Status',
            'connection_type' => 'Connection',
        );
    }

    public function get_sortable_columns() {
        return array(
            'created_at' => array( 'created_at', true ),
            'status'     => array( 'status', false ),
        );
    }

    public function get_bulk_actions() {
        return array(
            'delete' => 'Delete',
        );
    }

    protected function extra_tablenav( $which ) {
        if ( 'top' !== $which ) {
            return;
        }
        $current_status = isset( $_GET['filter_status'] ) ? sanitize_text_field( $_GET['filter_status'] ) : '';
        ?>
        <div class="alignleft actions">
            <select name="filter_status">
                <option value="">All Statuses</option>
                <option value="sent" <?php selected( $current_status, 'sent' ); ?>>Sent</option>
                <option value="failed" <?php selected( $current_status, 'failed' ); ?>>Failed</option>
            </select>
            <?php submit_button( 'Filter', '', 'filter_action', false ); ?>
        </div>
        <?php
    }

    public function column_cb( $item ) {
        return sprintf( '<input type="checkbox" name="log_ids[]" value="%d" />', $item->id );
    }

    public function column_created_at( $item ) {
        $time = strtotime( $item->created_at );
        return sprintf(
            '<strong>%s</strong><br><small>%s</small>',
            esc_html( date_i18n( 'M j, Y', $time ) ),
            esc_html( date_i18n( 'g:i a', $time ) )
        );
    }

    public function column_to_email( $item ) {
        return esc_html( $item->to_email );
    }

    public function column_subject( $item ) {
        $actions = array(
            'view'   => sprintf(
                '<a href="#" class="fhsmtp-view-log" data-id="%d">View</a>',
                $item->id
            ),
            'delete' => sprintf(
                '<a href="%s" onclick="return confirm(\'Delete this log entry?\')">Delete</a>',
                wp_nonce_url(
                    add_query_arg( array( 'page' => 'fhsmtp-email-logs', 'action' => 'delete', 'log_ids' => $item->id ), admin_url( 'options-general.php' ) ),
                    'bulk-email_logs'
                )
            ),
        );

        if ( 'failed' === $item->status ) {
            $actions['resend'] = sprintf(
                '<a href="#" class="fhsmtp-resend-log" data-id="%d">Resend</a>',
                $item->id
            );
        }

        return sprintf(
            '<strong>%s</strong> %s',
            esc_html( wp_trim_words( $item->subject, 10 ) ),
            $this->row_actions( $actions )
        );
    }

    public function column_status( $item ) {
        $class = 'sent' === $item->status ? 'fhsmtp-status-sent' : 'fhsmtp-status-failed';
        $output = sprintf( '<span class="%s">%s</span>', $class, esc_html( ucfirst( $item->status ) ) );

        if ( 'failed' === $item->status && ! empty( $item->error_message ) ) {
            $output .= sprintf(
                '<br><small class="fhsmtp-error-msg" title="%s">%s</small>',
                esc_attr( $item->error_message ),
                esc_html( wp_trim_words( $item->error_message, 8 ) )
            );
        }

        return $output;
    }

    public function column_connection_type( $item ) {
        return esc_html( ucfirst( $item->connection_type ) );
    }

    public function prepare_items() {
        global $wpdb;

        // Handle bulk delete
        $this->process_bulk_action();

        $table    = FHSMTP_Logger::get_table_name();
        $per_page = 50;
        $paged    = $this->get_pagenum();
        $offset   = ( $paged - 1 ) * $per_page;

        $where = array( '1=1' );
        $values = array();

        // Status filter
        if ( ! empty( $_GET['filter_status'] ) ) {
            $status = sanitize_text_field( $_GET['filter_status'] );
            if ( in_array( $status, array( 'sent', 'failed' ), true ) ) {
                $where[]  = 'status = %s';
                $values[] = $status;
            }
        }

        // Search
        if ( ! empty( $_GET['s'] ) ) {
            $search   = '%' . $wpdb->esc_like( sanitize_text_field( $_GET['s'] ) ) . '%';
            $where[]  = '(to_email LIKE %s OR subject LIKE %s)';
            $values[] = $search;
            $values[] = $search;
        }

        $where_sql = implode( ' AND ', $where );

        // Sorting
        $orderby = 'created_at';
        if ( ! empty( $_GET['orderby'] ) && in_array( $_GET['orderby'], array( 'created_at', 'status' ), true ) ) {
            $orderby = sanitize_sql_orderby( $_GET['orderby'] );
        }
        $order = 'DESC';
        if ( ! empty( $_GET['order'] ) && in_array( strtoupper( $_GET['order'] ), array( 'ASC', 'DESC' ), true ) ) {
            $order = strtoupper( $_GET['order'] );
        }

        // Total items
        if ( ! empty( $values ) ) {
            $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where_sql", ...$values ) );
        } else {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where_sql" );
        }

        // Fetch items
        $query = "SELECT * FROM $table WHERE $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $query_values = array_merge( $values, array( $per_page, $offset ) );
        $this->items = $wpdb->get_results( $wpdb->prepare( $query, ...$query_values ) );

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );

        $this->set_pagination_args( array(
            'total_items' => $total,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total / $per_page ),
        ) );
    }

    private function process_bulk_action() {
        if ( 'delete' !== $this->current_action() ) {
            return;
        }

        if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'] ?? '', 'bulk-email_logs' ) ) {
            return;
        }

        $ids = isset( $_REQUEST['log_ids'] ) ? (array) $_REQUEST['log_ids'] : array();
        if ( ! empty( $ids ) ) {
            FHSMTP_Logger::delete_logs( $ids );
        }
    }
}
