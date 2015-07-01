<?php

/*
 * Paymentwall Gateway for WooCommerce
 *
 * Description: Official Paymentwall module for WordPress WooCommerce.
 * Plugin URI: https://www.paymentwall.com/en/documentation/WooCommerce/1409
 * Version: 0.2.1
 * Author: Paymentwall
 * License: MIT
 *
 */
require_once(dirname(__FILE__) . '/lib/paymentwall.php');
add_action('plugins_loaded', 'loadPaymentwallGateway', 0);

function loadPaymentwallGateway()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return; // Nothing happens here is WooCommerce is not loaded
    }

    class Paymentwall_Gateway extends WC_Payment_Gateway
    {

        public function __construct()
        {
            $this->id = 'paymentwall';
            $this->icon = plugins_url('paymentwall-for-woocommerce/images/icon.png');
            $this->has_fields = true;
            $this->method_title = __('Paymentwall', 'woocommerce');

            // Load the form fields.
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Load Paymentwall Merchant Information
            $this->app_key = $this->settings['appkey'];
            $this->secret_key = $this->settings['secretkey'];
            $this->widget_code = $this->settings['widget'];
            $this->description = $this->settings['description'];

            Paymentwall_Config::getInstance()->set(array(
                'api_type' => Paymentwall_Config::API_GOODS,
                'public_key' => $this->app_key,
                'private_key' => $this->secret_key
            ));

            $this->title = 'Paymentwall';
            $this->notify_url = str_replace('https:', 'http:', add_query_arg('wc-api', 'Paymentwall_Gateway', home_url('/')));

            // Our Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_paymentwall', array($this, 'receipt_page'));
            add_action('woocommerce_api_paymentwall_gateway', array($this, 'check_ipn_response'));

        }

        /*
         * Makes the widget call
         */
        function receipt_page($order_id)
        {

            $order = new WC_Order ($order_id);

            $widget = new Paymentwall_Widget(
                $order->billing_email,
                $this->widget_code,
                array(
                    new Paymentwall_Product(
                        $order->id,
                        $order->order_total,
                        $order->order_currency,
                        'Order #' . $order->id,
                        Paymentwall_Product::TYPE_FIXED
                    )
                ),
                array('email' => $order->billing_email)
            );
            echo '<p>' . __('Please continue the purchase via Paymentwall using the widget below.', 'woocommerce') . '</p>';

            echo $widget->getHtmlCode();
        }

        /*
         * Process the order after payment is made
         */
        function process_payment($order_id)
        {

            $order = new WC_Order ($order_id);

            global $woocommerce;

            if (isset ($_REQUEST ['ipn']) && $_REQUEST ['ipn'] == true) {

                // Remove cart
                $woocommerce->cart->empty_cart();

                // Payment complete
                $order->payment_complete();

                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('thanks'))))

                );

            } else {

                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('pay'))))
                );

            }
        }

        /*
         * Display administrative fields under the Payment Gateways tab in the Settings page
         */
        function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable the Paymentwall Payment Solution', 'woocommerce'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Paymentwall', 'woocommerce')
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default' => __("Pay via Paymentwall.", 'woocommerce')
                ),
                'appkey' => array(
                    'title' => __('Application Key', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Your Paymentwall Application Key', 'woocommerce'),
                    'default' => ''
                ),
                'secretkey' => array(
                    'title' => __('Secret Key', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Your Paymentwall Secret Key', 'woocommerce'),
                    'default' => ''
                ),
                'widget' => array(
                    'title' => __('Widget Code', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Enter your preferred widget code', 'woocommerce'),
                    'default' => ''
                )
            );
        } // End init_form_fields()

        /*
         * Displays a short description to the user during checkout
         */
        function payment_fields()
        {
            echo $this->description;
        }

        /*
         * Displays text like introduction, instructions in the admin area of the widget
         */
        public function admin_options()
        {

            ?>
            <h3><?php _e('Paymentwall Gateway', 'woocommerce'); ?></h3>
            <p><?php _e('Enables the Paymentwall Payment Solution. The easiest way to monetize your game or web service globally.', 'woocommerce'); ?></p>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
        <?php
        }

        /*
         * Calculate the parameters so we know we are sending a legitimate (unaltered) request to the servers
         */
        function calculateWidgetSignature($params, $secret)
        {
            // work with sorted data
            ksort($params);
            // generate the base string
            $baseString = '';
            foreach ($params as $key => $value) {
                $baseString .= $key . '=' . $value;
            }
            $baseString .= $secret;
            return md5($baseString);
        }

        /*
         * This ensures that the pingback response was not tampered
         */
        function calculatePingbackSignature($params, $secret)
        {
            //sort params before calculate signature
            ksort($params);
            $str = '';
            foreach ($params as $k => $v) {
                $str .= "$k=$v";
            }
            $str .= $secret;
            return md5($str);
        }

        /*
         * Check the response from Paymentwall's Servers
         */
        function check_ipn_response()
        {
            $_REQUEST['ipn'] = true;
            $signatureParams = $_GET;
            //These parameters are not necessary for calculating signature
            if(isset($signatureParams['wc-api']) && $signatureParams['wc-api'] != ''){
                unset($signatureParams['wc-api']);
            }
            if(isset($signatureParams['paymentwallListener']) && $signatureParams['paymentwallListener'] != ''){
                unset($signatureParams['paymentwallListener']);
            }
            $pingback = new Paymentwall_Pingback($signatureParams, $_SERVER['REMOTE_ADDR']);
            if ($pingback->validate()) {
                $goodsId = $pingback->getProduct()->getId();
                $reason = $pingback->getParameter('reason');
                $order = new WC_Order((int) $goodsId);
                global $woocommerce;
                if ($order->get_order($goodsId)) {
                    if ($pingback->isCancelable()) {
                        $order->update_status('cancelled', __('Reason: ' . $reason, 'woocommerce'));
                    } else {
                        $order->add_order_note(__('Paymentwall payment completed', 'woocommerce'));
                        $order->payment_complete();
                        $woocommerce->cart->empty_cart();
                    }
                    echo 'OK';
                } else {
                    echo 'Paymentwall IPN Request Failure';
                }
            } else {
                echo $pingback->getErrorSummary();
            }
            return;
        }
    }

    function WcPwGateway($methods)
    {
        $methods[] = 'Paymentwall_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'WcPwGateway');
}