<?php
/**
 * Plugin Name: Hold New Subscriptions Until Order Completed
 * Description: Puts newly created WooCommerce Subscriptions on hold (configurable) until the parent order reaches selected statuses (e.g. Completed), then activates them.
 * Author: Vitalijus Gavinas
 * Version: 1.3.5
 * License: GPL-2.0-or-later
 * Text Domain: hold-new-subscriptions
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.2
 * Requires Plugins: woocommerce
 *
 * @package Hold_New_Subscriptions
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'HNS_PLUGIN_VERSION', '1.3.5' );
define( 'HNS_PLUGIN_FILE', __FILE__ );
define( 'HNS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'HNS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare HPOS (custom order tables) compatibility.
 * Must run on before_woocommerce_init, before WooCommerce checks compatibility flags.
 */
add_action(
    'before_woocommerce_init',
    function () {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                HNS_PLUGIN_FILE,
                true
            );
        }
    }
);

if ( file_exists( HNS_PLUGIN_DIR . 'includes/class-hns-admin.php' ) ) {
    require_once HNS_PLUGIN_DIR . 'includes/class-hns-admin.php';
}
if ( file_exists( HNS_PLUGIN_DIR . 'includes/class-hns-i18n.php' ) ) {
    require_once HNS_PLUGIN_DIR . 'includes/class-hns-i18n.php';
}

/**
 * Whether Pro features are active.
 *
 * No Freemius integration yet (deliberately — monetization comes after the
 * free version is solid). This is a placeholder gate so Pro-only code can be
 * built and reviewed now, then switched on later by making this function
 * check the Freemius license instead, with no changes needed anywhere else.
 *
 * For local testing before Freemius is wired up:
 *   add_filter( 'hns_is_pro', '__return_true' );
 */
function hns_is_pro() {
    return (bool) apply_filters( 'hns_is_pro', false );
}

/**
 * Load Pro-only modules.
 *
 * Each lives in includes/pro/class-hns-<feature>__premium_only.php and is
 * required only when Pro is active — the same "__premium_only" naming
 * convention used by Order Note Templates / Order Tags & Labels, so this
 * plugin is ready for the same Freemius free-build stripping process
 * whenever monetization is connected. Until then, with hns_is_pro() always
 * false, none of this code loads or runs.
 */
function hns_load_pro_modules() {
    if ( ! hns_is_pro() ) { return; }
    if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wcs_order_contains_subscription' ) ) { return; }

    $pro_dir = HNS_PLUGIN_DIR . 'includes/pro/';
    $modules = array(
        'class-hns-send-info__premium_only.php',
        'class-hns-product-rules__premium_only.php',
        'class-hns-escalation__premium_only.php',
        'class-hns-notifications__premium_only.php',
    );
    foreach ( $modules as $module ) {
        $path = $pro_dir . $module;
        if ( file_exists( $path ) ) {
            require_once $path;
        }
    }
}
add_action( 'plugins_loaded', 'hns_load_pro_modules', 6 ); // after hns_boot() (priority 5).

/**
 * Clear the Pro escalation-timer cron event on deactivation. Safe to call
 * even when the Pro module was never active (wp_clear_scheduled_hook() is a
 * no-op if nothing was scheduled).
 */
register_deactivation_hook( HNS_PLUGIN_FILE, function () {
    wp_clear_scheduled_hook( 'hns_pro_escalation_check' );
} );

/**
 * Register custom email classes with WooCommerce.
 * require_once is inside the callback so WC_Email is guaranteed to exist.
 */
add_filter( 'woocommerce_email_classes', function( $email_classes ) {
    require_once HNS_PLUGIN_DIR . 'includes/class-hns-email-hold.php';
    require_once HNS_PLUGIN_DIR . 'includes/class-hns-email-active.php';
    $email_classes['HNS_Email_Hold']   = new HNS_Email_Hold();
    $email_classes['HNS_Email_Active'] = new HNS_Email_Active();
    return $email_classes;
} );

/**
 * Get plugin options with defaults.
 */
function hns_get_options() {
    $defaults = array(
        'enabled'              => 1,
        'initial_status'       => 'on-hold', // allowed: on-hold, pending
        'activate_on_statuses' => array( 'completed' ),
        'skip_renewals'        => 1,
        'limit_gateways'       => 0,
        'allowed_gateways'     => array(),
        'add_order_notes'      => 1,
        'use_wc_logger'        => 0,
        'send_hold_email'      => 0,
        'send_active_email'    => 0,
    );
    $opts = get_option( 'hns_options', array() );
    if ( ! is_array( $opts ) ) { $opts = array(); }
    return wp_parse_args( $opts, $defaults );
}

/**
 * Simple dependency checks with admin notices.
 */
function hns_dependencies_ok() {
    $ok = true;

    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Hold New Subscriptions', 'hold-new-subscriptions' ) . '</strong>: ' . esc_html__( 'WooCommerce must be active.', 'hold-new-subscriptions' ) . '</p></div>';
        } );
        $ok = false;
    }

    if ( ! function_exists( 'wcs_order_contains_subscription' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Hold New Subscriptions', 'hold-new-subscriptions' ) . '</strong>: ' . esc_html__( 'WooCommerce Subscriptions plugin is required.', 'hold-new-subscriptions' ) . '</p></div>';
        } );
        $ok = false;
    }

    return $ok;
}

/**
 * Add notes / logs helper.
 */
function hns_log( $message, $context = array() ) {
    $opts = hns_get_options();
    if ( ! empty( $opts['use_wc_logger'] ) && function_exists( 'wc_get_logger' ) ) {
        $logger = wc_get_logger();
        $logger->info( $message . ( $context ? ' ' . wp_json_encode( $context ) : '' ), array( 'source' => 'hold-new-subscriptions' ) );
    }
}

/**
 * Strip the 'wc-' prefix from an order status slug if present.
 *
 * @param string $status
 * @return string
 */
function hns_strip_wc_prefix( $status ) {
    return ( 0 === strpos( $status, 'wc-' ) ) ? substr( $status, 3 ) : $status;
}

/**
 * Send an email to the customer when their subscription is placed on hold.
 *
 * @param WC_Order        $order
 * @param WC_Subscription $sub
 * @param array           $activate_statuses Slugs without wc- prefix.
 */
function hns_send_hold_email( $order, $sub, $activate_statuses ) {
    if ( ! $order instanceof WC_Order || ! $sub instanceof WC_Subscription ) {
        return;
    }
    if ( ! function_exists( 'WC' ) || ! WC() ) {
        return;
    }
    $mailer = WC()->mailer();
    if ( ! $mailer ) {
        return;
    }
    $emails      = $mailer->get_emails();
    $email_class = is_array( $emails ) ? ( $emails['HNS_Email_Hold'] ?? null ) : null;
    if ( $email_class ) {
        $email_class->trigger( $order, $sub, $activate_statuses );
        hns_log( 'Hold email sent', array( 'subscription' => $sub->get_id(), 'order' => $order->get_id() ) );
    } else {
        hns_log( 'Hold email class not found, email not sent', array( 'subscription' => $sub->get_id(), 'order' => $order->get_id() ) );
    }
}

/**
 * Send a confirmation email to the customer when their subscription is activated.
 *
 * @param WC_Order        $order
 * @param WC_Subscription $sub
 */
function hns_send_active_email( $order, $sub ) {
    if ( ! $order instanceof WC_Order || ! $sub instanceof WC_Subscription ) {
        return;
    }
    if ( ! function_exists( 'WC' ) || ! WC() ) {
        return;
    }
    $mailer = WC()->mailer();
    if ( ! $mailer ) {
        return;
    }
    $emails      = $mailer->get_emails();
    $email_class = is_array( $emails ) ? ( $emails['HNS_Email_Active'] ?? null ) : null;
    if ( $email_class ) {
        $email_class->trigger( $order, $sub );
        hns_log( 'Active email sent', array( 'subscription' => $sub->get_id(), 'order' => $order->get_id() ) );
    } else {
        hns_log( 'Active email class not found, email not sent', array( 'subscription' => $sub->get_id(), 'order' => $order->get_id() ) );
    }
}

/**
 * Activate a subscription that's on hold/pending, applying the same guard,
 * note, log and email behavior the automatic order-status trigger uses.
 *
 * Shared by the automatic path below and any Pro-only manual trigger (e.g.
 * the "send subscription info" action), so both stay consistent and neither
 * duplicates the HPOS-safe duplicate-activation guard.
 *
 * @param WC_Subscription $sub    Subscription to activate.
 * @param WC_Order        $order  Parent order, used for email/context only.
 * @param string          $reason Human-readable reason recorded on the subscription.
 * @return bool True if this call activated the subscription.
 */
function hns_activate_subscription( $sub, $order, $reason ) {
    if ( ! $sub instanceof WC_Subscription ) { return false; }
    if ( ! in_array( $sub->get_status(), array( 'pending', 'on-hold' ), true ) ) { return false; }
    if ( $sub->get_meta( '_hns_activated' ) ) { return false; }

    $sub->update_meta_data( '_hns_activated', '1' );
    $sub->save_meta_data();
    $sub->update_status( 'active', $reason );

    $opts = hns_get_options();
    if ( ! empty( $opts['add_order_notes'] ) ) {
        /* translators: %s: reason the subscription was activated */
        $sub->add_order_note( sprintf( __( 'HNS: subscription activated. %s', 'hold-new-subscriptions' ), $reason ) );
    }
    if ( ! empty( $opts['send_active_email'] ) && $order instanceof WC_Order ) {
        hns_send_active_email( $order, $sub );
    }
    hns_log( 'Subscription activated', array( 'subscription' => $sub->get_id(), 'reason' => $reason ) );

    /**
     * Fires after HNS activates a subscription, however it was triggered.
     *
     * @param WC_Subscription $sub
     * @param WC_Order        $order
     * @param string          $reason
     */
    do_action( 'hns_subscription_activated', $sub, $order, $reason );

    return true;
}

/**
 * Core: place subs on-hold after checkout, then activate when order hits desired statuses.
 */
function hns_boot() {
    // i18n
    HNS_I18n::init();

    if ( ! hns_dependencies_ok() ) { return; }

    // Admin UI (only when dependencies are met)
    HNS_Admin::init();

    $opts = hns_get_options();
    if ( empty( $opts['enabled'] ) ) {
        hns_log( 'Plugin disabled via settings.' );
        return;
    }

    // When a new subscription is created at checkout: mark it for a deferred hold.
    // We do NOT change the status here because this hook fires during payment processing —
    // calling update_status() at this point cancels WCS scheduled payments and breaks gateways.
    add_action( 'woocommerce_checkout_subscription_created', function( $sub, $order ) use ( $opts ) {
        if ( ! $order instanceof WC_Order ) return;
        $order_id = $order->get_id();

        // Gateways filter
        if ( ! empty( $opts['limit_gateways'] ) && ! empty( $opts['allowed_gateways'] ) ) {
            $pm = $order->get_payment_method();
            if ( ! in_array( $pm, (array) $opts['allowed_gateways'], true ) ) {
                hns_log( 'Order skipped due to gateway filter', array( 'order' => $order_id, 'gateway' => $pm ) );
                return;
            }
        }

        // Skip renewals
        if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) && ! empty( $opts['skip_renewals'] ) ) {
            hns_log( 'Renewal order skipped', array( 'order' => $order_id ) );
            return;
        }

        // Per-subscription options, so a Pro rule (e.g. per-product initial status)
        // can override the globally configured ones. Free installs get $opts back
        // unchanged, since no filter is registered.
        $sub_opts = apply_filters( 'hns_subscription_options', $opts, $sub, $order );

        $activate_statuses = array_map( 'hns_strip_wc_prefix', (array) $sub_opts['activate_on_statuses'] );
        if ( $order->has_status( $activate_statuses ) ) {
            return; // Order already at an activation status — no need to hold.
        }

        $target = in_array( $sub_opts['initial_status'], array( 'on-hold', 'pending' ), true ) ? $sub_opts['initial_status'] : 'on-hold';
        $sub->update_meta_data( '_hns_hold_target', $target );
        $sub->save_meta_data();
        hns_log( 'Subscription marked for deferred hold', array( 'subscription' => $sub->get_id(), 'target' => $target ) );
    }, 10, 2 );

    // Apply the deferred hold after checkout is fully complete.
    // Fires on the thank-you page (covers all gateway types) and on payment_complete
    // (covers REST/API/headless flows). The _hns_hold_target meta acts as a one-time flag —
    // deleted on first processing so page refreshes are safe.
    $hns_apply_hold = function( $order_id ) use ( $opts ) {
        $order_id = absint( $order_id );
        if ( ! $order_id ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) return;
        if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) return;

        $subs = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'parent' ) );
        if ( ! is_array( $subs ) || empty( $subs ) ) return;

        foreach ( $subs as $sub ) {
            $target = $sub->get_meta( '_hns_hold_target' );
            if ( ! $target ) continue;

            // Remove flag immediately to prevent duplicate processing on refresh.
            $sub->delete_meta_data( '_hns_hold_target' );
            $sub->save_meta_data();

            $sub_opts = apply_filters( 'hns_subscription_options', $opts, $sub, $order );
            $activate_statuses = array_map( 'hns_strip_wc_prefix', (array) $sub_opts['activate_on_statuses'] );

            if ( $order->has_status( $activate_statuses ) ) {
                hns_log( 'Hold skipped — order already at activation status', array( 'subscription' => $sub->get_id() ) );
                continue;
            }

            $current = $sub->get_status();
            if ( $current === $target ) continue;

            $allow_filter = 'woocommerce_can_subscription_be_updated_to_' . $target;
            add_filter( $allow_filter, '__return_true', 100 );
            try {
                $sub->update_status( $target, __( 'Automatically set by Hold New Subscriptions.', 'hold-new-subscriptions' ) );
            } catch ( Exception $e ) {
                hns_log( 'Failed to set subscription status', array( 'subscription' => $sub->get_id(), 'target' => $target, 'error' => $e->getMessage() ) );
                remove_filter( $allow_filter, '__return_true', 100 );
                continue;
            }
            remove_filter( $allow_filter, '__return_true', 100 );

            if ( ! empty( $opts['add_order_notes'] ) ) {
                /* translators: 1: initial subscription status, 2: comma-separated order statuses that will trigger activation */
                $sub->add_order_note( sprintf( __( 'HNS: subscription set to %1$s until parent order reaches: %2$s', 'hold-new-subscriptions' ), $target, implode( ', ', $activate_statuses ) ) );
            }
            if ( ! empty( $opts['send_hold_email'] ) ) {
                hns_send_hold_email( $order, $sub, $activate_statuses );
            }
            hns_log( 'Subscription set to initial status', array( 'subscription' => $sub->get_id(), 'target' => $target ) );

            /**
             * Fires after HNS puts a subscription on hold/pending.
             *
             * @param WC_Subscription $sub
             * @param WC_Order        $order
             * @param string          $target Status the subscription was set to.
             */
            do_action( 'hns_subscription_held', $sub, $order, $target );
        }
    };
    add_action( 'woocommerce_thankyou', $hns_apply_hold, 10 );
    add_action( 'woocommerce_payment_complete', $hns_apply_hold, 20 );

    // When order status changes: if new status is one of activate_on_statuses -> activate related subs
    add_action( 'woocommerce_order_status_changed', function( $order_id, $old_status, $new_status ) use ( $opts ) {
        $order_id = absint( $order_id );
        if ( ! $order_id ) { return; }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) return;

        // Fast path: on free installs activate_on_statuses is the same for every
        // subscription, so we can bail before touching the database at all. Pro
        // installs may override activate_on_statuses per subscription/product via
        // the hns_subscription_options filter below, so they can't take this
        // shortcut — the real check happens per-subscription further down.
        if ( ! hns_is_pro() ) {
            $activate_statuses = array_map( 'hns_strip_wc_prefix', (array) $opts['activate_on_statuses'] );
            if ( ! in_array( $new_status, $activate_statuses, true ) ) {
                return;
            }
        }

        // Gateways filter
        if ( ! empty( $opts['limit_gateways'] ) && ! empty( $opts['allowed_gateways'] ) ) {
            $pm = $order->get_payment_method();
            if ( ! in_array( $pm, (array) $opts['allowed_gateways'], true ) ) {
                hns_log( 'Order skipped due to gateway filter', array( 'order' => $order_id, 'gateway' => $pm ) );
                return;
            }
        }

        // Skip renewals
        if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) && ! empty( $opts['skip_renewals'] ) ) {
            hns_log( 'Renewal order skipped on status change', array( 'order' => $order_id ) );
            return;
        }

        if ( function_exists( 'wcs_order_contains_subscription' ) && function_exists( 'wcs_get_subscriptions_for_order' ) && wcs_order_contains_subscription( $order ) ) {
            $subs = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'any' ) );
            if ( is_wp_error( $subs ) || ! $subs || ! is_array( $subs ) ) { return; }
            foreach ( $subs as $sub ) {
                // Per-subscription options, so a Pro rule (e.g. per-product activation
                // statuses) can override the globally configured ones. Free installs get
                // $opts back unchanged, since no filter is registered.
                $sub_opts = apply_filters( 'hns_subscription_options', $opts, $sub, $order );
                $sub_activate_statuses = array_map( 'hns_strip_wc_prefix', (array) $sub_opts['activate_on_statuses'] );
                if ( ! in_array( $new_status, $sub_activate_statuses, true ) ) {
                    continue;
                }

                hns_activate_subscription(
                    $sub,
                    $order,
                    sprintf(
                        /* translators: %s: order status that triggered activation */
                        __( 'Activated when parent order reached target status "%s".', 'hold-new-subscriptions' ),
                        (string) $new_status
                    )
                );
            }
        }
    }, 10, 3 );

    // Reset the duplicate-activation guard when a subscription is put back on hold/pending,
    // or clean it up when the subscription is cancelled/expired (no longer needed).
    add_action( 'woocommerce_subscription_status_changed', function( $subscription, $old_status, $new_status ) {
        if ( ! $subscription instanceof WC_Subscription ) return;
        if ( in_array( $new_status, array( 'on-hold', 'pending', 'cancelled', 'expired', 'trash' ), true ) && $subscription->get_meta( '_hns_activated' ) ) {
            $subscription->delete_meta_data( '_hns_activated' );
            $subscription->save_meta_data();
        }
    }, 10, 3 );
}
add_action( 'plugins_loaded', 'hns_boot', 5 );

/**
 * Cleanup is handled via uninstall.php (WordPress will call it on plugin deletion).
 */
