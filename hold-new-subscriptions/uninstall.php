<?php
/**
 * Fired when the plugin is uninstalled.
 * Removes plugin options from the database.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Remove the plugin's option and the two transient meta flags it uses
 * ('_hns_activated', '_hns_hold_target'). HPOS stores subscription/order meta
 * in a custom table (wp_wc_orders_meta) instead of wp_postmeta, so
 * delete_post_meta_by_key() alone would silently miss it on HPOS sites.
 */
function hns_uninstall_cleanup_site() {
    global $wpdb;

    delete_option( 'hns_options' );

    $hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

    if ( $hpos_enabled ) {
        $table = $wpdb->prefix . 'wc_orders_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $table, array( 'meta_key' => '_hns_activated' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->delete( $table, array( 'meta_key' => '_hns_hold_target' ) );
    } else {
        delete_post_meta_by_key( '_hns_activated' );
        delete_post_meta_by_key( '_hns_hold_target' );
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
