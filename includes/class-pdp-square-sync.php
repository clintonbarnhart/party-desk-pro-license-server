<?php
if (!defined('ABSPATH')) { exit; }

final class PDP_Square_Sync {

    const PLANS_OPTION = 'pdp_square_subscription_plans_cache';

    public static function sync_subscription_plans() {
        $client = new PDP_Square_Client();
        if (!$client->is_configured()) {
            return new WP_Error('pdp_square_not_configured', 'Connect Square before syncing subscription plans.');
        }
        $objects = $client->list_subscription_plans();
        if (is_wp_error($objects)) { return $objects; }

        $plans = array();
        foreach ($objects as $object) {
            if (!is_array($object) || 'SUBSCRIPTION_PLAN' !== strtoupper($object['type'] ?? '')) { continue; }
            $plan_id = sanitize_text_field($object['id'] ?? '');
            $data = $object['subscription_plan_data'] ?? array();
            $plan_name = sanitize_text_field($data['name'] ?? 'Square Subscription Plan');
            $variations = $data['subscription_plan_variations'] ?? array();
            foreach ($variations as $variation) {
                if (!is_array($variation)) { continue; }
                $variation_id = sanitize_text_field($variation['id'] ?? '');
                $variation_data = $variation['subscription_plan_variation_data'] ?? array();
                if (!$variation_id || !empty($variation['is_deleted'])) { continue; }
                $variation_name = sanitize_text_field($variation_data['name'] ?? 'Default');
                $phases = is_array($variation_data['phases'] ?? null) ? $variation_data['phases'] : array();
                $cadences = array();
                $amount = null;
                $currency = '';
                foreach ($phases as $phase) {
                    if (!empty($phase['cadence'])) { $cadences[] = sanitize_text_field($phase['cadence']); }
                    $money = $phase['pricing']['price_money'] ?? ($phase['pricing']['static_price_money'] ?? array());
                    if (is_array($money) && isset($money['amount'])) {
                        $amount = (int) $money['amount'];
                        $currency = sanitize_text_field($money['currency'] ?? 'USD');
                    }
                }
                $cadence = implode(' + ', array_unique($cadences));
                $price = null === $amount ? '' : number_format_i18n($amount / 100, 2) . ' ' . ($currency ?: 'USD');
                $label_parts = array($plan_name);
                if ($variation_name && 'Default' !== $variation_name) { $label_parts[] = $variation_name; }
                $details = trim(implode(' · ', array_filter(array($price, self::human_cadence($cadence)))));
                $plans[$variation_id] = array(
                    'variation_id' => $variation_id,
                    'plan_id' => $plan_id,
                    'plan_name' => $plan_name,
                    'variation_name' => $variation_name,
                    'cadence' => $cadence,
                    'amount' => $amount,
                    'currency' => $currency,
                    'label' => implode(' — ', $label_parts) . ($details ? ' (' . $details . ')' : ''),
                );
            }
        }
        uasort($plans, function($a, $b) { return strcasecmp($a['label'], $b['label']); });
        $settings = PDP_Subscriptions::settings();
        update_option(self::PLANS_OPTION, array(
            'environment' => sanitize_key($settings['environment'] ?? 'sandbox'),
            'synced_at' => current_time('mysql', true),
            'plans' => $plans,
        ), false);
        PDP_Logger::info('Square subscription plans synchronized.', array('count' => count($plans)));
        return $plans;
    }

    public static function get_cached_subscription_plans() {
        $cache = get_option(self::PLANS_OPTION, array());
        $settings = PDP_Subscriptions::settings();
        if (($cache['environment'] ?? '') !== ($settings['environment'] ?? 'sandbox')) { return array(); }
        return is_array($cache['plans'] ?? null) ? $cache['plans'] : array();
    }

    public static function get_last_plan_sync() {
        $cache = get_option(self::PLANS_OPTION, array());
        return sanitize_text_field($cache['synced_at'] ?? '');
    }

    public static function human_cadence($cadence) {
        $map = array(
            'DAILY' => 'Daily', 'WEEKLY' => 'Weekly', 'EVERY_TWO_WEEKS' => 'Every 2 weeks',
            'THIRTY_DAYS' => 'Every 30 days', 'SIXTY_DAYS' => 'Every 60 days',
            'NINETY_DAYS' => 'Every 90 days', 'MONTHLY' => 'Monthly',
            'EVERY_TWO_MONTHS' => 'Every 2 months', 'QUARTERLY' => 'Quarterly',
            'EVERY_FOUR_MONTHS' => 'Every 4 months', 'EVERY_SIX_MONTHS' => 'Every 6 months',
            'ANNUAL' => 'Annual', 'EVERY_TWO_YEARS' => 'Every 2 years',
        );
        if (!$cadence) { return ''; }
        $parts = array_map('trim', explode('+', $cadence));
        $parts = array_map(function($part) use ($map) { return $map[$part] ?? ucwords(strtolower(str_replace('_', ' ', $part))); }, $parts);
        return implode(' + ', $parts);
    }

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
