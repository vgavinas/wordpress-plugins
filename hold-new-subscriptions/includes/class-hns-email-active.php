<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * WooCommerce email: subscription activated.
 */
class HNS_Email_Active extends WC_Email {

    /** @var WC_Subscription */
    public $subscription;

    public function __construct() {
        $this->id             = 'hns_subscription_active';
        $this->title          = __( 'Subscription Activated (HNS)', 'hold-new-subscriptions' );
        $this->description    = __( 'Sent when a held subscription is activated after order approval.', 'hold-new-subscriptions' );
        $this->customer_email = true;
        $this->heading        = __( 'Your subscription is now active', 'hold-new-subscriptions' );
        $this->subject        = __( 'Your subscription #{subscription_id} is now active', 'hold-new-subscriptions' );

        $this->template_html  = 'hns-subscription-active.php';
        $this->template_plain = 'plain/hns-subscription-active.php';
        $this->template_base  = HNS_PLUGIN_DIR . 'emails/';

        $this->placeholders = array(
            '{subscription_id}' => '',
            '{order_id}'        => '',
        );

        parent::__construct();
    }

    /**
     * Trigger the email send.
     *
     * @param WC_Order        $order
     * @param WC_Subscription $subscription
     */
    public function trigger( $order, $subscription ) {
        if ( ! $order instanceof WC_Order || ! $subscription instanceof WC_Subscription ) {
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->warning(
                    'HNS: active email trigger() called with invalid order or subscription.',
                    array( 'source' => 'hold-new-subscriptions' )
                );
            }
            return;
        }

        $this->object       = $order;
        $this->subscription = $subscription;
        $this->recipient    = $order->get_billing_email();

        $this->placeholders['{subscription_id}'] = $subscription->get_id();
        $this->placeholders['{order_id}']        = $order->get_id();

        if ( $this->is_enabled() && $this->get_recipient() ) {
            $this->setup_locale();
            $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );
            $this->restore_locale();
        }
    }

    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            array(
                'order'         => $this->object,
                'subscription'  => $this->subscription,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => false,
                'email'         => $this,
            ),
            '',
            $this->template_base
        );
    }

    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            array(
                'order'         => $this->object,
                'subscription'  => $this->subscription,
                'email_heading' => $this->get_heading(),
                'sent_to_admin' => false,
                'plain_text'    => true,
                'email'         => $this,
            ),
            '',
            $this->template_base
        );
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Enable/Disable', 'hold-new-subscriptions' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable this email notification', 'hold-new-subscriptions' ),
                'default' => 'yes',
            ),
            'subject' => array(
                'title'       => __( 'Subject', 'hold-new-subscriptions' ),
                'type'        => 'text',
                'description' => sprintf( __( 'Available placeholders: %s', 'hold-new-subscriptions' ), '<code>{subscription_id}, {order_id}</code>' ),
                'placeholder' => $this->get_default_subject(),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'heading' => array(
                'title'       => __( 'Email Heading', 'hold-new-subscriptions' ),
                'type'        => 'text',
                'description' => sprintf( __( 'Available placeholders: %s', 'hold-new-subscriptions' ), '<code>{subscription_id}, {order_id}</code>' ),
                'placeholder' => $this->get_default_heading(),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'email_type' => array(
                'title'       => __( 'Email type', 'hold-new-subscriptions' ),
                'type'        => 'select',
                'description' => __( 'Choose which format of email to send.', 'hold-new-subscriptions' ),
                'default'     => 'html',
                'class'       => 'email_type wc-enhanced-select',
                'options'     => $this->get_email_type_options(),
                'desc_tip'    => true,
            ),
        );
    }
}
