<?php
/**
 * Pro: conditional automation engine that auto-assigns tags to orders.
 *
 * Rule shape (stored as JSON in wp_order_tag_rules.condition_json):
 * {
 *   "field":    "order_total|payment_method|shipping_method|product_id|customer_role|customer_type|order_status|is_subscription",
 *   "operator": ">|<|>=|<=|==|!=|contains",
 *   "value":    "…"
 * }
 *
 * @package Order_Tags_Labels_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_OTL_Auto_Tag_Rules
 */
class WC_OTL_Auto_Tag_Rules {

	/**
	 * Singleton instance.
	 *
	 * @var WC_OTL_Auto_Tag_Rules|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WC_OTL_Auto_Tag_Rules
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
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'evaluate_for_order_id' ) );
		add_action( 'woocommerce_new_order', array( $this, 'evaluate_for_order_id' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'evaluate_for_order_id' ) );

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_wc_otl_save_rule', array( $this, 'handle_save_rule' ) );
		add_action( 'admin_post_wc_otl_delete_rule', array( $this, 'handle_delete_rule' ) );
	}

	/**
	 * Register the "Auto-Tag Rules" submenu (Pro only, enforced by the bootstrap loader too).
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Auto-Tag Rules', 'pro-web-design-order-tags-labels-for-woocommerce' ),
			__( 'Auto-Tag Rules', 'pro-web-design-order-tags-labels-for-woocommerce' ),
			'manage_woocommerce',
			'wc-order-tag-rules',
			array( $this, 'render_rules_page' )
		);
	}

	/**
	 * Hook callback: resolve the order and run rules against it.
	 * Handles the differing signatures of the hooks it's attached to.
	 *
	 * @param int|WC_Order $order_or_id Order ID (checkout/new order hooks) or int order ID (status changed).
	 */
	public function evaluate_for_order_id( $order_or_id ) {
		$order_id = is_a( $order_or_id, 'WC_Order' ) ? $order_or_id->get_id() : absint( $order_or_id );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// woocommerce_new_order also fires for the auto-draft WooCommerce creates when a
		// customer merely loads the checkout page, before they've confirmed anything — that
		// record isn't a real order yet and gets cleaned up by WooCommerce on its own
		// schedule. Skip it (and any other non-order-yet status) so rules don't leave tag
		// relationships pointing at rows that are about to disappear.
		if ( in_array( $order->get_status(), array( 'auto-draft', 'checkout-draft', 'trash' ), true ) ) {
			return;
		}

		$this->evaluate_rules( $order );
	}

	/**
	 * Get all enabled rules.
	 *
	 * @return array[]
	 */
	private function get_rules() {
		global $wpdb;

		$table = $wpdb->prefix . 'order_tag_rules';

		// Custom plugin table — direct query is expected; %i safely escapes the identifier
		// (requires WP 6.2+, our declared minimum). Not cached: rules only run on order
		// create/status-change events, not on every page load.
		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM %i WHERE enabled = 1', $table ),
			ARRAY_A
		);
	}

	/**
	 * Evaluate every enabled rule against an order and assign matching tags.
	 *
	 * @param WC_Order $order Order object.
	 */
	public function evaluate_rules( $order ) {
		foreach ( $this->get_rules() as $rule ) {
			$condition = json_decode( $rule['condition_json'], true );

			if ( ! is_array( $condition ) ) {
				continue;
			}

			if ( $this->condition_matches( $order, $condition ) ) {
				WC_OTL_Tags::assign_tag( $order->get_id(), $rule['tag_id'] );
			}
		}
	}

	/**
	 * Check whether a single condition matches the given order.
	 *
	 * @param WC_Order $order     Order object.
	 * @param array    $condition Decoded condition: field, operator, value.
	 * @return bool
	 */
	private function condition_matches( $order, $condition ) {
		$field    = isset( $condition['field'] ) ? $condition['field'] : '';
		$operator = isset( $condition['operator'] ) ? $condition['operator'] : '==';
		$value    = isset( $condition['value'] ) ? $condition['value'] : '';

		switch ( $field ) {
			case 'order_total':
				return $this->compare( (float) $order->get_total(), $operator, (float) $value );

			case 'payment_method':
				return $this->compare( $order->get_payment_method(), $operator, $value );

			case 'shipping_method':
				$methods = wp_list_pluck( $order->get_shipping_methods(), 'method_id' );
				return in_array( $value, $methods, true );

			case 'product_id':
				foreach ( $order->get_items() as $item ) {
					if ( (int) $item->get_product_id() === (int) $value ) {
						return true;
					}
				}
				return false;

			case 'customer_role':
				$user = $order->get_user();
				return $user && in_array( $value, (array) $user->roles, true );

			case 'customer_type':
				$is_returning = $order->get_customer_id() && wc_get_customer_order_count( $order->get_customer_id() ) > 1;
				return ( 'returning' === $value ) === $is_returning;

			case 'order_status':
				return $this->compare( $order->get_status(), $operator, str_replace( 'wc-', '', $value ) );

			case 'is_subscription':
				$is_sub = function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order );
				return (bool) $value === $is_sub;

			default:
				return false;
		}
	}

	/**
	 * Generic comparison helper for numeric/string operators.
	 *
	 * @param mixed  $left     Left-hand value.
	 * @param string $operator One of >, <, >=, <=, ==, !=, contains.
	 * @param mixed  $right    Right-hand value.
	 * @return bool
	 */
	private function compare( $left, $operator, $right ) {
		switch ( $operator ) {
			case '>':
				return $left > $right;
			case '<':
				return $left < $right;
			case '>=':
				return $left >= $right;
			case '<=':
				return $left <= $right;
			case '!=':
				return $left !== $right;
			case 'contains':
				return is_string( $left ) && false !== strpos( $left, (string) $right );
			case '==':
			default:
				return $left === $right || (string) $left === (string) $right;
		}
	}

	/**
	 * Handle the "save rule" admin-post request.
	 */
	public function handle_save_rule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pro-web-design-order-tags-labels-for-woocommerce' ) );
		}
		check_admin_referer( 'wc_otl_save_rule' );

		global $wpdb;

		$tag_id       = isset( $_POST['tag_id'] ) ? absint( $_POST['tag_id'] ) : 0;
		$field        = isset( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '';
		$operator     = isset( $_POST['operator'] ) ? sanitize_text_field( wp_unslash( $_POST['operator'] ) ) : '==';
		$value        = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';
		$valid_fields = array( 'order_total', 'payment_method', 'shipping_method', 'product_id', 'customer_role', 'customer_type', 'order_status', 'is_subscription' );
		$valid_ops    = array( '>', '<', '>=', '<=', '==', '!=', 'contains' );

		// Reject incomplete/invalid submissions instead of silently saving a rule that would
		// match every order (e.g. an empty "contains" value matches every string in PHP).
		if ( ! $tag_id || ! in_array( $field, $valid_fields, true ) || ! in_array( $operator, $valid_ops, true ) || '' === trim( $value ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=wc-order-tag-rules&error=1' ) );
			exit;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'order_tag_rules',
			array(
				'tag_id'         => $tag_id,
				'condition_json' => wp_json_encode(
					array(
						'field'    => $field,
						'operator' => $operator,
						'value'    => $value,
					)
				),
				'enabled'        => 1,
			),
			array( '%d', '%s', '%d' )
		);

		wp_safe_redirect( admin_url( 'admin.php?page=wc-order-tag-rules&created=1' ) );
		exit;
	}

	/**
	 * Handle the "delete rule" admin-post request.
	 */
	public function handle_delete_rule() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pro-web-design-order-tags-labels-for-woocommerce' ) );
		}
		check_admin_referer( 'wc_otl_delete_rule' );

		global $wpdb;

		$rule_id = isset( $_GET['rule_id'] ) ? absint( $_GET['rule_id'] ) : 0;

		if ( $rule_id ) {
			// Single-row delete by primary key — nothing to cache here; the get_rules() list
			// this affects isn't cached either (see its own justification comment above).
			$wpdb->delete( $wpdb->prefix . 'order_tag_rules', array( 'id' => $rule_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wc-order-tag-rules&deleted=1' ) );
		exit;
	}

	/**
	 * Render the Auto-Tag Rules admin screen: existing rules + a form to add one.
	 */
	public function render_rules_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		global $wpdb;

		// Custom plugin tables — direct query is expected; %i safely escapes both identifiers
		// (requires WP 6.2+, our declared minimum). Not cached: this only runs when an admin
		// opens the Auto-Tag Rules screen, not on a customer-facing or high-traffic path.
		$rules = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT r.*, t.name AS tag_name, t.color AS tag_color FROM %i r LEFT JOIN %i t ON t.id = r.tag_id ORDER BY r.id DESC',
				$wpdb->prefix . 'order_tag_rules',
				$wpdb->prefix . 'order_tags'
			),
			ARRAY_A
		);
		$tags  = WC_OTL_Tags::get_all_tags();

		$fields = array(
			'order_total'     => __( 'Order total', 'pro-web-design-order-tags-labels-for-woocommerce' ),
			'payment_method'  => __( 'Payment method', 'pro-web-design-order-tags-labels-for-woocommerce' ),
			'shipping_method' => __( 'Shipping method', 'pro-web-design-order-tags-labels-for-woocommerce' ),
			'product_id'      => __( 'Contains product ID', 'pro-web-design-order-tags-labels-for-woocommerce' ),
			'customer_role'   => __( 'Customer role', 'pro-web-design-order-tags-labels-for-woocommerce' ),
			'customer_type'   => __( 'Customer type (new/returning)', 'pro-web-design-order-tags-labels-for-woocommerce' ),
			'order_status'    => __( 'Order status', 'pro-web-design-order-tags-labels-for-woocommerce' ),
			'is_subscription' => __( 'Is a subscription order', 'pro-web-design-order-tags-labels-for-woocommerce' ),
		);

		// Fields with a fixed, enumerable set of values get a <select> instead of free text —
		// avoids typos/case-mismatches (e.g. "Processing" vs "processing") silently breaking a rule.
		$order_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$roles          = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();

		$payment_gateways = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
				$payment_gateways[ $gateway->id ] = $gateway->get_title();
			}
		}

		$shipping_methods = array();
		if ( class_exists( 'WC_Shipping' ) ) {
			foreach ( WC_Shipping::instance()->get_shipping_methods() as $method ) {
				$shipping_methods[ $method->id ] = $method->get_method_title();
			}
		}
		?>
		<div class="wrap wc-otl-wrap">
			<h1><?php esc_html_e( 'Auto-Tag Rules', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Automatically tag orders when they are created or change status.', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></p>

			<?php if ( isset( $_GET['created'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rule added.', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></p></div>
			<?php elseif ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rule deleted.', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></p></div>
			<?php elseif ( isset( $_GET['error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Please choose a tag, a condition and a value before adding the rule.', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></p></div>
			<?php endif; ?>

			<table class="widefat striped wc-otl-tags-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Tag', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Condition', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></th>
						<th style="width:120px"><?php esc_html_e( 'Actions', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rules ) ) : ?>
						<tr><td colspan="3"><?php esc_html_e( 'No rules yet.', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $rules as $rule ) : ?>
						<?php $condition = json_decode( $rule['condition_json'], true ); ?>
						<tr>
							<td>
								<span class="wc-otl-pill" style="background-color:<?php echo esc_attr( $rule['tag_color'] ); ?>">
									<?php echo esc_html( $rule['tag_name'] ); ?>
								</span>
							</td>
							<td>
								<code>
									<?php echo esc_html( $condition['field'] ?? '' ); ?>
									<?php echo esc_html( $condition['operator'] ?? '' ); ?>
									<?php echo esc_html( $condition['value'] ?? '' ); ?>
								</code>
							</td>
							<td>
								<a
									class="button"
									href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wc_otl_delete_rule&rule_id=' . $rule['id'] ), 'wc_otl_delete_rule' ) ); ?>"
									onclick="return confirm('<?php echo esc_js( __( 'Delete this rule?', 'pro-web-design-order-tags-labels-for-woocommerce' ) ); ?>');"
								>
									<?php esc_html_e( 'Delete', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Add a Rule', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></h2>

			<?php if ( empty( $tags ) ) : ?>
				<p>
					<?php
					printf(
						/* translators: %s: URL to the tag management screen. */
						wp_kses_post( __( 'Create a tag first under <a href="%s">Order Tags</a> before adding automation rules.', 'pro-web-design-order-tags-labels-for-woocommerce' ) ),
						esc_url( admin_url( 'admin.php?page=wc-order-tags' ) )
					);
					?>
				</p>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wc_otl_save_rule" />
				<?php wp_nonce_field( 'wc_otl_save_rule' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="wc-otl-rule-tag"><?php esc_html_e( 'Apply Tag', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></label></th>
						<td>
							<select name="tag_id" id="wc-otl-rule-tag" required>
								<?php foreach ( $tags as $tag ) : ?>
									<option value="<?php echo esc_attr( $tag['id'] ); ?>"><?php echo esc_html( $tag['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="wc-otl-rule-field"><?php esc_html_e( 'When', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></label></th>
						<td>
							<select name="field" id="wc-otl-rule-field">
								<?php foreach ( $fields as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<select name="operator">
								<option value=">">&gt;</option>
								<option value="<">&lt;</option>
								<option value=">=">&gt;=</option>
								<option value="<=">&lt;=</option>
								<option value="==">=</option>
								<option value="!=">&ne;</option>
								<option value="contains"><?php esc_html_e( 'contains', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></option>
							</select>

							<span class="wc-otl-value-wrap">
								<input
									type="text"
									name="value"
									class="wc-otl-value-input wc-otl-value-default"
									placeholder="<?php esc_attr_e( 'value', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?>"
								/>
								<select class="wc-otl-value-input wc-otl-value-for-order_status" data-for-field="order_status" style="display:none">
									<?php foreach ( $order_statuses as $status_key => $status_label ) : ?>
										<option value="<?php echo esc_attr( str_replace( 'wc-', '', $status_key ) ); ?>"><?php echo esc_html( $status_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<select class="wc-otl-value-input wc-otl-value-for-payment_method" data-for-field="payment_method" style="display:none">
									<?php foreach ( $payment_gateways as $gateway_id => $gateway_title ) : ?>
										<option value="<?php echo esc_attr( $gateway_id ); ?>"><?php echo esc_html( $gateway_title ); ?></option>
									<?php endforeach; ?>
								</select>
								<select class="wc-otl-value-input wc-otl-value-for-shipping_method" data-for-field="shipping_method" style="display:none">
									<?php foreach ( $shipping_methods as $method_id => $method_title ) : ?>
										<option value="<?php echo esc_attr( $method_id ); ?>"><?php echo esc_html( $method_title ); ?></option>
									<?php endforeach; ?>
								</select>
								<select class="wc-otl-value-input wc-otl-value-for-customer_role" data-for-field="customer_role" style="display:none">
									<?php foreach ( $roles as $role_key => $role_label ) : ?>
										<option value="<?php echo esc_attr( $role_key ); ?>"><?php echo esc_html( $role_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<select class="wc-otl-value-input wc-otl-value-for-customer_type" data-for-field="customer_type" style="display:none">
									<option value="new"><?php esc_html_e( 'New customer', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></option>
									<option value="returning"><?php esc_html_e( 'Returning customer', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></option>
								</select>
								<select class="wc-otl-value-input wc-otl-value-for-is_subscription" data-for-field="is_subscription" style="display:none">
									<option value="1"><?php esc_html_e( 'Yes', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></option>
									<option value="0"><?php esc_html_e( 'No', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></option>
								</select>
							</span>
							<p class="description">
								<?php esc_html_e( 'Order total: a number (e.g. 100). Payment/shipping method: the gateway or method ID (e.g. bacs, flat_rate). Contains product ID: a numeric product ID.', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Add Rule', 'pro-web-design-order-tags-labels-for-woocommerce' ); ?></button>
				</p>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
