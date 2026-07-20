<?php
if (!defined('ABSPATH')) { exit; }

final class PDP_Subscription_Manager {
    public static function create($args) {
        $args = wp_parse_args($args, array('user_id'=>0,'license_id'=>0,'plan_id'=>0,'square_plan_variation_id'=>'','start_date'=>'','timezone'=>'UTC'));
        $user_id = absint($args['user_id']);
        $plan_id = absint($args['plan_id']);
        if (!$user_id || !$plan_id) { return new WP_Error('pdp_subscription_missing_data', 'Customer and plan are required.'); }
        $variation_id = sanitize_text_field($args['square_plan_variation_id'] ?: get_post_meta($plan_id, '_pdp_square_plan_variation_id', true));
        if (!$variation_id) { return new WP_Error('pdp_square_plan_missing', 'The selected plan does not have a Square plan variation ID.'); }
        $square_customer_id = PDP_Square_Sync::sync_user($user_id);
        if (is_wp_error($square_customer_id)) { return $square_customer_id; }
        $settings = PDP_Subscriptions::settings();
        $payload = array(
            'location_id' => $settings['location_id'],
            'customer_id' => $square_customer_id,
            'plan_variation_id' => $variation_id,
            'start_date' => $args['start_date'] ?: gmdate('Y-m-d'),
            'timezone' => sanitize_text_field($args['timezone']),
            'idempotency_key' => wp_generate_uuid4(),
        );
        $remote = (new PDP_Square_Client())->create_subscription($payload);
        if (is_wp_error($remote)) { return $remote; }
        $square = $remote['subscription'] ?? array();
        $local_id = PDP_DB::create_subscription(array(
            'customer_user_id' => $user_id,
            'license_id' => absint($args['license_id']),
            'plan_id' => $plan_id,
            'status' => self::normalize_status($square['status'] ?? 'pending'),
            'billing_cycle' => sanitize_text_field(get_post_meta($plan_id, '_pdp_billing', true)),
            'amount' => (float) get_post_meta($plan_id, '_pdp_price', true),
            'currency' => 'USD',
            'square_customer_id' => $square_customer_id,
            'square_subscription_id' => sanitize_text_field($square['id'] ?? ''),
            'next_billing_at' => !empty($square['charged_through_date']) ? $square['charged_through_date'] . ' 00:00:00' : null,
            'metadata' => $square,
        ));
        if (is_wp_error($local_id)) { return $local_id; }
        PDP_DB::log_event($local_id, 'subscription_created', 'Square subscription created.', array('square_subscription_id'=>$square['id'] ?? ''), 'admin');
        return $local_id;
    }

    public static function action($local_id, $action) {
        $row = PDP_DB::get_subscription($local_id);
        if (!$row || empty($row['square_subscription_id'])) { return new WP_Error('pdp_subscription_not_linked', 'This subscription is not linked to Square.'); }
        $client = new PDP_Square_Client();
        if ('cancel' === $action) { $result = $client->cancel_subscription($row['square_subscription_id']); }
        elseif ('pause' === $action) { $result = $client->pause_subscription($row['square_subscription_id']); }
        elseif ('resume' === $action) { $result = $client->resume_subscription($row['square_subscription_id']); }
        elseif ('refresh' === $action) { $result = $client->retrieve_subscription($row['square_subscription_id']); }
        else { return new WP_Error('pdp_invalid_subscription_action', 'Unknown subscription action.'); }
        if (is_wp_error($result)) { return $result; }
        $square = $result['subscription'] ?? array();
        $status = self::normalize_status($square['status'] ?? ('cancel' === $action ? 'canceled' : ('pause' === $action ? 'paused' : 'active')));
        PDP_DB::update_subscription($local_id, array(
            'status' => $status,
            'canceled_at' => 'canceled' === $status ? current_time('mysql') : null,
            'metadata' => $square,
        ));
        PDP_DB::log_event($local_id, 'subscription_' . $action, 'Subscription action completed.', array('status'=>$status), 'admin');
        return true;
    }

    public static function normalize_status($status) {
        $status = strtolower((string)$status);
        $map = array('active'=>'active','pending'=>'pending','canceled'=>'canceled','cancelled'=>'canceled','paused'=>'paused','deactivated'=>'canceled','completed'=>'canceled');
        return $map[$status] ?? 'pending';
    }
}
