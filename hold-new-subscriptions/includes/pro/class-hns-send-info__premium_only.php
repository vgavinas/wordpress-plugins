<?php
/**
 * Pro: "Send subscription info & activate".
 *
 * Adds a meta box to the order/subscription edit screen with a one-click
 * action: insert a customer-facing note (optionally reusing a template from
 * "Order Note Templates for WooCommerce") and immediately activate the
 * subscription, regardless of the parent order's status.
 *
 * Integration with Order Note Templates (ONT) is read-only and soft: this
 * class only SELECTs from ONT's own `order_note_templates` table when it
 * exists, and never requires ONT to be installed — without it, the admin
 * just types the info text directly into this plugin's own settings field.
 * ONT itself is not modified by this integration.
 *
 * @package Hold_New_Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class HNS_Pro_Send_Info {

    const OPTION_KEY = 'hns_pro_send_info';
    const NONCE_ACTION = 'hns_pro_send_info_nonce';

    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'hns_after_settings_page', array( __CLASS__, 'render_settings' ) );
        add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
        add_action( 'wp_ajax_hns_send_info_activate', array( __CLASS__, 'ajax_send_info_activate' ) );
    }

    /* --------------------------------------------------------------------- */
    /* Options                                                                */
    /* --------------------------------------------------------------------- */

    public static function get_options() {
        $defaults = array(
            'source'          => 'custom', // 'ont_template' or 'custom'
            'ont_template_id' => 0,
            'custom_text'     => '',
        );
        $opts = get_option( self::OPTION_KEY, array() );
        if ( ! is_array( $opts ) ) { $opts = array(); }
        return wp_parse_args( $opts, $defaults );
    }

    public static function sanitize( $input ) {
        $output = array();
        $source = isset( $input['source'] ) ? sanitize_key( $input['source'] ) : 'custom';
        $output['source']          = in_array( $source, array( 'ont_template', 'custom' ), true ) ? $source : 'custom';
        $output['ont_template_id'] = isset( $input['ont_template_id'] ) ? absint( $input['ont_template_id'] ) : 0;
        $output['custom_text']     = isset( $input['custom_text'] ) ? wp_kses_post( wp_unslash( $input['custom_text'] ) ) : '';
        return $output;
    }

    /* --------------------------------------------------------------------- */
    /* Settings UI (own form/group — never touches the free hns_options)     */
    /* --------------------------------------------------------------------- */

    public static function register_settings() {
        register_setting( 'hns_pro_send_info_group', self::OPTION_KEY, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );

        add_settings_section(
            'hns_pro_send_info_main',
            __( 'Pro — Отправка данных подписки', 'hold-new-subscriptions' ),
            function() {
                echo '<p>' . esc_html__( 'Настройте текст, который вставляется как заметка для клиента при нажатии «Отправить данные и активировать» на экране заказа/подписки. Отправка сразу переводит подписку в статус Active, независимо от статуса заказа.', 'hold-new-subscriptions' ) . '</p>';
            },
            'hns_pro_send_info_settings'
        );

        add_settings_field( 'source', __( 'Источник текста', 'hold-new-subscriptions' ), array( __CLASS__, 'field_source' ), 'hns_pro_send_info_settings', 'hns_pro_send_info_main' );
        add_settings_field( 'ont_template_id', __( 'Шаблон ONT', 'hold-new-subscriptions' ), array( __CLASS__, 'field_ont_template' ), 'hns_pro_send_info_settings', 'hns_pro_send_info_main' );
        add_settings_field( 'custom_text', __( 'Свой текст', 'hold-new-subscriptions' ), array( __CLASS__, 'field_custom_text' ), 'hns_pro_send_info_settings', 'hns_pro_send_info_main' );
    }

    public static function render_settings() {
        ?>
        <h2><?php esc_html_e( 'Pro: данные подписки', 'hold-new-subscriptions' ); ?></h2>
        <form method="post" action="options.php">
            <?php settings_fields( 'hns_pro_send_info_group' ); ?>
            <?php do_settings_sections( 'hns_pro_send_info_settings' ); ?>
            <?php submit_button( __( 'Сохранить Pro-настройки', 'hold-new-subscriptions' ) ); ?>
        </form>
        <?php
    }

    public static function field_source() {
        $opts = self::get_options();
        ?>
        <label>
            <input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[source]" value="custom" <?php checked( $opts['source'], 'custom' ); ?> />
            <?php esc_html_e( 'Свой текст (ниже)', 'hold-new-subscriptions' ); ?>
        </label><br>
        <label>
            <input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[source]" value="ont_template" <?php checked( $opts['source'], 'ont_template' ); ?> <?php disabled( ! self::ont_templates_available() ); ?> />
            <?php esc_html_e( 'Шаблон из Order Note Templates', 'hold-new-subscriptions' ); ?>
        </label>
        <?php if ( ! self::ont_templates_available() ) : ?>
            <p class="description"><?php esc_html_e( 'Order Note Templates не найден или в нём ещё нет клиентских шаблонов.', 'hold-new-subscriptions' ); ?></p>
        <?php endif; ?>
        <?php
    }

    public static function field_ont_template() {
        $opts      = self::get_options();
        $templates = self::get_ont_customer_templates();
        ?>
        <select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[ont_template_id]" <?php disabled( empty( $templates ) ); ?>>
            <option value="0"><?php esc_html_e( '— выбрать —', 'hold-new-subscriptions' ); ?></option>
            <?php foreach ( $templates as $t ) : ?>
                <option value="<?php echo esc_attr( $t['id'] ); ?>" <?php selected( absint( $opts['ont_template_id'] ), (int) $t['id'] ); ?>>
                    <?php echo esc_html( $t['title'] ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'Только клиентские (customer) шаблоны из Order Note Templates.', 'hold-new-subscriptions' ); ?></p>
        <?php
    }

    public static function field_custom_text() {
        $opts = self::get_options();
        ?>
        <textarea name="<?php echo esc_attr( self::OPTION_KEY ); ?>[custom_text]" rows="5" cols="60"><?php echo esc_textarea( $opts['custom_text'] ); ?></textarea>
        <p class="description">
            <?php
            printf(
                /* translators: %s: list of available placeholders */
                esc_html__( 'Используется, если источник — «Свой текст», либо как запасной вариант, если шаблон ONT не выбран. Доступные плейсхолдеры: %s', 'hold-new-subscriptions' ),
                '<code>{order_id}, {subscription_id}, {customer_name}, {billing_email}</code>'
            );
            ?>
        </p>
        <?php
    }

    /* --------------------------------------------------------------------- */
    /* Soft, read-only integration with Order Note Templates                 */
    /* --------------------------------------------------------------------- */

    /**
     * Read customer-facing templates directly from ONT's own table, if present.
     * Read-only; never touches ONT's code or data. Returns [] if ONT (or its
     * table) isn't there — callers must fall back to the custom-text field.
     *
     * @return array[] Each: ['id' => int, 'title' => string, 'note_text' => string]
     */
    public static function get_ont_customer_templates() {
        static $cache = null;
        if ( null !== $cache ) { return $cache; }

        global $wpdb;
        $table = $wpdb->prefix . 'order_note_templates';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $table_exists ) {
            $cache = array();
            return $cache;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( "SELECT id, title, note_text FROM {$table} WHERE note_type = 'customer' ORDER BY title", ARRAY_A );

        $cache = is_array( $rows ) ? $rows : array();
        return $cache;
    }

    public static function ont_templates_available() {
        return ! empty( self::get_ont_customer_templates() );
    }

    /**
     * Resolve the info text to send: chosen ONT template if configured and
     * still present, otherwise the plugin's own custom-text field.
     */
    public static function resolve_info_text( $sub, $order ) {
        $opts = self::get_options();
        $text = '';

        if ( 'ont_template' === $opts['source'] && ! empty( $opts['ont_template_id'] ) ) {
            foreach ( self::get_ont_customer_templates() as $t ) {
                if ( (int) $t['id'] === (int) $opts['ont_template_id'] ) {
                    $text = $t['note_text'];
                    break;
                }
            }
        }

        if ( '' === trim( (string) $text ) ) {
            $text = $opts['custom_text'];
        }

        return self::substitute_placeholders( (string) $text, $sub, $order );
    }

    private static function substitute_placeholders( $text, $sub, $order ) {
        if ( '' === trim( $text ) ) { return $text; }

        $replacements = array(
            '{order_id}'        => $order instanceof WC_Order ? $order->get_id() : '',
            '{subscription_id}' => $sub instanceof WC_Subscription ? $sub->get_id() : '',
            '{customer_name}'   => $order instanceof WC_Order ? trim( $order->get_formatted_billing_full_name() ) : '',
            '{billing_email}'   => $order instanceof WC_Order ? $order->get_billing_email() : '',
        );

        return strtr( $text, $replacements );
    }

    /* --------------------------------------------------------------------- */
    /* Meta box                                                               */
    /* --------------------------------------------------------------------- */

    public static function add_meta_box() {
        $screens = array( 'shop_order', 'shop_subscription', 'woocommerce_page_wc-orders', 'woocommerce_page_wc-orders--shop_subscription' );
        foreach ( $screens as $screen ) {
            add_meta_box(
                'hns-pro-send-info',
                __( 'Hold New Subscriptions', 'hold-new-subscriptions' ),
                array( __CLASS__, 'render_meta_box' ),
                $screen,
                'side',
                'high'
            );
        }
    }

    public static function enqueue( $hook ) {
        global $post;
        $screens = array( 'shop_order', 'shop_subscription', 'woocommerce_page_wc-orders', 'woocommerce_page_wc-orders--shop_subscription' );
        $screen  = get_current_screen();
        if ( ! $screen || ! in_array( $screen->id, $screens, true ) ) { return; }

        wp_enqueue_script( 'hns-pro-admin', HNS_PLUGIN_URL . 'includes/pro/hns-pro-admin.js', array( 'jquery' ), HNS_PLUGIN_VERSION, true );
        wp_localize_script( 'hns-pro-admin', 'hnsPro', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
            'i18n'    => array(
                'confirm' => __( 'Отправить данные подписки клиенту и сразу активировать подписку?', 'hold-new-subscriptions' ),
                'sending' => __( 'Отправка…', 'hold-new-subscriptions' ),
                'error'   => __( 'Не удалось выполнить действие.', 'hold-new-subscriptions' ),
            ),
        ) );
    }

    /**
     * Normalize the current order/subscription screen into the list of
     * on-hold/pending subscriptions eligible for the action.
     *
     * @param WC_Order|WP_Post $post_or_order_object As passed by WooCommerce to meta box callbacks.
     * @return array{order: WC_Order|null, subs: WC_Subscription[]}
     */
    private static function resolve_context( $post_or_order_object ) {
        $order = ( $post_or_order_object instanceof WC_Order )
            ? $post_or_order_object
            : ( isset( $post_or_order_object->ID ) ? wc_get_order( $post_or_order_object->ID ) : false );

        if ( ! $order instanceof WC_Order ) {
            return array( 'order' => null, 'subs' => array() );
        }

        if ( $order instanceof WC_Subscription ) {
            $eligible = in_array( $order->get_status(), array( 'pending', 'on-hold' ), true ) ? array( $order ) : array();
            return array( 'order' => $order->get_parent() ? $order->get_parent() : $order, 'subs' => $eligible );
        }

        if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
            return array( 'order' => $order, 'subs' => array() );
        }

        $subs     = wcs_get_subscriptions_for_order( $order->get_id(), array( 'order_type' => 'any' ) );
        $eligible = array();
        if ( is_array( $subs ) ) {
            foreach ( $subs as $sub ) {
                if ( $sub instanceof WC_Subscription && in_array( $sub->get_status(), array( 'pending', 'on-hold' ), true ) ) {
                    $eligible[] = $sub;
                }
            }
        }

        return array( 'order' => $order, 'subs' => $eligible );
    }

    public static function render_meta_box( $post_or_order_object ) {
        $context = self::resolve_context( $post_or_order_object );
        $subs    = $context['subs'];

        if ( empty( $subs ) ) {
            echo '<p>' . esc_html__( 'Нет подписок на удержании для этого заказа.', 'hold-new-subscriptions' ) . '</p>';
            return;
        }

        $has_text = '' !== trim( (string) self::resolve_info_text( $subs[0], $context['order'] ) );
        if ( ! $has_text ) {
            echo '<p>' . esc_html__( 'Настройте текст в WooCommerce → Hold Subscriptions → Pro.', 'hold-new-subscriptions' ) . '</p>';
            return;
        }

        foreach ( $subs as $sub ) {
            printf(
                '<p><strong>%1$s</strong><br><button type="button" class="button button-primary hns-pro-send-info" data-subscription-id="%2$d">%3$s</button></p>',
                esc_html( sprintf(
                    /* translators: %s: subscription ID */
                    __( 'Подписка #%s', 'hold-new-subscriptions' ),
                    $sub->get_id()
                ) ),
                absint( $sub->get_id() ),
                esc_html__( 'Отправить данные и активировать', 'hold-new-subscriptions' )
            );
        }
        echo '<p class="description">' . esc_html__( 'Отправляет клиентскую заметку с данными и сразу переводит подписку в Active, независимо от статуса заказа.', 'hold-new-subscriptions' ) . '</p>';
    }

    /* --------------------------------------------------------------------- */
    /* AJAX action                                                           */
    /* --------------------------------------------------------------------- */

    public static function ajax_send_info_activate() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'hold-new-subscriptions' ) ), 403 );
        }

        $sub_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
        $sub    = ( $sub_id && function_exists( 'wcs_get_subscription' ) ) ? wcs_get_subscription( $sub_id ) : false;

        if ( ! $sub instanceof WC_Subscription ) {
            wp_send_json_error( array( 'message' => __( 'Подписка не найдена.', 'hold-new-subscriptions' ) ), 404 );
        }

        $order = $sub->get_parent();
        if ( ! $order instanceof WC_Order ) {
            $order = $sub; // Subscriptions are orders too; used only for note/email context.
        }

        $text = self::resolve_info_text( $sub, $order );
        if ( '' === trim( $text ) ) {
            wp_send_json_error( array( 'message' => __( 'Текст не настроен. Задайте его в WooCommerce → Hold Subscriptions → Pro.', 'hold-new-subscriptions' ) ), 400 );
        }

        $sub->add_order_note( $text, 1, true ); // is_customer_note = true -> WooCommerce sends its own "customer_note" email.

        $activated = hns_activate_subscription(
            $sub,
            $order,
            __( 'Activated after subscription info was sent to the customer (HNS Pro).', 'hold-new-subscriptions' )
        );

        wp_send_json_success( array(
            'activated' => $activated,
            'message'   => $activated
                ? __( 'Данные отправлены, подписка активирована.', 'hold-new-subscriptions' )
                : __( 'Данные отправлены. Подписка уже была активна или не подходит для активации.', 'hold-new-subscriptions' ),
        ) );
    }
}

HNS_Pro_Send_Info::init();
