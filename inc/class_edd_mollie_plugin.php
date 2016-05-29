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

    /**
     * Mollie API Connect
     * @return Mollie_API_Client|object
     */
    public function mollie_api_connect() {
        global $edd_options;
        $mollie = '';
        try {
            $mollie_test_api = $edd_options['test_api_key'];
            $mollie_live_api = $edd_options['live_api_key'];

            // Load Mollie API with autoloader
            if (!class_exists('Mollie_API_Autoloader')) {
                require (EDD_MOLLIE_PLUGIN_PATH . 'Mollie/API/Autoloader.php');
            }

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

    public function edd_send_to_failed_page($args = array() ) {
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
     * Send To Success Page
     *
     * Sends the user to the succes page.
     *
     * @param string $query_string
     * @access      public
     * @since       1.0
     * @return      void
     */
    function edd_mollie_send_to_success_page( $args = array() ) {
        $redirect = edd_get_success_page_uri();

        if ( ! empty( $args ) ) {
            // Check for backward compatibility
            if ( is_string( $args ) )
                $args = str_replace( '?', '', $args );
            $args = wp_parse_args( $args );
            $redirect = add_query_arg( $args, $redirect );
        }

        wp_redirect( apply_filters( 'edd_mollie_send_to_success_page', $redirect, $args ) );
        edd_die();
    }

    /**
     * @since 1.0.1
     * Take the order ID from the return URL
     * and find the order in EDD. If the order is
     * complete, redirect the user to the success
     * page. Otherwise send the user to the failed page.
     * @param $params
     */
    public function mollie_payments_redirect($params)
    {
        if(!is_array($params)) {
            return;
        }
        //$status = get_post_status($mollie_payment_id);
        if($params['payment-status'] == 'paid') {
            edd_update_payment_status($params['payment-id'], $new_status = 'publish');
            $this->edd_mollie_send_to_success_page(array_slice($params, 0, 2));
        } elseif($params['payment-status'] == 'open') {
            edd_record_gateway_error(
                'Payment Not Paid',
                sprintf( __( 'A payment was not processed: ', 'edd' ), json_encode( $payment ) )
            );
            edd_update_payment_status($params['payment-id'], $new_status = 'failed');
            $this->edd_send_to_failed_page(array_slice($params, 0, 2));
        } elseif($params['payment-status'] == 'cancelled') {
            // If payment status is cancelled, set the order status to revoked
            edd_record_gateway_error(
                'Payment Cancelled',
                sprintf( __( 'A payment was cancelled: ', 'edd' ), json_encode( $payment ) )
            );
            edd_update_payment_status($order_id, $new_status = 'revoked');
            $this->edd_send_to_failed_page(array_slice($params, 0, 2));
        } else {
            $this->edd_send_to_failed_page(array_slice($params, 0, 2));
        }
    }
}