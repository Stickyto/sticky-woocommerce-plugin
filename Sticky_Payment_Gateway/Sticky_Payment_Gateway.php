<?php
/*
Plugin Name: Sticky WooCommerce Payment Gateway
Description: Sticky WooCommerce Payment Gateway.
Version: 1.0
Author: Zakariya Mohummed
*/

// Ensure WooCommerce is active
if (!defined('ABSPATH')) exit;

// Add the custom payment gateway class
add_action('plugins_loaded', 'init_custom_payment_gateway');
function init_custom_payment_gateway() {
    if (!class_exists('WC_Custom_Payment_Gateway')) {
        class WC_Custom_Payment_Gateway extends WC_Payment_Gateway {
            public function __construct() {
                $this->id = 'custom_payment';
                $this->method_title = $this->get_option('title', ' Sticky WooCommerce Payment Gateway');
                $this->title = $this->get_option('title', ' Sticky WooCommerce Payment Gateway');
                $this->has_fields = false;
                $this->init_form_fields();
                $this->init_settings();
                $this->enabled = $this->get_option('enabled');
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            }

            public function init_form_fields() {
                $this->form_fields = array(
                    'enabled' => array(
                        'title' => 'Enable/Disable',
                        'type' => 'checkbox',
                        'label' => 'Enable Sticky WooCommerce Payment Gateway',
                        'default' => 'yes',
                    ),
                    'title' => array(
                        'title' => 'Title',
                        'type' => 'text',
                        'description' => 'This controls the title which the user sees during checkout.',
                        'default' => 'Sticky Payment',
                    ),
                    'flow_url' => array(
                        'title' => 'Flow URL',
                        'type' => 'text',
                        'description' => 'Enter the flow URL for your sticky payment gateway.',
                        'default' => 'https://sticky.to/go/flow/123',
                    ),
                    'private_key' => array(
                        'title' => 'Private Key',
                        'type' => 'text',
                        'description' => 'Enter the private key for your sticky payment gateway.',
                        'default' => 'private-456',
                    ),
                );
            }

            private function get_signature($data, $privateKey) {
                return hash_hmac('sha512', $data, $privateKey, false);
            }

            public function process_payment($order_id) {
                $order = wc_get_order($order_id);
                $order_total = number_format($order->get_total(), 2, '.', '');
                $currency = get_woocommerce_currency();
                $userPaymentId = $order_id; // Use the WooCommerce order ID as the payment ID
                $flow = $this->get_option('flow_url'); // Retrieve the flow URL from the settings
                $privateKey = $this->get_option('private_key'); // Retrieve the private key from the settings

                // Construct the data string for hashing
                $dataString = "total={$order_total}&currency={$currency}&userPaymentId={$userPaymentId}";

                // Generate the hash signature
                $hash = $this->get_signature($dataString, $privateKey);

                // Construct the final URL with the hash signature
                $redirect_url = "{$flow}?{$dataString}&hash={$hash}";

                // Update the order status
                $order->update_status('on-hold', __('Awaiting Sticky WooCommerce Payment Gateway confirmation.', 'woocommerce'));

                return array(
                    'result' => 'success',
                    // Use the modified redirect URL
                    'redirect' => $redirect_url,
                );
            }
        }
    }
}

// Register the custom payment gateway with WooCommerce
add_filter('woocommerce_payment_gateways', 'add_custom_payment_gateway');
function add_custom_payment_gateway($gateways) {
    $gateways[] = 'WC_Custom_Payment_Gateway';
    return $gateways;
}
