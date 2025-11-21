<?php
/*
Plugin Name: Sticky WooCommerce Plugin
Description: Sticky WooCommerce Plugin.
Version: 1.3
Author: James Garner
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
                    'popup_iframe' => array(
                        'title'       => 'Use popup instead of redirect',
                        'type'        => 'checkbox',
                        'label'       => 'Enable pop-up instead of redirect',
                        'description' => 'If enabled, customers will see the payment page in a popup iframe instead of being redirected.',
                        'default'     => 'no',
                    ),
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
                        'description' => 'Enter your flow URL.',
                        'default' => 'https://sticky.to/go/flow/123',
                    ),
                    'do_on_hold' => array(
                        'title' => 'Put orders on "On Hold" before redirecting',
                        'type' => 'checkbox',
                        'label' => 'Set the order to "on-hold" before redirecting.',
                        'default' => 'yes',
                    ),
                    'private_key' => array(
                        'title' => 'Private Key',
                        'type' => 'text',
                        'description' => 'Enter your private key.',
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
                $currency = $order->get_currency();
                $userPaymentId = $order_id; // Use the WooCommerce order ID as the payment ID
                $flow = $this->get_option('flow_url'); // Retrieve the flow URL from the settings
                $privateKey = $this->get_option('private_key'); // Retrieve the private key from the settings
                $usePopup = 'yes' === $this->get_option('popup_iframe', 'no');
                // Detect WooCommerce "Pay for Order" flow (order-pay endpoint)
                $is_order_pay = (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) || isset($_POST['woocommerce-pay-nonce']);
                // In order-pay, avoid DOM injection/popup and always return a redirect
                $usePopup = $usePopup && !$is_order_pay;

                // Construct the data string for hashing
                $dataString = "total={$order_total}&currency={$currency}&userPaymentId={$userPaymentId}";

                // Generate the hash signature
                $hash = $this->get_signature($dataString, $privateKey);

                // Construct the final URL with the hash signature
                $redirect_url = "{$flow}?{$dataString}&hash={$hash}";

                if ($this->get_option('do_on_hold') === 'yes') {
                    $order->update_status('on-hold', __('Awaiting Sticky WooCommerce Plugin confirmation.', 'woocommerce'));
                }

                if ($usePopup) {
                    return array(
                        'result'   => 'success',
                        'messages' => '<script>
                            (() => {
                                const styleDuplicateKeys = []

                                const noop = () => {}

                                function addStyle (deduplicateKey, string) {
                                    if (typeof window === "undefined") return noop
                                    if (typeof deduplicateKey === "string" && styleDuplicateKeys.includes(deduplicateKey)) return noop
                                    const style = document.createElement("style")
                                    style.textContent = string
                                    document.head.append(style)
                                    typeof deduplicateKey === "string" && styleDuplicateKeys.push(deduplicateKey)
                                    return () => {
                                        style.remove()
                                    }
                                }

                                function uuid (useUnderscores = false) {
                                    const base = !useUnderscores ? "zzzzzzzz-zzzz-4zzz-yzzz-zzzzzzzzzzzz" : "zzzzzzzz_zzzz_4zzz_yzzz_zzzzzzzzzzzz"
                                    let
                                        d = new Date().getTime(),
                                        d2 = (performance && performance.now && (performance.now() * 1000)) || 0
                                    return base.replace(/[zy]/g, c => {
                                        let r = Math.random() * 16
                                        if (d > 0) {
                                            r = (d + r) % 16 | 0
                                            d = Math.floor(d / 16)
                                        } else {
                                            r = (d2 + r) % 16 | 0
                                            d2 = Math.floor(d2 / 16)
                                        }
                                        return (c == "z" ? r : (r & 0x7 | 0x8)).toString(16)
                                    })
                                }

                                function popUpIframe ({ html, inlineStyle, src, canClose = true, width, height, maxWidth, maxHeight, borderRadius, onClose, insideElementName = "iframe", showBlocker = true }) {
                                    document.body.style.overflow = "hidden"

                                    const foundIframe = document.querySelector("iframe.pop-up-frame--inside")
                                    if (foundIframe) {
                                        foundIframe.src = src
                                        return
                                    }
                                    addStyle(
                                        "pop-up-something",
                                        `
                                        .pop-up-frame--blocker {
                                            background-color: rgba(0, 0, 0, 0.85);
                                            backdrop-filter: blur(8px);
                                            position: fixed;
                                            top: 0;
                                            left: 0;
                                            bottom: 0;
                                            right: 0;
                                            z-index: 100000;
                                        }
                                        .pop-up-frame--inside {
                                            display: block;
                                            width: ${typeof width === "string" ? width : "calc(100% - 32px)"};
                                            height: ${typeof height === "string" ? height : "calc(100% - 32px)"};
                                            max-width: ${typeof maxWidth === "string" ? maxWidth : "1024px"};
                                            max-height: ${typeof maxHeight === "string" ? maxHeight : "640px"};
                                            border-radius: ${typeof borderRadius === "string" ? borderRadius : "6px"};
                                            background-color: white;
                                            position: fixed;
                                            top: 50%;
                                            left: 50%;
                                            transform: translate(-50%, -50%);
                                            z-index: 1000001;
                                            box-shadow: rgba(60, 68, 86, 0.2) 0px 3px 6px 0px, rgba(0, 0, 0, 0.2) 0px 1px 2px 0px;
                                            border: 0;
                                        }
                                        .pop-up-frame--button {
                                            display: block;
                                            width: 28px;
                                            height: 28px;
                                            font-size: 28px;
                                            background-color: white;
                                            color: white;
                                            border-radius: 50%;
                                            position: absolute;
                                            top: 20px;
                                            right: 20px;
                                            z-index: 1001;
                                            box-shadow: 0 2px 4px 0 rgb(60 66 87 / 40%), 0 2px 4px 0 rgb(0 0 0 / 40%);
                                        }
                                        .pop-up-frame--button svg {
                                            color: #1A1F35;
                                            display: block;
                                            width: 20px;
                                            margin: 0 auto 0 auto;
                                            position: absolute;
                                            top: 0px;
                                            left: 2px;
                                        }
                                        `
                                    )

                                    const blocker = document.createElement("div")
                                    showBlocker && ((e, es) => {
                                        e.setAttribute("role", "presentation")
                                        e.classList.add("pop-up-frame--blocker")
                                        document.body.appendChild(e)
                                    })(blocker, blocker.style)

                                    const insideElementId = uuid()
                                    const insideElement = document.createElement(insideElementName)
                                    ;((e, es) => {
                                        e.setAttribute("role", "dialog")
                                        e.ariaModal = "true"
                                        e.name = "pop-up-frame--inside"
                                        e.classList.add("pop-up-frame--inside")
                                        e.id = insideElementId
                                        document.body.appendChild(e)
                                        if (typeof html === "string" && insideElementName === "iframe") {
                                            e.contentWindow.document.open()
                                            e.contentWindow.document.write(html)
                                            e.contentWindow.document.close()
                                        }
                                        if (src && insideElementName === "iframe") {
                                            e.src = src
                                            e.allow = "payment *"
                                        }
                                        if (typeof inlineStyle === "string") {
                                            e.style = inlineStyle
                                        }
                                        if (typeof html === "string" && ["div", "form"].includes(insideElementName)) {
                                            e.innerHTML = html
                                        }
                                    })(insideElement, insideElement.style)

                                    let closeButton
                                    if (canClose) {
                                        closeButton = document.createElement("button")
                                        ;((e, es) => {
                                            e.innerHTML = "<svg fill=\"none\" height=\"24\" stroke=\"currentColor\" stroke-linecap=\"round\" stroke-width=\"3\" viewBox=\"0 0 24 24\" width=\"24\" xmlns=\"http://www.w3.org/2000/svg\"><path d=\"m18 6-12 12\"/><path d=\"m6 6 12 12\"/></svg>"
                                            e.classList.add("pop-up-frame--button")
                                            e.addEventListener("click", () => {
                                                doClose()
                                                onClose && onClose()
                                            })
                                            document.body.appendChild(e)
                                        })(closeButton, closeButton.style)
                                    }

                                    const doClose = () => {
                                        document.body.style.overflow = "visible"
                                        blocker.remove()
                                        insideElement.remove()
                                        canClose && closeButton.remove()
                                    }
                                    return {
                                        doClose,
                                        element: insideElement,
                                        elementId: insideElementId
                                    }
                                }

                                popUpIframe({ src: "' . $redirect_url . '", canClose: false })
                            })();
                        </script>',
                    );
                }

                return array(
                    'result' => 'success',
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

/**
 * Allow Apple Pay inside iframe from pay.sticky.to
 * by setting the modern Permissions-Policy header
 * and the legacy Feature-Policy header for older Safari/WebKit.
 */
add_filter('wp_headers', function ($headers) {
  if (function_exists('is_checkout') && is_checkout()) {
    $pp_value = 'payment=(self "https://pay.sticky.to")';
    $fp_value = "payment 'self' https://pay.sticky.to";

    // Merge/append Permissions-Policy
    if (isset($headers['Permissions-Policy']) && $headers['Permissions-Policy'] !== '') {
      $headers['Permissions-Policy'] .= ', ' . $pp_value;
    } else {
      $headers['Permissions-Policy'] = $pp_value;
    }

    // Also set legacy Feature-Policy for older WebKit/Safari error messaging
    if (isset($headers['Feature-Policy']) && $headers['Feature-Policy'] !== '') {
      // If a policy exists, append our directive
      $headers['Feature-Policy'] .= ', ' . $fp_value;
    } else {
      $headers['Feature-Policy'] = $fp_value;
    }
  }
  return $headers;
}, 10);
