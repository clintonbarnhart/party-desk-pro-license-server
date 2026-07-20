<?php
if (!defined('ABSPATH')) { exit; }

final class PDP_Subscriptions {
    const OPT_SQUARE = 'pdp_square_subscription_settings';

    public static function init() {
        add_action('admin_post_pdp_save_square_subscription_settings', array(__CLASS__, 'save_square_settings'));
        add_action('admin_post_pdp_test_webhook_log', array(__CLASS__, 'create_test_log'));
        add_action('admin_post_pdp_clear_webhook_logs', array(__CLASS__, 'clear_webhook_logs'));
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function defaults() {
        return array(
            'environment' => 'sandbox',
            'application_id' => '',
            'access_token' => '',
            'location_id' => '',
            'webhook_signature_key' => '',
            'api_version' => '2026-05-20',
            'webhook_enabled' => '1',
        );
    }

    public static function settings() {
        return wp_parse_args((array) get_option(self::OPT_SQUARE, array()), self::defaults());
    }

    public static function webhook_url() {
        return rest_url('party-desk-pro/v3/square/webhook');
    }

    public static function register_routes() {
        register_rest_route('party-desk-pro/v3', '/square/webhook', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'receive_square_webhook'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function save_square_settings() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to change Square settings.', 'party-desk-pro-license-server')); }
        check_admin_referer('pdp_save_square_subscription_settings');
        $old = self::settings();
        $environment = isset($_POST['environment']) && 'production' === sanitize_key(wp_unslash($_POST['environment'])) ? 'production' : 'sandbox';
        $new = array(
            'environment' => $environment,
            'application_id' => sanitize_text_field(wp_unslash($_POST['application_id'] ?? '')),
            'access_token' => sanitize_text_field(wp_unslash($_POST['access_token'] ?? '')),
            'location_id' => sanitize_text_field(wp_unslash($_POST['location_id'] ?? '')),
            'webhook_signature_key' => sanitize_text_field(wp_unslash($_POST['webhook_signature_key'] ?? '')),
            'api_version' => sanitize_text_field(wp_unslash($_POST['api_version'] ?? '2026-05-20')),
            'webhook_enabled' => !empty($_POST['webhook_enabled']) ? '1' : '0',
        );
        // Preserve saved secrets when the masked field is submitted unchanged.
        foreach (array('access_token','webhook_signature_key') as $secret) {
            if ('********' === $new[$secret]) { $new[$secret] = $old[$secret]; }
        }
        update_option(self::OPT_SQUARE, $new, false);
        self::redirect_notice('pdp-square-subscriptions', 'Square subscription settings saved.');
    }

    public static function create_test_log() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to create test logs.', 'party-desk-pro-license-server')); }
        check_admin_referer('pdp_test_webhook_log');
        $event_id = 'test_' . wp_generate_uuid4();
        PDP_DB::log_webhook(array(
            'provider' => 'square',
            'event_id' => $event_id,
            'event_type' => 'party_desk_pro.test',
            'signature_valid' => 1,
            'processing_status' => 'processed',
            'http_status' => 200,
            'payload' => array('event_id'=>$event_id,'type'=>'party_desk_pro.test','created_at'=>gmdate('c')),
            'response_body' => array('ok'=>true,'test'=>true),
            'processed_at' => current_time('mysql'),
        ));
        self::redirect_notice('pdp-webhook-logs', 'A test webhook log was created successfully.');
    }

    public static function clear_webhook_logs() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to clear webhook logs.', 'party-desk-pro-license-server')); }
        check_admin_referer('pdp_clear_webhook_logs');
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE `' . PDP_DB::table('webhook_logs') . '`');
        self::redirect_notice('pdp-webhook-logs', 'Webhook logs were cleared.');
    }

    private static function redirect_notice($page, $message) {
        wp_safe_redirect(add_query_arg(array('page'=>$page,'pdp_notice'=>rawurlencode($message)), admin_url('admin.php')));
        exit;
    }

    public static function receive_square_webhook(WP_REST_Request $request) {
        $settings = self::settings();
        $raw = $request->get_body();
        $payload = json_decode($raw, true);
        $event_id = is_array($payload) ? sanitize_text_field($payload['event_id'] ?? '') : '';
        $event_type = is_array($payload) ? sanitize_text_field($payload['type'] ?? '') : '';
        if (!$event_id) { $event_id = 'missing_' . wp_generate_uuid4(); }
        if (!$event_type) { $event_type = 'unknown'; }

        $signature = (string) $request->get_header('x-square-hmacsha256-signature');
        $signature_key = (string) $settings['webhook_signature_key'];
        $enabled = '1' === $settings['webhook_enabled'];
        $valid = $enabled && $signature_key && $signature && self::verify_signature($raw, $signature, $signature_key, self::webhook_url());

        if (!$enabled) {
            PDP_DB::log_webhook(array('event_id'=>$event_id,'event_type'=>$event_type,'signature_valid'=>0,'processing_status'=>'disabled','http_status'=>503,'payload'=>$raw,'error_message'=>'Webhook receiver is disabled.','processed_at'=>current_time('mysql')));
            return new WP_REST_Response(array('ok'=>false,'message'=>'Webhook receiver disabled.'), 503);
        }

        if (!$valid) {
            PDP_DB::log_webhook(array('event_id'=>$event_id,'event_type'=>$event_type,'signature_valid'=>0,'processing_status'=>'rejected','http_status'=>403,'payload'=>$raw,'error_message'=>'Invalid or missing Square webhook signature.','processed_at'=>current_time('mysql')));
            return new WP_REST_Response(array('ok'=>false,'message'=>'Invalid signature.'), 403);
        }

        $log_id = PDP_DB::log_webhook(array(
            'event_id'=>$event_id,
            'event_type'=>$event_type,
            'signature_valid'=>1,
            'processing_status'=>'processed',
            'http_status'=>200,
            'payload'=>$raw,
            'response_body'=>array('ok'=>true),
            'processed_at'=>current_time('mysql'),
        ));
        PDP_DB::log_event(0, $event_type, 'Square webhook received and validated.', array('event_id'=>$event_id,'webhook_log_id'=>$log_id), 'square');
        return new WP_REST_Response(array('ok'=>true), 200);
    }

    public static function verify_signature($raw_body, $signature, $signature_key, $notification_url) {
        $generated = base64_encode(hash_hmac('sha256', $notification_url . $raw_body, $signature_key, true));
        return hash_equals($generated, trim($signature));
    }
}
PDP_Subscriptions::init();
