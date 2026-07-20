<?php
if (!defined('ABSPATH')) { exit; }

final class PDP_Square_Sync {
    public static function sync_user($user_id) {
        $user = get_userdata(absint($user_id));
        if (!$user) { return new WP_Error('pdp_user_missing', 'Customer user was not found.'); }
        global $wpdb;
        $table = PDP_DB::table('square_customers');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE user_id=%d ORDER BY id DESC LIMIT 1", $user->ID), ARRAY_A);
        $payload = array(
            'given_name' => sanitize_text_field(get_user_meta($user->ID, 'first_name', true)),
            'family_name' => sanitize_text_field(get_user_meta($user->ID, 'last_name', true)),
            'company_name' => sanitize_text_field(get_user_meta($user->ID, '_pdp_business_name', true)),
            'email_address' => sanitize_email($user->user_email),
            'reference_id' => 'wp-user-' . $user->ID,
        );
        $client = new PDP_Square_Client();
        $result = $existing && !empty($existing['square_customer_id'])
            ? $client->update_customer($existing['square_customer_id'], $payload)
            : $client->create_customer($payload);
        if (is_wp_error($result)) { return $result; }
        $customer = $result['customer'] ?? array();
        $square_id = $customer['id'] ?? ($existing['square_customer_id'] ?? '');
        if (!$square_id) { return new WP_Error('pdp_square_customer_missing', 'Square did not return a customer ID.'); }
        $saved = PDP_DB::save_square_customer(array(
            'user_id' => $user->ID,
            'square_customer_id' => $square_id,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'company_name' => $payload['company_name'],
            'environment' => PDP_Subscriptions::settings()['environment'],
            'metadata' => $customer,
        ));
        if (!is_wp_error($saved)) { PDP_Logger::info('Square customer synchronized.', array('user_id' => $user->ID, 'square_customer_id' => $square_id)); }
        return is_wp_error($saved) ? $saved : $square_id;
    }
}
