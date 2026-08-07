<?php
/**
 * Plugin Name: Order Note Templates for WooCommerce
 * Plugin URI:  https://wordpress.org/plugins/order-note-templates-for-woocommerce/
 * Description: Save and reuse order note templates in WooCommerce admin. Works with HPOS and WooCommerce Subscriptions.
 * Version:     1.1.4
 * Author:      Pro Technologies Limited
 * Author URI:  https://pro-webdesign.co.uk
 * Text Domain: order-note-templates-for-woocommerce
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

// Auto-deactivate free version when Pro is activated
if ( function_exists( 'ontfw_fs' ) ) {
    ontfw_fs()->set_basename( true, __FILE__ );
} else {
    /**
     * DO NOT REMOVE THIS IF. IT IS ESSENTIAL FOR THE
     * `function_exists` CALL ABOVE TO PROPERLY WORK.
     */
    if ( ! function_exists( 'ontfw_fs' ) ) {

        define( 'WC_ONT_VERSION', '1.1.4' );
        define( 'WC_ONT_FILE',    __FILE__ );
        define( 'WC_ONT_DIR',     plugin_dir_path( __FILE__ ) );
        define( 'WC_ONT_URL',     plugin_dir_url( __FILE__ ) );

        /* -------------------------------------------------------------------------
         * Freemius SDK
         * ---------------------------------------------------------------------- */
        function ontfw_fs() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
            global $ontfw_fs; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

            if ( ! isset( $ontfw_fs ) ) {
                require_once dirname( __FILE__ ) . '/vendor/freemius/start.php';

                $ontfw_fs = fs_dynamic_init( array( // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
                    'id'                  => '36694',
                    'slug'                => 'order-note-templates-for-woocommerce',
                    'type'                => 'plugin',
                    'public_key'          => 'pk_cf33727630f61efd2baf1a4b67938',
                    'is_premium'          => true,
                    'premium_suffix'      => 'Professional',
                    'has_premium_version' => true,
                    'has_addons'          => false,
                    'has_paid_plans'      => true,
                    'is_org_compliant'    => true,
                    // Automatically removed in the free version.
                    'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                    'trial'               => array(
                        'days'               => 14,
                        'is_require_payment' => false,
                    ),
                    'menu'                => array(
                        'support' => false,
                    ),
                ) );
            }

            return $ontfw_fs;
        }

        ontfw_fs();
        do_action( 'ontfw_fs_loaded' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

        /* -------------------------------------------------------------------------
         * HPOS compatibility
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
         * Helpers
         * ---------------------------------------------------------------------- */
        function wc_ont_is_pro() {
            return function_exists( 'ontfw_fs' ) && ontfw_fs()->can_use_premium_code__premium_only();
        }

        define( 'WC_ONT_FREE_LIMIT', 3 );

        /* -------------------------------------------------------------------------
         * Activation
         * ---------------------------------------------------------------------- */
        register_activation_hook( __FILE__, 'wc_ont_activate' );
        function wc_ont_activate() {
            wc_ont_create_table();
            wc_ont_insert_defaults();
        }

        /**
         * Create or upgrade the templates table.
         *
         * The full schema — including the columns used only by Pro features —
         * lives here so that dbDelta() can add anything that is missing on an
         * existing install. Note: no "IF NOT EXISTS"; dbDelta() parses the table
         * name with a regex and would read "IF" as the table name.
         */
        function wc_ont_create_table() {
            global $wpdb;
            $table   = $wpdb->prefix . 'order_note_templates';
            $charset = $wpdb->get_charset_collate();

            // dbDelta is picky: one field per line, two spaces after PRIMARY KEY.
            $sql = "CREATE TABLE {$table} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                title varchar(200) NOT NULL,
                note_text text NOT NULL,
                note_type varchar(20) NOT NULL DEFAULT 'customer',
                category varchar(100) NOT NULL DEFAULT '',
                pdf_attachment varchar(500) NOT NULL DEFAULT '',
                sort_order int(11) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY  (id),
                KEY note_type (note_type),
                KEY category (category),
                KEY sort_order (sort_order)
            ) {$charset};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );

            update_option( 'wc_ont_db_version', WC_ONT_VERSION );
        }

        /**
         * True when the given column exists on the templates table.
         */
        function wc_ont_column_exists( $column ) {
            global $wpdb;
            $table = $wpdb->prefix . 'order_note_templates';

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $found = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );

            return ! empty( $found );
        }

        function wc_ont_insert_defaults() {
            global $wpdb;
            $table = esc_sql( $wpdb->prefix . 'order_note_templates' );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0 ) {
                return;
            }

            $defaults = array(
                array( 'Order received',            'Your order #{order_id} has been received and is being processed.',    'customer', 10 ),
                array( 'Order shipped',             'Your order #{order_id} has been shipped. Expected delivery: 3-5 days.', 'customer', 20 ),
                array( '[Internal] Awaiting stock', 'Waiting for warehouse stock confirmation.',                           'internal', 10 ),
            );

            foreach ( $defaults as $d ) {
                $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                    $wpdb->prefix . 'order_note_templates',
                    array(
                        'title'      => $d[0],
                        'note_text'  => $d[1],
                        'note_type'  => $d[2],
                        'sort_order' => $d[3],
                    )
                );
            }
        }

        /* -------------------------------------------------------------------------
         * Boot
         * ---------------------------------------------------------------------- */
        add_action( 'plugins_loaded', 'wc_ont_init' );
        function wc_ont_init() {
            if ( ! class_exists( 'WooCommerce' ) ) {
                add_action( 'admin_notices', function () {
                    echo '<div class="notice notice-error"><p><strong>Order Note Templates for WooCommerce</strong>: WooCommerce must be active.</p></div>';
                } );
                return;
            }

            /*
             * Run the schema migration synchronously, before anything that
             * writes to the table is loaded. Do not defer this to a hook —
             * we are already inside plugins_loaded here.
             */
            if ( get_option( 'wc_ont_db_version' ) !== WC_ONT_VERSION
                || ! wc_ont_column_exists( 'category' )
                || ! wc_ont_column_exists( 'pdf_attachment' ) ) {
                wc_ont_create_table();
            }

            require_once WC_ONT_DIR . 'includes/class-admin-page.php';
            require_once WC_ONT_DIR . 'includes/class-order-meta-box.php';
            require_once WC_ONT_DIR . 'includes/class-ajax.php';
        }

    } // end if ( ! function_exists )
} // end else
