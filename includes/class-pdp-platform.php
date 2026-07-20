<?php
/**
 * Phase 7 commercial platform tools: onboarding, update integration and health overview.
 */
if (!defined('ABSPATH')) { exit; }

final class PDP_Platform {
    const OPT = 'pdp_platform_settings';

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 35);
        add_action('admin_post_pdp_save_platform_setup', array(__CLASS__, 'save_setup'));
        add_action('rest_api_init', array(__CLASS__, 'register_routes'), 40);
    }

    public static function defaults() {
        return array(
            'company_name' => get_bloginfo('name'),
            'support_url' => '',
            'portal_url' => home_url('/my-account/'),
            'default_channel' => 'stable',
            'validation_hours' => 12,
            'updates_enabled' => '1',
            'setup_complete' => '0',
        );
    }

    public static function settings() {
        return wp_parse_args((array) get_option(self::OPT, array()), self::defaults());
    }

    public static function admin_menu() {
        add_submenu_page('pdp-dashboard', 'Setup Wizard', 'Setup Wizard', 'manage_options', 'pdp-setup-wizard', array(__CLASS__, 'setup_page'));
        add_submenu_page('pdp-dashboard', 'Update Integration', 'Update Integration', 'manage_options', 'pdp-update-integration', array(__CLASS__, 'integration_page'));
    }

    public static function save_setup() {
        if (!current_user_can('manage_options')) { wp_die('Permission denied.'); }
        check_admin_referer('pdp_save_platform_setup');
        $current = self::settings();
        $current['company_name'] = sanitize_text_field(wp_unslash($_POST['company_name'] ?? ''));
        $current['support_url'] = esc_url_raw(wp_unslash($_POST['support_url'] ?? ''));
        $current['portal_url'] = esc_url_raw(wp_unslash($_POST['portal_url'] ?? ''));
        $current['default_channel'] = in_array(($_POST['default_channel'] ?? ''), array('stable','beta'), true) ? $_POST['default_channel'] : 'stable';
        $current['validation_hours'] = min(168, max(1, absint($_POST['validation_hours'] ?? 12)));
        $current['updates_enabled'] = isset($_POST['updates_enabled']) ? '1' : '0';
        $current['setup_complete'] = '1';
        update_option(self::OPT, $current, false);
        wp_safe_redirect(add_query_arg(array('page'=>'pdp-setup-wizard','pdp_notice'=>'Setup settings saved.'), admin_url('admin.php')));
        exit;
    }

    private static function health_items() {
        $settings = self::settings();
        $products = get_posts(array('post_type'=>'pdp_product','post_status'=>'publish','numberposts'=>1,'fields'=>'ids'));
        $releases = get_posts(array('post_type'=>'pdp_release','post_status'=>'publish','numberposts'=>1,'fields'=>'ids','meta_key'=>'_pdp_release_published','meta_value'=>'1'));
        $square = get_option('pdp_ls_settings', array());
        return array(
            array('WordPress REST API', function_exists('rest_url'), rest_url('party-desk-license/v1/status')),
            array('Product configured', !empty($products), admin_url('admin.php?page=pdp-products-releases')),
            array('Published release', !empty($releases), admin_url('post-new.php?post_type=pdp_release')),
            array('Customer portal URL', !empty($settings['portal_url']), $settings['portal_url']),
            array('Square credentials', !empty($square['square_token']) && !empty($square['square_location']), admin_url('admin.php?page=pdp-square-subscriptions')),
            array('Secure HTTPS', is_ssl(), home_url('/')),
        );
    }

    public static function setup_page() {
        if (!current_user_can('manage_options')) return;
        $s = self::settings();
        echo '<div class="wrap pdp-admin pdp-modern-admin"><div class="pdp-admin-hero"><div><span class="pdp-admin-kicker">COMMERCIAL PLATFORM</span><h1>Setup Wizard</h1><p>Finish the essential server configuration and verify that licensing, billing, portal access, and automatic updates are ready.</p></div></div>';
        if (!empty($_GET['pdp_notice'])) echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(wp_unslash($_GET['pdp_notice'])).'</p></div>';
        echo '<div class="pdp-dashboard-grid"><main><section class="pdp-dashboard-panel"><div class="pdp-dashboard-panel-head"><div><h2>Platform settings</h2><p>These values are returned to licensed Party Desk Pro clients and used throughout the customer experience.</p></div></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'; wp_nonce_field('pdp_save_platform_setup'); echo '<input type="hidden" name="action" value="pdp_save_platform_setup">';
        echo '<table class="form-table"><tr><th><label for="company_name">Company name</label></th><td><input class="regular-text" id="company_name" name="company_name" value="'.esc_attr($s['company_name']).'"></td></tr>';
        echo '<tr><th><label for="portal_url">Customer portal URL</label></th><td><input class="regular-text code" type="url" id="portal_url" name="portal_url" value="'.esc_attr($s['portal_url']).'"></td></tr>';
        echo '<tr><th><label for="support_url">Support URL</label></th><td><input class="regular-text code" type="url" id="support_url" name="support_url" value="'.esc_attr($s['support_url']).'"></td></tr>';
        echo '<tr><th><label for="default_channel">Default update channel</label></th><td><select id="default_channel" name="default_channel"><option value="stable" '.selected($s['default_channel'],'stable',false).'>Stable</option><option value="beta" '.selected($s['default_channel'],'beta',false).'>Beta</option></select></td></tr>';
        echo '<tr><th><label for="validation_hours">License recheck interval</label></th><td><input class="small-text" type="number" min="1" max="168" id="validation_hours" name="validation_hours" value="'.absint($s['validation_hours']).'"> hours</td></tr>';
        echo '<tr><th>Automatic updates</th><td><label><input type="checkbox" name="updates_enabled" value="1" '.checked($s['updates_enabled'],'1',false).'> Return update packages to active licensed websites</label></td></tr></table>';
        submit_button('Save and Continue'); echo '</form></section></main><aside><section class="pdp-dashboard-panel"><h2>Readiness checks</h2><div class="pdp-setup-checks">';
        foreach (self::health_items() as $item) {
            echo '<a class="pdp-setup-check '.($item[1]?'is-ready':'is-needed').'" href="'.esc_url($item[2]).'"><span class="dashicons '.($item[1]?'dashicons-yes-alt':'dashicons-warning').'"></span><strong>'.esc_html($item[0]).'</strong><small>'.($item[1]?'Ready':'Needs attention').'</small></a>';
        }
        echo '</div></section></aside></div></div>';
    }

    public static function integration_page() {
        if (!current_user_can('manage_options')) return;
        $base = untrailingslashit(rest_url('party-desk-license/v1'));
        echo '<div class="wrap pdp-admin pdp-modern-admin"><div class="pdp-admin-hero"><div><span class="pdp-admin-kicker">CLIENT CONNECTION</span><h1>Automatic Update Integration</h1><p>Use these endpoints in the Party Desk Pro client plugin. Active licenses receive signed, one-hour package links; invalid licenses never receive the ZIP URL.</p></div></div>';
        echo '<section class="pdp-dashboard-panel"><h2>Server endpoints</h2><table class="widefat striped"><tbody>';
        $rows = array('Activate license'=>$base.'/activate','Validate license'=>$base.'/validate','Check for update'=>$base.'/update-check','Deactivate website'=>$base.'/deactivate','Public product manifest'=>$base.'/manifest/party-desk-pro','Service status'=>$base.'/status');
        foreach ($rows as $label=>$url) echo '<tr><th>'.esc_html($label).'</th><td><code>'.esc_html($url).'</code></td></tr>';
        echo '</tbody></table></section>';
        echo '<section class="pdp-dashboard-panel"><h2>Client request format</h2><p>The client sends a JSON <code>POST</code> request to the update-check endpoint.</p><pre class="pdp-code-block">'.esc_html(wp_json_encode(array('license_key'=>'PDP-XXXX-XXXX-XXXX-XXXX','product'=>'party-desk-pro','site_url'=>'https://customer-site.example','site_name'=>'Customer Site','installed_version'=>'1.0.0','instance_id'=>'persistent-random-install-id'), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>';
        echo '<p><strong>WordPress integration:</strong> connect the response to the client plugin\'s <code>pre_set_site_transient_update_plugins</code>, <code>plugins_api</code>, and <code>upgrader_package_options</code> filters. The response now includes version, package checksum, requirements, release notes, plugin slug, and a signed download URL.</p></section></div>';
    }

    public static function register_routes() {
        register_rest_route('party-desk-license/v1', '/manifest/(?P<product>[a-z0-9\-]+)', array(
            'methods' => WP_REST_Server::READABLE,
            'permission_callback' => '__return_true',
            'callback' => array(__CLASS__, 'manifest'),
            'args' => array('product'=>array('sanitize_callback'=>'sanitize_key')),
        ));
    }

    public static function manifest(WP_REST_Request $request) {
        $slug = sanitize_key($request['product']);
        $product_id = PDP_Product_Manager::find_product($slug);
        if (!$product_id || !PDP_Product_Manager::product_accepts_requests($slug)) {
            return new WP_REST_Response(array('success'=>false,'message'=>'Product not found.'), 404);
        }
        $product_channel = get_post_meta($product_id, '_pdp_product_channel', true) ?: 'stable';
        $release = PDP_Product_Manager::latest_release($slug, $product_channel);
        return new WP_REST_Response(array(
            'success' => true,
            'product' => $slug,
            'name' => get_the_title($product_id),
            'channel' => $product_channel,
            'latest_version' => sanitize_text_field($release['version'] ?? ''),
            'requires_wordpress' => sanitize_text_field($release['requires_wp'] ?? ''),
            'requires_php' => sanitize_text_field($release['requires_php'] ?? ''),
            'release_notes' => sanitize_textarea_field($release['changelog'] ?? ''),
            'published_at' => !empty($release['id']) ? get_post_time('c', true, $release['id']) : '',
            'license_required' => true,
            'update_endpoint' => rest_url('party-desk-license/v1/update-check'),
        ), 200);
    }
}
