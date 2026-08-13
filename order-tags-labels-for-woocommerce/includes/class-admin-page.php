<?php
/**
 * Tag management screen: create, edit, delete and reorder tags.
 *
 * @package Order_Tags_Labels_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_OTL_Admin_Page
 */
class WC_OTL_Admin_Page {

	/**
	 * Singleton instance.
	 *
	 * @var WC_OTL_Admin_Page|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WC_OTL_Admin_Page
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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_wc_otl_save_settings', array( $this, 'handle_save_settings' ) );
	}

	/**
	 * Register the "Order Tags" submenu under WooCommerce.
	 */
	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Order Tags', 'order-tags-labels-for-woocommerce' ),
			__( 'Order Tags', 'order-tags-labels-for-woocommerce' ),
			'manage_woocommerce',
			'wc-order-tags',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Get the hook suffixes of every admin screen this plugin renders on.
	 * Centralized so the tag-management, rules and export screens (registered
	 * by separate classes) and the order edit screen all agree on the list.
	 *
	 * @return string[]
	 */
	private function get_plugin_screen_hooks() {
		$hooks = array(
			'woocommerce_page_wc-order-tags',
			'woocommerce_page_wc-order-tag-rules',
			'woocommerce_page_wc-order-tags-export',
			'post.php',
			'post-new.php',
		);

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$hooks[] = wc_get_page_screen_id( 'shop-order' );
			$hooks[] = wc_get_page_screen_id( 'shop-subscription' );
		} else {
			$hooks[] = 'woocommerce_page_wc-orders';
		}

		return array_filter( array_unique( $hooks ) );
	}

	/**
	 * Enqueue admin assets on every screen this plugin renders UI on.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, $this->get_plugin_screen_hooks(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'wc-otl-admin',
			WC_OTL_URL . 'assets/admin.css',
			array(),
			WC_OTL_VERSION
		);

		wp_enqueue_script(
			'wc-otl-admin',
			WC_OTL_URL . 'assets/admin.js',
			array( 'jquery', 'wp-color-picker', 'jquery-ui-sortable' ),
			WC_OTL_VERSION,
			true
		);

		wp_enqueue_style( 'wp-color-picker' );

		wp_localize_script(
			'wc-otl-admin',
			'wcOtl',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wc_otl_nonce' ),
				'isPro'   => wc_otl_fs() && wc_otl_fs()->can_use_premium_code(),
				'i18n'    => array(
					'confirmDelete' => __( 'Delete this tag? It will be removed from all orders.', 'order-tags-labels-for-woocommerce' ),
					'save'          => __( 'Save', 'order-tags-labels-for-woocommerce' ),
					'cancel'        => __( 'Cancel', 'order-tags-labels-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Handle the "save settings" admin-post request (currently just the
	 * uninstall data-cleanup opt-in — kept intentionally minimal).
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'order-tags-labels-for-woocommerce' ) );
		}
		check_admin_referer( 'wc_otl_save_settings' );

		update_option( 'wc_otl_delete_data_on_uninstall', ! empty( $_POST['delete_data_on_uninstall'] ) ? 1 : 0 );

		wp_safe_redirect( admin_url( 'admin.php?page=wc-order-tags&settings-updated=1' ) );
		exit;
	}

	/**
	 * Render the tag management screen.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tags   = WC_OTL_Tags::get_all_tags();
		$is_pro = wc_otl_fs() && wc_otl_fs()->can_use_premium_code();
		?>
		<div class="wrap wc-otl-wrap">
			<h1><?php esc_html_e( 'Order Tags', 'order-tags-labels-for-woocommerce' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Create color-coded tags and assign them to orders from the order edit screen.', 'order-tags-labels-for-woocommerce' ); ?>
			</p>

			<?php if ( ! $is_pro ) : ?>
				<div class="notice notice-info wc-otl-limit-notice">
					<p>
						<strong><?php esc_html_e( 'Upgrade to Professional for auto-tag rules, bulk actions and CSV export.', 'order-tags-labels-for-woocommerce' ); ?></strong>
					</p>
				</div>
			<?php endif; ?>

			<table class="widefat striped wc-otl-tags-table">
				<thead>
					<tr>
						<th style="width:40px"></th>
						<th><?php esc_html_e( 'Tag', 'order-tags-labels-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Color', 'order-tags-labels-for-woocommerce' ); ?></th>
						<th style="width:160px"><?php esc_html_e( 'Actions', 'order-tags-labels-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody id="wc-otl-tags-list">
					<?php foreach ( $tags as $tag ) : ?>
						<tr data-tag-id="<?php echo esc_attr( $tag['id'] ); ?>" data-tag-name="<?php echo esc_attr( $tag['name'] ); ?>" data-tag-color="<?php echo esc_attr( $tag['color'] ); ?>">
							<td class="wc-otl-drag-handle">&#9776;</td>
							<td class="wc-otl-col-name">
								<span class="wc-otl-pill" style="background-color:<?php echo esc_attr( $tag['color'] ); ?>">
									<?php echo esc_html( $tag['name'] ); ?>
								</span>
							</td>
							<td class="wc-otl-col-color"><?php echo esc_html( $tag['color'] ); ?></td>
							<td class="wc-otl-col-actions">
								<button type="button" class="button wc-otl-edit-tag"><?php esc_html_e( 'Edit', 'order-tags-labels-for-woocommerce' ); ?></button>
								<button type="button" class="button wc-otl-delete-tag"><?php esc_html_e( 'Delete', 'order-tags-labels-for-woocommerce' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Add a New Tag', 'order-tags-labels-for-woocommerce' ); ?></h2>
			<form id="wc-otl-add-tag-form">
				<table class="form-table">
					<tr>
						<th><label for="wc-otl-new-tag-name"><?php esc_html_e( 'Name', 'order-tags-labels-for-woocommerce' ); ?></label></th>
						<td><input type="text" id="wc-otl-new-tag-name" maxlength="100" required /></td>
					</tr>
					<tr>
						<th><label for="wc-otl-new-tag-color"><?php esc_html_e( 'Color', 'order-tags-labels-for-woocommerce' ); ?></label></th>
						<td><input type="text" id="wc-otl-new-tag-color" class="wc-otl-color-picker" value="#2271b1" /></td>
					</tr>
				</table>
				<p>
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Add Tag', 'order-tags-labels-for-woocommerce' ); ?>
					</button>
				</p>
			</form>

			<?php if ( $is_pro ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Auto-Tag Rules', 'order-tags-labels-for-woocommerce' ); ?></h2>
				<p class="description">
					<?php
					// Rendered by WC_OTL_Auto_Tag_Rules — kept as a separate tab/section to avoid coupling Free/Pro UI.
					printf(
						/* translators: %s: URL to the Auto-Tag Rules screen. */
						wp_kses_post( __( 'Manage automatic tagging rules on the <a href="%s">Auto-Tag Rules</a> screen.', 'order-tags-labels-for-woocommerce' ) ),
						esc_url( admin_url( 'admin.php?page=wc-order-tag-rules' ) )
					);
					?>
				</p>
			<?php endif; ?>

			<hr />
			<h2><?php esc_html_e( 'Settings', 'order-tags-labels-for-woocommerce' ); ?></h2>

			<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'order-tags-labels-for-woocommerce' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wc_otl_save_settings" />
				<?php wp_nonce_field( 'wc_otl_save_settings' ); ?>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'On Uninstall', 'order-tags-labels-for-woocommerce' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="delete_data_on_uninstall"
									value="1"
									<?php checked( get_option( 'wc_otl_delete_data_on_uninstall', 0 ), 1 ); ?>
								/>
								<?php esc_html_e( 'Delete all tags, tag assignments and auto-tag rules when this plugin is deleted.', 'order-tags-labels-for-woocommerce' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Leave unchecked to keep your data if you reinstall the plugin later. This only removes plugin data, never your orders.', 'order-tags-labels-for-woocommerce' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<p>
					<button type="submit" class="button"><?php esc_html_e( 'Save Settings', 'order-tags-labels-for-woocommerce' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
