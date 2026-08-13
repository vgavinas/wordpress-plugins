<?php
/**
 * Fired when the plugin is uninstalled.
 * Removes plugin options from the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Remove the plugin's options and the transient meta flags it uses
 * ('_hns_activated', '_hns_hold_target', plus '_hns_held_at' and
 * '_hns_escalated' used only by the Pro escalation timer — harmless to
 * remove even if Pro was never active). HPOS stores subscription/order meta
 * in a custom table (wp_wc_orders_meta) instead of wp_postmeta, so
 * delete_post_meta_by_key() alone would silently miss it on HPOS sites.
 */
function hns_uninstall_cleanup_site() {
    global $wpdb;

    delete_option( 'hns_options' );
    delete_option( 'hns_pro_send_info' );
    delete_option( 'hns_pro_product_rules' );
    delete_option( 'hns_pro_escalation' );
    delete_option( 'hns_pro_notifications' );

    $meta_keys = array( '_hns_activated', '_hns_hold_target', '_hns_held_at', '_hns_escalated' );

    $hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

    if ( $hpos_enabled ) {
        $table = $wpdb->prefix . 'wc_orders_meta';
        foreach ( $meta_keys as $meta_key ) {
            // 'meta_key' here is a literal column name for $wpdb->delete()'s WHERE
            // clause, not a WP_Query/WC_Order_Query meta_key argument.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            $wpdb->delete( $table, array( 'meta_key' => $meta_key ) );
        }
    } else {
        foreach ( $meta_keys as $meta_key ) {
            delete_post_meta_by_key( $meta_key );
        }
    }
}

if ( is_multisite() ) {
    $sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
    foreach ( $sites as $site_id ) {
        switch_to_blog( $site_id );
        hns_uninstall_cleanup_site();
        restore_current_blog();
    }
} else {
    hns_uninstall_cleanup_site();
}
