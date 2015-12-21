<?php
/**
 * Helper functions for Mollie EDD plugin
 */

/**
 * Mollie API Connect
 * @return Mollie_API_Client|object
 */
function mollie_api_connect() {
    global $edd_options;
    $mollie = '';
    try {
        $mollie_test_api = $edd_options['test_api_key'];
        $mollie_live_api = $edd_options['live_api_key'];

        // Load Mollie API with autoloader

        require ('../Mollie/API/Autoloader.php');

        $mollie = new Mollie_API_Client;

        // Check if test mode is set to determine what API Key to use for payment object.
        if (edd_is_test_mode()) {
            $mollie->setApiKey($mollie_test_api);
        } else {
            $mollie->setApiKey($mollie_live_api);
        }
    } catch (Mollie_API_Exception $e) {
        echo "API call failed: " . htmlspecialchars($e->getMessage());
    }
    return $mollie;
}

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
 * Take the order ID from the return URL
 * and find the order in EDD. If the order is
 * complete, redirect the user to the success
 * page. Otherwise send the user to the failed page.
 * @param $id
 */
function mollie_payments_redirect($params)
{
    global $edd_options;
    $mollie_trans_id = '';
    $mollie = '';
    $payment = '';
    $paid = false;

    if(!is_array($params)) {
        return;
    }
    if(count($params) !== 2) {
        return;
    }

    $mollie_payment_id = $params['payment-id'];

    $status = get_post_status($mollie_payment_id);
    $mollie_trans_id = edd_get_payment_transaction_id($mollie_payment_id);
    $mollie = mollie_api_connect();
    try {
        $payment = $mollie->payments->get($mollie_trans_id);
        if($payment->isPaid()) {
            edd_send_to_success_page();
        } elseif($payment->isOpen()) {
            edd_send_to_failed_page();
        } elseif($payment->isCancelled()) {
            edd_send_to_failed_page();
        } else {
            edd_send_to_failed_page();
        }
    } catch (Mollie_API_Exception $e) {
        echo "API call failed: " . htmlspecialchars($e->getMessage());
    }

}

