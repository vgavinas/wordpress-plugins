<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class HNS_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
    }

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Hold Subscriptions', 'hold-new-subscriptions' ),
            __( 'Hold Subscriptions', 'hold-new-subscriptions' ),
            'manage_woocommerce',
            'hns-settings',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    public static function register_settings() {
        register_setting( 'hns_settings_group', 'hns_options', array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );

        add_settings_section(
            'hns_main',
            __( 'Core Settings', 'hold-new-subscriptions' ),
            function() {
                echo '<p>' . esc_html__( 'Configure how new subscriptions are held and when they become active.', 'hold-new-subscriptions' ) . '</p>';
            },
            'hns_settings'
        );

        add_settings_field( 'enabled', __( 'Enable plugin', 'hold-new-subscriptions' ), array( __CLASS__, 'field_enabled' ), 'hns_settings', 'hns_main' );
        add_settings_field( 'initial_status', __( 'Initial subscription status', 'hold-new-subscriptions' ), array( __CLASS__, 'field_initial_status' ), 'hns_settings', 'hns_main' );
        add_settings_field( 'activate_on_statuses', __( 'Activate when parent order becomes', 'hold-new-subscriptions' ), array( __CLASS__, 'field_activate_on_statuses' ), 'hns_settings', 'hns_main' );
        add_settings_field( 'skip_renewals', __( 'Skip renewal orders', 'hold-new-subscriptions' ), array( __CLASS__, 'field_skip_renewals' ), 'hns_settings', 'hns_main' );
        add_settings_field( 'limit_gateways', __( 'Limit by payment gateways', 'hold-new-subscriptions' ), array( __CLASS__, 'field_limit_gateways' ), 'hns_settings', 'hns_main' );
        add_settings_field( 'allowed_gateways', __( 'Allowed gateways', 'hold-new-subscriptions' ), array( __CLASS__, 'field_allowed_gateways' ), 'hns_settings', 'hns_main' );
        add_settings_field( 'add_order_notes', __( 'Add notes to subscriptions', 'hold-new-subscriptions' ), array( __CLASS__, 'field_add_order_notes' ), 'hns_settings', 'hns_main' );
        add_settings_field( 'use_wc_logger', __( 'Write to WooCommerce log', 'hold-new-subscriptions' ), array( __CLASS__, 'field_use_wc_logger' ), 'hns_settings', 'hns_main' );
        add_settings_field( 'send_hold_email', __( 'Email customer when on hold', 'hold-new-subscriptions' ), array( __CLASS__, 'field_send_hold_email' ), 'hns_settings', 'hns_main' );
        add_settings_field( 'send_active_email', __( 'Email customer when activated', 'hold-new-subscriptions' ), array( __CLASS__, 'field_send_active_email' ), 'hns_settings', 'hns_main' );
    }

    public static function sanitize( $input ) {
        $output = array();
        $output['enabled'] = ! empty( $input['enabled'] ) ? 1 : 0;
        $output['initial_status'] = ( isset( $input['initial_status'] ) && in_array( $input['initial_status'], array( 'on-hold', 'pending' ), true ) ) ? $input['initial_status'] : 'on-hold';

        $statuses = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();
        $clean_statuses = array();
        if ( ! empty( $input['activate_on_statuses'] ) && is_array( $input['activate_on_statuses'] ) ) {
            foreach ( $input['activate_on_statuses'] as $st ) {
                if ( ! is_string( $st ) ) { continue; }
                $st = hns_strip_wc_prefix( $st );
                if ( in_array( 'wc-' . $st, $statuses, true ) ) {
                    $clean_statuses[] = $st;
                }
            }
        }
        if ( empty( $clean_statuses ) ) { $clean_statuses = array( 'completed' ); }
        $output['activate_on_statuses'] = $clean_statuses;

        $output['skip_renewals'] = ! empty( $input['skip_renewals'] ) ? 1 : 0;
        $output['limit_gateways'] = ! empty( $input['limit_gateways'] ) ? 1 : 0;

        $allowed = array();
        if ( ! empty( $input['allowed_gateways'] ) && is_array( $input['allowed_gateways'] ) ) {
            $available_gateways = array();
            if ( function_exists( 'WC' ) && WC() && WC()->payment_gateways() ) {
                $available_gateways = array_keys( WC()->payment_gateways()->payment_gateways() );
            }
            foreach ( $input['allowed_gateways'] as $g ) {
                $g = sanitize_key( $g );
                if ( empty( $available_gateways ) || in_array( $g, $available_gateways, true ) ) {
                    $allowed[] = $g;
                }
            }
        }
        $output['allowed_gateways'] = $allowed;

        $output['add_order_notes'] = ! empty( $input['add_order_notes'] ) ? 1 : 0;
        $output['use_wc_logger']   = ! empty( $input['use_wc_logger'] ) ? 1 : 0;
        $output['send_hold_email']   = ! empty( $input['send_hold_email'] ) ? 1 : 0;
        $output['send_active_email'] = ! empty( $input['send_active_email'] ) ? 1 : 0;

        return $output;
    }

    public static function enqueue( $hook ) {
        if ( $hook !== 'woocommerce_page_hns-settings' ) return;
        wp_enqueue_style( 'hns-admin', HNS_PLUGIN_URL . 'includes/hns-admin.css', array(), HNS_PLUGIN_VERSION );
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'hold-new-subscriptions' ) );
        }
        $opts = hns_get_options();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Hold Subscriptions', 'hold-new-subscriptions' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'hns_settings_group' ); ?>
                <?php do_settings_sections( 'hns_settings' ); ?>
                <?php submit_button(); ?>
            </form>
            <?php
            /**
             * Extension point for Pro-only settings sections. Rendered as a separate
             * <form>/settings group so Pro's own option ('hns_pro_options') never
             * touches this file's sanitize()/option handling.
             *
             * @param array $opts Current core (free) options, for context.
             */
            do_action( 'hns_after_settings_page', $opts );
            ?>
        </div>
        <?php
    }

    // ---- Fields ----
    public static function field_enabled() {
        $opts = hns_get_options();
        ?>
        <label><input type="checkbox" name="hns_options[enabled]" value="1" <?php checked( ! empty( $opts['enabled'] ) ); ?>/> <?php esc_html_e( 'Enable core behavior', 'hold-new-subscriptions' ); ?></label>
        <?php
    }

    public static function field_initial_status() {
        $opts = hns_get_options();
        $val  = isset( $opts['initial_status'] ) ? $opts['initial_status'] : 'on-hold';
        ?>
        <select name="hns_options[initial_status]">
            <option value="on-hold" <?php selected( $val, 'on-hold' ); ?>><?php esc_html_e( 'On-hold', 'hold-new-subscriptions' ); ?></option>
            <option value="pending" <?php selected( $val, 'pending' ); ?>><?php esc_html_e( 'Pending', 'hold-new-subscriptions' ); ?></option>
        </select>
        <p class="description"><?php esc_html_e( 'Status set on the subscription right after checkout.', 'hold-new-subscriptions' ); ?></p>
        <?php
    }

    public static function field_activate_on_statuses() {
        $opts = hns_get_options();
        $selected = array_map( 'hns_strip_wc_prefix', (array) $opts['activate_on_statuses'] );
        $statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
        ?>
        <fieldset>
            <?php foreach ( $statuses as $key => $label ) :
                $key_slim = hns_strip_wc_prefix( $key );
                ?>
                <label style="display:block;margin:2px 0;">
                    <input type="checkbox" name="hns_options[activate_on_statuses][]" value="<?php echo esc_attr( $key_slim ); ?>" <?php checked( in_array( $key_slim, $selected, true ) ); ?> />
                    <?php echo esc_html( $label ); ?>
                </label>
            <?php endforeach; ?>
            <p class="description"><?php esc_html_e( 'Subscriptions will switch to Active when the parent order reaches any of the selected statuses.', 'hold-new-subscriptions' ); ?></p>
        </fieldset>
        <?php
    }

    public static function field_skip_renewals() {
        $opts = hns_get_options();
        ?>
        <label><input type="checkbox" name="hns_options[skip_renewals]" value="1" <?php checked( ! empty( $opts['skip_renewals'] ) ); ?>/> <?php esc_html_e( 'Do not change subscription status for renewal orders.', 'hold-new-subscriptions' ); ?></label>
        <?php
    }

    public static function field_limit_gateways() {
        $opts = hns_get_options();
        ?>
        <label><input type="checkbox" id="hns_limit_gateways" name="hns_options[limit_gateways]" value="1" <?php checked( ! empty( $opts['limit_gateways'] ) ); ?>/> <?php esc_html_e( 'Apply logic only for selected gateways', 'hold-new-subscriptions' ); ?></label>
        <?php
    }

    public static function field_allowed_gateways() {
        $opts = hns_get_options();
        $allowed = isset( $opts['allowed_gateways'] ) ? (array) $opts['allowed_gateways'] : array();
        $gateways = array();
        $wc = function_exists( 'WC' ) ? WC() : null;
        if ( $wc ) {
            $payment_gateways_instance = $wc->payment_gateways();
            if ( $payment_gateways_instance ) {
                foreach ( $payment_gateways_instance->payment_gateways() as $id => $g ) {
                    $gateways[ $id ] = $g->get_title() . ' (' . $id . ')';
                }
            }
        }
        ?>
        <fieldset>
            <?php if ( empty( $gateways ) ) : ?>
                <p><?php esc_html_e( 'No gateways detected. Save settings and ensure WooCommerce is active.', 'hold-new-subscriptions' ); ?></p>
            <?php else : ?>
                <?php foreach ( $gateways as $id => $label ) : ?>
                    <label style="display:block;margin:2px 0;">
                        <input type="checkbox" name="hns_options[allowed_gateways][]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $allowed, true ) ); ?> />
                        <?php echo esc_html( $label ); ?>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
            <p class="description"><?php esc_html_e( 'Works only if "Limit by payment gateways" is enabled.', 'hold-new-subscriptions' ); ?></p>
        </fieldset>
        <?php
    }

    public static function field_add_order_notes() {
        $opts = hns_get_options();
        ?>
        <label><input type="checkbox" name="hns_options[add_order_notes]" value="1" <?php checked( ! empty( $opts['add_order_notes'] ) ); ?>/> <?php esc_html_e( 'Write a note on the subscription when status is changed.', 'hold-new-subscriptions' ); ?></label>
        <?php
    }

    public static function field_use_wc_logger() {
        $opts = hns_get_options();
        ?>
        <label><input type="checkbox" name="hns_options[use_wc_logger]" value="1" <?php checked( ! empty( $opts['use_wc_logger'] ) ); ?>/> <?php esc_html_e( 'Also log actions to WooCommerce > Status > Logs.', 'hold-new-subscriptions' ); ?></label>
        <?php
    }

    public static function field_send_hold_email() {
        $opts = hns_get_options();
        ?>
        <label><input type="checkbox" name="hns_options[send_hold_email]" value="1" <?php checked( ! empty( $opts['send_hold_email'] ) ); ?>/> <?php esc_html_e( 'Send an email notification to the customer when their subscription is placed on hold.', 'hold-new-subscriptions' ); ?></label>
        <p class="description"><?php esc_html_e( 'The email includes the subscription number, order number, and the statuses that will trigger activation.', 'hold-new-subscriptions' ); ?></p>
        <?php
    }

    public static function field_send_active_email() {
        $opts = hns_get_options();
        ?>
        <label><input type="checkbox" name="hns_options[send_active_email]" value="1" <?php checked( ! empty( $opts['send_active_email'] ) ); ?>/> <?php esc_html_e( 'Send a confirmation email to the customer when their subscription is activated.', 'hold-new-subscriptions' ); ?></label>
        <p class="description"><?php esc_html_e( 'The email notifies the customer that their order has been approved and the subscription is now active.', 'hold-new-subscriptions' ); ?></p>
        <?php
    }
}
