<?php
/**
 * Adds a "Tags" column to the WooCommerce Orders list, showing tag pills.
 * Compatible with both HPOS and the legacy list table.
 *
 * The filter-by-tag dropdown (Pro) lives entirely in
 * class-order-list-filter__premium_only.php — see that file.
 *
 * @package Order_Tags_Labels_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_OTL_Order_List_Column
 */
class WC_OTL_Order_List_Column {

	/**
	 * Singleton instance.
	 *
	 * @var WC_OTL_Order_List_Column|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WC_OTL_Order_List_Column
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
			add_filter( 'woocommerce_shop_order_list_table_columns', array( $this, 'add_column' ) );
			add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_column' ), 10, 2 );
		} else {
			add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
			add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_legacy' ), 10, 2 );
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
	 * Insert the "Tags" column right after the Order column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'order_number' === $key || 'order_title' === $key ) {
				$new_columns['order_tags'] = __( 'Tags', 'order-tags-labels-for-woocommerce' );
			}
		}

		if ( ! isset( $new_columns['order_tags'] ) ) {
			$new_columns['order_tags'] = __( 'Tags', 'order-tags-labels-for-woocommerce' );
		}

		return $new_columns;
	}

	/**
	 * Render the column content (HPOS).
	 *
	 * @param string   $column Column key.
	 * @param WC_Order $order  Order object.
	 */
	public function render_column( $column, $order ) {
		if ( 'order_tags' !== $column ) {
			return;
		}
		$this->render_pills( $order->get_id() );
	}

	/**
	 * Render the column content (legacy post-based orders).
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Order/post ID.
	 */
	public function render_column_legacy( $column, $post_id ) {
		if ( 'order_tags' !== $column ) {
			return;
		}
		$this->render_pills( $post_id );
	}

	/**
	 * Echo the tag pills for a given order.
	 *
	 * @param int $order_id Order ID.
	 */
	private function render_pills( $order_id ) {
		$tags = WC_OTL_Tags::get_order_tags( $order_id );

		if ( empty( $tags ) ) {
			echo '&#8212;';
			return;
		}

		foreach ( $tags as $tag ) {
			printf(
				'<span class="wc-otl-pill wc-otl-pill-small" style="background-color:%s">%s</span> ',
				esc_attr( $tag['color'] ),
				esc_html( $tag['name'] )
			);
		}
	}

}
