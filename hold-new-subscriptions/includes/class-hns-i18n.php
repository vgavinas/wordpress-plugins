<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class HNS_I18n {

    public static function init() {
        // No load_plugin_textdomain() call here: since WP 4.6, WordPress
        // automatically loads translations for a plugin's own bundled .mo
        // files based on the "Text Domain" / "Domain Path" headers alone
        // (this also covers translations served from WordPress.org for
        // plugins hosted there) — a manual call is redundant and discouraged.
        // Same convention as Order Tags & Labels for WooCommerce.
        self::fallback_ru_ru();
    }

    /**
     * Fallback Russian translations via 'gettext' filter (in case .mo is missing).
     * This only runs for ru_RU locale.
     */
    public static function fallback_ru_ru() {
        $map = array(
            // Admin notices
            'Hold New Subscriptions' => 'Удержание новых подписок',
            'WooCommerce must be active.' => 'Необходимо активировать WooCommerce.',
            'WooCommerce Subscriptions plugin is required.' => 'Требуется плагин WooCommerce Subscriptions.',

            // Admin UI
            'Hold Subscriptions' => 'Удержание подписок',
            'Core Settings' => 'Основные настройки',
            'Configure how new subscriptions are held and when they become active.' => 'Настройте, как удерживать новые подписки и когда их активировать.',
            'Enable plugin' => 'Включить плагин',
            'Initial subscription status' => 'Начальный статус подписки',
            'Activate when parent order becomes' => 'Активировать, когда заказ станет',
            'Skip renewal orders' => 'Пропускать счета на продление',
            'Limit by payment gateways' => 'Ограничить по платёжным шлюзам',
            'Allowed gateways' => 'Разрешённые шлюзы',
            'Add notes to subscriptions' => 'Добавлять заметки к подписке',
            'Write to WooCommerce log' => 'Писать в журнал WooCommerce',
            'Enable core behavior' => 'Включить основную логику',
            'On-hold' => 'На удержании',
            'Pending' => 'Ожидание',
            'Status set on the subscription right after checkout.' => 'Статус, устанавливаемый подписке сразу после оформления.',
            'Subscriptions will switch to Active when the parent order reaches any of the selected statuses.' => 'Подписка станет активной, когда заказ достигнет любого из выбранных статусов.',
            'Do not change subscription status for renewal orders.' => 'Не менять статус подписки для счетов на продление.',
            'Apply logic only for selected gateways' => 'Применять логику только для выбранных шлюзов',
            'No gateways detected. Save settings and ensure WooCommerce is active.' => 'Шлюзы не обнаружены. Сохраните настройки и убедитесь, что WooCommerce активен.',
            'Works only if "Limit by payment gateways" is enabled.' => 'Работает только если включено «Ограничить по платёжным шлюзам».',
            'Write a note on the subscription when status is changed.' => 'Добавлять заметку к подписке при смене статуса.',
            'Also log actions to WooCommerce > Status > Logs.' => 'Также писать действия в WooCommerce → Статус → Логи.',

            // Email notification
            'Email customer when on hold' => 'Отправлять письмо клиенту при удержании',
            'Send an email notification to the customer when their subscription is placed on hold.' => 'Отправлять клиенту уведомление по электронной почте, когда подписка переходит в статус удержания.',
            'The email includes the subscription number, order number, and the statuses that will trigger activation.' => 'Письмо содержит номер подписки, номер заказа и статусы, при которых подписка будет активирована.',
            'Your subscription #{subscription_id} is on hold' => 'Ваша подписка #{subscription_id} приостановлена',
            'Hello %s,' => 'Здравствуйте, %s!',
            'Your subscription #%1$d has been placed on hold while we process your order #%2$d.' => 'Ваша подписка #%1$d приостановлена на время обработки заказа #%2$d.',
            'It will be activated automatically once our specialists have reviewed and approved your order. You will receive a confirmation once your subscription is activated.' => 'Она будет активирована автоматически, как только заказ будет проверен и одобрен нашими специалистами. Вы получите подтверждение об активации подписки.',
            'Activation will be triggered when the order reaches one of the following statuses: %s.' => 'Подписка будет активирована, когда заказ получит один из следующих статусов: %s.',
            'Thank you for your purchase!' => 'Спасибо за покупку!',
            'Email customer when activated' => 'Отправлять письмо клиенту при активации',
            'Send a confirmation email to the customer when their subscription is activated.' => 'Отправлять клиенту подтверждение по электронной почте при активации подписки.',
            'The email notifies the customer that their order has been approved and the subscription is now active.' => 'Письмо уведомляет клиента о том, что заказ одобрен и подписка активирована.',
            'Your subscription #{subscription_id} is now active' => 'Ваша подписка #{subscription_id} активирована',
            'Your subscription #%1$d has been activated. Your order #%2$d has been reviewed and approved by our specialists.' => 'Ваша подписка #%1$d активирована. Ваш заказ #%2$d был проверен и одобрен нашими специалистами.',
            'Enjoy watching! Please top up your wallet balance in advance to avoid interruptions.' => 'Приятного просмотра! Пополняйте баланс кошелька заранее, чтобы избежать перерывов в просмотре.',

            // Runtime messages
            'Automatically set by Hold New Subscriptions.' => 'Автоматически установлено плагином «Удержание новых подписок».',
            'HNS: subscription set to %1$s until parent order reaches: %2$s' => 'HNS: статус подписки установлен в %1$s до достижения заказом статусов: %2$s',
            'Activated when parent order reached target status.' => 'Активировано, когда заказ достиг целевого статуса.',
            'HNS: subscription activated because parent order status is now "%s".' => 'HNS: подписка активирована, так как статус заказа теперь «%s».',
        );

        add_filter( 'gettext', function( $translated, $text, $domain ) use ( $map ) {
            if ( $domain !== 'hold-new-subscriptions' ) {
                return $translated;
            }
            // Check locale inside the filter so user-specific locales (set after plugins_loaded) are respected.
            if ( strpos( determine_locale(), 'ru_' ) !== 0 ) {
                return $translated;
            }
            // Only apply fallback if no .mo translation was found (translated === original text).
            if ( $translated === $text && isset( $map[ $text ] ) ) {
                return $map[ $text ];
            }
            return $translated;
        }, 10, 3 );
    }
}
