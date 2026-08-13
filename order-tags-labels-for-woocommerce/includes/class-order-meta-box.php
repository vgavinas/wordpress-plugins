<?php
/**
 * Sidebar meta box on the order edit screen for assigning/removing tags.
 * Compatible with both HPOS (wc-orders) and the legacy shop_order post type.
 *
 * @package Order_Tags_Labels_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_OTL_Order_Meta_Box
 */
class WC_OTL_Order_Meta_Box {

	/**
	 * Singleton instance.
	 *
	 * @var WC_OTL_Order_Meta_Box|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WC_OTL_Order_Meta_Box
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
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
	}

	/**
	 * Get the correct screen ID for order edit screens, HPOS-aware.
	 *
	 * @return string
	 */
	private function get_order_screen_id() {
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			return wc_get_page_screen_id( 'shop-order' );
		}
		return 'shop_order';
	}

	/**
	 * Register the meta box on the order edit screen (and subscription screen, if present).
	 */
	public function register_meta_box() {
		$screens = array( $this->get_order_screen_id() );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$subscription_screen = wc_get_page_screen_id( 'shop-subscription' );
			if ( $subscription_screen ) {
				$screens[] = $subscription_screen;
			}
		} elseif ( post_type_exists( 'shop_subscription' ) ) {
			$screens[] = 'shop_subscription';
		}

		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box(
				'wc-otl-order-tags',
				__( 'Order Tags', 'pro-web-design-order-tags-labels-for-woocommerce' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the meta box contents.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post object (legacy) or order object (HPOS).
	 */
	public function render_meta_box( $post_or_order ) {
		$order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;

		if ( ! $order ) {
			return;
		}

		$order_id = $order->get_id();
		$all_tags = WC_OTL_Tags::get_all_tags();
		$assigned = wp_list_pluck( WC_OTL_Tags::get_order_tags( $order_id ), 'id' );

		// No form nonce here by design: tag assignment saves instantly via AJAX
		// (see assets/admin.js), authenticated by the wc_otl_nonce passed through
		// wp_localize_script() and verified in WC_OTL_Ajax::guard(). There is no
		// form POST on order save for this meta box to protect.
		?>
		<div class="wc-otl-meta-box" data-order-id="<?php echo esc_attr( $order_id ); ?>">
			<?php if ( empty( $all_tags ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: URL to the tag management screen. */
						wp_kses_post( __( 'No tags yet. <a href="%s">Create one</a>.', 'pro-web-design-order-tags-labels-for-woocommerce' ) ),
						esc_url( admin_url( 'admin.php?page=wc-order-tags' ) )
					);
					?>
				</p>
			<?php else : ?>
				<ul class="wc-otl-tag-checklist">
					<?php foreach ( $all_tags as $tag ) : ?>
						<li>
							<label>
								<input
									type="checkbox"
									class="wc-otl-tag-toggle"
									value="<?php echo esc_attr( $tag['id'] ); ?>"
									<?php checked( in_array( (int) $tag['id'], array_map( 'intval', $assigned ), true ) ); ?>
								/>
								<span class="wc-otl-pill" style="background-color:<?php echo esc_attr( $tag['color'] ); ?>">
									<?php echo esc_html( $tag['name'] ); ?>
								</span>
							</label>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p class="wc-otl-meta-box-footer">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-order-tags' ) ); ?>">
					<?php esc_html_e( 'Manage tags', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?>
				</a>
			</p>
			<span class="spinner wc-otl-spinner"></span>
		</div>
		<?php
	}
}
