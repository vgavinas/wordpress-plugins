<?php
/**
 * Pro: team notifications via webhook.
 *
 * Posts a JSON payload to a configured URL when a subscription enters hold,
 * is escalated (stuck too long — see the escalation module), or is
 * activated. The payload includes a "text" field so it can be pointed
 * directly at a Slack/Discord/Mattermost incoming webhook without any
 * transformation; Telegram/other services typically go through a small relay
 * (e.g. Zapier/Make) that also just reads "text" or the structured fields.
 *
 * @package Hold_New_Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class HNS_Pro_Notifications {

    const OPTION_KEY = 'hns_pro_notifications';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'hns_after_settings_page', array( __CLASS__, 'render_settings' ) );

        add_action( 'hns_subscription_held', array( __CLASS__, 'on_held' ), 10, 3 );
        add_action( 'hns_subscription_activated', array( __CLASS__, 'on_activated' ), 10, 3 );
        add_action( 'hns_subscription_escalated', array( __CLASS__, 'on_escalated' ), 10, 3 );
    }

    /* --------------------------------------------------------------------- */
    /* Options                                                                */
    /* --------------------------------------------------------------------- */

    public static function get_options() {
        $defaults = array(
            'webhook_url'      => '',
            'notify_held'      => 0,
            'notify_activated' => 0,
            'notify_escalated' => 1,
        );
        $opts = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $opts ) ) { $opts = array(); }
        return wp_parse_args( $opts, $defaults );
    }

    public static function sanitize( $input ) {
        $output = array();
        $output['webhook_url']      = isset( $input['webhook_url'] ) ? esc_url_raw( trim( $input['webhook_url'] ) ) : '';
        $output['notify_held']      = ! empty( $input['notify_held'] ) ? 1 : 0;
        $output['notify_activated'] = ! empty( $input['notify_activated'] ) ? 1 : 0;
        $output['notify_escalated'] = ! empty( $input['notify_escalated'] ) ? 1 : 0;
        return $output;
    }

    /* --------------------------------------------------------------------- */
    /* Settings UI                                                            */
    /* --------------------------------------------------------------------- */

    public static function register_settings() {
        register_setting( 'hns_pro_notifications_group', self::OPTION_KEY, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );

        add_settings_section(
            'hns_pro_notifications_main',
            __( 'Pro — уведомления для команды', 'hold-new-subscriptions' ),
            function() {
                echo '<p>' . esc_html__( 'Отправляет POST-запрос с JSON на указанный webhook (Slack/Discord/Mattermost incoming webhook, либо Zapier/Make сценарий для Telegram и других сервисов).', 'hold-new-subscriptions' ) . '</p>';
            },
            'hns_pro_notifications_settings'
        );

        add_settings_field( 'webhook_url', __( 'Webhook URL', 'hold-new-subscriptions' ), array( __CLASS__, 'field_webhook_url' ), 'hns_pro_notifications_settings', 'hns_pro_notifications_main' );
        add_settings_field( 'events', __( 'События', 'hold-new-subscriptions' ), array( __CLASS__, 'field_events' ), 'hns_pro_notifications_settings', 'hns_pro_notifications_main' );
    }

    public static function render_settings() {
        ?>
        <h2><?php esc_html_e( 'Pro: уведомления', 'hold-new-subscriptions' ); ?></h2>
        <form method="post" action="options.php">
            <?php settings_fields( 'hns_pro_notifications_group' ); ?>
            <?php do_settings_sections( 'hns_pro_notifications_settings' ); ?>
            <?php submit_button( __( 'Сохранить настройки уведомлений', 'hold-new-subscriptions' ) ); ?>
        </form>
        <?php
    }

    public static function field_webhook_url() {
        $opts = self::get_options();
        ?>
        <input type="url" class="regular-text" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[webhook_url]" value="<?php echo esc_attr( $opts['webhook_url'] ); ?>" placeholder="https://hooks.slack.com/services/…" />
        <?php
    }

    public static function field_events() {
        $opts = self::get_options();
        ?>
        <label style="display:block;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notify_held]" value="1" <?php checked( ! empty( $opts['notify_held'] ) ); ?>/> <?php esc_html_e( 'Новая подписка встала на удержание', 'hold-new-subscriptions' ); ?></label>
        <label style="display:block;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notify_activated]" value="1" <?php checked( ! empty( $opts['notify_activated'] ) ); ?>/> <?php esc_html_e( 'Подписка активирована', 'hold-new-subscriptions' ); ?></label>
        <label style="display:block;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[notify_escalated]" value="1" <?php checked( ! empty( $opts['notify_escalated'] ) ); ?>/> <?php esc_html_e( 'Подписка просрочена (таймер эскалации)', 'hold-new-subscriptions' ); ?></label>
        <p class="description"><?php esc_html_e( 'Требует настроенный таймер эскалации в соответствующем разделе Pro.', 'hold-new-subscriptions' ); ?></p>
        <?php
    }

    /* --------------------------------------------------------------------- */
    /* Event handlers                                                        */
    /* --------------------------------------------------------------------- */

    public static function on_held( $sub, $order, $target ) {
        $opts = self::get_options();
        if ( empty( $opts['notify_held'] ) ) { return; }
        self::send(
            $opts,
            sprintf(
                /* translators: 1: subscription ID, 2: target status */
                __( '⏸ Подписка #%1$d поставлена на удержание (%2$s) и ждёт проверки.', 'hold-new-subscriptions' ),
                $sub->get_id(),
                $target
            ),
            array( 'event' => 'held', 'subscription_id' => $sub->get_id(), 'order_id' => $order instanceof WC_Order ? $order->get_id() : 0 )
        );
    }

    public static function on_activated( $sub, $order, $reason ) {
        $opts = self::get_options();
        if ( empty( $opts['notify_activated'] ) ) { return; }
        self::send(
            $opts,
            sprintf(
                /* translators: %d: subscription ID */
                __( '✅ Подписка #%d активирована.', 'hold-new-subscriptions' ),
                $sub->get_id()
            ),
            array( 'event' => 'activated', 'subscription_id' => $sub->get_id(), 'order_id' => $order instanceof WC_Order ? $order->get_id() : 0, 'reason' => (string) $reason )
        );
    }

    public static function on_escalated( $sub, $order, $action ) {
        $opts = self::get_options();
        if ( empty( $opts['notify_escalated'] ) ) { return; }
        self::send(
            $opts,
            sprintf(
                /* translators: %d: subscription ID */
                __( '⚠️ Подписка #%d слишком долго висит на удержании — требуется внимание.', 'hold-new-subscriptions' ),
                $sub->get_id()
            ),
            array( 'event' => 'escalated', 'subscription_id' => $sub->get_id(), 'order_id' => $order instanceof WC_Order ? $order->get_id() : 0, 'escalation_action' => (string) $action )
        );
    }

    /* --------------------------------------------------------------------- */
    /* Delivery                                                               */
    /* --------------------------------------------------------------------- */

    private static function send( $opts, $text, $extra = array() ) {
        if ( empty( $opts['webhook_url'] ) ) { return; }

        $payload = array_merge( array( 'text' => $text ), $extra );

        $response = wp_remote_post( $opts['webhook_url'], array(
            'timeout' => 10,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( $payload ),
        ) );

        if ( is_wp_error( $response ) ) {
            hns_log( 'Pro webhook notification failed', array( 'error' => $response->get_error_message() ) );
        } elseif ( wp_remote_retrieve_response_code( $response ) >= 300 ) {
            hns_log( 'Pro webhook notification returned non-2xx status', array( 'status' => wp_remote_retrieve_response_code( $response ) ) );
        }
    }
}

HNS_Pro_Notifications::init();
