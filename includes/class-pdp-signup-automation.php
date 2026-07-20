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
            'email_login_details' => '1',
            'force_password_reset' => '0',
            'suspend_failed_payment' => '1',
            'expire_on_cancel' => '1',
            'grace_period_days' => '7',
            'success_url' => home_url('/my-account/?signup=success'),
            'cancel_url' => home_url('/pricing/?signup=cancelled'),
            'billing_url' => home_url('/my-account/'),
            'support_url' => home_url('/support/'),
            'documentation_url' => home_url('/documentation/'),
            'signup_heading' => 'Start Your Party Desk Pro Subscription',
            'signup_subheading' => 'Choose your plan, create your account, and continue to secure Square checkout.',
            'checkout_button' => 'Continue to Secure Checkout',
            'show_plan_comparison' => '1',
            'show_faq' => '1',
            'show_testimonials' => '0',
        );
    }

    public static function settings() {
        return wp_parse_args((array) get_option(self::OPT, array()), self::defaults());
    }

    public static function admin_menu() {
        add_submenu_page('pdp-dashboard', 'Signup Automation Studio', 'Signup Automation', 'manage_options', 'pdp-signup-automation', array(__CLASS__, 'settings_page'));
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) { wp_die('Permission denied.'); }
        check_admin_referer('pdp_save_signup_automation');
        $checkboxes = array('enabled','auto_create_account','auto_create_license','auto_email_license','email_login_details','force_password_reset','suspend_failed_payment','expire_on_cancel','show_plan_comparison','show_faq','show_testimonials');
        $settings = self::defaults();
        foreach ($checkboxes as $key) { $settings[$key] = isset($_POST[$key]) ? '1' : '0'; }
        foreach (array('success_url','cancel_url','billing_url','support_url','documentation_url') as $key) {
            $settings[$key] = esc_url_raw(wp_unslash($_POST[$key] ?? ''));
        }
        foreach (array('signup_heading','signup_subheading','checkout_button') as $key) {
            $settings[$key] = sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
        }
        $settings['grace_period_days'] = (string) min(90, max(0, absint($_POST['grace_period_days'] ?? 7)));
        update_option(self::OPT, $settings, false);

        $mapping = isset($_POST['square_plan_map']) && is_array($_POST['square_plan_map']) ? wp_unslash($_POST['square_plan_map']) : array();
        foreach ($mapping as $plan_id => $variation_id) {
            $plan_id = absint($plan_id);
            if ($plan_id && 'pdp_plan' === get_post_type($plan_id) && current_user_can('edit_post', $plan_id)) {
                update_post_meta($plan_id, '_pdp_square_plan_variation_id', sanitize_text_field($variation_id));
            }
        }

        $notice = 'Automation studio settings saved.';
        if (isset($_POST['pdp_sync_square_plans'])) {
            $synced = PDP_Square_Sync::sync_subscription_plans();
            if (is_wp_error($synced)) {
                $notice = 'Settings saved. Square plan sync failed: ' . $synced->get_error_message();
            } else {
                $notice = 'Settings saved and ' . count($synced) . ' Square subscription plan variations synced.';
            }
        }
        wp_safe_redirect(add_query_arg(array('page'=>'pdp-signup-automation','pdp_notice'=>$notice), admin_url('admin.php')));
        exit;
    }

    public static function settings_page() {
        if (!current_user_can('manage_options')) { return; }
        $s = self::settings();
        $plans = get_posts(array('post_type'=>'pdp_plan','post_status'=>'publish','numberposts'=>-1,'orderby'=>'menu_order title','order'=>'ASC'));
        $square_plans = PDP_Square_Sync::get_cached_subscription_plans();
        $last_sync = PDP_Square_Sync::get_last_plan_sync();
        $square_ready = class_exists('PDP_Square_Client') && (new PDP_Square_Client())->is_configured();
        $mapped = 0;
        foreach ($plans as $plan) {
            $price = (float) get_post_meta($plan->ID, '_pdp_price', true);
            $billing = sanitize_key(get_post_meta($plan->ID, '_pdp_billing', true));
            if ($price <= 0 || 'trial' === $billing || get_post_meta($plan->ID, '_pdp_square_plan_variation_id', true)) { $mapped++; }
        }
        $all_mapped = count($plans) > 0 && $mapped === count($plans);
        $portal_ready = !empty($s['success_url']);
        $shortcode_page = self::find_signup_page();

        echo '<div class="wrap pdp-admin pdp-modern-admin pdp-automation-studio">';
        echo '<div class="pdp-admin-hero"><div><span class="pdp-admin-kicker">CUSTOMER JOURNEY</span><h1>Signup & Subscription Automation</h1><p>Configure the complete path from plan selection and Square checkout through account creation, license delivery, and customer portal access.</p></div><div class="pdp-hero-actions"><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=pdp-form-builder')).'">Edit Signup Form</a>'.($shortcode_page ? '<a class="button" target="_blank" rel="noopener" href="'.esc_url(get_permalink($shortcode_page)).'">Preview Signup Page</a>' : '').'</div></div>';
        if (!empty($_GET['pdp_notice'])) { echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(wp_unslash($_GET['pdp_notice'])).'</p></div>'; }

        echo '<div class="pdp-journey-status">';
        self::status_card('Signup page', $shortcode_page ? 'Ready' : 'Needs page', $shortcode_page ? 'ready' : 'warning', 'dashicons-welcome-widgets-menus');
        self::status_card('Square billing', $square_ready ? 'Connected' : 'Needs attention', $square_ready ? 'ready' : 'warning', 'dashicons-money-alt');
        self::status_card('Plan mapping', $all_mapped ? 'Complete' : $mapped.'/'.count($plans).' ready', $all_mapped ? 'ready' : 'warning', 'dashicons-editor-table');
        self::status_card('License delivery', '1' === $s['auto_create_license'] ? 'Automatic' : 'Manual', '1' === $s['auto_create_license'] ? 'ready' : 'warning', 'dashicons-admin-network');
        echo '</div>';

        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="pdp-automation-form">';
        wp_nonce_field('pdp_save_signup_automation');
        echo '<input type="hidden" name="action" value="pdp_save_signup_automation">';
        echo '<div class="pdp-studio-layout"><main class="pdp-studio-main">';

        self::panel_open('1', 'Checkout workflow', 'Control which automation steps run when a customer submits the signup form.');
        echo '<div class="pdp-automation-switches">';
        self::switch_row('enabled','Enable self-service subscription checkout','Send paid-plan customers directly to Square hosted checkout after signup.',$s['enabled']);
        self::switch_row('auto_create_account','Create or connect customer account','Use the signup email to create a secure Party Desk Pro customer account.',$s['auto_create_account']);
        self::switch_row('auto_create_license','Issue license after successful subscription','Provision the selected plan license only after Square confirms the subscription.',$s['auto_create_license']);
        self::switch_row('auto_email_license','Send branded license email','Email the customer their license, download, and portal access after provisioning.',$s['auto_email_license']);
        echo '</div>';
        self::panel_close();

        self::panel_open('2', 'Signup page content', 'Customize the key text used during the signup and checkout handoff.');
        echo '<div class="pdp-studio-fields">';
        self::text_field('signup_heading','Signup heading',$s['signup_heading']);
        self::text_field('signup_subheading','Signup subheading',$s['signup_subheading']);
        self::text_field('checkout_button','Checkout button label',$s['checkout_button']);
        echo '</div><div class="pdp-inline-options">';
        self::compact_check('show_plan_comparison','Show plan comparison',$s['show_plan_comparison']);
        self::compact_check('show_faq','Show FAQ section',$s['show_faq']);
        self::compact_check('show_testimonials','Show testimonials section',$s['show_testimonials']);
        echo '</div>';
        self::panel_close();

        self::panel_open('3', 'Customer account and access', 'Choose how customer credentials and portal access are handled.');
        echo '<div class="pdp-automation-switches">';
        self::switch_row('email_login_details','Email account login details','Include account access instructions in the welcome email.',$s['email_login_details']);
        self::switch_row('force_password_reset','Require password reset on first login','Ask newly created customers to choose a private password before using the portal.',$s['force_password_reset']);
        echo '</div><div class="pdp-studio-fields pdp-studio-fields-3">';
        self::url_field('billing_url','Billing page URL',$s['billing_url']);
        self::url_field('support_url','Support page URL',$s['support_url']);
        self::url_field('documentation_url','Documentation URL',$s['documentation_url']);
        echo '</div>';
        self::panel_close();

        self::panel_open('4', 'Subscription protection', 'Set the default response when Square reports payment or cancellation changes.');
        echo '<div class="pdp-automation-switches">';
        self::switch_row('suspend_failed_payment','Suspend license after failed payment','Mark the license unavailable when the subscription becomes past due.',$s['suspend_failed_payment']);
        self::switch_row('expire_on_cancel','Expire license after cancellation','End license access when Square confirms the subscription has been canceled.',$s['expire_on_cancel']);
        echo '</div><div class="pdp-studio-fields"><label class="pdp-studio-field"><span>Payment grace period</span><div class="pdp-number-suffix"><input type="number" min="0" max="90" name="grace_period_days" value="'.esc_attr($s['grace_period_days']).'"><em>days</em></div><small>Time allowed before a past-due license is suspended.</small></label></div>';
        self::panel_close();

        self::panel_open('5', 'Checkout redirects', 'Choose where Square returns customers after checkout.');
        echo '<div class="pdp-studio-fields">';
        self::url_field('success_url','Successful checkout URL',$s['success_url']);
        self::url_field('cancel_url','Cancelled checkout URL',$s['cancel_url']);
        echo '</div>';
        self::panel_close();

        echo '<div class="pdp-studio-save"><div><strong>Ready to save?</strong><span>Changes apply to new signup and subscription checkouts.</span></div><button type="submit" class="button button-primary button-hero">Save Automation Studio</button></div>';
        echo '</main><aside class="pdp-studio-side">';

        echo '<section class="pdp-dashboard-panel pdp-flow-panel"><div class="pdp-dashboard-panel-head"><div><span class="pdp-section-kicker">LIVE WORKFLOW</span><h2>Automatic flow</h2><p>The customer journey for paid subscriptions.</p></div></div><ol class="pdp-flow-list">';
        $steps = array(
            array('Signup submitted','Customer details and selected plan are saved.'),
            array('Account prepared','A customer account is created or connected.'),
            array('Square checkout','The buyer completes recurring payment securely.'),
            array('Webhook verified','Square confirms the subscription server-to-server.'),
            array('License issued','The correct plan and website allowance are assigned.'),
            array('Access delivered','The welcome email, download, and portal are sent.'),
        );
        foreach ($steps as $i=>$step) { echo '<li><b>'.($i+1).'</b><span><strong>'.esc_html($step[0]).'</strong><small>'.esc_html($step[1]).'</small></span></li>'; }
        echo '</ol></section>';

        echo '<section class="pdp-dashboard-panel pdp-square-sync-panel"><div class="pdp-dashboard-panel-head"><div><span class="pdp-section-kicker">PLAN CONNECTIONS</span><h2>Square plan mapping</h2><p>Sync Square, then select the recurring variation for each paid Party Desk Pro plan.</p></div></div>';
        echo '<div class="pdp-square-sync-toolbar"><div><strong>'.(count($square_plans) ? esc_html(count($square_plans).' Square variations available') : 'No Square plans synced yet').'</strong><small>'.($last_sync ? 'Last synced '.esc_html(get_date_from_gmt($last_sync, get_option('date_format').' '.get_option('time_format'))) : 'Connect Square and run your first sync.').'</small></div><button type="submit" name="pdp_sync_square_plans" value="1" class="button button-primary"><span class="dashicons dashicons-update"></span> Sync Square Plans</button></div>';
        echo '<div class="pdp-plan-map pdp-plan-map-selects">';
        if (!$plans) { echo '<div class="pdp-empty-mini">No published plans found.</div>'; }
        foreach ($plans as $plan) {
            $price=(float)get_post_meta($plan->ID,'_pdp_price',true); $billing=sanitize_key(get_post_meta($plan->ID,'_pdp_billing',true)); $variation=(string)get_post_meta($plan->ID,'_pdp_square_plan_variation_id',true);
            $free = $price<=0 || 'trial'===$billing;
            echo '<div class="pdp-plan-map-select-row"><div><strong>'.esc_html($plan->post_title).'</strong><small>'.($free ? 'No Square plan needed' : esc_html('$'.number_format($price,2).' / '.$billing)).'</small></div>';
            if ($free) {
                echo '<span class="pdp-map-free">Free / trial</span>';
            } else {
                echo '<select name="square_plan_map['.absint($plan->ID).']"><option value="">Select a Square subscription plan…</option>';
                if ($variation && !isset($square_plans[$variation])) { echo '<option value="'.esc_attr($variation).'" selected>Currently mapped plan (sync to show its name)</option>'; }
                foreach ($square_plans as $square_plan) { echo '<option value="'.esc_attr($square_plan['variation_id']).'" '.selected($variation,$square_plan['variation_id'],false).'>'.esc_html($square_plan['label']).'</option>'; }
                echo '</select>';
            }
            echo '</div>';
        }
        echo '</div><div class="pdp-panel-footer"><span>Selections are saved with the Automation Studio.</span><a href="'.esc_url(admin_url('admin.php?page=pdp-plans')).'">Manage all plans →</a></div></section>';
        echo '</aside></div></form></div>';
    }

    private static function find_signup_page() {
        $pages = get_posts(array('post_type'=>'page','post_status'=>'publish','numberposts'=>50,'s'=>'[pdpsignup]'));
        foreach ($pages as $page) {
            if (has_shortcode($page->post_content, 'pdpsignup') || has_shortcode($page->post_content, 'party_desk_pro_license_request_form')) { return $page->ID; }
        }
        return 0;
    }

    private static function status_card($label,$value,$tone,$icon) {
        echo '<div class="pdp-journey-card '.esc_attr($tone).'"><span class="dashicons '.esc_attr($icon).'"></span><div><small>'.esc_html($label).'</small><strong>'.esc_html($value).'</strong></div></div>';
    }

    private static function panel_open($number,$title,$description) {
        echo '<section class="pdp-dashboard-panel pdp-studio-panel"><div class="pdp-dashboard-panel-head"><div class="pdp-step-title"><b>'.esc_html($number).'</b><div><h2>'.esc_html($title).'</h2><p>'.esc_html($description).'</p></div></div></div>';
    }
    private static function panel_close() { echo '</section>'; }
    private static function text_field($name,$label,$value) { echo '<label class="pdp-studio-field"><span>'.esc_html($label).'</span><input type="text" name="'.esc_attr($name).'" value="'.esc_attr($value).'"></label>'; }
    private static function url_field($name,$label,$value) { echo '<label class="pdp-studio-field"><span>'.esc_html($label).'</span><input class="code" type="url" name="'.esc_attr($name).'" value="'.esc_attr($value).'"></label>'; }
    private static function compact_check($name,$label,$value) { echo '<label class="pdp-compact-check"><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked($value,'1',false).'><span>'.esc_html($label).'</span></label>'; }

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
