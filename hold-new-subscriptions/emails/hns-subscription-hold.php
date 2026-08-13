<?php
/**
 * HNS: Subscription on hold — HTML email template.
 *
 * Variables available:
 * @var WC_Order        $order
 * @var WC_Subscription $subscription
 * @var array           $activate_statuses
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
        /* translators: 1: subscription ID, 2: order ID */
        esc_html__( 'Your subscription #%1$d has been placed on hold while we process your order #%2$d.', 'hold-new-subscriptions' ),
        isset( $subscription ) ? absint( $subscription->get_id() ) : 0,
        isset( $order ) ? absint( $order->get_id() ) : 0
    );
?></p>

<p><?php esc_html_e( 'It will be activated automatically once our specialists have reviewed and approved your order.', 'hold-new-subscriptions' ); ?><br>
<?php esc_html_e( 'You will receive an additional confirmation once your subscription is activated.', 'hold-new-subscriptions' ); ?></p>

<?php
/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action( 'woocommerce_email_footer', isset( $email ) ? $email : null );
