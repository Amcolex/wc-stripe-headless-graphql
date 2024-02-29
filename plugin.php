<?php
/**
 * Plugin Name:       wp-stripe-headless-graphql
 * Description:       Facilitates Stripe Payment Intent creation via WooCommerce for headless setups using GraphQL. Works with 3DS Credit Cards.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Alex P
 */

add_action('graphql_register_types', 'register_graphql_create_payment_intent_mutation');

function register_graphql_create_payment_intent_mutation() {
    register_graphql_mutation('createPaymentIntent', [
        'inputFields' => [
            'orderKey' => [
                'type' => 'String',
                'description' => 'The order key to create a payment intent for',
            ],
        ],
        'outputFields' => [
            'status' => [
                'type' => 'Boolean',
                'description' => 'True if the payment intent was created successfully',
            ],
            'data' => [
                'type' => 'String',
                'description' => 'The payment intent data returned by Stripe, as a JSON string',
            ],
        ],
        'mutateAndGetPayload' => function($input) {
            $order_id = !empty($input['orderId']) ? sanitize_text_field($input['orderId']) : null;

            $order = wc_get_order($order_id);
            if (!$order) {
                throw new \GraphQL\Error\UserError('Order not found.');
            }

            $amount = $order->get_total() * 100; // Convert to cents for Stripe API
            $currency = $order->get_currency();

            $stripe_settings = get_option('woocommerce_stripe_settings');
            $test_mode = 'yes' === $stripe_settings['testmode'];
            $stripe_token = $test_mode ? $stripe_settings['test_secret_key'] : $stripe_settings['secret_key'];

            if (empty($stripe_token)) {
                throw new \GraphQL\Error\UserError('Stripe API key not found.');
            }

            $url = 'https://api.stripe.com/v1/payment_intents';
            $body = http_build_query([
                "amount" => $amount,
                'currency' => $currency,
                "automatic_payment_methods" => ["enabled" => "true"],
            ]);

            $response = stripe_curl_request($url, $body, $stripe_token);
            $data = json_decode($response, true);

            if (isset($data['id'])) {
                $order->update_meta_data('_stripe_intent_id', $data['id']);
                $order->save();
            } else {
                throw new \GraphQL\Error\UserError('Failed to create payment intent.');
            }

            return [
                'status' => true,
                'data' => json_encode($data),
            ];
        },
    ]);
}

function stripe_curl_request($url, $body, $token) {
    $args = array(
        'method'      => 'POST',
        'headers'     => array(
            'Content-Type'  => 'application/x-www-form-urlencoded',
            'Authorization' => 'Bearer ' . $token,
        ),
        'body'        => $body,
    );

    $response = wp_remote_post($url, $args);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
        return new WP_Error('request_failed', 'Stripe API request failed', array('status' => 500));
    }

    return wp_remote_retrieve_body($response);
}
