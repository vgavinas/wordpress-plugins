<?php
/**
 * Data-access layer for tags and order/tag relationships.
 * Shared by the admin page, meta box, list column, AJAX and Pro modules.
 *
 * @package Order_Tags_Labels_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_OTL_Tags
 */
class WC_OTL_Tags {

	/**
	 * Get all tags, ordered by sort_order.
	 *
	 * @return array[]
	 */
	public static function get_all_tags() {
		global $wpdb;

		$table = $wpdb->prefix . 'order_tags';

		$cached = wp_cache_get( 'wc_otl_all_tags', 'wc_otl' );
		if ( false !== $cached ) {
			return $cached;
		}

		// Custom plugin table — direct query is expected; %i safely escapes the identifier
		// (requires WP 6.2+, our declared minimum) and the result is cached just below.
		$tags = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY sort_order ASC, id ASC', $table ),
			ARRAY_A
		);

		wp_cache_set( 'wc_otl_all_tags', $tags, 'wc_otl', 5 * MINUTE_IN_SECONDS );

		return $tags;
	}

	/**
	 * Whether the current site is allowed to create another tag.
	 *
	 * Tag creation is unrestricted on both Free and Pro — kept as a method
	 * (rather than removed outright) so call sites don't need to change if a
	 * future gating need arises.
	 *
	 * @return bool
	 */
	public static function can_create_more_tags() {
		return true;
	}

	/**
	 * Create a new tag.
	 *
	 * @param string $name       Tag name.
	 * @param string $color      Hex color, e.g. #FF6B6B.
	 * @param int    $sort_order Optional sort order.
	 * @return int|WP_Error New tag ID, or WP_Error on failure.
	 */
	public static function create_tag( $name, $color = '#2271b1', $sort_order = 0 ) {
		global $wpdb;

		$table = $wpdb->prefix . 'order_tags';

		// Custom plugin table — $wpdb->insert() is the standard wpdb API for writes to it,
		// there's no non-direct alternative for a plugin-defined table.
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'name'       => sanitize_text_field( $name ),
				'color'      => sanitize_hex_color( $color ) ? $color : '#2271b1',
				'sort_order' => absint( $sort_order ),
			),
			array( '%s', '%s', '%d' )
		);

		wp_cache_delete( 'wc_otl_all_tags', 'wc_otl' );

		if ( false === $inserted ) {
			return new WP_Error( 'wc_otl_db_error', __( 'Could not create the tag.', 'pro-web-design-order-tags-labels-for-woocommerce' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing tag.
	 *
	 * @param int   $tag_id Tag ID.
	 * @param array $args   Any of: name, color, sort_order.
	 * @return bool
	 */
	public static function update_tag( $tag_id, $args ) {
		global $wpdb;

		$table  = $wpdb->prefix . 'order_tags';
		$data   = array();
		$format = array();

		if ( isset( $args['name'] ) ) {
			$data['name'] = sanitize_text_field( $args['name'] );
			$format[]     = '%s';
		}
		if ( isset( $args['color'] ) && sanitize_hex_color( $args['color'] ) ) {
			$data['color'] = $args['color'];
			$format[]      = '%s';
		}
		if ( isset( $args['sort_order'] ) ) {
			$data['sort_order'] = absint( $args['sort_order'] );
			$format[]           = '%d';
		}

		if ( empty( $data ) ) {
			return false;
		}

		$updated = $wpdb->update( $table, $data, array( 'id' => absint( $tag_id ) ), $format, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		wp_cache_delete( 'wc_otl_all_tags', 'wc_otl' );

		return false !== $updated;
	}

	/**
	 * Delete a tag and all of its order relationships.
	 *
	 * @param int $tag_id Tag ID.
	 * @return bool
	 */
	public static function delete_tag( $tag_id ) {
		global $wpdb;

		$tag_id = absint( $tag_id );

		// Invalidate the per-order cache for every order that carried this tag, before the
		// relationships are gone and we lose the ability to look them up.
		foreach ( self::get_order_ids_for_tag( $tag_id ) as $affected_order_id ) {
			wp_cache_delete( 'wc_otl_order_tags_' . $affected_order_id, 'wc_otl' );
		}

		$wpdb->delete( $wpdb->prefix . 'order_tag_relationships', array( 'tag_id' => $tag_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->delete( $wpdb->prefix . 'order_tags', array( 'id' => $tag_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		wp_cache_delete( 'wc_otl_all_tags', 'wc_otl' );

		return (bool) $deleted;
	}

	/**
	 * Get the tags assigned to a given order.
	 *
	 * Cached per order (5 min TTL) — this is called once per row on the Orders list
	 * column render, so without caching a 20-row list page would run 20 JOIN queries.
	 * Renaming/recoloring a tag can leave this cache briefly (<=5 min) stale, same
	 * trade-off already accepted for get_all_tags().
	 *
	 * @param int $order_id Order ID.
	 * @return array[]
	 */
	public static function get_order_tags( $order_id ) {
		global $wpdb;

		$order_id  = absint( $order_id );
		$cache_key = 'wc_otl_order_tags_' . $order_id;

		$cached = wp_cache_get( $cache_key, 'wc_otl' );
		if ( false !== $cached ) {
			return $cached;
		}

		$tags_table = $wpdb->prefix . 'order_tags';
		$rel_table  = $wpdb->prefix . 'order_tag_relationships';

		// Custom plugin tables — direct query is expected; %i safely escapes both
		// identifiers (requires WP 6.2+, our declared minimum).
		$tags = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				'SELECT t.* FROM %i t INNER JOIN %i r ON r.tag_id = t.id WHERE r.order_id = %d ORDER BY t.sort_order ASC',
				$tags_table,
				$rel_table,
				$order_id
			),
			ARRAY_A
		);

		wp_cache_set( $cache_key, $tags, 'wc_otl', 5 * MINUTE_IN_SECONDS );

		return $tags;
	}

	/**
	 * Assign a tag to an order (idempotent).
	 *
	 * @param int $order_id Order ID.
	 * @param int $tag_id   Tag ID.
	 * @return bool
	 */
	public static function assign_tag( $order_id, $tag_id ) {
		global $wpdb;

		$order_id = absint( $order_id );

		$result = $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'order_tag_relationships',
			array(
				'order_id' => $order_id,
				'tag_id'   => absint( $tag_id ),
			),
			array( '%d', '%d' )
		);

		wp_cache_delete( 'wc_otl_order_tags_' . $order_id, 'wc_otl' );

		if ( false !== $result ) {
			/**
			 * Fires after a tag has been assigned to an order.
			 *
			 * @param int $order_id Order ID.
			 * @param int $tag_id   Tag ID.
			 */
			do_action( 'wc_otl_tag_assigned', $order_id, $tag_id );
		}

		return false !== $result;
	}

	/**
	 * Remove a tag from an order.
	 *
	 * @param int $order_id Order ID.
	 * @param int $tag_id   Tag ID.
	 * @return bool
	 */
	public static function remove_tag( $order_id, $tag_id ) {
		global $wpdb;

		$order_id = absint( $order_id );

		$result = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'order_tag_relationships',
			array(
				'order_id' => $order_id,
				'tag_id'   => absint( $tag_id ),
			),
			array( '%d', '%d' )
		);

		wp_cache_delete( 'wc_otl_order_tags_' . $order_id, 'wc_otl' );

		if ( $result ) {
			do_action( 'wc_otl_tag_removed', $order_id, $tag_id );
		}

		return (bool) $result;
	}

	/**
	 * Get order IDs that have a given tag — used by the list column filter and export.
	 *
	 * @param int $tag_id Tag ID.
	 * @return int[]
	 */
	public static function get_order_ids_for_tag( $tag_id ) {
		global $wpdb;

		$rel_table = $wpdb->prefix . 'order_tag_relationships';

		// Custom plugin table — direct query is expected; %i safely escapes the identifier
		// (requires WP 6.2+, our declared minimum). Not cached: used by Pro-only, low-frequency
		// paths (list filter dropdown selection, CSV export, tag deletion cache invalidation).
		return array_map(
			'absint',
			$wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( 'SELECT order_id FROM %i WHERE tag_id = %d', $rel_table, absint( $tag_id ) )
			)
		);
	}

	/**
	 * Delete all tag relationships for an order. Hooked to `woocommerce_delete_order`
	 * (permanent delete, fires the same way on both legacy post-based storage and HPOS)
	 * so relationship rows don't pile up as orphaned data once the order itself is gone —
	 * trashing an order is left alone since a trashed order can still be restored.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function delete_relationships_for_order( $order_id ) {
		global $wpdb;

		$order_id = absint( $order_id );

		if ( ! $order_id ) {
			return;
		}

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . 'order_tag_relationships',
			array( 'order_id' => $order_id ),
			array( '%d' )
		);

		wp_cache_delete( 'wc_otl_order_tags_' . $order_id, 'wc_otl' );
	}
}
