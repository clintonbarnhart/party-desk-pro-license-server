<?php
if (!defined('ABSPATH')) { exit; }

final class PDP_Webhook_Processor {
    public static function process($payload) {
        $type = sanitize_text_field($payload['type'] ?? 'unknown');
        $object = $payload['data']['object'] ?? array();
        $subscription = $object['subscription'] ?? array();
        if (!$subscription || empty($subscription['id'])) { return array('handled'=>false,'message'=>'No subscription object found.'); }
        global $wpdb;
        $table = PDP_DB::table('subscriptions');
        $local_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE square_subscription_id=%s", sanitize_text_field($subscription['id'])));
        if (!$local_id) { return array('handled'=>false,'message'=>'Subscription is not linked locally.'); }
        PDP_DB::update_subscription($local_id, array(
            'status' => PDP_Subscription_Manager::normalize_status($subscription['status'] ?? 'pending'),
            'next_billing_at' => !empty($subscription['charged_through_date']) ? sanitize_text_field($subscription['charged_through_date']) . ' 00:00:00' : null,
            'metadata' => $subscription,
        ));
        PDP_DB::log_event($local_id, sanitize_key($type), 'Square webhook synchronized the subscription.', array('square'=>$subscription), 'square');
        return array('handled'=>true,'subscription_id'=>$local_id);
    }
}
