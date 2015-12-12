<?php
/**
 *  Class for constructing the Mollie EDD Payment Gateway
 *  Loads additional settings
 *  Loads active payment gateways from Mollie and adds them to EDD
 */

class Mollie_EDD {

    private mollie_api_client;
    private mollie_api_key;
    private mollie_live_api;
    private mollie_test_api;
    private mollie_active_gateways;

    function __construct()
    {
        add_action('init', 'mollie_gateway_listener');
    }

    public function init() {

    }
}