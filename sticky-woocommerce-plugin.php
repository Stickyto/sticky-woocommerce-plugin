<?php
/*
Plugin Name: Sticky WooCommerce Plugin
Description: Sticky WooCommerce Plugin.
Version: 1.0
Author: Zakariya Mohummed
*/

// Ensure WooCommerce is active
if (!defined('ABSPATH')) exit;

add_action('rest_api_init', 'create_custom_endpoint');
function create_custom_endpoint() {
    register_rest_route('sticky-payment/v1', '/payment-notification', array(
        'methods' => 'POST',
        'callback' => 'get_response',
        'permission_callback' => '__return_true',
        'args' => array(
            'private_key' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return is_string($param);
                }
            ),
            'order_number' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
            'order_status' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return is_string($param);
                }
            ),
        ),
    ));
}

function get_response($request) {
    try {
        // Retrieve the parameters from the request.
        $received_private_key = $request->get_param('private_key');
        $order_number = $request->get_param('order_number');
        $order_status = $request->get_param('order_status');

        // Get the saved private key from the custom payment gateway settings.
        $payment_gateways = WC()->payment_gateways->payment_gateways();
        $custom_gateway = isset($payment_gateways['sticky_plugin']) ? $payment_gateways['sticky_plugin'] : null;

        // Check if the custom payment gateway is set and fetch the private key.
        if ($custom_gateway && !empty($custom_gateway->settings['private_key'])) {
            $saved_private_key = $custom_gateway->settings['private_key'];
        } else {
            return new WP_REST_Response(array(
                'status' => 'error',
                'message' => 'Payment gateway settings are not set.'
            ), 500);
        }

        // Check if the received private key matches the saved private key.
        if ($saved_private_key !== $received_private_key) {
            return new WP_REST_Response(array(
                'status' => 'error',
                'message' => 'Unauthorized: Invalid private key'
            ), 401);
        }

        // Check if the order exists and is a valid WooCommerce order.
        $order = wc_get_order($order_number);
        if (!$order) {
            return new WP_REST_Response(array(
                'status' => 'error',
                'message' => 'Invalid order number'
            ), 404);
        }

        // Assuming 'processing' status means the payment is made but not fulfilled yet.
        // You might need to adjust the order status based on your workflow.
        $order->update_status($order_status, 'Order payment received via custom REST API.');

        return new WP_REST_Response(array(
            'status' => 'success',
            'message' => 'Order status updated.',
            'order_id' => $order->get_id(),
            'order_status' => $order->get_status()
        ), 200);
    } catch (Exception $e) {
        // Log the error for debugging purposes.
        error_log('Custom endpoint error: ' . $e->getMessage());

        // Return a generic error response to the client.
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Internal server error.'
        ), 500);
    }
}

// Add the custom payment gateway class
add_action('plugins_loaded', 'init_custom_payment_gateway');
function init_custom_payment_gateway() {
    if (!class_exists('WC_Sticky_Payment_Gateway')) {
        class WC_Sticky_Payment_Gateway extends WC_Payment_Gateway {
            public function __construct() {
                $this->id = 'sticky_plugin';
                $this->method_title = $this->get_option('title', 'Sticky WooCommerce Plugin');
                $this->title = $this->get_option('title', 'Sticky WooCommerce Plugin');
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
                        'label' => 'Enable Sticky WooCommerce Plugin',
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
                $order_total = number_format($order->get_total(), 2, '', '');
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
                $order->update_status('on-hold', __('Awaiting Sticky WooCommerce Plugin confirmation.', 'woocommerce'));

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
    $gateways[] = 'WC_Sticky_Payment_Gateway';
    return $gateways;
}