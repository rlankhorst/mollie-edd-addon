<?php
/**
 *  Class for constructing the Mollie EDD Payment Gateway
 *  Loads additional settings
 *  Loads active payment gateways from Mollie and adds them to EDD
 */

class Mollie_EDD {

    public $mollie_api_client;
    private $mollie_api_key;
    private $mollie_live_api;
    private $mollie_test_api;
    private $mollie_active_gateways;

    function __construct()
    {
        $this->helpers = require('mollie-edd-functions.php');
        add_action('init', array(&$this, 'init'));
    }

    public function init()
    {
        $this->M = $this->helpers->mollie_api_connect();
        $this->check_for_mollie_payments();
        $this->mollie_gateway_listener();
    }



    public function check_for_mollie_payments()
    {
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

    public function mollie_gateway_listener()
    {
        // Mollie IPN
        if (isset( $_POST["id"] )) {
            do_action('edd_mollie_verify_ipn');
        }
    }

    public function sw_edd_process_mollie_ipn()
    {
        $mollie = this->mollie_api_connect();
        try {
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
                // header("HTTP/1.0 200 Ok");
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
}