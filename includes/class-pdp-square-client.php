<?php
if (!defined('ABSPATH')) { exit; }

final class PDP_Square_Client {
    private $settings;
    private $base_url;

    public function __construct($settings = null) {
        $this->settings = is_array($settings) ? $settings : PDP_Subscriptions::settings();
        $this->base_url = ('production' === ($this->settings['environment'] ?? 'sandbox'))
            ? 'https://connect.squareup.com/v2'
            : 'https://connect.squareupsandbox.com/v2';
    }

    public function is_configured() {
        return !empty($this->settings['access_token']) && !empty($this->settings['location_id']);
    }

    public function request($method, $path, $body = null, $query = array()) {
        if (!$this->is_configured()) {
            return new WP_Error('pdp_square_not_configured', 'Square access token and location ID are required.');
        }
        $url = $this->base_url . '/' . ltrim($path, '/');
        if ($query) { $url = add_query_arg($query, $url); }
        $args = array(
            'method' => strtoupper($method),
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . trim($this->settings['access_token']),
                'Square-Version' => sanitize_text_field($this->settings['api_version']),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
        );
        if (null !== $body) { $args['body'] = wp_json_encode($body); }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            PDP_Logger::error('Square API transport error.', array('path' => $path, 'message' => $response->get_error_message()));
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300) {
            $message = 'Square API request failed.';
            if (!empty($decoded['errors'][0]['detail'])) { $message = $decoded['errors'][0]['detail']; }
            elseif (!empty($decoded['errors'][0]['code'])) { $message .= ' ' . $decoded['errors'][0]['code']; }
            PDP_Logger::error('Square API error.', array('path' => $path, 'status' => $code, 'response' => $decoded));
            return new WP_Error('pdp_square_api_error', $message, array('status' => $code, 'response' => $decoded));
        }
        return is_array($decoded) ? $decoded : array();
    }

    public function test_connection() { return $this->request('GET', 'locations'); }
    public function create_customer($payload) { $payload['idempotency_key'] = $payload['idempotency_key'] ?? wp_generate_uuid4(); return $this->request('POST', 'customers', $payload); }
    public function update_customer($id, $payload) { return $this->request('PUT', 'customers/' . rawurlencode($id), $payload); }
    public function retrieve_customer($id) { return $this->request('GET', 'customers/' . rawurlencode($id)); }
    public function create_payment_link($payload) { $payload['idempotency_key'] = $payload['idempotency_key'] ?? wp_generate_uuid4(); return $this->request('POST', 'online-checkout/payment-links', $payload); }
    public function create_subscription($payload) { $payload['idempotency_key'] = $payload['idempotency_key'] ?? wp_generate_uuid4(); return $this->request('POST', 'subscriptions', $payload); }
    public function retrieve_subscription($id) { return $this->request('GET', 'subscriptions/' . rawurlencode($id)); }
    public function cancel_subscription($id) { return $this->request('POST', 'subscriptions/' . rawurlencode($id) . '/cancel', array()); }
    public function pause_subscription($id) { return $this->request('POST', 'subscriptions/' . rawurlencode($id) . '/pause', array('pause_effective_date' => gmdate('Y-m-d'))); }
    public function resume_subscription($id) { return $this->request('POST', 'subscriptions/' . rawurlencode($id) . '/resume', array('resume_effective_date' => gmdate('Y-m-d'))); }
}
