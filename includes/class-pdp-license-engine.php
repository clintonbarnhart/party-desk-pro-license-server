<?php
if (!defined('ABSPATH')) { exit; }

final class PDP_License_Engine {
    const API_NAMESPACE = 'party-desk-license/v1';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'), 20);
        add_action('admin_post_pdp_license_site_action', array(__CLASS__, 'handle_site_action'));
        add_action('admin_post_pdp_license_bulk_action', array(__CLASS__, 'handle_bulk_action'));
    }

    public static function register_routes() {
        foreach (array('activate','validate','deactivate','update-check') as $action) {
            register_rest_route(self::API_NAMESPACE, '/' . $action, array(
                'methods' => WP_REST_Server::CREATABLE,
                'permission_callback' => '__return_true',
                'callback' => function($request) use ($action) { return self::handle($request, $action); },
            ), true);
        }
        register_rest_route(self::API_NAMESPACE, '/status', array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => array(__CLASS__, 'status'),
        ));
    }

    private static function payload(WP_REST_Request $request) {
        $data = $request->get_json_params();
        if (!is_array($data) || !$data) { $data = $request->get_params(); }
        return array(
            'license_key' => strtoupper(trim(sanitize_text_field($data['license_key'] ?? ''))),
            'product' => sanitize_key($data['product'] ?? 'party-desk-pro'),
            'site_url' => esc_url_raw($data['site_url'] ?? ''),
            'site_name' => sanitize_text_field($data['site_name'] ?? ''),
            'customer_email' => sanitize_email($data['customer_email'] ?? ''),
            'installed_version' => sanitize_text_field($data['installed_version'] ?? ''),
            'instance_id' => sanitize_text_field($data['instance_id'] ?? ''),
        );
    }

    private static function rate_limit($key) {
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $bucket = 'pdp_api_' . md5($ip . '|' . substr($key, 0, 12));
        $count = (int) get_transient($bucket);
        if ($count >= 90) { return false; }
        set_transient($bucket, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }

    public static function find_license($key) {
        if (!$key) { return 0; }
        $ids = get_posts(array('post_type'=>'pdp_license','post_status'=>array('publish','draft','pending','private'),'numberposts'=>1,'fields'=>'ids','meta_key'=>'_pdp_key','meta_value'=>$key));
        return $ids ? absint($ids[0]) : 0;
    }

    public static function state($license_id) {
        $status = strtolower(trim((string) get_post_meta($license_id, '_pdp_status', true)));
        if (!$status) { $status = 'pending'; }
        $expires = sanitize_text_field(get_post_meta($license_id, '_pdp_expires', true));
        $is_lifetime = !$expires || in_array(strtolower($expires), array('lifetime','never'), true);
        if (!$is_lifetime && strtotime($expires . ' 23:59:59') < current_time('timestamp')) { $status = 'expired'; }
        $subscription_id = absint(get_post_meta($license_id, '_pdp_subscription_id', true));
        if ($subscription_id) {
            $subscription = PDP_DB::get_subscription($subscription_id);
            if ($subscription && in_array($subscription['status'], array('canceled','failed','past_due'), true)) { $status = 'suspended'; }
        }
        $ok = $status === 'active';
        $messages = array('active'=>'License is active.','expired'=>'This license has expired.','suspended'=>'This license has been suspended.','disabled'=>'This license has been disabled.','revoked'=>'This license has been revoked.','pending'=>'This license is pending activation.');
        return array('ok'=>$ok,'status'=>$status,'message'=>$messages[$status] ?? 'This license is not active.','expires_at'=>$is_lifetime ? '' : $expires,'lifetime'=>$is_lifetime);
    }

    public static function normalize_site($url) {
        $parts = wp_parse_url(trim($url));
        if (!$parts || empty($parts['host'])) { return ''; }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower(preg_replace('/^www\./', '', $parts['host']));
        $port = isset($parts['port']) ? ':' . absint($parts['port']) : '';
        $path = isset($parts['path']) ? '/' . trim($parts['path'], '/') : '';
        return untrailingslashit($scheme . '://' . $host . $port . $path);
    }

    private static function response($license_id, $state, $activation=array()) {
        $sites = PDP_DB::get_license_activations($license_id);
        $limit = self::site_limit($license_id);
        return array(
            'success' => (bool) $state['ok'],
            'status' => $state['status'],
            'message' => $state['message'],
            'license_id' => $license_id,
            'plan' => sanitize_text_field(get_post_meta($license_id, '_pdp_plan', true)),
            'expires_at' => $state['expires_at'],
            'lifetime' => !empty($state['lifetime']),
            'site_limit' => $limit,
            'sites_used' => count($sites),
            'activation' => $activation,
            'server_time' => current_time('mysql', true),
            'validation_interval' => max(1, absint(PDP_Platform::settings()['validation_hours'])) * HOUR_IN_SECONDS,
        );
    }

    public static function site_limit($license_id) {
        $raw = get_post_meta($license_id, '_pdp_sites', true);
        if (in_array(strtolower((string)$raw), array('unlimited','0',''), true)) { return 0; }
        return max(1, absint($raw));
    }

    public static function handle(WP_REST_Request $request, $action) {
        $p = self::payload($request);
        if (!$p['license_key']) { return new WP_REST_Response(array('success'=>false,'status'=>'invalid','message'=>'A license key is required.'), 400); }
        if (!self::rate_limit($p['license_key'])) { return new WP_REST_Response(array('success'=>false,'status'=>'rate_limited','message'=>'Too many license requests. Try again shortly.'), 429); }
        if (!PDP_Product_Manager::product_accepts_requests($p['product'])) { return new WP_REST_Response(array('success'=>false,'status'=>'invalid_product','message'=>'This product is not available for license requests.'), 400); }
        $license_id = self::find_license($p['license_key']);
        if (!$license_id) { self::audit(0, $action . '_failed', 'License key not found.', $p); return new WP_REST_Response(array('success'=>false,'status'=>'invalid','message'=>'License key not found.'), 404); }
        $email = sanitize_email(get_post_meta($license_id, '_pdp_email', true));
        if ($email && $p['customer_email'] && strtolower($email) !== strtolower($p['customer_email'])) { self::audit($license_id, $action . '_failed', 'Customer email mismatch.', $p); return new WP_REST_Response(array('success'=>false,'status'=>'invalid','message'=>'The email address does not match this license.'), 403); }
        $state = self::state($license_id);
        $site = self::normalize_site($p['site_url']);

        if ($action === 'deactivate') {
            if (!$site) { return new WP_REST_Response(array('success'=>false,'status'=>'invalid','message'=>'A valid website URL is required.'), 400); }
            PDP_DB::deactivate_license_site($license_id, $site);
            self::audit($license_id, 'deactivated', 'License deactivated from ' . $site, $p);
            $state['ok'] = true; $state['status'] = 'inactive'; $state['message'] = 'License deactivated on this website.';
            return new WP_REST_Response(self::response($license_id, $state), 200);
        }
        if (!$state['ok']) { self::audit($license_id, $action . '_blocked', $state['message'], $p); return new WP_REST_Response(self::response($license_id, $state), 403); }
        if (!$site) { return new WP_REST_Response(array('success'=>false,'status'=>'invalid','message'=>'A valid website URL is required.'), 400); }
        $activation = PDP_DB::get_license_activation($license_id, $site);
        if ($action === 'activate' && !$activation) {
            $limit = self::site_limit($license_id);
            if ($limit > 0 && PDP_DB::count_active_license_sites($license_id) >= $limit) { self::audit($license_id, 'activation_limit', 'Website activation limit reached.', $p); return new WP_REST_Response(array('success'=>false,'status'=>'limit_reached','message'=>'This license has reached its website activation limit.','site_limit'=>$limit), 403); }
        }
        $activation_id = PDP_DB::upsert_license_activation(array('license_id'=>$license_id,'site_url'=>$site,'site_name'=>$p['site_name'],'installed_version'=>$p['installed_version'],'instance_id'=>$p['instance_id'],'status'=>'active'));
        if (is_wp_error($activation_id)) { return new WP_REST_Response(array('success'=>false,'status'=>'server_error','message'=>$activation_id->get_error_message()), 500); }
        $activation = PDP_DB::get_license_activation($license_id, $site);
        self::audit($license_id, $action === 'activate' ? 'activated' : 'validated', ($action === 'activate' ? 'Activated' : 'Validated') . ' on ' . $site, $p, $activation_id);
        $result = self::response($license_id, $state, $activation ?: array());
        if ($action === 'update-check') {
            $channel = sanitize_key(get_post_meta($license_id, '_pdp_update_channel', true) ?: 'stable');
            $release = PDP_Product_Manager::latest_release($p['product'], $channel);
            $latest = sanitize_text_field($release['version'] ?? '');
            $result['update_available'] = $latest && version_compare($latest, $p['installed_version'], '>');
            $result['version'] = $latest ?: $p['installed_version'];
            $result['download_url'] = ($result['update_available'] && !empty($release['attachment_id'])) ? PDP_Product_Manager::signed_download_url($license_id, $site, $release['id']) : '';
            $result['changelog'] = sanitize_textarea_field($release['changelog'] ?? '');
            $result['channel'] = $release['channel'] ?? $channel;
            $result['requires_wordpress'] = $release['requires_wp'] ?? '';
            $result['requires_php'] = $release['requires_php'] ?? '';
            $platform = PDP_Platform::settings();
            $release_id = absint($release['id'] ?? 0);
            $attachment_id = absint($release['attachment_id'] ?? 0);
            $updates_enabled = $platform['updates_enabled'] === '1';
            if (!$updates_enabled) {
                $result['update_available'] = false;
                $result['download_url'] = '';
            }
            $result['package_ready'] = $updates_enabled && !empty($attachment_id);
            $result['plugin'] = sanitize_key($p['product']) . '/' . sanitize_key($p['product']) . '.php';
            $result['slug'] = sanitize_key($p['product']);
            $result['name'] = $release_id ? sanitize_text_field(get_post_meta($release_id, '_pdp_release_plugin_name', true)) : 'Party Desk Pro';
            $result['checksum_sha256'] = $release_id ? sanitize_text_field(get_post_meta($release_id, '_pdp_release_sha256', true)) : '';
            $result['package_size'] = $release_id ? absint(get_post_meta($release_id, '_pdp_release_package_size', true)) : 0;
            $result['published_at'] = $release_id ? get_post_time('c', true, $release_id) : '';
            $result['homepage'] = home_url('/');
            $result['support_url'] = esc_url_raw($platform['support_url']);
            $result['portal_url'] = esc_url_raw($platform['portal_url']);
            $result['sections'] = array('description'=>'Party Desk Pro commercial event management software.','changelog'=>sanitize_textarea_field($release['changelog'] ?? ''));
        }
        return new WP_REST_Response($result, 200);
    }

    public static function status() {
        return new WP_REST_Response(array('service'=>'Party Desk Pro License Server','version'=>PDP_LS_VERSION,'status'=>'online','time'=>current_time('mysql', true)), 200);
    }

    public static function signed_download_url($license_id, $site='') {
        $expires = time() + HOUR_IN_SECONDS;
        $token = hash_hmac('sha256', $license_id . '|' . $site . '|' . $expires, wp_salt('auth'));
        return add_query_arg(array('pdp_download_license'=>$license_id,'site'=>rawurlencode($site),'expires'=>$expires,'signature'=>$token), home_url('/'));
    }

    public static function audit($license_id, $event_type, $message, $data=array(), $activation_id=0) {
        $safe = $data;
        unset($safe['license_key']);
        return PDP_DB::log_license_event(array('license_id'=>$license_id,'activation_id'=>$activation_id,'event_type'=>$event_type,'message'=>$message,'ip_address'=>sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? ''),'user_agent'=>sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),'event_data'=>$safe));
    }

    public static function handle_site_action() {
        if (!current_user_can('manage_options')) { wp_die('Permission denied.'); }
        $license_id = absint($_GET['license_id'] ?? 0); $activation_id = absint($_GET['activation_id'] ?? 0); $task = sanitize_key($_GET['task'] ?? '');
        check_admin_referer('pdp_license_site_' . $license_id . '_' . $activation_id);
        if ($task === 'deactivate') { PDP_DB::deactivate_activation($activation_id); self::audit($license_id, 'admin_deactivated_site', 'Administrator deactivated a website.', array(), $activation_id); }
        if ($task === 'delete') { PDP_DB::delete_activation($activation_id); self::audit($license_id, 'admin_removed_site', 'Administrator removed a website activation.', array(), $activation_id); }
        wp_safe_redirect(add_query_arg(array('page'=>'pdp-license-activity','license_id'=>$license_id,'pdp_notice'=>'Website activation updated.'), admin_url('admin.php'))); exit;
    }

    public static function handle_bulk_action() {
        if (!current_user_can('manage_options')) { wp_die('Permission denied.'); }
        check_admin_referer('pdp_license_bulk_action');
        $ids = array_map('absint', (array)($_POST['license_ids'] ?? array())); $task = sanitize_key($_POST['bulk_task'] ?? '');
        foreach ($ids as $id) {
            if (get_post_type($id) !== 'pdp_license') { continue; }
            if ($task === 'activate') { update_post_meta($id, '_pdp_status', 'Active'); }
            if ($task === 'suspend') { update_post_meta($id, '_pdp_status', 'Suspended'); }
            if ($task === 'revoke') { update_post_meta($id, '_pdp_status', 'Revoked'); PDP_DB::deactivate_all_license_sites($id); }
            self::audit($id, 'admin_' . $task, 'Administrator performed bulk action: ' . $task);
        }
        wp_safe_redirect(add_query_arg(array('page'=>'pdp-licenses','pdp_notice'=>'License action completed.'), admin_url('admin.php'))); exit;
    }
}
