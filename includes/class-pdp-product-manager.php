<?php
if (!defined('ABSPATH')) { exit; }

final class PDP_Product_Manager {
    const PRODUCT_TYPE = 'pdp_product';
    const RELEASE_TYPE = 'pdp_release';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_types'));
        add_action('init', array(__CLASS__, 'maybe_seed'), 20);
        add_action('add_meta_boxes', array(__CLASS__, 'meta_boxes'));
        add_action('save_post_' . self::PRODUCT_TYPE, array(__CLASS__, 'save_product'));
        add_action('save_post_' . self::RELEASE_TYPE, array(__CLASS__, 'save_release'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'), 30);
        add_action('template_redirect', array(__CLASS__, 'handle_download'));
        add_filter('manage_' . self::PRODUCT_TYPE . '_posts_columns', array(__CLASS__, 'product_columns'));
        add_action('manage_' . self::PRODUCT_TYPE . '_posts_custom_column', array(__CLASS__, 'product_column'), 10, 2);
        add_filter('manage_' . self::RELEASE_TYPE . '_posts_columns', array(__CLASS__, 'release_columns'));
        add_action('manage_' . self::RELEASE_TYPE . '_posts_custom_column', array(__CLASS__, 'release_column'), 10, 2);
    }

    public static function maybe_seed() {
        if (!get_option('pdp_phase5_seeded')) { self::activate(); update_option('pdp_phase5_seeded', '1', false); }
    }

    public static function activate() {
        self::register_types();
        if (!self::find_product('party-desk-pro')) {
            $id = wp_insert_post(array(
                'post_type' => self::PRODUCT_TYPE,
                'post_status' => 'publish',
                'post_title' => 'Party Desk Pro',
            ));
            if ($id && !is_wp_error($id)) {
                update_post_meta($id, '_pdp_product_slug', 'party-desk-pro');
                update_post_meta($id, '_pdp_product_channel', 'stable');
                update_post_meta($id, '_pdp_product_active', '1');
            }
        }
    }

    public static function register_types() {
        register_post_type(self::PRODUCT_TYPE, array(
            'labels' => array('name'=>'Products','singular_name'=>'Product','add_new_item'=>'Add Product','edit_item'=>'Edit Product'),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => array('title'),
            'capability_type' => 'post',
        ));
        register_post_type(self::RELEASE_TYPE, array(
            'labels' => array('name'=>'Releases','singular_name'=>'Release','add_new_item'=>'Add Release','edit_item'=>'Edit Release'),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'supports' => array('title','editor'),
            'capability_type' => 'post',
        ));
    }

    public static function admin_menu() {
        add_submenu_page('pdp-dashboard', 'Products & Releases', 'Products & Releases', 'manage_options', 'pdp-products-releases', array(__CLASS__, 'dashboard'));
        add_submenu_page(null, 'Products', 'Products', 'manage_options', 'edit.php?post_type=' . self::PRODUCT_TYPE);
        add_submenu_page(null, 'Releases', 'Releases', 'manage_options', 'edit.php?post_type=' . self::RELEASE_TYPE);
    }

    public static function meta_boxes() {
        add_meta_box('pdp_product_details', 'Product Settings', array(__CLASS__, 'product_box'), self::PRODUCT_TYPE, 'normal', 'high');
        add_meta_box('pdp_release_details', 'Release Settings', array(__CLASS__, 'release_box'), self::RELEASE_TYPE, 'normal', 'high');
    }

    public static function product_box($post) {
        wp_nonce_field('pdp_save_product', 'pdp_product_nonce');
        $slug = get_post_meta($post->ID, '_pdp_product_slug', true);
        $channel = get_post_meta($post->ID, '_pdp_product_channel', true) ?: 'stable';
        $active = get_post_meta($post->ID, '_pdp_product_active', true) !== '0';
        echo '<table class="form-table"><tr><th><label for="pdp_product_slug">API product slug</label></th><td><input class="regular-text" id="pdp_product_slug" name="pdp_product_slug" value="'.esc_attr($slug).'" placeholder="party-desk-pro"><p class="description">Used by client plugins during activation and update checks.</p></td></tr>';
        echo '<tr><th><label for="pdp_product_channel">Default channel</label></th><td><select id="pdp_product_channel" name="pdp_product_channel"><option value="stable" '.selected($channel,'stable',false).'>Stable</option><option value="beta" '.selected($channel,'beta',false).'>Beta</option></select></td></tr>';
        echo '<tr><th>Availability</th><td><label><input type="checkbox" name="pdp_product_active" value="1" '.checked($active,true,false).'> Accept license and update requests</label></td></tr></table>';
    }

    public static function release_box($post) {
        wp_nonce_field('pdp_save_release', 'pdp_release_nonce');
        $product = absint(get_post_meta($post->ID, '_pdp_release_product', true));
        $version = get_post_meta($post->ID, '_pdp_release_version', true);
        $channel = get_post_meta($post->ID, '_pdp_release_channel', true) ?: 'stable';
        $attachment = absint(get_post_meta($post->ID, '_pdp_release_attachment', true));
        $minimum_wp = get_post_meta($post->ID, '_pdp_release_min_wp', true) ?: '5.8';
        $minimum_php = get_post_meta($post->ID, '_pdp_release_min_php', true) ?: '7.4';
        $published = get_post_meta($post->ID, '_pdp_release_published', true) !== '0';
        $products = get_posts(array('post_type'=>self::PRODUCT_TYPE,'post_status'=>'publish','numberposts'=>-1,'orderby'=>'title','order'=>'ASC'));
        echo '<table class="form-table"><tr><th><label for="pdp_release_product">Product</label></th><td><select id="pdp_release_product" name="pdp_release_product"><option value="0">Select product</option>';
        foreach ($products as $item) echo '<option value="'.absint($item->ID).'" '.selected($product,$item->ID,false).'>'.esc_html($item->post_title).'</option>';
        echo '</select></td></tr><tr><th><label for="pdp_release_version">Version</label></th><td><input class="regular-text" id="pdp_release_version" name="pdp_release_version" value="'.esc_attr($version).'" placeholder="1.0.0"></td></tr>';
        echo '<tr><th><label for="pdp_release_channel">Channel</label></th><td><select id="pdp_release_channel" name="pdp_release_channel"><option value="stable" '.selected($channel,'stable',false).'>Stable</option><option value="beta" '.selected($channel,'beta',false).'>Beta</option></select></td></tr>';
        echo '<tr><th><label for="pdp_release_attachment">Plugin ZIP attachment ID</label></th><td><input type="number" min="0" id="pdp_release_attachment" name="pdp_release_attachment" value="'.esc_attr($attachment).'"><p class="description">Upload the ZIP to Media Library and enter its attachment ID.</p></td></tr>';
        echo '<tr><th>Requirements</th><td><label>WordPress <input class="small-text" name="pdp_release_min_wp" value="'.esc_attr($minimum_wp).'"></label> &nbsp; <label>PHP <input class="small-text" name="pdp_release_min_php" value="'.esc_attr($minimum_php).'"></label></td></tr>';
        echo '<tr><th>Release status</th><td><label><input type="checkbox" name="pdp_release_published" value="1" '.checked($published,true,false).'> Available to licensed clients</label></td></tr></table>';
    }

    public static function save_product($post_id) {
        if (!isset($_POST['pdp_product_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pdp_product_nonce'])), 'pdp_save_product')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        update_post_meta($post_id, '_pdp_product_slug', sanitize_key($_POST['pdp_product_slug'] ?? ''));
        update_post_meta($post_id, '_pdp_product_channel', in_array(($_POST['pdp_product_channel'] ?? ''), array('stable','beta'), true) ? $_POST['pdp_product_channel'] : 'stable');
        update_post_meta($post_id, '_pdp_product_active', isset($_POST['pdp_product_active']) ? '1' : '0');
    }

    public static function save_release($post_id) {
        if (!isset($_POST['pdp_release_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pdp_release_nonce'])), 'pdp_save_release')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        update_post_meta($post_id, '_pdp_release_product', absint($_POST['pdp_release_product'] ?? 0));
        update_post_meta($post_id, '_pdp_release_version', preg_replace('/[^0-9A-Za-z.\-+]/', '', sanitize_text_field($_POST['pdp_release_version'] ?? '')));
        update_post_meta($post_id, '_pdp_release_channel', in_array(($_POST['pdp_release_channel'] ?? ''), array('stable','beta'), true) ? $_POST['pdp_release_channel'] : 'stable');
        update_post_meta($post_id, '_pdp_release_attachment', absint($_POST['pdp_release_attachment'] ?? 0));
        update_post_meta($post_id, '_pdp_release_min_wp', sanitize_text_field($_POST['pdp_release_min_wp'] ?? '5.8'));
        update_post_meta($post_id, '_pdp_release_min_php', sanitize_text_field($_POST['pdp_release_min_php'] ?? '7.4'));
        update_post_meta($post_id, '_pdp_release_published', isset($_POST['pdp_release_published']) ? '1' : '0');
    }

    public static function find_product($slug) {
        $ids = get_posts(array('post_type'=>self::PRODUCT_TYPE,'post_status'=>'publish','numberposts'=>1,'fields'=>'ids','meta_key'=>'_pdp_product_slug','meta_value'=>sanitize_key($slug)));
        return $ids ? absint($ids[0]) : 0;
    }

    public static function product_accepts_requests($slug) {
        if ($slug === 'party-desk') { $slug = 'party-desk-pro'; }
        $id = self::find_product($slug);
        return $id && get_post_meta($id, '_pdp_product_active', true) !== '0';
    }

    public static function latest_release($slug, $channel='stable') {
        if ($slug === 'party-desk') { $slug = 'party-desk-pro'; }
        $product_id = self::find_product($slug);
        if (!$product_id) return array();
        $releases = get_posts(array(
            'post_type'=>self::RELEASE_TYPE,
            'post_status'=>'publish',
            'numberposts'=>-1,
            'meta_query'=>array(
                array('key'=>'_pdp_release_product','value'=>$product_id,'compare'=>'='),
                array('key'=>'_pdp_release_channel','value'=>in_array($channel,array('stable','beta'),true)?$channel:'stable','compare'=>'='),
                array('key'=>'_pdp_release_published','value'=>'1','compare'=>'='),
            ),
        ));
        $best = 0; $best_version = '';
        foreach ($releases as $release) {
            $version = (string)get_post_meta($release->ID, '_pdp_release_version', true);
            if ($version && (!$best_version || version_compare($version, $best_version, '>'))) { $best = $release->ID; $best_version = $version; }
        }
        if (!$best) return array();
        return array(
            'id'=>$best,
            'version'=>$best_version,
            'channel'=>get_post_meta($best, '_pdp_release_channel', true) ?: 'stable',
            'attachment_id'=>absint(get_post_meta($best, '_pdp_release_attachment', true)),
            'requires_wp'=>get_post_meta($best, '_pdp_release_min_wp', true) ?: '5.8',
            'requires_php'=>get_post_meta($best, '_pdp_release_min_php', true) ?: '7.4',
            'changelog'=>wp_strip_all_tags(get_post_field('post_content', $best)),
        );
    }

    public static function dashboard() {
        if (!current_user_can('manage_options')) return;
        $products = get_posts(array('post_type'=>self::PRODUCT_TYPE,'post_status'=>array('publish','draft'),'numberposts'=>-1));
        $releases = get_posts(array('post_type'=>self::RELEASE_TYPE,'post_status'=>array('publish','draft'),'numberposts'=>-1));
        $licenses = wp_count_posts('pdp_license');
        global $wpdb;
        $active_sites = (int)$wpdb->get_var("SELECT COUNT(*) FROM `".PDP_DB::table('license_activations')."` WHERE status='active'");
        $requests_today = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `".PDP_DB::table('license_events')."` WHERE created_at >= %s", current_time('Y-m-d 00:00:00')));
        echo '<div class="wrap pdp-admin-wrap"><div class="pdp-admin-hero"><div><span class="pdp-admin-kicker">COMMERCIAL DELIVERY CENTER</span><h1>Products & Releases</h1><p>Manage products, publish licensed plugin packages, and monitor update activity.</p></div><div class="pdp-admin-hero-actions"><a class="button" href="'.esc_url(admin_url('edit.php?post_type='.self::PRODUCT_TYPE)).'">Manage Products</a><a class="button button-primary" href="'.esc_url(admin_url('post-new.php?post_type='.self::RELEASE_TYPE)).'">+ Publish Release</a></div></div>';
        echo '<div class="pdp-admin-stats"><article><span>Products</span><strong>'.count($products).'</strong><small>Commercial products configured</small></article><article><span>Releases</span><strong>'.count($releases).'</strong><small>Stable and beta packages</small></article><article><span>Issued licenses</span><strong>'.absint(($licenses->publish ?? 0)+($licenses->draft ?? 0)).'</strong><small>Across all products</small></article><article><span>API activity today</span><strong>'.$requests_today.'</strong><small>'.$active_sites.' active websites</small></article></div>';
        echo '<div class="pdp-admin-card"><div class="pdp-admin-card-head"><div><h2>Latest releases</h2><p>Packages currently available to licensed WordPress sites.</p></div><a class="button" href="'.esc_url(admin_url('edit.php?post_type='.self::RELEASE_TYPE)).'">View all</a></div>';
        if (!$products) echo '<div class="pdp-admin-empty"><h2>No products configured</h2><p>Create a product before publishing a release.</p></div>';
        else { echo '<table class="widefat striped"><thead><tr><th>Product</th><th>Stable</th><th>Beta</th><th>Status</th></tr></thead><tbody>'; foreach($products as $product){ $slug=get_post_meta($product->ID,'_pdp_product_slug',true); $stable=self::latest_release($slug,'stable'); $beta=self::latest_release($slug,'beta'); $active=get_post_meta($product->ID,'_pdp_product_active',true)!=='0'; echo '<tr><td><strong>'.esc_html($product->post_title).'</strong><br><code>'.esc_html($slug).'</code></td><td>'.esc_html($stable['version'] ?? 'Not published').'</td><td>'.esc_html($beta['version'] ?? 'Not published').'</td><td><span class="pdp-status-pill status-'.($active?'active':'disabled').'">'.($active?'Active':'Disabled').'</span></td></tr>'; } echo '</tbody></table>'; }
        echo '</div></div>';
    }

    public static function signed_download_url($license_id, $site, $release_id) {
        $expires = time() + HOUR_IN_SECONDS;
        $payload = absint($license_id).'|'.absint($release_id).'|'.$site.'|'.$expires;
        $signature = hash_hmac('sha256', $payload, wp_salt('auth'));
        return add_query_arg(array('pdp_release_download'=>absint($release_id),'license_id'=>absint($license_id),'site'=>rawurlencode($site),'expires'=>$expires,'signature'=>$signature), home_url('/'));
    }

    public static function handle_download() {
        if (empty($_GET['pdp_release_download'])) return;
        $release_id = absint($_GET['pdp_release_download']);
        $license_id = absint($_GET['license_id'] ?? 0);
        $site = sanitize_text_field(wp_unslash($_GET['site'] ?? ''));
        $expires = absint($_GET['expires'] ?? 0);
        $signature = sanitize_text_field(wp_unslash($_GET['signature'] ?? ''));
        $expected = hash_hmac('sha256', $license_id.'|'.$release_id.'|'.$site.'|'.$expires, wp_salt('auth'));
        if (!$release_id || !$license_id || $expires < time() || !hash_equals($expected, $signature)) wp_die('This secure download link is invalid or has expired.', 'Download unavailable', array('response'=>403));
        $state = PDP_License_Engine::state($license_id);
        if (empty($state['ok'])) wp_die('This license is not active.', 'Download unavailable', array('response'=>403));
        $attachment_id = absint(get_post_meta($release_id, '_pdp_release_attachment', true));
        $path = $attachment_id ? get_attached_file($attachment_id) : '';
        if (!$path || !is_readable($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') wp_die('The release package is not available.', 'Download unavailable', array('response'=>404));
        PDP_License_Engine::audit($license_id, 'release_downloaded', 'Licensed release package downloaded.', array('release_id'=>$release_id,'site_url'=>$site));
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.sanitize_file_name(basename($path)).'"');
        header('Content-Length: '.filesize($path));
        readfile($path); exit;
    }

    public static function product_columns($columns) { return array('cb'=>$columns['cb'],'title'=>'Product','pdp_slug'=>'API Slug','pdp_channel'=>'Default Channel','pdp_active'=>'Status','date'=>'Date'); }
    public static function product_column($column,$post_id) { if($column==='pdp_slug') echo '<code>'.esc_html(get_post_meta($post_id,'_pdp_product_slug',true)).'</code>'; if($column==='pdp_channel') echo esc_html(ucfirst(get_post_meta($post_id,'_pdp_product_channel',true)?:'stable')); if($column==='pdp_active') echo get_post_meta($post_id,'_pdp_product_active',true)!=='0'?'Active':'Disabled'; }
    public static function release_columns($columns) { return array('cb'=>$columns['cb'],'title'=>'Release','pdp_product'=>'Product','pdp_version'=>'Version','pdp_channel'=>'Channel','pdp_package'=>'Package','date'=>'Date'); }
    public static function release_column($column,$post_id) { if($column==='pdp_product') echo esc_html(get_the_title(absint(get_post_meta($post_id,'_pdp_release_product',true)))); if($column==='pdp_version') echo '<code>'.esc_html(get_post_meta($post_id,'_pdp_release_version',true)).'</code>'; if($column==='pdp_channel') echo esc_html(ucfirst(get_post_meta($post_id,'_pdp_release_channel',true)?:'stable')); if($column==='pdp_package') echo absint(get_post_meta($post_id,'_pdp_release_attachment',true))?'Attached':'Missing'; }
}
