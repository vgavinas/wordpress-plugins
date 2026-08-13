<?php
/**
 * Pro: bulk "Add tag" / "Remove tag" actions on the WooCommerce Orders list.
 * Compatible with both HPOS and the legacy list table.
 *
 * @package Order_Tags_Labels_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_OTL_Bulk_Actions
 */
class WC_OTL_Bulk_Actions {

	/**
	 * Singleton instance.
	 *
	 * @var WC_OTL_Bulk_Actions|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WC_OTL_Bulk_Actions
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Register the bulk_actions/handle_bulk_actions filters from inside the
		// `current_screen` hook rather than pre-computing the screen ID via
		// wc_get_page_screen_id() at plugins_loaded time. The orders LIST screen
		// and the single-order EDIT screen can resolve to different screen IDs
		// under HPOS depending on the WooCommerce version/page controller, so a
		// value that works for the edit-screen meta box (see
		// WC_OTL_Order_Meta_Box) is not guaranteed to match the list screen the
		// bulk actions dropdown actually renders on. Reading
		// get_current_screen()->id directly, once WordPress has determined it
		// for the real request, is the only way to get an exact match in both
		// HPOS and legacy (posts table) installs.
		add_action( 'current_screen', array( $this, 'maybe_register_for_current_screen' ) );
		add_action( 'admin_notices', array( $this, 'bulk_action_admin_notice' ) );
	}

	/**
	 * Hook the bulk actions filters onto whatever screen ID WordPress resolved
	 * for the current request, if (and only if) it's the orders list screen.
	 *
	 * @param WP_Screen $screen Current screen object.
	 */
	public function maybe_register_for_current_screen( $screen ) {
		if ( ! $screen || ! $this->is_orders_list_screen( $screen ) ) {
			return;
		}

		add_filter( "bulk_actions-{$screen->id}", array( $this, 'register_bulk_actions' ) );
		add_filter( "handle_bulk_actions-{$screen->id}", array( $this, 'handle_bulk_actions' ), 10, 3 );
	}

	/**
	 * Determine whether the given screen is the orders list table (HPOS or legacy).
	 * Deliberately excludes the single-order edit screen: WooCommerce reuses the
	 * same base screen ID for both list and edit views under HPOS, so we also
	 * check $_GET['action'] to avoid registering list-only bulk actions while
	 * viewing/editing a single order.
	 *
	 * @param WP_Screen $screen Current screen object.
	 * @return bool
	 */
	private function is_orders_list_screen( $screen ) {
		$is_orders_screen = ( 'edit-shop_order' === $screen->id )
			|| ( false !== strpos( $screen->id, 'wc-orders' ) );

		if ( ! $is_orders_screen ) {
			return false;
		}

		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return ! in_array( $action, array( 'edit', 'new' ), true );
	}

	/**
	 * Add one "Tag: X" bulk action per existing tag, plus a "Remove all tags" action.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function register_bulk_actions( $actions ) {
		foreach ( WC_OTL_Tags::get_all_tags() as $tag ) {
			$actions[ 'wc_otl_add_tag_' . $tag['id'] ] = sprintf(
				/* translators: %s: tag name. */
				__( 'Add tag: %s', 'pro-web-design-order-tags-labels-for-woocommerce' ),
				$tag['name']
			);
			$actions[ 'wc_otl_remove_tag_' . $tag['id'] ] = sprintf(
				/* translators: %s: tag name. */
				__( 'Remove tag: %s', 'pro-web-design-order-tags-labels-for-woocommerce' ),
				$tag['name']
			);
		}

		return $actions;
	}

	/**
	 * Process the bulk action against the selected order IDs.
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $action      Bulk action key.
	 * @param int[]  $order_ids   Selected order IDs.
	 * @return string
	 */
	public function handle_bulk_actions( $redirect_to, $action, $order_ids ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		$count = 0;

		if ( preg_match( '/^wc_otl_add_tag_(\d+)$/', $action, $matches ) ) {
			$tag_id = absint( $matches[1] );
			foreach ( $order_ids as $order_id ) {
				if ( WC_OTL_Tags::assign_tag( $order_id, $tag_id ) ) {
					$count++;
				}
			}
			$redirect_to = add_query_arg( 'wc_otl_tagged', $count, $redirect_to );
		} elseif ( preg_match( '/^wc_otl_remove_tag_(\d+)$/', $action, $matches ) ) {
			$tag_id = absint( $matches[1] );
			foreach ( $order_ids as $order_id ) {
				if ( WC_OTL_Tags::remove_tag( $order_id, $tag_id ) ) {
					$count++;
				}
			}
			$redirect_to = add_query_arg( 'wc_otl_untagged', $count, $redirect_to );
		}

		return $redirect_to;
	}

	/**
	 * Show a confirmation notice after a bulk tag action runs.
	 */
	public function bulk_action_admin_notice() {
		if ( ! empty( $_REQUEST['wc_otl_tagged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of orders tagged. */
						_n( '%d order tagged.', '%d orders tagged.', absint( $_REQUEST['wc_otl_tagged'] ), 'pro-web-design-order-tags-labels-for-woocommerce' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						absint( $_REQUEST['wc_otl_tagged'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					)
				)
			);
		}

		if ( ! empty( $_REQUEST['wc_otl_untagged'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of orders untagged. */
						_n( '%d order updated.', '%d orders updated.', absint( $_REQUEST['wc_otl_untagged'] ), 'pro-web-design-order-tags-labels-for-woocommerce' ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						absint( $_REQUEST['wc_otl_untagged'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					)
				)
			);
		}
	}
}
