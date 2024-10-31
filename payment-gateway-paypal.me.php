<?php

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins'))))
    return;

/*
  Plugin Name: Payment Gateway - Paypal.Me
  Description: Provides an Offline Payment Gateway using Paypal.Me link. Display your Paypal.Me link Or Paypal Email on your website and get payment direcly using PayPal.
  Version: 1.0.0
  Author: Smit Raval
  License: GPL2
 */

add_action('plugins_loaded', 'wc_paypal_me_offline_gateway_init', 11);

function wc_paypal_me_offline_gateway_init() {

    class WC_Gateway_Paypal_Me extends WC_Payment_Gateway {

        /**
         * Init and hook in the integration.
         */
        function __construct() {
            global $woocommerce;
            $this->id = "paypal-me";
            $this->has_fields = false;
            $this->method_title = __("Paypal Me", 'woocommerce-paypal-me');
            $this->method_description = "Provides an Offline Payment Gateway using Paypal.Me link. Display your Paypal.Me link Or Paypal Email on your website and get payment direcly using PayPal.<br/><a class='button-primary' style='float:right;font-size:20px;' target='_blank' href='https://www.paypal.me/SmitRaval'>Donate Me :)</a>";

            //Initialize form methods
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables.
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->instructions = $this->settings['instructions'];
            $this->paypal_me_url = $this->settings['paypal_me_url'];
            $this->paypal_email_id = $this->settings['paypal_email_id'];

            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
                add_action('woocommerce_thankyou', array(&$this, 'thankyou_page'));
            }
            // Customer Emails
            add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
        }

        // Build the administration fields for this specific Gateway
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce-paypal-me'),
                    'type' => 'checkbox',
                    'label' => __('Enable Paypal.me gateway', 'woocommerce-paypal-me'),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce-paypal-me'),
                    'type' => 'text',
                    'description' => __('This controls the title for the payment method the customer sees during checkout.', 'woocommerce-paypal-me'),
                    'default' => __('Pay with PayPal.me', 'woocommerce-paypal-me'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce-paypal-me'),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'woocommerce-paypal-me'),
                    'default' => __('Make your payment directly to our paypal account(Paypal.me link or Paypal email). Please use your Order ID as a payment reference.', 'woocommerce-paypal-me'),
                    'desc_tip' => true,
                ),
                'instructions' => array(
                    'title' => __('Instructions', 'woocommerce-paypal-me'),
                    'type' => 'textarea',
                    'description' => __('Instructions that will be added to the thank you page and emails.', 'woocommerce-paypal-me'),
                    'default' => "Make your payment directly to our paypal accountusing Paypal.me link or paypal email. Please use your Order ID as the payment reference in 'Special instructions to seller (optional)' on Paypal while making payment. Your order won't be shipped until the funds have cleared in our paypal account.",
                    'desc_tip' => true,
                ),
                'paypal_me_url' => array(
                    'title' => __('Paypal.me URL*', 'woocommerce-paypal-me'),
                    'type' => 'text',
                    'description' => __('Please Provide your paypal.me link here', 'woocommerce-paypal-me'),
                    'default' => "Your Paypal.me url, to be displayed on Thank you page!! For example https://paypal.me/smitraval",
                    'desc_tip' => true,
                ),
                'paypal_email_id' => array(
                    'title' => __('Paypal Email(optional)', 'woocommerce-paypal-me'),
                    'type' => 'text',
                    'description' => __('Please Provide your Paypal email here to accept payments!!', 'woocommerce-paypal-me'),
                    'default' => "You can also provide your Paypal email, to be displayed on Thank you page!!",
                    'desc_tip' => true,
                ),
            );
        }

        public function validate_paypal_me_url_field($key, $value) {
            if (isset($value)) {
                if (filter_var($value, FILTER_VALIDATE_URL) === FALSE) {
                    WC_Admin_Settings::add_error(esc_html__('Please enter a valid paypal.me URL. This url will be displayed on Thank you page to recieve payments.', 'woocommerce-paypal-me'));
                }
            }

            return $value;
        }

        public function validate_paypal_email_id_field($key, $value) {
            if (isset($value) && !empty($value)) {
                if (filter_var($value, FILTER_VALIDATE_EMAIL) === FALSE) {
                    WC_Admin_Settings::add_error(esc_html__('Please enter a valid paypal email. This email will be displayed on Thank you page to recieve payments.', 'woocommerce-paypal-me'));
                }
            }

            return $value;
        }

        public function process_payment($order_id) {

            $order = wc_get_order($order_id);

            // Mark as on-hold (we're awaiting the payment)
            $order->update_status('on-hold', __('Awaiting offline payment', 'woocommerce-paypal-me'));

            // Reduce stock levels
            $order->reduce_order_stock();

            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }

        /**
         * Output for the order received page.
         */
        public function thankyou_page($order_id) {
            $order = new WC_Order($order_id);
            $order_total = "/" . $order->get_total() . "USD";
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
            }
            if ($this->paypal_me_url) {
                echo "<br/>Please make payment using Paypal.<b> Please use Order Number as a payment reference while making payment.</b>";
                echo "<br/><div class='paypal_me_wrapper'><a href=" . $this->paypal_me_url . $order_total . " class='paypal_me_class' id='paypalme_button_id' target='_blank'>Click Here To Pay With <span class='paypalme__span'>PayPal.ME</span></a></div>";
                echo "<style>.paypal_me_wrapper{display:block;width:100%;height:auto;position:relative;} a.paypal_me_class{position:relative;margin:10px auto;background:#37287f;border:1px solid #569;border-radius:5px;box-shadow:0 1px 0 0 #444;color:#fff;display:block;padding:12px 20px;font:normal 400 20px/1 'Open Sans',sans-serif;text-align:center;text-shadow:none;width:40%;} span.paypalme__span{font-weight:bold;}</style>";
                if ($this->paypal_email_id) {
                    echo "You can also use our Paypal Email_id to make payments.Our Paypal Email is <b>" . $this->paypal_email_id . "</b><br/><br/>";
                }
            }
        }

        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions($order, $sent_to_admin, $plain_text = false) {
            if ($this->instructions && !$sent_to_admin && 'paypal-me' === $order->payment_method && $order->has_status('on-hold')) {
                if ($this->instructions) {
                    echo wpautop(wptexturize($this->instructions));
                }
                if ($this->paytm_me_url) {
                    echo "<br/>Please use this <a href=" . $this->paypal_me_url . " target='_blank' >link</a> to make payment using Paypal.<b> Please use Order Number as a payment reference while making payment.</b>";
                    echo "Our Paypal.Me link = <b>" . $this->paytm_qr_url . "</b>";
                    if (isset($this->paypal_email_id) && !empty($this->paypal_email_id)) {
                        echo "You can also use our Paypal Email_id to make payments.Our Paypal Email is <b>" . $this->paypal_email_id . "</b>";
                    }
                }
            }
        }

    }

    // Now that we have successfully included our class,
    // Lets add it too WooCommerce
    add_filter('woocommerce_payment_gateways', 'add_paypal_me');

    function add_paypal_me($methods) {
        $methods[] = 'WC_Gateway_Paypal_ME';
        return $methods;
    }

}
