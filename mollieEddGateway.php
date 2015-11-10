<?php
/*
Plugin Name: Easy Digital Downloads - Mollie Gateway
Plugin URL: https://github.com/sanderdewijs/mollie-edd-addon
Description: A simple Mollie payment gateway addon for Easy Digital Downloads Wordpress plugin
Version: 1.0.1
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
            edd_record_gateway_error(__('Payment Error', 'edd'), sprintf(__('Payment creation failed before sending buyer to IDEAL. Payment data: %s', 'edd'), json_encode($payment)), $payment);
        // Problems? send back to checkout
            edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
        }  else {
            // y send to Mollie if the pending payment is created successfully
            // Get the return url
            $home_url = get_home_url() . '/';
            $return_url = add_query_arg(array(
                    'payment-notification' => 'mollie-gateway',
                    'payment-id' => $edd_payment

                ), $home_url);

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
                $mollie_id = $payment->id;
                edd_set_payment_transaction_id($edd_payment, $mollie_id);

            } catch (Mollie_API_Exception $e) {
                echo "API call failed: " . htmlspecialchars($e->getMessage());
            }

            // Get payment URL to process payment

            $payment_redirect = $payment->getPaymentUrl();

            edd_empty_cart();

            // Redirect to Mollie
            wp_redirect($payment_redirect);
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
    if (isset( $_POST["id"] )) {
        do_action('edd_mollie_verify_ipn');
    }
}
add_action('init', 'mollie_gateway_listener');


/**
 * @since 1.0.1
 * Send users to the failed transaction page.
 * @param array $args
 */
function edd_send_to_failed_page($args = array() ) {
    $redirect = edd_get_failed_transaction_uri();

    if ( ! empty( $args ) ) {
        // Check for backward compatibility
        if ( is_string( $args ) )
            $args = str_replace( '?', '', $args );

        $args = wp_parse_args( $args );

        $redirect = add_query_arg( $args, $redirect );
    }

    wp_redirect( apply_filters( 'edd_send_to_failed_page', $redirect, $args ) );
    edd_die();
}

/**
 * @since 1.0.1
 * Listen for users being sent beck by Mollie
 * and call the Payment redirect function to
 * lookup their order status. After this, redirect
 * to succes or failed page
 */
function check_for_mollie_payments() {
    if (!isset($_GET["payment-notification"])) {
        return;
    }
    $id = $_GET["payment-id"];
    $params = array(
        'payment-notification'  => $_GET["payment-notification"],
        'payment-id'            => $_GET["payment-id"]
    );

    mollie_payments_redirect($params);
}
add_action('init', 'check_for_mollie_payments');

/**
 * @since 1.0.1
 * Take the order ID from the return URL
 * and find the order in EDD. If the order is
 * complete, redirect the user to the success
 * page. Otherwise send the user to the failed page.
 * @param $id
 */
function mollie_payments_redirect($params)
{
    global $edd_options;

    if(!is_array($params)) {
        return;
    }
    if(count($params) !== 2) {
        return;
    }

    $mollie_payment_id = $params['payment-id'];

    $status = get_post_status($mollie_payment_id);
    if($status === false) {
        return;
    }
    if ($status == 'complete' || $status == 'publish') {
        edd_send_to_success_page();
    } else {
        edd_send_to_failed_page();
    }
}

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
        if ( get_post_status( $edd_payment ) == 'publish' ) {
            return; // Only complete payments once
        }
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
        } elseif ($payment->isCancelled()) {
            // If payment status is cancelled, set the order status to revoked
            edd_record_gateway_error( 
                'Payment Cancelled', 
                sprintf( __( 'A payment was cancelled: ', 'edd' ), json_encode( $payment ) ) 
            );
            edd_update_payment_status($order_id, $new_status = 'revoked');
        } else {
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
