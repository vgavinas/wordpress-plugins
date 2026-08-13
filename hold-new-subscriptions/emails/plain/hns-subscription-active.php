<?php
/**
 * HNS: Subscription activated — plain text email template.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

echo "= " . esc_html( wp_strip_all_tags( isset( $email_heading ) ? $email_heading : '' ) ) . " =\n\n";

printf(
    esc_html__( 'Hello %s,', 'hold-new-subscriptions' ),
    esc_html( wp_strip_all_tags( isset( $order ) ? $order->get_billing_first_name() : '' ) )
);
echo "\n\n";

printf(
    esc_html__( 'Your subscription #%d has been activated.', 'hold-new-subscriptions' ),
    isset( $subscription ) ? $subscription->get_id() : 0
);
echo "\n";
esc_html_e( 'Enjoy watching!', 'hold-new-subscriptions' );
echo "\n";
esc_html_e( 'This subscription is set up for automatic renewal using funds from your personal wallet on the site.', 'hold-new-subscriptions' );
echo "\n";
esc_html_e( 'Please top up your wallet in advance to avoid interruptions in viewing.', 'hold-new-subscriptions' );
echo "\n\n";

echo esc_html( wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) );
