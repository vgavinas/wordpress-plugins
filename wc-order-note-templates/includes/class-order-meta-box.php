<?php
defined( 'ABSPATH' ) || exit;

/**
 * Injects the template selector into the WooCommerce order/subscription notes meta box.
 *
 * Supports:
 *  - Classic orders      (CPT shop_order)
 *  - HPOS orders         (woocommerce_page_wc-orders)
 *  - Classic subscriptions (CPT shop_subscription)
 *  - HPOS subscriptions  (woocommerce_page_wc-orders--shop_subscription)
 */
class WC_ONT_Order_Meta_Box {

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [ $this, 'add_meta_box' ] );
        add_action( 'add_meta_boxes_woocommerce_page_wc-orders--shop_subscription', [ $this, 'add_meta_box' ] );
    }

    public function add_meta_box() {
        $screens = [
            'shop_order',
            'shop_subscription',
            'woocommerce_page_wc-orders',
            'woocommerce_page_wc-subscriptions',
            'woocommerce_page_wc-orders--shop_subscription',
        ];
        foreach ( $screens as $screen ) {
            add_meta_box(
                'wc-ont-selector',
                '📝 ' . __( 'Note Templates', 'wc-ont' ),
                [ $this, 'render' ],
                $screen,
                'side',
                'high'
            );
        }
    }

    private function get_context( $post_or_order ) {
        if ( $post_or_order instanceof WP_Post ) {
            return $post_or_order->post_type === 'shop_subscription' ? 'subscription' : 'order';
        }
        if ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_type' ) ) {
            return $post_or_order->get_type() === 'shop_subscription' ? 'subscription' : 'order';
        }
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'subscription' ) !== false ) {
            return 'subscription';
        }
        return 'order';
    }

    public function render( $post_or_order ) {
        $context = $this->get_context( $post_or_order );
        $label   = $context === 'subscription'
            ? __( 'subscription', 'wc-ont' )
            : __( 'order', 'wc-ont' );
        ?>
        <div class="wc-ont-metabox" id="wc-ont-metabox"
             data-context="<?= esc_attr( $context ) ?>">
            <p style="margin-top:0">
                <label for="wc-ont-select"><strong><?php esc_html_e( 'Select a template:', 'wc-ont' ); ?></strong></label>
            </p>
            <select id="wc-ont-select" class="wc-ont-select">
                <option value=""><?php esc_html_e( '— choose a template —', 'wc-ont' ); ?></option>
                <optgroup label="👤 <?php esc_attr_e( 'Customer notes', 'wc-ont' ); ?>" id="wc-ont-group-customer"></optgroup>
                <optgroup label="🔒 <?php esc_attr_e( 'Private notes', 'wc-ont' ); ?>" id="wc-ont-group-internal"></optgroup>
            </select>

            <div id="wc-ont-preview-box" style="display:none; margin-top:8px;">
                <textarea id="wc-ont-preview-text" rows="4"
                          style="width:100%; box-sizing:border-box; resize:vertical;"
                          readonly></textarea>
                <p style="margin-bottom:4px">
                    <button type="button" id="wc-ont-insert-btn" class="button button-primary button-small" style="width:100%">
                        ✅ <?php esc_html_e( 'Insert into note field', 'wc-ont' ); ?>
                    </button>
                </p>
            </div>

            <p class="description" style="margin-top:6px; font-size:11px">
                <?php printf(
                    /* translators: %s: 'order' or 'subscription' */
                    esc_html__( 'The template will fill the %s note field. Click "Add" in the notes panel to save it.', 'wc-ont' ),
                    esc_html( $label )
                ); ?>
            </p>

            <p style="margin:6px 0 0">
                <a href="<?= esc_url( admin_url( 'admin.php?page=wc-ont-templates' ) ) ?>"
                   target="_blank" style="font-size:11px">⚙️ <?php esc_html_e( 'Manage templates', 'wc-ont' ); ?></a>
            </p>
        </div>
        <?php
    }
}

new WC_ONT_Order_Meta_Box();
