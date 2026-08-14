<?php
/**
 * Pro: per-product / per-plan hold rules.
 *
 * Lets a store override the globally configured initial status and
 * activation statuses for specific subscription products — e.g. one plan
 * needs manual KYC review (hold until Completed) while another can activate
 * as soon as the order is Processing.
 *
 * @package Hold_New_Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class HNS_Pro_Product_Rules {

    const OPTION_KEY = 'hns_pro_product_rules';
    const ACTION     = 'hns_pro_save_product_rules';

    public static function init() {
        add_filter( 'hns_subscription_options', array( __CLASS__, 'filter_subscription_options' ), 10, 3 );
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
        add_action( 'hns_after_settings_page', array( __CLASS__, 'render_settings' ) );
    }

    /* --------------------------------------------------------------------- */
    /* Rule application                                                       */
    /* --------------------------------------------------------------------- */

    public static function filter_subscription_options( $opts, $sub, $order ) {
        if ( ! $sub instanceof WC_Subscription ) { return $opts; }

        $rules = self::get_rules();
        if ( empty( $rules ) ) { return $opts; }

        $product_ids = array();
        foreach ( $sub->get_items() as $item ) {
            $product_ids[] = (int) $item->get_product_id();
        }
        if ( empty( $product_ids ) ) { return $opts; }

        foreach ( $rules as $rule ) {
            if ( empty( $rule['product_id'] ) || ! in_array( (int) $rule['product_id'], $product_ids, true ) ) {
                continue;
            }
            if ( ! empty( $rule['initial_status'] ) && in_array( $rule['initial_status'], array( 'on-hold', 'pending' ), true ) ) {
                $opts['initial_status'] = $rule['initial_status'];
            }
            if ( ! empty( $rule['activate_on_statuses'] ) && is_array( $rule['activate_on_statuses'] ) ) {
                $opts['activate_on_statuses'] = $rule['activate_on_statuses'];
            }
            break; // First matching rule wins.
        }

        return $opts;
    }

    /* --------------------------------------------------------------------- */
    /* Storage                                                                */
    /* --------------------------------------------------------------------- */

    public static function get_rules() {
        $rules = get_option( self::OPTION_KEY, array() );
        return is_array( $rules ) ? $rules : array();
    }

    public static function handle_save() {
        check_admin_referer( self::ACTION );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Недостаточно прав.', 'hold-new-subscriptions' ) );
        }

        $statuses = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();

        // Nonce verified above via check_admin_referer(). Every field of every row is
        // individually validated/sanitized below (absint, sanitize_key + status whitelist)
        // before it's used, so the raw unslash here is intentional.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $raw_rules = isset( $_POST['hns_pro_rules'] ) ? wp_unslash( $_POST['hns_pro_rules'] ) : array();
        $rules     = array();

        if ( is_array( $raw_rules ) ) {
            foreach ( $raw_rules as $raw_rule ) {
                $product_id = isset( $raw_rule['product_id'] ) ? absint( $raw_rule['product_id'] ) : 0;
                if ( ! $product_id ) { continue; }

                $initial_status = isset( $raw_rule['initial_status'] ) && in_array( $raw_rule['initial_status'], array( 'on-hold', 'pending' ), true )
                    ? $raw_rule['initial_status']
                    : '';

                $activate_on_statuses = array();
                if ( ! empty( $raw_rule['activate_on_statuses'] ) && is_array( $raw_rule['activate_on_statuses'] ) ) {
                    foreach ( $raw_rule['activate_on_statuses'] as $st ) {
                        $st = hns_strip_wc_prefix( sanitize_key( $st ) );
                        if ( in_array( 'wc-' . $st, $statuses, true ) ) {
                            $activate_on_statuses[] = $st;
                        }
                    }
                }

                $rules[] = array(
                    'product_id'           => $product_id,
                    'initial_status'       => $initial_status,
                    'activate_on_statuses' => $activate_on_statuses,
                );
            }
        }

        update_option( self::OPTION_KEY, $rules );

        wp_safe_redirect( add_query_arg( array( 'page' => 'hns-settings', 'hns_pro_rules_saved' => 1 ), admin_url( 'admin.php' ) ) );
        exit;
    }

    /* --------------------------------------------------------------------- */
    /* Settings UI                                                            */
    /* --------------------------------------------------------------------- */

    private static function get_subscription_products() {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return array();
        }

        $candidates = wc_get_products( array(
            'limit'   => -1,
            'status'  => array( 'publish' ),
            'orderby' => 'title',
            'order'   => 'ASC',
        ) );

        if ( ! is_array( $candidates ) ) {
            return array();
        }

        $products = array();
        foreach ( $candidates as $product ) {
            if ( $product instanceof WC_Product && self::product_is_subscribable( $product ) ) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * Whether a product can be purchased as a subscription, covering both
     * WooCommerce Subscriptions data models:
     *
     * 1. The classic 'subscription' / 'variable-subscription' product types
     *    — WC_Subscriptions_Product::is_subscription() (filter-driven via
     *    'woocommerce_is_subscription') is the officially documented check
     *    for these.
     * 2. WooCommerce Subscriptions 9.0+'s built-in "All Products for
     *    Subscriptions" ("Purchase options"): subscription plans attached to
     *    an ordinary Simple/Variable product without changing its product
     *    type at all — WC_Subscriptions_Product::is_subscription() does NOT
     *    recognize these (confirmed empirically against a live WCS 9.1.0
     *    site: it returned false for a product with 3 active plans).
     *    WCS_ATT_Product_Schemes::has_subscription_schemes() is the correct,
     *    status-aware check here — it already accounts for the product's
     *    own enable/disable/override setting (confirmed: false for products
     *    with schemes present but status 'disable', true for products with
     *    active schemes under 'inherit'/'override').
     *
     * @param WC_Product $product
     * @return bool
     */
    private static function product_is_subscribable( $product ) {
        if ( class_exists( 'WC_Subscriptions_Product' ) && method_exists( 'WC_Subscriptions_Product', 'is_subscription' ) && WC_Subscriptions_Product::is_subscription( $product ) ) {
            return true;
        }

        if ( class_exists( 'WCS_ATT_Product_Schemes' ) && method_exists( 'WCS_ATT_Product_Schemes', 'has_subscription_schemes' ) && WCS_ATT_Product_Schemes::has_subscription_schemes( $product ) ) {
            return true;
        }

        return false;
    }

    public static function render_settings() {
        $rules    = self::get_rules();
        $products = self::get_subscription_products();
        $statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

        // Always show existing rules plus one empty row for adding a new one.
        $rows   = $rules;
        $rows[] = array( 'product_id' => 0, 'initial_status' => '', 'activate_on_statuses' => array() );
        ?>
        <h2><?php esc_html_e( 'Pro: правила по товарам/тарифам', 'hold-new-subscriptions' ); ?></h2>
        <?php
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success flag, no state change
        $rules_just_saved = ! empty( $_GET['hns_pro_rules_saved'] );
        ?>
        <?php if ( $rules_just_saved ) : ?>
            <div class="notice notice-success"><p><?php esc_html_e( 'Правила сохранены.', 'hold-new-subscriptions' ); ?></p></div>
        <?php endif; ?>
        <p><?php esc_html_e( 'Переопределяет глобальный начальный статус и статусы активации для подписок с выбранным товаром. Первое совпавшее правило побеждает.', 'hold-new-subscriptions' ); ?></p>
        <?php if ( empty( $products ) ) : ?>
            <p><?php esc_html_e( 'Не найдено товаров-подписок.', 'hold-new-subscriptions' ); ?></p>
        <?php else : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="hns-pro-rules-form">
            <?php wp_nonce_field( self::ACTION ); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
            <table class="widefat" id="hns-pro-rules-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Товар', 'hold-new-subscriptions' ); ?></th>
                        <th><?php esc_html_e( 'Начальный статус', 'hold-new-subscriptions' ); ?></th>
                        <th><?php esc_html_e( 'Активировать при статусе заказа', 'hold-new-subscriptions' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $i => $rule ) : ?>
                        <tr>
                            <td>
                                <select name="hns_pro_rules[<?php echo (int) $i; ?>][product_id]">
                                    <option value="0"><?php esc_html_e( '— не задано —', 'hold-new-subscriptions' ); ?></option>
                                    <?php foreach ( $products as $product ) : ?>
                                        <option value="<?php echo esc_attr( $product->get_id() ); ?>" <?php selected( (int) $rule['product_id'], $product->get_id() ); ?>>
                                            <?php echo esc_html( $product->get_name() ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <select name="hns_pro_rules[<?php echo (int) $i; ?>][initial_status]">
                                    <option value=""><?php esc_html_e( '(глобальный)', 'hold-new-subscriptions' ); ?></option>
                                    <option value="on-hold" <?php selected( $rule['initial_status'], 'on-hold' ); ?>><?php esc_html_e( 'On-hold', 'hold-new-subscriptions' ); ?></option>
                                    <option value="pending" <?php selected( $rule['initial_status'], 'pending' ); ?>><?php esc_html_e( 'Pending', 'hold-new-subscriptions' ); ?></option>
                                </select>
                            </td>
                            <td>
                                <?php foreach ( $statuses as $key => $label ) :
                                    $key_slim = hns_strip_wc_prefix( $key );
                                    $selected = in_array( $key_slim, (array) $rule['activate_on_statuses'], true );
                                    ?>
                                    <label style="display:inline-block;margin:0 8px 2px 0;">
                                        <input type="checkbox" name="hns_pro_rules[<?php echo (int) $i; ?>][activate_on_statuses][]" value="<?php echo esc_attr( $key_slim ); ?>" <?php checked( $selected ); ?> />
                                        <?php echo esc_html( $label ); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description"><?php esc_html_e( 'Пусто = использовать глобальные статусы.', 'hold-new-subscriptions' ); ?></p>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button( __( 'Сохранить правила', 'hold-new-subscriptions' ) ); ?>
        </form>
        <?php endif;
    }
}

HNS_Pro_Product_Rules::init();
