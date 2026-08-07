<?php
defined( 'ABSPATH' ) || exit;

class WC_ONT_Ajax {

    public function __construct() {
        add_action( 'wp_ajax_wc_ont_get_templates', [ $this, 'get_templates' ] );
        add_action( 'wp_ajax_wc_ont_get_order_data', [ $this, 'get_order_data' ] );
    }

    public function get_templates() {
        check_ajax_referer( 'wc_ont_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'order_note_templates';
        $rows  = $wpdb->get_results(
            "SELECT id, title, note_text, note_type FROM {$table} ORDER BY note_type, sort_order, title",
            ARRAY_A
        );

        wp_send_json_success( $rows );
    }

    /**
     * Returns order/subscription meta for variable substitution.
     * Works for both WC_Order and WC_Subscription objects.
     */
    public function get_order_data() {
        check_ajax_referer( 'wc_ont_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( 'Order not found', 404 );
        }

        $data = [
            'order_id'        => $order->get_id(),
            'customer_name'   => $order->get_formatted_billing_full_name(),
            'billing_email'   => $order->get_billing_email(),
            'total'           => $order->get_formatted_order_total(),
            // Subscription-specific (empty for plain orders)
            'subscription_id' => '',
            'next_payment'    => '',
            'start_date'      => '',
        ];

        // If WC Subscriptions is active and this is a subscription object
        if ( class_exists( 'WC_Subscription' ) && $order instanceof WC_Subscription ) {
            $data['subscription_id'] = $order->get_id();
            $next = $order->get_date( 'next_payment' );
            if ( $next ) {
                $data['next_payment'] = date_i18n( get_option( 'date_format' ), strtotime( $next ) );
            }
            $start = $order->get_date( 'start' );
            if ( $start ) {
                $data['start_date'] = date_i18n( get_option( 'date_format' ), strtotime( $start ) );
            }
        }

        wp_send_json_success( $data );
    }
}

new WC_ONT_Ajax();
