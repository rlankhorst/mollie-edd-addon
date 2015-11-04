<?php
/*
Plugin Name: Easy Digital Downloads - Mollie Gateway
Plugin URL: http://www.degrinthorst.nl/edd-mollie-plugin
Description: A simple Mollie payment gateway addon for Easy Digital Downloads Wordpress plugin
Version: 1.1
Author: Sander de Wijs
Author URI: http://www.degrinthorst.nl
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Mollie EDD options
function sw_edd_add_settings($settings)
{
    $mollie_gateway_settings = array(
        array(
            'id' => 'mollie_gateway_settings',
            'name' => '<strong>' . __('Mollie Gateway settings', 'sw_edd') . '</strong>',
            'desc' => __('Configure the Gateway settings', 'sw_edd'),
            'type' => 'header'
        ),
        array(
            'id' => 'mollie_live_api_key',
            'name' => __('Live API key', 'sw_edd'),
            'desc' => __('Enter your Mollie live API key, found in your Mollie website profile', 'sw_edd'),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id' => 'mollie_test_api_key',
            'name' => __('Test API key', 'sw_edd'),
            'desc' => __('Enter your Mollie test API key, found in your Mollie website profile', 'sw_edd'),
            'type' => 'text',
            'size' => 'regular'
        )
    );
    return array_merge($settings, $mollie_gateway_settings);
}
add_filter('edd_settings_gateways', 'sw_edd_add_settings');

if(!function_exists(mollie_validate)) {
    function mollie_validate() {
        global $edd_options;
        $output = array();
        $error_msg = '';
        $live_api_set = false;
        $test_api_set = false;
        $test_api = $edd_options['mollie_test_api_key'];
        $live_api = $edd_options['mollie_live_api_key'];

        // Test if live key exsists and is valid
        if(isset($live_api)) {
            $live_api_validate = strpos($live_api, 'live_');
            // var_dump($live_api);
            if($live_api_validate !== 0) {
                $error_msg .= ' - API Key must start with live_';
            } elseif(strlen(utf8_encode($live_api)) !== 35) {
                $error_msg .= ' - API Key must be 35 chars';
            } else {
                $live_api_set = true;
            }
        } else {
            $live_api_set = false;
        }
        // Test if test key exsists and is valid
        if(isset($test_api)) {
            $test_api_validate = strpos($test_api, 'test_');
            if($test_api_validate !== 0) {
                $error_msg .= ' - API Key must start with test_';
            } elseif(strlen(utf8_encode($test_api)) !== 35) {
                $error_msg .= ' - API Key must be 35 chars';
            } else {
                $test_api_set = true;
            }
        } else {
            $test_api_set = false;
        }
        if($test_api_set == true && $live_api_set == true) {
            $mollie_is_valid = true;
        } else {
            $mollie_is_valid = false;
        }
        $output[] = $mollie_is_valid;
        $output[] = $error_msg;
        return $output;
    }
}

// Setup Mollie API object
if(!function_exists(mollieApiConnect)) {
    function mollieApiConnect()
    {
        global $edd_options;

        require_once 'Mollie/API/Autoloader.php';

        if (!isset($edd_options['mollie_live_api_key'])) {
            $edd_options['mollie_live_api_key'] = 'live_h9b0b5XjCh9dneJArJ6e5VUPSN94aI';
        }
        if (!isset($edd_options['mollie_test_api_key'])) {
            $edd_options['mollie_test_api_key'] = 'test_AANaktwcJV3vqANWz29RtEKdoWKrpI';
        }
        if (NULL !== $edd_options['mollie_test_api_key'] && NULL !== $edd_options['mollie_live_api_key']) {
            $mollie = NULL;
            if (edd_is_test_mode()) {
                $mollie_api = $edd_options['mollie_test_api_key'];
            } else {
                $mollie_api = $edd_options['mollie_live_api_key'];
            }

            try {
                $mollie = new Mollie_API_Client;
                // write_log($mollie);
            } catch (Mollie_API_Exception $e) {
                echo "API call failed: " . htmlspecialchars($e->getMessage());
            }

            try {
                $mollie->setApiKey($mollie_api);
            } catch (Mollie_API_Exception $e) {
                echo "<p>" . "API call failed: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
            return $mollie;

        } else {
            echo '</p>No valid API keys set</p>';
        }
    }
}

function sw_edd_register_gateway($gateways)
{
    $validation = mollie_validate();
    if($validation[0] == false) { ?>
        <div id="setting-error-" class="updated settings-error notice is-dismissible">
            <p><strong><?php echo $validation[1]; ?></strong></p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text">Deze waarschuwing verbergen.</span>
            </button>
        </div>
    <?php }
    $mollie_gateway = mollieApiConnect();
    try {
        $methods = $mollie_gateway->methods->all();
    } catch (Mollie_API_Exception $e) {
        echo "API call failed: " . htmlspecialchars($e->getMessage());
    }
    foreach ($methods as $method) {
        $gateway = json_decode(json_encode($method->id), true);
        $label = json_decode(json_encode($method->description), true);
        $admin_label = json_decode(json_encode($method->description), true) . ' (Mollie)';

        $mollie_gateways[$gateway] = array(
            'admin_label' => $admin_label,
            'checkout_label' => $label);
    }
    $new_gateways = array_merge($mollie_gateways, $gateways);

    // var_dump($new_gateways);
    return $new_gateways;
}
add_filter('edd_payment_gateways', 'sw_edd_register_gateway');

function sw_edd_mollie_gateway_cc_form()
{
    return;
}
add_action('edd_mollie_gateway_cc_form', 'sw_edd_mollie_gateway_cc_form');

function mollie_payment_icons($icons)
{
    $validation = mollie_validate();
    if($validation[0] === false) { ?>
        <div id="setting-error-" class="updated settings-error notice is-dismissible">
            <p><strong><?php echo $validation[1]; ?></strong></p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text">Deze waarschuwing verbergen.</span>
            </button>
        </div>
    <?php }
    $mollie = mollieApiConnect();

    try {
        $methods = $mollie->methods->all();
    } catch (Mollie_API_Exception $e) {
        echo "API call failed: " . htmlspecialchars($e->getMessage());
    }
    foreach ($methods as $method) {
        $gateway = json_decode(json_encode($method->id), true);
        $gateway_img = json_decode(json_encode($method->image->normal), true);
        $icons[$gateway_img] = $gateway;
    }
    return $icons;
}

add_filter('edd_accepted_payment_icons', 'mollie_payment_icons');

if (! function_exists('gateway_mollie_payment_processing')) {
    function gateway_mollie_payment_processing($purchase_data)
    {
        global $edd_options;

        // Setup variables for payment processing
        $plugin_url = plugin_dir_url(__FILE__);
        $mollie_test_api = $edd_options['test_api_key'];
        $mollie_live_api = $edd_options['live_api_key'];
        // $success_page = edd_get_success_page_url($purchase_data);

        $cart_summary = edd_get_purchase_summary($purchase_data, false);

        // Setup payment details for EDD database

        $edd_payment = array(
            'price' => $purchase_data['price'],
            'date' => $purchase_data['date'],
            'user_email' => $purchase_data['user_email'],
            'purchase_key' => $purchase_data['purchase_key'],
            'currency' => $edd_options['currency'],
            'downloads' => $purchase_data['downloads'],
            'cart_details' => $purchase_data['cart_details'],
            'user_info' => $purchase_data['user_info'],
            'gateway' => 'mollie_gateway',
            'status' => 'pending'
        );

        $edd_payment = edd_insert_payment($edd_payment);

        // Check payment
        if (! $edd_payment) {
            // Record the error
            edd_record_gateway_error(__('Payment Error', 'edd'), sprintf(__('Payment creation failed before sending buyer to IDEAL. Payment data: %s', 'edd'), json_encode($payment_data)), $payment);
            // Problems? send back
            edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
        }  else {
            // Only send to Mollie if the pending payment is created successfully
            // Get the success url
            $return_url = add_query_arg(array(
                'payment-confirmation' => 'mollie_gateway',
                'payment-id' => $edd_payment

            ), get_permalink($edd_options['success_page']));

            // Create a new Mollie Payment object
//            try {
//                $mollie_test_api = $edd_options['test_api_key'];
//                $mollie_live_api = $edd_options['live_api_key'];
//
//                // Load Mollie API with autoloader
//
//                require ('Mollie/API/Autoloader.php');
//
//                $mollie = new Mollie_API_Client;
//
//                // Check if test mode is set to determine what API Key to use for payment object.
//                if (edd_is_test_mode()) {
//                        $mollie->setApiKey($mollie_test_api);
//                } else {
//                        $mollie->setApiKey($mollie_live_api);
//                }

            $validation = mollie_validate();
            if($validation[0] == false) { ?>
                <div id="setting-error-" class="updated settings-error notice is-dismissible">
                    <p><strong><?php echo $validation[1]; ?></strong></p>
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Deze waarschuwing verbergen.</span>
                    </button>
                </div>
            <?php }
            $mollie_gateway = mollieApiConnect();

            // Create webhook URL for Mollie
            $base_url = get_site_url();
            $webhookUrl = $base_url . '/?edd-listener=MOLLIE';

            $payment = $mollie->payments->create(array(
                'amount' => $purchase_data['price'],
                'description' => $cart_summary,
                'method' => 'ideal',
                'redirectUrl' => $return_url,
                'webhookUrl' => $webhookUrl,
                'metadata' => array(
                    'order_id' => $purchase_data['purchase_key']
                )
            ));
            // Write the Mollie Payment object id to the database for reference
            try {
                $mollie_id = $payment->id;

                // write_log($payment);
                edd_set_payment_transaction_id($edd_payment, $mollie_id);


                // Get payment URL to process payment
                $payment_redirect = $payment->getPaymentUrl();

                edd_empty_cart();

                // Redirect to Mollie
                header("Location: " . $payment_redirect);
                exit;
            } catch (Mollie_API_Exception $e) {
                echo "API call failed: " . htmlspecialchars($e->getMessage());
            }
        }
    }

    add_action('edd_gateway_mollie_gateway', 'gateway_mollie_payment_processing');
}

/**
 * Listen for a Mollie POST with the payment ID and call the processing function
 *
 * @since 1.0
 * @global $edd_options Array of all the EDD Options
 * @return void
 */

function mollie_gateway_listener()
{
    global $edd_options;
    // Mollie IPN
    // if (isset( $_POST["id"])) {
    if (isset( $_POST["id"] )) {
        do_action('edd_mollie_verify_ipn');
    }
}
add_action('init', 'mollie_gateway_listener');

/**
 * Add fields in EDD admin for Mollie API Keys
 *
 * @since 1.0
 * @global $edd_options Array of all the EDD Options
 * @return void
 */


// Process the payment status notification
function sw_edd_process_mollie_ipn()
{
    global $edd_options;

    $validation = mollie_validate();
    if($validation[0] == false) { ?>
        <div id="setting-error-" class="updated settings-error notice is-dismissible">
            <p><strong><?php echo $validation[1]; ?></strong></p>
            <button type="button" class="notice-dismiss">
                <span class="screen-reader-text">Deze waarschuwing verbergen.</span>
            </button>
        </div>
    <?php }
    $mollie_gateway = mollieApiConnect();
    $payment = "";
    $id = $_POST["id"];
    // write_log($id);

    try {

        // Get the request id from the Mollie payment status update
        $payment = $mollie_ipn->payments->get($id);
        $edd_payment = $payment->metadata->order_id;
        // Create logfile for $edd_payment_id
        // write_log($payment);
        // write_log($edd_payment);
        $order_id = edd_get_purchase_id_by_key($edd_payment);

        if ($payment->isPaid()) {
            edd_update_payment_status($order_id, $new_status = 'publish');
            header("HTTP/1.0 200 Ok");
            // write_log("Payment is OK");
        } elseif ($payment->isOpen()) {
            edd_record_gateway_error(
                'Payment Not Paid',
                sprintf( __( 'A payment was not processed: ', 'edd' ), json_encode( $payment ) )
            );
            edd_update_payment_status($order_id, $new_status = 'failed');
            $fail = true;
        } elseif ($payment->isCancelled()) {
            edd_record_gateway_error(
                'Payment Cancelled',
                sprintf( __( 'A payment was cancelled: ', 'edd' ), json_encode( $payment ) )
            );
            edd_update_payment_status($order_id, $new_status = 'revoked');
        } elseif ($fail !== false) {
            return;
        }
    } catch (Mollie_API_Exception $e) {
        header("HTTP/1.0 500 Internal Server Error");
    }

}

add_action('edd_mollie_verify_ipn', 'sw_edd_process_mollie_ipn');
