<?php
/*
Plugin Name: Easy Digital Downloads - Mollie Gateway
Plugin URL: https://github.com/sanderdewijs/mollie-edd-addon
Description: A simple Mollie payment gateway addon for Easy Digital Downloads Wordpress plugin
Version: 1.0
Author: Sander de Wijs
Author URI: http://www.degrinthorst.nl
*/

/**
 * Register the Mollie gateway in EDD
 *
 * @since 1.0
 * @param $gateways Array Array of gateways registered in EDD
 * @return $gateways Array updated gateways array
 */
function sw_edd_register_gateway($gateways)
{
    $gateways['mollie_gateway'] = array(
      'admin_label' => 'Mollie Payments',
      'checkout_label' => __('Mollie Payments', 'sw_edd'));
    return $gateways;
}
add_filter('edd_payment_gateways', 'sw_edd_register_gateway');

/**
 * Remove creditcard from from checkout screen since 
 * we're redirecting to Mollie checkout screen
 *
 * @since 1.0
 * @return void
 */
function sw_edd_mollie_gateway_cc_form()
{
    return;
}
add_action('edd_mollie_gateway_cc_form', 'sw_edd_mollie_gateway_cc_form');

/**
 * Add an IDEAL icon for the EDD settings screen
 *
 * @since 1.0
 * @param $icons Array Icon array for the EDD payment icons
 * @return $icons Array updated icons array
 */
function mollie_payment_icon($icons)
{
    $plugin_url = plugin_dir_url(__FILE__);
    $icons[$plugin_url . 'assets/img/iDEAL-klein.gif'] = 'IDEAL';
    return $icons;
}

add_filter('edd_accepted_payment_icons', 'mollie_payment_icon');

/**
 * Create a payment in EDD to process with Mollie Gateway
 *
 * @since 1.0
 * @global $edd_options Array of all the EDD Options
 * @param $purchase_data All data from the created EDD order
 * @return void
 */
if (! function_exists('gateway_mollie_payment_processing')) {
    function gateway_mollie_payment_processing($purchase_data)
    {
        global $edd_options;

        // Setup variables for payment processing
        $plugin_url = plugin_dir_url(__FILE__);
        $mollie_test_api = $edd_options['test_api_key'];
        $mollie_live_api = $edd_options['live_api_key'];

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

        // Check if payment is EDD payment
        if (! $edd_payment) {
        // Record the error
            edd_record_gateway_error(__('Payment Error', 'edd'), sprintf(__('Payment creation failed before sending buyer to IDEAL. Payment data: %s', 'edd'), json_encode($payment_data)), $payment);
        // Problems? send back to checkout
            edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
        }  else {
            // Only send to Mollie if the pending payment is created successfully
            // Get the success url
            $return_url = add_query_arg(array(
                    'payment-confirmation' => 'mollie_gateway',
                    'payment-id' => $edd_payment

                ), get_permalink($edd_options['success_page']));

            // Create a Mollie Payment object
            try {
                $mollie_test_api = $edd_options['test_api_key'];
                $mollie_live_api = $edd_options['live_api_key'];

                // Load Mollie API with autoloader

                require ('Mollie/API/Autoloader.php');

                $mollie = new Mollie_API_Client;

                // Check if test mode is set to determine what API Key to use for payment object.
                if (edd_is_test_mode()) {
                        $mollie->setApiKey($mollie_test_api);
                } else {
                        $mollie->setApiKey($mollie_live_api);
                }

                // Create webhook URL for Mollie. This way the client doesn't need to 
                // set a webhook URL in Mollie website profile
                $base_url = get_site_url();
                $webhookUrl = $base_url . '?edd-listener=MOLLIE';

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
                $mollie_id = $payment->id;
                edd_set_payment_transaction_id($edd_payment, $mollie_id);

            } catch (Mollie_API_Exception $e) {
                echo "API call failed: " . htmlspecialchars($e->getMessage());
            }

            // Get payment URL to process payment
            $payment_redirect = $payment->getPaymentUrl();

            edd_empty_cart();

            // Redirect to Mollie
            header("Location: " . $payment_redirect);
            exit;
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
 * Process the Mollie Payment notification
 * Use the transaction ID to retrieve the payment
 * Update the EDD order payment status
 *
 * @since 1.0
 * @global $edd_options Array of all the EDD Options
 * @return void
 */


// Process the payment status notification
function sw_edd_process_mollie_ipn()
{
    global $edd_options;

    try {

        $mollie_test_api = $edd_options['test_api_key'];
        $mollie_live_api = $edd_options['live_api_key'];

    // Load Mollie API with autoloader

        require ('Mollie/API/Autoloader.php');

        $mollie = new Mollie_API_Client;
        $payment = "";
        $id = $_POST["id"];

    } catch (Mollie_API_Exception $e) {
            echo "API call failed: " . htmlspecialchars($e->getMessage());
    }
    try {

        //Check if test mode is set to determine what API Key to use for Mollie API call.
        if (edd_is_test_mode()) {
            $mollie->setApiKey($mollie_test_api);
        } else {
            $mollie->setApiKey($mollie_live_api);
        }

        // Get the id from the Mollie payment status update
            $payment = $mollie->payments->get($id);
            $edd_payment = $payment->metadata->order_id;

            // Use the edd_get_purchase_id_by_key() function to retrieve the order ID
            // with the transaction id stored in the Mollie Payment object metadata
            $order_id = edd_get_purchase_id_by_key($edd_payment);
  
        if ($payment->isPaid()) {
            // If payment status is Paid, complete the order  
            edd_update_payment_status($order_id, $new_status = 'publish');
            header("HTTP/1.0 200 Ok");
        } elseif ($payment->isOpen()) {
            // If payment status is Open, set the order status to failed  
            edd_record_gateway_error( 
                'Payment Not Paid', 
                sprintf( __( 'A payment was not processed: ', 'edd' ), json_encode( $payment ) ) 
            );
            edd_update_payment_status($order_id, $new_status = 'failed');
            $fail = true;
        } elseif ($payment->isCancelled()) {
            // If payment status is cancelled, set the order status to revoked
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
        'id' => 'live_api_key',
        'name' => __('Live API key', 'sw_edd'),
        'desc' => __('Enter your Mollie live API key, found in your Mollie website profile', 'sw_edd'),
        'type' => 'text',
        'size' => 'regular'
        ),
      array(
        'id' => 'test_api_key',
        'name' => __('Test API key', 'sw_edd'),
        'desc' => __('Enter your Mollie test API key, found in your Mollie website profile', 'sw_edd'),
        'type' => 'text',
        'size' => 'regular'
        )
      );
    return array_merge($settings, $mollie_gateway_settings);
}
add_filter('edd_settings_gateways', 'sw_edd_add_settings');
