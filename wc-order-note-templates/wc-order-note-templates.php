<?php
/**
 * Plugin Name: WooCommerce Order Note Templates
 * Plugin URI:  https://wordpress.org/plugins/wc-order-note-templates/
 * Description: Save and reuse order note templates in WooCommerce admin. Works with HPOS and WooCommerce Subscriptions.
 * Version:     1.0.1
 * Author:      Pro Technologies Limited
 * Author URI:  https://pro-technologies.co.uk
 * Text Domain: wc-ont
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * Tested up to: 7.0
 * WC tested up to: 10.8
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_ONT_VERSION', '1.0.1' );
define( 'WC_ONT_FILE',    __FILE__ );
define( 'WC_ONT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'WC_ONT_URL',     plugin_dir_url( __FILE__ ) );

/* -------------------------------------------------------------------------
 * HPOS compatibility declaration
 * ---------------------------------------------------------------------- */
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

/* -------------------------------------------------------------------------
 * Activation
 * ---------------------------------------------------------------------- */
register_activation_hook( WC_ONT_FILE, 'wc_ont_activate' );
function wc_ont_activate() {
    wc_ont_create_table();
    wc_ont_insert_defaults();
}

function wc_ont_create_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'order_note_templates';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        title       VARCHAR(200)    NOT NULL,
        note_text   TEXT            NOT NULL,
        note_type   VARCHAR(20)     NOT NULL DEFAULT 'customer',
        sort_order  INT             NOT NULL DEFAULT 0,
        created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY note_type (note_type),
        KEY sort_order (sort_order)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    update_option( 'wc_ont_db_version', WC_ONT_VERSION );
}

function wc_ont_insert_defaults() {
    global $wpdb;
    $table = $wpdb->prefix . 'order_note_templates';

    if ( $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
        return;
    }

    $defaults = [
        [ 'Order received',       'Your order #{order_id} has been received and is being processed. We will notify you when it ships.',          'customer', 10 ],
        [ 'Order shipped',        'Your order #{order_id} has been shipped. Tracking number: [enter tracking]. Expected delivery: 3-5 days.',    'customer', 20 ],
        [ 'Shipping delay',       'Dear {customer_name}, shipment of order #{order_id} is delayed by 1-2 days. We apologise for the inconvenience.', 'customer', 30 ],
        [ 'Clarification needed', 'Please clarify the details of order #{order_id}: [specify what needs clarification].',                        'customer', 40 ],
        [ 'Refund approved',      'Refund for order #{order_id} has been approved. Funds will arrive within 5-7 business days.',                 'customer', 50 ],
        [ '[Internal] Awaiting stock',   'Waiting for warehouse stock confirmation.',   'internal', 10 ],
        [ '[Internal] Payment issue',    'Manual payment verification required.',       'internal', 20 ],
        [ '[Internal] VIP customer',     'VIP customer — priority processing.',         'internal', 30 ],
    ];

    foreach ( $defaults as $d ) {
        $wpdb->insert( $table, [
            'title'      => $d[0],
            'note_text'  => $d[1],
            'note_type'  => $d[2],
            'sort_order' => $d[3],
        ] );
    }
}

/* -------------------------------------------------------------------------
 * Boot
 * ---------------------------------------------------------------------- */
add_action( 'plugins_loaded', 'wc_ont_init' );
function wc_ont_init() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>WC Order Note Templates</strong>: WooCommerce must be active.</p></div>';
        } );
        return;
    }

    if ( get_option( 'wc_ont_db_version' ) !== WC_ONT_VERSION ) {
        wc_ont_create_table();
        update_option( 'wc_ont_db_version', WC_ONT_VERSION );
    }

    require_once WC_ONT_DIR . 'includes/class-admin-page.php';
    require_once WC_ONT_DIR . 'includes/class-order-meta-box.php';
    require_once WC_ONT_DIR . 'includes/class-ajax.php';
}
