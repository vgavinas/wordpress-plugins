<?php
/**
 * AJAX handlers for tag assignment and tag CRUD from the admin UI.
 *
 * @package Order_Tags_Labels_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_OTL_Ajax
 */
class WC_OTL_Ajax {

	/**
	 * Singleton instance.
	 *
	 * @var WC_OTL_Ajax|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WC_OTL_Ajax
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
		add_action( 'wp_ajax_wc_otl_assign_tag', array( $this, 'assign_tag' ) );
		add_action( 'wp_ajax_wc_otl_remove_tag', array( $this, 'remove_tag' ) );
		add_action( 'wp_ajax_wc_otl_create_tag', array( $this, 'create_tag' ) );
		add_action( 'wp_ajax_wc_otl_update_tag', array( $this, 'update_tag' ) );
		add_action( 'wp_ajax_wc_otl_delete_tag', array( $this, 'delete_tag' ) );
		add_action( 'wp_ajax_wc_otl_reorder_tags', array( $this, 'reorder_tags' ) );
	}

	/**
	 * Verify nonce + capability for every request. Dies with a JSON error on failure.
	 */
	private function guard() {
		check_ajax_referer( 'wc_otl_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'pro-web-design-order-tags-labels-for-woocommerce' ) ), 403 );
		}
	}

	/**
	 * Assign a tag to an order.
	 */
	public function assign_tag() {
		$this->guard();

		// Nonce already verified above in guard() via check_ajax_referer().
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tag_id   = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $order_id || ! $tag_id || ! wc_get_order( $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order or tag.', 'pro-web-design-order-tags-labels-for-woocommerce' ) ) );
		}

		$ok = WC_OTL_Tags::assign_tag( $order_id, $tag_id );

		if ( $ok ) {
			wp_send_json_success();
		}

		wp_send_json_error( array( 'message' => __( 'Could not assign the tag.', 'pro-web-design-order-tags-labels-for-woocommerce' ) ) );
	}

	/**
	 * Remove a tag from an order.
	 */
	public function remove_tag() {
		$this->guard();

		// Nonce already verified above in guard() via check_ajax_referer().
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tag_id   = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $order_id || ! $tag_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid order or tag.', 'pro-web-design-order-tags-labels-for-woocommerce' ) ) );
		}

		WC_OTL_Tags::remove_tag( $order_id, $tag_id );
		wp_send_json_success();
	}

	/**
	 * Create a new tag.
	 */
	public function create_tag() {
		$this->guard();

		// Nonce already verified above in guard() via check_ajax_referer().
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$color = isset( $_POST['color'] ) ? sanitize_hex_color( wp_unslash( $_POST['color'] ) ) : '#2271b1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $name ) {
			wp_send_json_error( array( 'message' => __( 'Tag name is required.', 'pro-web-design-order-tags-labels-for-woocommerce' ) ) );
		}

		$result = WC_OTL_Tags::create_tag( $name, $color ? $color : '#2271b1' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array( 'tag_id' => $result ) );
	}

	/**
	 * Update a tag's name/color.
	 */
	public function update_tag() {
		$this->guard();

		// Nonce already verified above in guard() via check_ajax_referer().
		$tag_id = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$color  = isset( $_POST['color'] ) ? sanitize_hex_color( wp_unslash( $_POST['color'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $tag_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid tag.', 'pro-web-design-order-tags-labels-for-woocommerce' ) ) );
		}

		$args = array();
		if ( '' !== $name ) {
			$args['name'] = $name;
		}
		if ( $color ) {
			$args['color'] = $color;
		}

		WC_OTL_Tags::update_tag( $tag_id, $args );
		wp_send_json_success();
	}

	/**
	 * Delete a tag.
	 */
	public function delete_tag() {
		$this->guard();

		// Nonce already verified above in guard() via check_ajax_referer().
		$tag_id = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! $tag_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid tag.', 'pro-web-design-order-tags-labels-for-woocommerce' ) ) );
		}

		WC_OTL_Tags::delete_tag( $tag_id );
		wp_send_json_success();
	}

	/**
	 * Persist a new drag-and-drop tag order.
	 */
	public function reorder_tags() {
		$this->guard();

		// Nonce already verified above in guard() via check_ajax_referer().
		$order = isset( $_POST['order'] ) ? array_map( 'absint', (array) $_POST['order'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		foreach ( $order as $index => $tag_id ) {
			WC_OTL_Tags::update_tag( $tag_id, array( 'sort_order' => $index ) );
		}

		wp_send_json_success();
	}
}
