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
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
        add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'add_meta_box' ) );
        add_action( 'add_meta_boxes_woocommerce_page_wc-orders--shop_subscription', array( $this, 'add_meta_box' ) );
    }

    public function add_meta_box() {
        $screens = array(
            'shop_order',
            'shop_subscription',
            'woocommerce_page_wc-orders',
            'woocommerce_page_wc-subscriptions',
            'woocommerce_page_wc-orders--shop_subscription',
        );
        foreach ( $screens as $screen ) {
            add_meta_box(
                'wc-ont-selector',
                '📝 ' . __( 'Note Templates', 'order-note-templates-for-woocommerce' ),
                array( $this, 'render' ),
                $screen,
                'side',
                'high'
            );
        }
    }

    private function get_context( $post_or_order ) {
        if ( $post_or_order instanceof WP_Post ) {
            return 'shop_subscription' === $post_or_order->post_type ? 'subscription' : 'order';
        }
        if ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_type' ) ) {
            return 'shop_subscription' === $post_or_order->get_type() ? 'subscription' : 'order';
        }
        $screen = get_current_screen();
        if ( $screen && false !== strpos( $screen->id, 'subscription' ) ) {
            return 'subscription';
        }
        return 'order';
    }

    public function render( $post_or_order ) {
        $context = $this->get_context( $post_or_order );
        $label   = 'subscription' === $context
            ? __( 'subscription', 'order-note-templates-for-woocommerce' )
            : __( 'order', 'order-note-templates-for-woocommerce' );
        ?>
        <div class="wc-ont-metabox" id="wc-ont-metabox"
             data-context="<?php echo esc_attr( $context ); ?>">
            <p style="margin-top:0">
                <label for="wc-ont-select"><strong><?php esc_html_e( 'Select a template:', 'order-note-templates-for-woocommerce' ); ?></strong></label>
            </p>
            <select id="wc-ont-select" class="wc-ont-select">
                <option value=""><?php esc_html_e( '— choose a template —', 'order-note-templates-for-woocommerce' ); ?></option>
                <optgroup label="<?php echo esc_attr( '👤 ' . __( 'Customer notes', 'order-note-templates-for-woocommerce' ) ); ?>" id="wc-ont-group-customer"></optgroup>
                <optgroup label="<?php echo esc_attr( '🔒 ' . __( 'Private notes', 'order-note-templates-for-woocommerce' ) ); ?>" id="wc-ont-group-internal"></optgroup>
            </select>

            <div id="wc-ont-preview-box" style="display:none; margin-top:8px;">
                <textarea id="wc-ont-preview-text" rows="4"
                          style="width:100%; box-sizing:border-box; resize:vertical;"
                          readonly></textarea>
                <p style="margin-bottom:4px">
                    <button type="button" id="wc-ont-insert-btn" class="button button-primary button-small" style="width:100%">
                        ✅ <?php esc_html_e( 'Insert into note field', 'order-note-templates-for-woocommerce' ); ?>
                    </button>
                </p>
            </div>

            <p class="description" style="margin-top:6px; font-size:11px">
                <?php
                printf(
                    /* translators: %s: 'order' or 'subscription' */
                    esc_html__( 'The template will fill the %s note field. Click "Add" in the notes panel to save it.', 'order-note-templates-for-woocommerce' ),
                    esc_html( $label )
                );
                ?>
            </p>

            <p style="margin:6px 0 0">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-ont-templates' ) ); ?>"
                   target="_blank" style="font-size:11px">⚙️ <?php esc_html_e( 'Manage templates', 'order-note-templates-for-woocommerce' ); ?></a>
            </p>
        </div>
        <?php
    }
}

new WC_ONT_Order_Meta_Box();
