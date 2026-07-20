<?php
/**
 * Automated signup, Square hosted subscription checkout, and provisioning.
 */
if (!defined('ABSPATH')) { exit; }

final class PDP_Signup_Automation {
    const OPT = 'pdp_signup_automation_settings';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 24);
        add_action('admin_post_pdp_save_signup_automation', array(__CLASS__, 'save_settings'));
    }

    public static function defaults() {
        return array(
            'enabled' => '1',
            'auto_create_account' => '1',
            'auto_create_license' => '1',
            'auto_email_license' => '1',
            'success_url' => home_url('/my-account/?signup=success'),
            'cancel_url' => home_url('/pricing/?signup=cancelled'),
        );
    }

    public static function settings() {
        return wp_parse_args((array) get_option(self::OPT, array()), self::defaults());
    }

    public static function admin_menu() {
        add_submenu_page('pdp-dashboard', 'Signup Automation', 'Signup Automation', 'manage_options', 'pdp-signup-automation', array(__CLASS__, 'settings_page'));
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) { wp_die('Permission denied.'); }
        check_admin_referer('pdp_save_signup_automation');
        $settings = array(
            'enabled' => isset($_POST['enabled']) ? '1' : '0',
            'auto_create_account' => isset($_POST['auto_create_account']) ? '1' : '0',
            'auto_create_license' => isset($_POST['auto_create_license']) ? '1' : '0',
            'auto_email_license' => isset($_POST['auto_email_license']) ? '1' : '0',
            'success_url' => esc_url_raw(wp_unslash($_POST['success_url'] ?? '')),
            'cancel_url' => esc_url_raw(wp_unslash($_POST['cancel_url'] ?? '')),
        );
        update_option(self::OPT, $settings, false);
        wp_safe_redirect(add_query_arg(array('page'=>'pdp-signup-automation','pdp_notice'=>'Automation settings saved.'), admin_url('admin.php')));
        exit;
    }

    public static function settings_page() {
        if (!current_user_can('manage_options')) { return; }
        $s = self::settings();
        echo '<div class="wrap pdp-admin pdp-modern-admin"><div class="pdp-admin-hero"><div><span class="pdp-admin-kicker">AUTOMATED SALES</span><h1>Signup & Subscription Automation</h1><p>Turn the signup builder into a complete self-service checkout that creates the customer, starts Square billing, issues the license, and sends access automatically.</p></div></div>';
        if (!empty($_GET['pdp_notice'])) { echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(wp_unslash($_GET['pdp_notice'])).'</p></div>'; }
        echo '<div class="pdp-dashboard-grid"><main><section class="pdp-dashboard-panel"><div class="pdp-dashboard-panel-head"><div><h2>Automation workflow</h2><p>Paid plans use Square-hosted subscription checkout. Free and trial plans can be provisioned immediately.</p></div></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="pdp-automation-form">';
        wp_nonce_field('pdp_save_signup_automation');
        echo '<input type="hidden" name="action" value="pdp_save_signup_automation">';
        echo '<div class="pdp-automation-switches">';
        self::switch_row('enabled','Enable automatic signup checkout','Create a Square subscription checkout directly after the customer submits the signup form.',$s['enabled']);
        self::switch_row('auto_create_account','Create WordPress customer account','Create or connect a pdp_customer account using the signup email address.',$s['auto_create_account']);
        self::switch_row('auto_create_license','Issue license after successful subscription','Create and activate the plan license when Square confirms the subscription.',$s['auto_create_license']);
        self::switch_row('auto_email_license','Email license and account access','Send the existing branded license email after provisioning.',$s['auto_email_license']);
        echo '</div><table class="form-table"><tr><th><label for="success_url">Successful checkout URL</label></th><td><input class="regular-text code" type="url" id="success_url" name="success_url" value="'.esc_attr($s['success_url']).'"><p class="description">Square returns the customer here after checkout.</p></td></tr><tr><th><label for="cancel_url">Cancelled checkout URL</label></th><td><input class="regular-text code" type="url" id="cancel_url" name="cancel_url" value="'.esc_attr($s['cancel_url']).'"></td></tr></table>';
        submit_button('Save Automation Settings');
        echo '</form></section></main><aside><section class="pdp-dashboard-panel"><div class="pdp-dashboard-panel-head"><div><h2>Automatic flow</h2><p>What happens after a customer selects a paid plan.</p></div></div><ol class="pdp-flow-list"><li><b>1</b><span><strong>Signup submitted</strong><small>Customer details and selected plan are saved.</small></span></li><li><b>2</b><span><strong>Account prepared</strong><small>A secure WordPress customer account is created or connected.</small></span></li><li><b>3</b><span><strong>Square checkout</strong><small>The customer pays on Square&apos;s hosted recurring checkout.</small></span></li><li><b>4</b><span><strong>Webhook confirmation</strong><small>Square confirms the new subscription to the license server.</small></span></li><li><b>5</b><span><strong>License delivered</strong><small>The license, portal access, and download are issued automatically.</small></span></li></ol></section></aside></div></div>';
    }

    private static function switch_row($name,$title,$description,$value) {
        echo '<label class="pdp-automation-switch"><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked($value,'1',false).'><span class="pdp-toggle-track"></span><span><strong>'.esc_html($title).'</strong><small>'.esc_html($description).'</small></span></label>';
    }

    public static function prepare_checkout($request_id, $plan_id, array $data) {
        $s = self::settings();
        if ('1' !== $s['enabled']) { return new WP_Error('pdp_automation_disabled', 'Automatic signup checkout is disabled.'); }
        $price = (float) get_post_meta($plan_id, '_pdp_price', true);
        $setup = (float) get_post_meta($plan_id, '_pdp_setup_fee', true);
        $billing = sanitize_key(get_post_meta($plan_id, '_pdp_billing', true));
        $trial_days = absint(get_post_meta($plan_id, '_pdp_trial_days', true));
        $user_id = self::ensure_customer_user($data);
        if (is_wp_error($user_id)) { return $user_id; }
        update_post_meta($request_id, '_pdp_user_id', $user_id);

        if ($price <= 0 || 'trial' === $billing) {
            $license_id = self::provision_license($request_id, $user_id, 0, array('status'=>'trial','trial_days'=>$trial_days));
            return is_wp_error($license_id) ? $license_id : array('type'=>'provisioned','license_id'=>$license_id,'url'=>$s['success_url']);
        }

        $variation_id = sanitize_text_field(get_post_meta($plan_id, '_pdp_square_plan_variation_id', true));
        if (!$variation_id) { return new WP_Error('pdp_plan_not_connected', 'This plan needs a Square plan variation ID before automatic checkout can be used.'); }
        $client = new PDP_Square_Client();
        $settings = PDP_Subscriptions::settings();
        if (!$client->is_configured()) { return new WP_Error('pdp_square_not_configured', 'Square credentials must be configured before automatic checkout can be used.'); }
        $amount = (int) round(($price + $setup) * 100);
        $payload = array(
            'idempotency_key' => wp_generate_uuid4(),
            'quick_pay' => array(
                'name' => get_the_title($plan_id) . ' — ' . sanitize_text_field($data['business_name'] ?? ''),
                'price_money' => array('amount'=>$amount,'currency'=>strtoupper($settings['currency'] ?? 'USD')),
                'location_id' => $settings['location_id'],
            ),
            'checkout_options' => array(
                'subscription_plan_id' => $variation_id,
                'redirect_url' => add_query_arg(array('pdp_checkout'=>'complete','request'=>$request_id), $s['success_url']),
            ),
            'pre_populated_data' => array(
                'buyer_email' => sanitize_email($data['email'] ?? ''),
                'buyer_phone_number' => sanitize_text_field($data['phone'] ?? ''),
            ),
            'description' => 'Party Desk Pro automated signup request #' . absint($request_id),
        );
        $result = $client->create_payment_link($payload);
        if (is_wp_error($result)) { return $result; }
        $link = $result['payment_link'] ?? array();
        if (empty($link['url'])) { return new WP_Error('pdp_checkout_url_missing', 'Square did not return a checkout URL.'); }
        update_post_meta($request_id, '_pdp_square_url', esc_url_raw($link['url']));
        update_post_meta($request_id, '_pdp_square_payment_link_id', sanitize_text_field($link['id'] ?? ''));
        update_post_meta($request_id, '_pdp_square_order_id', sanitize_text_field($link['order_id'] ?? ''));
        update_post_meta($request_id, '_pdp_square_plan_variation_id', $variation_id);
        update_post_meta($request_id, '_pdp_status', 'Checkout Created');
        PDP_Logger::info('Automated signup checkout created.', array('request_id'=>$request_id,'user_id'=>$user_id,'plan_id'=>$plan_id));
        return array('type'=>'checkout','url'=>esc_url_raw($link['url']),'user_id'=>$user_id);
    }

    private static function ensure_customer_user(array $data) {
        $email = sanitize_email($data['email'] ?? '');
        if (!$email) { return new WP_Error('pdp_customer_email_missing', 'A valid email address is required to create the customer account.'); }
        $existing = get_user_by('email', $email);
        if ($existing) { $user_id = $existing->ID; }
        else {
            $base = sanitize_user(strstr($email, '@', true), true) ?: 'pdp-customer';
            $login = $base; $n = 1;
            while (username_exists($login)) { $login = $base . $n++; }
            $user_id = wp_insert_user(array(
                'user_login'=>$login,
                'user_email'=>$email,
                'display_name'=>sanitize_text_field($data['contact_name'] ?? $data['business_name'] ?? $email),
                'user_pass'=>wp_generate_password(24, true, true),
                'role'=>'pdp_customer',
            ));
            if (is_wp_error($user_id)) { return $user_id; }
            wp_new_user_notification($user_id, null, 'user');
        }
        $name = trim(sanitize_text_field($data['contact_name'] ?? ''));
        $parts = preg_split('/\s+/', $name, 2);
        if (!empty($parts[0])) update_user_meta($user_id, 'first_name', $parts[0]);
        if (!empty($parts[1])) update_user_meta($user_id, 'last_name', $parts[1]);
        update_user_meta($user_id, '_pdp_business_name', sanitize_text_field($data['business_name'] ?? ''));
        update_user_meta($user_id, '_pdp_phone', sanitize_text_field($data['phone'] ?? ''));
        update_user_meta($user_id, '_pdp_website', esc_url_raw($data['website'] ?? ''));
        return $user_id;
    }

    public static function handle_unlinked_subscription(array $subscription, $event_type = 'subscription.created') {
        if (empty($subscription['id']) || empty($subscription['customer_id'])) { return false; }
        global $wpdb;
        $table = PDP_DB::table('subscriptions');
        $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE square_subscription_id=%s", sanitize_text_field($subscription['id'])));
        if ($exists) { return $exists; }
        $customer_result = (new PDP_Square_Client())->retrieve_customer($subscription['customer_id']);
        if (is_wp_error($customer_result)) { return false; }
        $email = sanitize_email($customer_result['customer']['email_address'] ?? '');
        $user = $email ? get_user_by('email', $email) : false;
        if (!$user) { return false; }
        $requests = get_posts(array('post_type'=>'pdp_request','post_status'=>'publish','numberposts'=>1,'orderby'=>'date','order'=>'DESC','meta_query'=>array(array('key'=>'_pdp_user_id','value'=>$user->ID))));
        if (!$requests) { return false; }
        $request_id = $requests[0]->ID;
        $plan_id = absint(get_post_meta($request_id, '_pdp_plan_id', true));
        if (!$plan_id) { return false; }
        $local_id = PDP_DB::create_subscription(array(
            'customer_user_id'=>$user->ID,
            'license_id'=>0,
            'plan_id'=>$plan_id,
            'status'=>PDP_Subscription_Manager::normalize_status($subscription['status'] ?? 'active'),
            'billing_cycle'=>sanitize_text_field(get_post_meta($plan_id, '_pdp_billing', true)),
            'amount'=>(float)get_post_meta($plan_id, '_pdp_price', true),
            'currency'=>'USD',
            'square_customer_id'=>sanitize_text_field($subscription['customer_id']),
            'square_subscription_id'=>sanitize_text_field($subscription['id']),
            'next_billing_at'=>!empty($subscription['charged_through_date']) ? sanitize_text_field($subscription['charged_through_date']).' 00:00:00' : null,
            'metadata'=>$subscription,
        ));
        if (is_wp_error($local_id)) { return false; }
        $license_id = self::provision_license($request_id, $user->ID, $local_id, $subscription);
        if (!is_wp_error($license_id)) {
            PDP_DB::update_subscription($local_id, array('license_id'=>$license_id));
            PDP_DB::log_event($local_id, sanitize_key($event_type), 'Automated checkout provisioned the subscription and license.', array('request_id'=>$request_id,'license_id'=>$license_id), 'square');
        }
        return $local_id;
    }

    private static function provision_license($request_id, $user_id, $subscription_id = 0, array $remote = array()) {
        $existing = get_posts(array('post_type'=>'pdp_license','post_status'=>'publish','numberposts'=>1,'meta_query'=>array(array('key'=>'_pdp_request_id','value'=>$request_id))));
        if ($existing) { return $existing[0]->ID; }
        $plan_id = absint(get_post_meta($request_id, '_pdp_plan_id', true));
        $plan = get_post($plan_id);
        if (!$plan) { return new WP_Error('pdp_plan_missing', 'The selected plan could not be found.'); }
        $key = self::generate_key($plan_id);
        $billing = get_post_meta($plan_id, '_pdp_billing', true);
        $trial_days = absint(get_post_meta($plan_id, '_pdp_trial_days', true));
        $expires = '';
        if ('trial' === $billing && $trial_days) $expires = gmdate('Y-m-d', strtotime('+' . $trial_days . ' days'));
        elseif ('month' === $billing) $expires = gmdate('Y-m-d', strtotime('+1 month'));
        elseif ('6-months' === $billing) $expires = gmdate('Y-m-d', strtotime('+6 months'));
        elseif ('year' === $billing) $expires = gmdate('Y-m-d', strtotime('+1 year'));
        $business = sanitize_text_field(get_post_meta($request_id, '_pdp_business_name', true));
        $email = sanitize_email(get_post_meta($request_id, '_pdp_email', true));
        $license_id = wp_insert_post(array('post_type'=>'pdp_license','post_status'=>'publish','post_title'=>$business . ' — ' . $plan->post_title));
        if (is_wp_error($license_id) || !$license_id) return new WP_Error('pdp_license_create_failed','The license could not be created.');
        $meta = array(
            '_pdp_request_id'=>$request_id,
            '_pdp_user_id'=>$user_id,
            '_pdp_key'=>$key,
            '_pdp_business'=>$business,
            '_pdp_email'=>$email,
            '_pdp_plan_id'=>$plan_id,
            '_pdp_plan'=>$plan->post_title,
            '_pdp_price'=>get_post_meta($plan_id, '_pdp_price', true),
            '_pdp_status'=>'Active',
            '_pdp_sites'=>get_post_meta($plan_id, '_pdp_sites', true) ?: 1,
            '_pdp_expires'=>$expires,
            '_pdp_subscription_id'=>$subscription_id,
            '_pdp_square_subscription_id'=>sanitize_text_field($remote['id'] ?? ''),
        );
        foreach ($meta as $k=>$v) update_post_meta($license_id,$k,$v);
        update_post_meta($request_id, '_pdp_license_id', $license_id);
        update_post_meta($request_id, '_pdp_status', 'License Created');
        update_user_meta($user_id, '_pdp_license_id', $license_id);
        PDP_Logger::info('License provisioned automatically.', array('request_id'=>$request_id,'license_id'=>$license_id,'user_id'=>$user_id));
        do_action('pdp_automated_license_created', $license_id, $request_id, $user_id);
        return $license_id;
    }

    private static function generate_key($plan_id) {
        $settings = get_option('pdp_ls_settings', array());
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/i','', $settings['license_prefix'] ?? 'PDP')) ?: 'PDP';
        $segments = min(8,max(2,absint($settings['license_segments'] ?? 4)));
        $length = min(8,max(3,absint($settings['license_segment_length'] ?? 4)));
        for ($attempt=0;$attempt<20;$attempt++) {
            $parts = array($prefix);
            for ($i=0;$i<$segments;$i++) $parts[] = strtoupper(wp_generate_password($length,false,false));
            $key = implode('-',$parts);
            $found = get_posts(array('post_type'=>'pdp_license','post_status'=>'any','numberposts'=>1,'fields'=>'ids','meta_key'=>'_pdp_key','meta_value'=>$key));
            if (!$found) return $key;
        }
        return $prefix . '-' . strtoupper(wp_generate_password(24,false,false));
    }
}
