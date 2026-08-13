<?php
/**
 * Plugin Name:       Order Tags & Labels for WooCommerce
 * Plugin URI:        https://www.pro-webdesign.co.uk/plugins/order-tags-labels-for-woocommerce
 * Description:       Organize WooCommerce orders with color-coded tags. Assign tags manually or automatically, filter and bulk-manage tagged orders.
 * Version:           1.1.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Pro Technologies Limited
 * Author URI:        https://www.pro-webdesign.co.uk
 * Text Domain:       order-tags-labels-for-woocommerce
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce
 * WC requires at least: 7.1
 * WC tested up to:   11.0
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Order_Tags_Labels_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

// -----------------------------------------------------------------------
// Constants
// -----------------------------------------------------------------------
define( 'WC_OTL_VERSION', '1.1.2' );
define( 'WC_OTL_FILE', __FILE__ );
define( 'WC_OTL_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_OTL_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_OTL_BASENAME', plugin_basename( __FILE__ ) );

// -----------------------------------------------------------------------
// Freemius
// -----------------------------------------------------------------------
if ( ! function_exists( 'wc_otl_fs' ) ) {

	/**
	 * Create a helper function for easy SDK access.
	 *
	 * Returns `false` (instead of fataling) if the Freemius SDK hasn't been
	 * placed in vendor/freemius/ yet — e.g. during local development/testing
	 * before the Freemius product has been created. Every call site treats a
	 * falsy return as "Pro features unavailable", so the plugin stays fully
	 * activatable and usable on the Free feature set with no SDK present.
	 *
	 * @return object|false Freemius SDK instance, or false if unavailable.
	 */
	function wc_otl_fs() {
		global $wc_otl_fs;

		if ( ! isset( $wc_otl_fs ) ) {
			$freemius_start = WC_OTL_PATH . 'vendor/freemius/start.php';

			if ( ! file_exists( $freemius_start ) ) {
				// SDK not installed yet. Don't cache this as a permanent "false" in
				// the global so that dropping the SDK in later (without a page
				// reload loop, e.g. next request) is picked up automatically —
				// but do return false for *this* request instead of fataling.
				return false;
			}

			require_once $freemius_start;

			$wc_otl_fs = fs_dynamic_init(
				array(
					'id'                  => '36737',
					'slug'                => 'order-tags-labels-for-woocommerce',
					'type'                => 'plugin',
					'public_key'          => 'pk_ed7f9e00dc2e1fe5a95cfbb9117a7',
					'is_premium'          => false,
					'premium_suffix'      => 'Professional',
					'has_addons'          => false,
					'has_paid_plans'      => true,
					'menu'                => array(
						'slug'    => 'wc-order-tags',
						'account' => true,
						'support' => false,
						'contact' => false,
						'parent'  => array(
							'slug' => 'woocommerce',
						),
					),
					'is_org_compliant'    => true,
				)
			);
		}

		return $wc_otl_fs;
	}

	// Init Freemius (no-op / returns false if the SDK isn't present yet — see wc_otl_fs() above).
	wc_otl_fs();
	// Signal that SDK was initiated.
	do_action( 'wc_otl_fs_loaded' );

	// Register uninstall cleanup via the Freemius `after_uninstall` hook instead of a
	// standalone uninstall.php file. A plugin-defined uninstall.php prevents Freemius from
	// reliably tracking the uninstall event (and the user's uninstall feedback) due to a
	// WordPress limitation, so the SDK requires cleanup to run through this hook instead.
	if ( wc_otl_fs() ) {
		wc_otl_fs()->add_action( 'after_uninstall', 'wc_otl_uninstall_cleanup' );
	}
}

/**
 * Uninstall cleanup, run via the Freemius `after_uninstall` hook (see registration above)
 * instead of a standalone uninstall.php file.
 *
 * By default plugin data is kept, in case the store owner reinstalls later — data is only
 * removed if they explicitly opted in via WooCommerce → Order Tags → Settings → "Delete all
 * … when this plugin is deleted".
 */
function wc_otl_uninstall_cleanup() {
	// Respect the opt-in — do nothing unless the store owner asked us to clean up.
	if ( ! get_option( 'wc_otl_delete_data_on_uninstall' ) ) {
		return;
	}

	global $wpdb;

	// Drop our custom tables (%i safely escapes the identifiers, requires WP 6.2+, our declared
	// minimum). A direct schema-changing query is inherent to removing custom tables on uninstall —
	// there is no wpdb API or caching layer for DROP TABLE, and this only ever runs once, on
	// plugin deletion, never on a normal request.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'order_tag_relationships' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'order_tag_rules' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'order_tags' ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

	// Remove plugin options.
	delete_option( 'wc_otl_version' );
	delete_option( 'wc_otl_seeded_defaults' );
	delete_option( 'wc_otl_delete_data_on_uninstall' );

	// Clear any cached tag lookups.
	wp_cache_delete( 'wc_otl_all_tags', 'wc_otl' );
}

// -----------------------------------------------------------------------
// WooCommerce dependency check
// -----------------------------------------------------------------------
/**
 * Bail with an admin notice if WooCommerce isn't active.
 */
function wc_otl_missing_woocommerce_notice() {
	echo '<div class="notice notice-error"><p>';
	esc_html_e( 'Order Tags & Labels for WooCommerce requires WooCommerce to be installed and active.', 'order-tags-labels-for-woocommerce' );
	echo '</p></div>';
}

/**
 * Bootstraps the plugin once all plugins are loaded, guarded by a WooCommerce check.
 */
function wc_otl_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wc_otl_missing_woocommerce_notice' );
		return;
	}

	require_once WC_OTL_PATH . 'includes/class-wc-otl-install.php';
	require_once WC_OTL_PATH . 'includes/class-wc-otl-tags.php';
	require_once WC_OTL_PATH . 'includes/class-admin-page.php';
	require_once WC_OTL_PATH . 'includes/class-order-meta-box.php';
	require_once WC_OTL_PATH . 'includes/class-order-list-column.php';
	require_once WC_OTL_PATH . 'includes/class-ajax.php';

	/*
	 * Pro modules live in files suffixed __premium_only, which the Freemius build
	 * process strips from the Free distribution entirely — the files simply do not
	 * exist in the Free zip. Load them defensively (file_exists + can_use_premium_code)
	 * so the plugin behaves correctly whichever package is installed.
	 */
	if ( wc_otl_fs() && wc_otl_fs()->can_use_premium_code() ) {
		// Self-heal the Pro-only schema for a site that upgraded from free to Pro
		// without a fresh plugin activation (e.g. license activation in place).
		WC_OTL_Install::maybe_upgrade_pro_schema();

		$pro_modules = array(
			'class-auto-tag-rules__premium_only.php',
			'class-bulk-actions__premium_only.php',
			'class-export__premium_only.php',
			'class-order-list-filter__premium_only.php',
		);
		foreach ( $pro_modules as $pro_module ) {
			$file = WC_OTL_PATH . 'includes/' . $pro_module;
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}

	WC_OTL_Admin_Page::instance();
	WC_OTL_Order_Meta_Box::instance();
	WC_OTL_Order_List_Column::instance();
	WC_OTL_Ajax::instance();

	// Clean up tag relationships once an order is permanently deleted, on Free and Pro
	// alike (tag assignment itself is a Free feature). Trashing is left alone — a
	// trashed order can still be restored, so its tags should be too.
	add_action( 'woocommerce_delete_order', array( 'WC_OTL_Tags', 'delete_relationships_for_order' ) );

	if ( class_exists( 'WC_OTL_Auto_Tag_Rules' ) ) {
		WC_OTL_Auto_Tag_Rules::instance();
	}
	if ( class_exists( 'WC_OTL_Bulk_Actions' ) ) {
		WC_OTL_Bulk_Actions::instance();
	}
	if ( class_exists( 'WC_OTL_Export' ) ) {
		WC_OTL_Export::instance();
	}
	if ( class_exists( 'WC_OTL_Order_List_Filter' ) ) {
		WC_OTL_Order_List_Filter::instance();
	}

	// No load_plugin_textdomain() call here: since WP 4.6, WordPress.org automatically
	// loads translations for plugins hosted there whose text domain matches the plugin
	// slug (order-tags-labels-for-woocommerce) — a manual call is redundant and discouraged.
}
add_action( 'plugins_loaded', 'wc_otl_bootstrap', 20 );

// -----------------------------------------------------------------------
// HPOS compatibility declaration
// -----------------------------------------------------------------------
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				WC_OTL_FILE,
				true
			);
		}
	}
);

// -----------------------------------------------------------------------
// Activation / deactivation
// -----------------------------------------------------------------------
register_activation_hook(
	WC_OTL_FILE,
	function () {
		require_once WC_OTL_PATH . 'includes/class-wc-otl-install.php';
		WC_OTL_Install::activate();
	}
);
