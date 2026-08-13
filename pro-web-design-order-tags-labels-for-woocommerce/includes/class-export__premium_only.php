<?php
/**
 * Pro: CSV export of tagged orders.
 *
 * @package Order_Tags_Labels_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_OTL_Export
 */
class WC_OTL_Export {

	/**
	 * Singleton instance.
	 *
	 * @var WC_OTL_Export|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WC_OTL_Export
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
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_wc_otl_export_csv', array( $this, 'handle_export' ) );
	}

	/**
	 * Register the "Export" submenu.
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Export Tagged Orders', 'pro-web-design-order-tags-labels-for-woocommerce' ),
			__( 'Export Tagged Orders', 'pro-web-design-order-tags-labels-for-woocommerce' ),
			'manage_woocommerce',
			'wc-order-tags-export',
			array( $this, 'render_export_page' )
		);
	}

	/**
	 * Render a simple form: choose a tag, download CSV.
	 */
	public function render_export_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tags = WC_OTL_Tags::get_all_tags();
		?>
		<div class="wrap wc-otl-wrap">
			<h1><?php esc_html_e( 'Export Tagged Orders', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></h1>

			<?php if ( isset( $_GET['error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Please choose a tag to export.', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></p></div>
			<?php endif; ?>

			<?php if ( empty( $tags ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: URL to the tag management screen. */
						wp_kses_post( __( 'Create a tag first under <a href="%s">Order Tags</a> before exporting.', 'pro-web-design-order-tags-labels-for-woocommerce' ) ),
						esc_url( admin_url( 'admin.php?page=wc-order-tags' ) )
					);
					?>
				</p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wc_otl_export_csv" />
					<?php wp_nonce_field( 'wc_otl_export_csv' ); ?>
					<table class="form-table">
						<tr>
							<th><label for="wc-otl-export-tag"><?php esc_html_e( 'Tag', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></label></th>
							<td>
								<select name="tag_id" id="wc-otl-export-tag" required>
									<option value=""><?php esc_html_e( '— Select a tag —', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></option>
									<?php foreach ( $tags as $tag ) : ?>
										<option value="<?php echo esc_attr( $tag['id'] ); ?>"><?php echo esc_html( $tag['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					</table>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Download CSV', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Guard against CSV/formula injection (OWASP): if a user-controlled field (e.g. a
	 * customer's billing name) starts with a character a spreadsheet app would interpret
	 * as the start of a formula, prefix it with a single quote so it's treated as plain text.
	 *
	 * @param string $value Raw field value.
	 * @return string
	 */
	private function sanitize_csv_field( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Stream a CSV of orders carrying the selected tag.
	 */
	public function handle_export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pro-web-design-order-tags-labels-for-woocommerce' ) );
		}
		check_admin_referer( 'wc_otl_export_csv' );

		$tag_id = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0;

		if ( ! $tag_id ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wc-order-tags-export&error=1' ) );
			exit;
		}

		$order_ids = WC_OTL_Tags::get_order_ids_for_tag( $tag_id );

		// get_order_ids_for_tag() reads the tag-relationship table directly and knows
		// nothing about order status, so it happily returns auto-draft/checkout-draft
		// records (the placeholder WooCommerce creates when a customer merely loads the
		// checkout page) and trashed orders alongside real ones. Build an explicit allow
		// list from wc_get_order_statuses() — the same source WooCommerce and third-party
		// plugins register real statuses through — rather than excluding known-bad
		// statuses, so a status a plugin doesn't know about defaults to left out, not in.
		$valid_statuses = array_map(
			function ( $status ) {
				return ( 'wc-' === substr( $status, 0, 3 ) ) ? substr( $status, 3 ) : $status;
			},
			array_keys( wc_get_order_statuses() )
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=tagged-orders-' . $tag_id . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		fputcsv( $output, array( 'Order ID', 'Order Number', 'Date', 'Status', 'Customer', 'Email', 'Total', 'Tags' ) );

		// Filter by id AND status inside a single wc_get_orders() call instead of loading
		// every candidate order one at a time via wc_get_order() and discarding the ones
		// that don't belong: 'post__in' + 'status' compile down to one indexed
		// WHERE id IN (...) AND status IN (...) query, on both HPOS and the legacy
		// post-based store, so this scales the same way regardless of how many orders the
		// tag has ever touched. Guard the empty case explicitly — an empty 'post__in' is
		// not "match nothing" to WC_Order_Query, it's "no id filter at all".
		if ( ! empty( $order_ids ) ) {
			$orders = wc_get_orders(
				array(
					'post__in' => $order_ids,
					'status'   => $valid_statuses,
					'limit'    => -1,
				)
			);

			foreach ( $orders as $order ) {
				$tag_names = wp_list_pluck( WC_OTL_Tags::get_order_tags( $order->get_id() ), 'name' );

				fputcsv(
					$output,
					array(
						$order->get_id(),
						$order->get_order_number(),
						$order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
						$order->get_status(),
						$this->sanitize_csv_field( trim( wp_strip_all_tags( $order->get_formatted_billing_full_name() ) ) ),
						$this->sanitize_csv_field( $order->get_billing_email() ),
						$order->get_total(),
						$this->sanitize_csv_field( implode( '; ', $tag_names ) ),
					)
				);
			}
		}

		fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
