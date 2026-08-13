<?php
/**
 * HNS: Subscription activated — HTML email template.
 *
 * Variables available:
 * @var WC_Order        $order
 * @var WC_Subscription $subscription
 * @var string          $email_heading
 * @var WC_Email        $email
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action( 'woocommerce_email_header', isset( $email_heading ) ? $email_heading : '', isset( $email ) ? $email : null ); ?>

<p><?php
    printf(
        /* translators: %s: customer first name */
        esc_html__( 'Hello %s,', 'hold-new-subscriptions' ),
        esc_html( isset( $order ) ? $order->get_billing_first_name() : '' )
    );
?></p>

<p><?php
    printf(
        /* translators: %d: subscription ID */
        esc_html__( 'Your subscription #%d has been activated.', 'hold-new-subscriptions' ),
        isset( $subscription ) ? $subscription->get_id() : 0
    );
?><br>
<?php esc_html_e( 'Enjoy watching!', 'hold-new-subscriptions' ); ?><br>
<?php esc_html_e( 'This subscription is set up for automatic renewal using funds from your personal wallet on the site.', 'hold-new-subscriptions' ); ?><br>
<?php esc_html_e( 'Please top up your wallet in advance to avoid interruptions in viewing.', 'hold-new-subscriptions' ); ?></p>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', isset( $email ) ? $email : null );
