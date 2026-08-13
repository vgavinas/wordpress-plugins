<?php
/**
 * Handles plugin activation: DB table creation and default options.
 *
 * @package Order_Tags_Labels_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_OTL_Install
 */
class WC_OTL_Install {

	/**
	 * Run on plugin activation.
	 */
	public static function activate() {
		self::create_tables();
		self::maybe_seed_default_tags();
		update_option( 'wc_otl_version', WC_OTL_VERSION );
		flush_rewrite_rules();
	}

	/**
	 * Make sure the Pro-only `order_tag_rules` table exists.
	 *
	 * Covers a site that was activated on the free plan (so the table was never
	 * created) and later upgrades to Pro without a fresh activation — e.g. via
	 * license activation rather than a plugin swap. Cheap to call on every
	 * `plugins_loaded` when Pro: a single SHOW TABLES lookup, and dbDelta() only
	 * runs when the table is actually missing.
	 */
	public static function maybe_upgrade_pro_schema() {
		global $wpdb;

		$rules_table = $wpdb->prefix . 'order_tag_rules';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rules_table ) );

		if ( $exists ) {
			return;
		}

		self::create_tables();
	}

	/**
	 * Create the plugin's custom tables.
	 *
	 * The `order_tag_rules` table backs Auto-Tag Rules, a Pro-only feature — the
	 * free build must not create it at all. Split into two dbDelta() calls so the
	 * Pro-only table is only ever created when running the Pro build.
	 */
	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$tags_table          = $wpdb->prefix . 'order_tags';
		$relationships_table = $wpdb->prefix . 'order_tag_relationships';

		$sql = "CREATE TABLE {$tags_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(100) NOT NULL,
			color VARCHAR(7) NOT NULL DEFAULT '#2271b1',
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY sort_order (sort_order)
		) {$charset_collate};

		CREATE TABLE {$relationships_table} (
			order_id BIGINT UNSIGNED NOT NULL,
			tag_id BIGINT UNSIGNED NOT NULL,
			assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (order_id, tag_id),
			KEY tag_id (tag_id)
		) {$charset_collate};";

		dbDelta( $sql );

		if ( function_exists( 'wc_otl_fs' ) && wc_otl_fs() && wc_otl_fs()->can_use_premium_code() ) {
			$rules_table = $wpdb->prefix . 'order_tag_rules';

			$pro_sql = "CREATE TABLE {$rules_table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				tag_id BIGINT UNSIGNED NOT NULL,
				condition_json LONGTEXT NOT NULL,
				enabled TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY tag_id (tag_id)
			) {$charset_collate};";

			dbDelta( $pro_sql );
		}
	}

	/**
	 * Seed a handful of default tags on first activation only (won't re-run on upgrades).
	 */
	private static function maybe_seed_default_tags() {
		if ( get_option( 'wc_otl_seeded_defaults' ) ) {
			return;
		}

		require_once WC_OTL_PATH . 'includes/class-wc-otl-tags.php';

		$defaults = array(
			array(
				'name'  => __( 'Urgent', 'pro-web-design-order-tags-labels-for-woocommerce' ),
				'color' => '#e53935',
			),
			array(
				'name'  => __( 'VIP', 'pro-web-design-order-tags-labels-for-woocommerce' ),
				'color' => '#8e24aa',
			),
			array(
				'name'  => __( 'Follow Up', 'pro-web-design-order-tags-labels-for-woocommerce' ),
				'color' => '#fb8c00',
			),
		);

		foreach ( $defaults as $index => $tag ) {
			WC_OTL_Tags::create_tag( $tag['name'], $tag['color'], $index );
		}

		update_option( 'wc_otl_seeded_defaults', 1 );
	}
}
