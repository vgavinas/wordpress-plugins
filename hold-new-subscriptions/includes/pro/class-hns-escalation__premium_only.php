<?php
/**
 * Pro: escalation timer for subscriptions stuck on hold too long.
 *
 * Records when HNS puts a subscription on hold (via the free hns_subscription_held
 * hook) and, on an hourly WP-Cron check, acts on subscriptions that have been
 * waiting longer than the configured threshold: fire a notification event
 * (consumed by the notifications module), auto-activate as a safety net, or
 * auto-cancel as abandoned.
 *
 * @package Hold_New_Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class HNS_Pro_Escalation {

    const OPTION_KEY = 'hns_pro_escalation';
    const CRON_HOOK   = 'hns_pro_escalation_check';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'hns_after_settings_page', array( __CLASS__, 'render_settings' ) );

        add_action( 'hns_subscription_held', array( __CLASS__, 'record_held_at' ), 10, 3 );
        add_action( 'hns_subscription_activated', array( __CLASS__, 'clear_guards' ), 10, 1 );
        add_action( 'woocommerce_subscription_status_changed', array( __CLASS__, 'maybe_clear_guards_on_status_change' ), 10, 3 );

        add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
        add_action( self::CRON_HOOK, array( __CLASS__, 'run_check' ) );
    }

    /* --------------------------------------------------------------------- */
    /* Options                                                                */
    /* --------------------------------------------------------------------- */

    public static function get_options() {
        $defaults = array(
            'enabled'         => 0,
            'threshold_hours' => 24,
            'action'          => 'notify', // 'notify' | 'auto_activate' | 'auto_cancel'
        );
        $opts = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $opts ) ) { $opts = array(); }
        return wp_parse_args( $opts, $defaults );
    }

    public static function sanitize( $input ) {
        $output = array();
        $output['enabled']         = ! empty( $input['enabled'] ) ? 1 : 0;
        $output['threshold_hours'] = isset( $input['threshold_hours'] ) ? max( 1, absint( $input['threshold_hours'] ) ) : 24;
        $action                    = isset( $input['action'] ) ? sanitize_key( $input['action'] ) : 'notify';
        $output['action']          = in_array( $action, array( 'notify', 'auto_activate', 'auto_cancel' ), true ) ? $action : 'notify';
        return $output;
    }

    /* --------------------------------------------------------------------- */
    /* Settings UI                                                            */
    /* --------------------------------------------------------------------- */

    public static function register_settings() {
        register_setting( 'hns_pro_escalation_group', self::OPTION_KEY, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );

        add_settings_section(
            'hns_pro_escalation_main',
            __( 'Pro — таймер эскалации', 'hold-new-subscriptions' ),
            function() {
                echo '<p>' . esc_html__( 'Если подписка висит на удержании дольше заданного времени, выполняется выбранное действие (проверка раз в час).', 'hold-new-subscriptions' ) . '</p>';
            },
            'hns_pro_escalation_settings'
        );

        add_settings_field( 'enabled', __( 'Включить таймер', 'hold-new-subscriptions' ), array( __CLASS__, 'field_enabled' ), 'hns_pro_escalation_settings', 'hns_pro_escalation_main' );
        add_settings_field( 'threshold_hours', __( 'Порог, часов', 'hold-new-subscriptions' ), array( __CLASS__, 'field_threshold' ), 'hns_pro_escalation_settings', 'hns_pro_escalation_main' );
        add_settings_field( 'action', __( 'Действие', 'hold-new-subscriptions' ), array( __CLASS__, 'field_action' ), 'hns_pro_escalation_settings', 'hns_pro_escalation_main' );
    }

    public static function render_settings() {
        ?>
        <h2><?php esc_html_e( 'Pro: таймер эскалации', 'hold-new-subscriptions' ); ?></h2>
        <form method="post" action="options.php">
            <?php settings_fields( 'hns_pro_escalation_group' ); ?>
            <?php do_settings_sections( 'hns_pro_escalation_settings' ); ?>
            <?php submit_button( __( 'Сохранить настройки таймера', 'hold-new-subscriptions' ) ); ?>
        </form>
        <?php
    }

    public static function field_enabled() {
        $opts = self::get_options();
        ?>
        <label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( ! empty( $opts['enabled'] ) ); ?>/> <?php esc_html_e( 'Проверять просроченные подписки на удержании каждый час', 'hold-new-subscriptions' ); ?></label>
        <?php
    }

    public static function field_threshold() {
        $opts = self::get_options();
        ?>
        <input type="number" min="1" step="1" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[threshold_hours]" value="<?php echo esc_attr( $opts['threshold_hours'] ); ?>" class="small-text" />
        <?php
    }

    public static function field_action() {
        $opts = self::get_options();
        ?>
        <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[action]">
            <option value="notify" <?php selected( $opts['action'], 'notify' ); ?>><?php esc_html_e( 'Только уведомить команду', 'hold-new-subscriptions' ); ?></option>
            <option value="auto_activate" <?php selected( $opts['action'], 'auto_activate' ); ?>><?php esc_html_e( 'Активировать автоматически (safety net)', 'hold-new-subscriptions' ); ?></option>
            <option value="auto_cancel" <?php selected( $opts['action'], 'auto_cancel' ); ?>><?php esc_html_e( 'Отменить как заброшенную', 'hold-new-subscriptions' ); ?></option>
        </select>
        <p class="description"><?php esc_html_e( '«Только уведомить» срабатывает один раз на подписку; повторно не дублируется, пока подписка остаётся на удержании.', 'hold-new-subscriptions' ); ?></p>
        <?php
    }

    /* --------------------------------------------------------------------- */
    /* Bookkeeping: when a subscription entered hold                         */
    /* --------------------------------------------------------------------- */

    public static function record_held_at( $sub, $order, $target ) {
        if ( ! $sub instanceof WC_Subscription ) { return; }
        $sub->update_meta_data( '_hns_held_at', time() );
        $sub->save_meta_data();
    }

    public static function clear_guards( $sub ) {
        self::clear_guards_for( $sub );
    }

    public static function maybe_clear_guards_on_status_change( $subscription, $old_status, $new_status ) {
        if ( ! $subscription instanceof WC_Subscription ) { return; }
        if ( in_array( $new_status, array( 'active', 'cancelled', 'expired', 'trash' ), true ) ) {
            self::clear_guards_for( $subscription );
        }
    }

    private static function clear_guards_for( $sub ) {
        if ( ! $sub instanceof WC_Subscription ) { return; }
        $changed = false;
        if ( $sub->get_meta( '_hns_held_at' ) ) {
            $sub->delete_meta_data( '_hns_held_at' );
            $changed = true;
        }
        if ( $sub->get_meta( '_hns_escalated' ) ) {
            $sub->delete_meta_data( '_hns_escalated' );
            $changed = true;
        }
        if ( $changed ) {
            $sub->save_meta_data();
        }
    }

    /* --------------------------------------------------------------------- */
    /* Cron                                                                   */
    /* --------------------------------------------------------------------- */

    public static function maybe_schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
        }
    }

    public static function run_check() {
        $opts = self::get_options();
        if ( empty( $opts['enabled'] ) ) { return; }
        if ( ! function_exists( 'wc_get_orders' ) ) { return; }

        $cutoff = time() - ( absint( $opts['threshold_hours'] ) * HOUR_IN_SECONDS );

        // wc_get_orders()/WC_Order_Query resolves meta queries against the
        // correct storage (custom order tables under HPOS, postmeta otherwise),
        // unlike a hand-written SQL query against wp_postmeta.
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        $subs = wc_get_orders( array(
            'type'         => 'shop_subscription',
            'status'       => array( 'wc-on-hold', 'wc-pending' ),
            'limit'        => -1,
            'meta_key'     => '_hns_held_at',
            'meta_value'   => $cutoff,
            'meta_compare' => '<=',
            'meta_type'    => 'NUMERIC',
        ) );

        if ( ! is_array( $subs ) ) { return; }

        foreach ( $subs as $sub ) {
            if ( ! $sub instanceof WC_Subscription ) { continue; }
            self::handle_overdue( $sub, $opts );
        }
    }

    private static function handle_overdue( $sub, $opts ) {
        $order = $sub->get_parent();

        switch ( $opts['action'] ) {
            case 'auto_activate':
                hns_activate_subscription(
                    $sub,
                    $order instanceof WC_Order ? $order : $sub,
                    sprintf(
                        /* translators: %d: hours */
                        __( 'Auto-activated: subscription was on hold for more than %d hour(s) (HNS Pro escalation).', 'hold-new-subscriptions' ),
                        (int) $opts['threshold_hours']
                    )
                );
                return;

            case 'auto_cancel':
                if ( $sub->get_meta( '_hns_escalated' ) ) { return; } // already handled
                $sub->update_meta_data( '_hns_escalated', '1' );
                $sub->save_meta_data();
                $sub->update_status(
                    'cancelled',
                    sprintf(
                        /* translators: %d: hours */
                        __( 'Cancelled: subscription was on hold for more than %d hour(s) with no action (HNS Pro escalation).', 'hold-new-subscriptions' ),
                        (int) $opts['threshold_hours']
                    )
                );
                do_action( 'hns_subscription_escalated', $sub, $order, $opts['action'] );
                return;

            case 'notify':
            default:
                if ( $sub->get_meta( '_hns_escalated' ) ) { return; } // don't renotify every hour
                $sub->update_meta_data( '_hns_escalated', '1' );
                $sub->save_meta_data();
                /**
                 * Fires when a subscription has been on hold longer than the
                 * configured threshold. Consumed by the notifications module.
                 *
                 * @param WC_Subscription $sub
                 * @param WC_Order|false  $order
                 * @param string          $action Configured escalation action.
                 */
                do_action( 'hns_subscription_escalated', $sub, $order, $opts['action'] );
                return;
        }
    }
}

HNS_Pro_Escalation::init();
