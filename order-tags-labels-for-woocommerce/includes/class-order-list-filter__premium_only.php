<?php
/**
 * Filter the Orders list by tag — Pro feature.
 *
 * Adds the "All tags" dropdown above the Orders list and applies it to the
 * orders query. Lives in a file suffixed __premium_only, which the Freemius
 * build process strips from the free distribution entirely, so this code is
 * physically absent from the free build rather than merely inactive in it.
 *
 * Compatible with both HPOS and the legacy list table.
 *
 * @package Order_Tags_Labels_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_OTL_Order_List_Filter
 */
class WC_OTL_Order_List_Filter {

	/**
	 * Singleton instance.
	 *
	 * @var WC_OTL_Order_List_Filter|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WC_OTL_Order_List_Filter
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
		if ( $this->is_hpos_enabled() ) {
			add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_filter_dropdown' ), 10, 2 );
			add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'apply_filter_hpos' ) );
		} else {
			add_action( 'restrict_manage_posts', array( $this, 'render_filter_dropdown_legacy' ), 10, 2 );
			add_filter( 'request', array( $this, 'apply_filter_legacy' ) );
		}
	}

	/**
	 * Whether HPOS (custom order tables) is the active storage.
	 *
	 * @return bool
	 */
	private function is_hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Render the filter dropdown above the orders list (HPOS).
	 *
	 * Fired via `woocommerce_order_list_table_restrict_manage_orders` which WooCommerce's
	 * ListTable::extra_tablenav() triggers for BOTH the top and bottom table nav — guard on
	 * $which so we only render the <select> once (a duplicate at the bottom would confuse
	 * the "Filter" button, which submits the top form).
	 *
	 * @param string $order_type Order type of the current list screen (e.g. 'shop_order').
	 * @param string $which      'top' or 'bottom'.
	 */
	public function render_filter_dropdown( $order_type = 'shop_order', $which = 'top' ) {
		if ( 'shop_order' !== $order_type || 'top' !== $which ) {
			return;
		}
		$this->render_filter_dropdown_markup();
	}

	/**
	 * Render the filter dropdown above the orders list (legacy).
	 *
	 * `restrict_manage_posts` also fires for both 'top' and 'bottom' — same guard as above.
	 *
	 * @param string $post_type Current post type of the list screen.
	 * @param string $which     'top' or 'bottom'.
	 */
	public function render_filter_dropdown_legacy( $post_type, $which = 'top' ) {
		if ( 'shop_order' !== $post_type || 'top' !== $which ) {
			return;
		}
		$this->render_filter_dropdown_markup();
	}

	/**
	 * Shared dropdown markup.
	 */
	private function render_filter_dropdown_markup() {
		$tags     = WC_OTL_Tags::get_all_tags();
		$selected = isset( $_GET['wc_otl_tag'] ) ? absint( $_GET['wc_otl_tag'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( empty( $tags ) ) {
			return;
		}
		?>
		<select name="wc_otl_tag">
			<option value="0"><?php esc_html_e( 'All tags', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></option>
			<?php foreach ( $tags as $tag ) : ?>
				<option value="<?php echo esc_attr( $tag['id'] ); ?>" <?php selected( $selected, $tag['id'] ); ?>>
					<?php echo esc_html( $tag['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Apply the tag filter to the HPOS orders query.
	 *
	 * @param array $query_args Query args passed to the orders query.
	 * @return array
	 */
	public function apply_filter_hpos( $query_args ) {
		$tag_id = isset( $_GET['wc_otl_tag'] ) ? absint( $_GET['wc_otl_tag'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $tag_id ) {
			$order_ids               = WC_OTL_Tags::get_order_ids_for_tag( $tag_id );
			$query_args['post__in']  = ! empty( $order_ids ) ? $order_ids : array( 0 );
		}

		return $query_args;
	}

	/**
	 * Apply the tag filter to the legacy WP_Query request.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public function apply_filter_legacy( $vars ) {
		global $pagenow;

		if ( 'edit.php' !== $pagenow || ! isset( $vars['post_type'] ) || 'shop_order' !== $vars['post_type'] ) {
			return $vars;
		}

		$tag_id = isset( $_GET['wc_otl_tag'] ) ? absint( $_GET['wc_otl_tag'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $tag_id ) {
			$order_ids       = WC_OTL_Tags::get_order_ids_for_tag( $tag_id );
			$vars['post__in'] = ! empty( $order_ids ) ? $order_ids : array( 0 );
		}

		return $vars;
	}
}
