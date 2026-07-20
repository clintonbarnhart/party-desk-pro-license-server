<?php
/**
 * Plugin Name: Party Desk Pro License Server
 * Description: Manual license requests, editable plans, Square payment links, licenses, and customer account management without WooCommerce.
 * Version: 3.7.0-alpha7
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Party Desk Pro
 * Text Domain: party-desk-pro-license-server
 */

if (!defined('ABSPATH')) { exit; }

define('PDP_LS_VERSION', '3.7.0-alpha7');
define('PDP_LS_FILE', __FILE__);
define('PDP_LS_PATH', plugin_dir_path(__FILE__));
define('PDP_LS_URL', plugin_dir_url(__FILE__));

require_once PDP_LS_PATH . 'includes/core/class-pdp-autoloader.php';
PDP_Autoloader::register(PDP_LS_PATH . 'includes/core');

require_once plugin_dir_path(__FILE__) . 'includes/class-pdp-db.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pdp-square-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pdp-square-sync.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pdp-subscription-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pdp-webhook-processor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pdp-license-engine.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pdp-license-admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pdp-product-manager.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pdp-platform.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pdp-subscriptions.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-pdp-subscriptions-admin.php';
PDP_DB::init();

final class PDP_License_Server {
    const VERSION = PDP_LS_VERSION;
    const OPT_SETTINGS = 'pdp_ls_settings';
    const OPT_FIELDS = 'pdp_ls_fields';

    public static function init() {
        add_action('init', array(__CLASS__, 'register_types'));
        PDP_License_Engine::init();
        PDP_Product_Manager::init();
        PDP_Platform::init();
        add_action('rest_api_init', array(__CLASS__, 'register_rest_routes'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('add_meta_boxes', array(__CLASS__, 'meta_boxes'));
        add_action('save_post_pdp_plan', array(__CLASS__, 'save_plan'));
        add_action('save_post_pdp_request', array(__CLASS__, 'save_request_admin'));
        add_action('save_post_pdp_license', array(__CLASS__, 'save_license'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_assets'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'front_assets'));
        add_shortcode('party_desk_pro_license_request_form', array(__CLASS__, 'signup_shortcode'));
        add_shortcode('pdpsignup', array(__CLASS__, 'signup_shortcode'));
        add_action('admin_post_pdp_create_square_link', array(__CLASS__, 'create_square_link'));
        add_action('admin_post_pdp_email_square_link', array(__CLASS__, 'email_square_link'));
        add_action('admin_post_pdp_create_license', array(__CLASS__, 'create_license_from_request'));
        add_action('admin_post_pdp_create_trial_license', array(__CLASS__, 'create_trial_license_from_request'));
        add_action('admin_post_pdp_mark_request_paid', array(__CLASS__, 'mark_request_paid'));
        add_action('admin_post_pdp_decline_request', array(__CLASS__, 'decline_request'));
        add_action('admin_post_pdp_email_license', array(__CLASS__, 'email_license'));
        add_action('admin_post_pdp_regenerate_license_key', array(__CLASS__, 'regenerate_license_key'));
        add_action('admin_post_pdp_toggle_license_status', array(__CLASS__, 'toggle_license_status'));
        add_filter('manage_pdp_plan_posts_columns', array(__CLASS__, 'plan_columns'));
        add_action('manage_pdp_plan_posts_custom_column', array(__CLASS__, 'plan_column_data'), 10, 2);
        add_filter('manage_pdp_request_posts_columns', array(__CLASS__, 'request_columns'));
        add_action('manage_pdp_request_posts_custom_column', array(__CLASS__, 'request_column_data'), 10, 2);
        add_filter('manage_pdp_license_posts_columns', array(__CLASS__, 'license_columns'));
        add_action('manage_pdp_license_posts_custom_column', array(__CLASS__, 'license_column_data'), 10, 2);
    }

    public static function activate() {
        add_role('pdp_customer', 'Party Desk Pro Customer', array('read' => true));
        self::register_types();
        PDP_DB::install();
        self::seed_defaults();
        PDP_Product_Manager::activate();
        flush_rewrite_rules();
    }

    public static function deactivate() { flush_rewrite_rules(); }

    private static function seed_defaults() {
        if (!get_option(self::OPT_SETTINGS)) {
            update_option(self::OPT_SETTINGS, array(
                'form_title' => 'Choose Your Party Desk Pro Plan',
                'form_intro' => 'Tell us about your business and select the package that fits your needs.',
                'submit_text' => 'Submit Signup Request',
                'accent' => '#6d5dfc',
                'portal_title' => 'My Account',
                'portal_subtitle' => 'Manage your Party Desk Pro license and subscription.',
                'portal_brand' => 'PARTY DESK PRO',
                'portal_logo' => '',
                'portal_accent' => '#2563eb',
                'portal_header_end' => '#1e3a8a',
                'portal_background' => '#f8fafc',
                'portal_card' => '#ffffff',
                'portal_text' => '#0f172a',
                'portal_muted' => '#64748b',
                'portal_radius' => '24',
                'portal_layout' => 'cards',
                'subscription_label' => 'Current Subscription',
                'status_label' => 'License Status',
                'sites_label' => 'Website Allowance',
                'license_heading' => 'License information',
                'show_subscription' => '1',
                'show_status' => '1',
                'show_sites' => '1',
                'show_business' => '1',
                'show_email' => '1',
                'show_license_key' => '1',
                'show_expiration' => '1',
                'support_label' => 'Contact Support',
                'support_url' => '',
                'square_mode' => 'production',
                'square_token' => '',
                'square_location' => '',
                'square_currency' => 'USD',
                'square_version' => '2026-05-20',
                'payment_email_subject' => 'Your Party Desk Pro payment link',
                'payment_email_intro' => 'Thank you for signing up. Use the secure Square link below to complete payment.',
                'license_prefix' => 'PDP',
                'license_segments' => '4',
                'license_segment_length' => '4',
                'license_include_plan' => '0',
                'license_email_subject' => 'Your Party Desk Pro license is ready',
                'license_email_preheader' => 'Your license, subscription, download, and My Account information are inside.',
                'license_email_heading' => 'Welcome to Party Desk Pro!',
                'license_email_intro' => 'Your Party Desk Pro account has been prepared and your license is ready to activate.',
                'license_email_button' => 'Open My Account',
                'license_email_download_button' => 'Download Party Desk Pro',
                'license_email_support_text' => 'Need help getting started? Reply to this email or visit your support page.',
                'license_email_footer' => 'Thank you for choosing Party Desk Pro. We are excited to help you manage and grow your party business.',
                'license_email_from_name' => 'Party Desk Pro',
                'license_email_reply_to' => get_option('admin_email'),
                'license_email_accent' => '#2563eb',
                'license_email_accent_end' => '#6d5dfc',
                'license_email_show_subscription' => '1',
                'license_email_show_activation' => '1',
                'license_email_show_download' => '1',
                'license_email_show_account' => '1',
                'license_zip_attachment_id' => '0',
                'license_zip_version' => '',
                'license_changelog' => '',
                'license_auto_email' => '1',
                'license_setup_instructions' => "1. Download the Party Desk Pro ZIP file.\n2. In WordPress, go to Plugins > Add New > Upload Plugin.\n3. Upload the ZIP file and activate Party Desk Pro.\n4. Open Party Desk Pro > License.\n5. Enter the license server URL and your license key, then click Activate License.",
                'portal_download_heading' => 'Download Party Desk Pro',
                'portal_download_text' => 'Download the latest Party Desk Pro plugin ZIP file included with your subscription.',
                'portal_download_button' => 'Download Plugin ZIP',
                'portal_page_url' => home_url('/my-account/'),
            ));
        }
        if (!get_option(self::OPT_FIELDS)) {
            update_option(self::OPT_FIELDS, self::default_fields());
        }
        $existing = get_posts(array('post_type'=>'pdp_plan','post_status'=>'any','numberposts'=>1));
        if (!$existing) {
            $plans = array(
                array('Trial','Try Party Desk Pro free.','0','trial','14','1'),
                array('Starter','Essential tools for growing businesses.','39','month','0','1'),
                array('Professional','Advanced tools, portals, and automation.','79','month','0','3'),
                array('Premium','Maximum features and priority support.','149','month','0','10'),
            );
            foreach ($plans as $i => $p) {
                $id = wp_insert_post(array('post_type'=>'pdp_plan','post_status'=>'publish','post_title'=>$p[0],'menu_order'=>$i));
                if ($id && !is_wp_error($id)) {
                    update_post_meta($id,'_pdp_description',$p[1]);
                    update_post_meta($id,'_pdp_price',$p[2]);
                    update_post_meta($id,'_pdp_billing',$p[3]);
                    update_post_meta($id,'_pdp_trial_days',$p[4]);
                    update_post_meta($id,'_pdp_sites',$p[5]);
                    update_post_meta($id,'_pdp_active','1');
                    update_post_meta($id,'_pdp_features',"Booking CRM\nCustomer portal\nEmail automation\nLicense updates");
                }
            }
        }
    }

    private static function default_fields() {
        return array(
            'business_name'=>array('label'=>'Business Name','placeholder'=>'Your business name','show'=>1,'required'=>1,'type'=>'text','section'=>'business'),
            'website'=>array('label'=>'Business Website','placeholder'=>'https://example.com','show'=>1,'required'=>1,'type'=>'url','section'=>'business'),
            'business_info'=>array('label'=>'Tell Us About Your Business','placeholder'=>'What services do you offer?','show'=>1,'required'=>0,'type'=>'textarea','section'=>'business'),
            'contact_name'=>array('label'=>'Contact Name','placeholder'=>'Your full name','show'=>1,'required'=>1,'type'=>'text','section'=>'contact'),
            'email'=>array('label'=>'Email Address','placeholder'=>'you@example.com','show'=>1,'required'=>1,'type'=>'email','section'=>'contact'),
            'phone'=>array('label'=>'Phone Number','placeholder'=>'(555) 555-5555','show'=>1,'required'=>0,'type'=>'tel','section'=>'contact'),
        );
    }

    public static function register_types() {
        register_post_type('pdp_plan', array(
            'labels'=>array('name'=>'Plans','singular_name'=>'Plan','add_new_item'=>'Add New Plan','edit_item'=>'Edit Plan'),
            'public'=>false,'show_ui'=>true,'show_in_menu'=>false,'supports'=>array('title','page-attributes'),'capability_type'=>'post','map_meta_cap'=>true,
        ));
        register_post_type('pdp_request', array(
            'labels'=>array('name'=>'License Requests','singular_name'=>'License Request','edit_item'=>'Review License Request'),
            'public'=>false,'show_ui'=>true,'show_in_menu'=>false,'supports'=>array('title'),'capability_type'=>'post','map_meta_cap'=>true,
        ));
        register_post_type('pdp_license', array(
            'labels'=>array('name'=>'Licenses','singular_name'=>'License','add_new_item'=>'Add License','edit_item'=>'Edit License'),
            'public'=>false,'show_ui'=>true,'show_in_menu'=>false,'supports'=>array('title'),'capability_type'=>'post','map_meta_cap'=>true,
        ));
    }

    public static function admin_menu() {
        add_menu_page('Party Desk Pro','Party Desk Pro','manage_options','pdp-dashboard',array(__CLASS__,'dashboard'),'dashicons-tickets-alt',26);
        add_submenu_page('pdp-dashboard','Dashboard','Dashboard','manage_options','pdp-dashboard',array(__CLASS__,'dashboard'));
        add_submenu_page('pdp-dashboard','Plans','Plans','manage_options','pdp-plans',array(__CLASS__,'plans_admin_page'));
        add_submenu_page('pdp-dashboard','License Requests','License Requests','manage_options','pdp-license-requests',array(__CLASS__,'requests_admin_page'));
        add_submenu_page('pdp-dashboard','Licenses','Licenses','manage_options','pdp-licenses',array(__CLASS__,'licenses_admin_page'));
        add_submenu_page('pdp-dashboard','License Activity','License Activity','manage_options','pdp-license-activity',array('PDP_License_Admin','activity_page')); 
        add_submenu_page('pdp-dashboard','Signup Form Builder','Signup Form Builder','manage_options','pdp-form-builder',array(__CLASS__,'form_builder'));
        add_submenu_page('pdp-dashboard','My Account Builder','My Account','manage_options','pdp-portal-settings',array(__CLASS__,'portal_settings'));
        add_submenu_page('pdp-dashboard','Subscriptions','Subscriptions','manage_options','pdp-subscriptions',array('PDP_Subscriptions_Admin','subscriptions_page'));
        add_submenu_page('pdp-dashboard','Square Subscriptions','Square Subscriptions','manage_options','pdp-square-subscriptions',array('PDP_Subscriptions_Admin','square_settings_page'));
        add_submenu_page('pdp-dashboard','Webhook Logs','Webhook Logs','manage_options','pdp-webhook-logs',array('PDP_Subscriptions_Admin','webhook_logs_page'));
        add_submenu_page('pdp-dashboard','Square Payment Links','Square Payment Links','manage_options','pdp-square-settings',array(__CLASS__,'square_settings'));
        add_submenu_page('pdp-dashboard','License Settings','License Settings','manage_options','pdp-license-settings',array(__CLASS__,'license_settings'));
        add_submenu_page('pdp-dashboard','License Email Template','Email Template','manage_options','pdp-license-email-template',array(__CLASS__,'license_email_template'));
        add_submenu_page('pdp-dashboard','Database Health','Database Health','manage_options','pdp-database-health',array(__CLASS__,'database_health_page'));
    }

    public static function admin_assets($hook) {
        if (isset($_GET['page']) && in_array(sanitize_key(wp_unslash($_GET['page'])), array('pdp-license-settings','pdp-license-email-template'), true)) { wp_enqueue_media(); }
        // The portal preview is rendered inside wp-admin, so it needs both the
        // public portal styles and the admin builder styles. Loading front.css
        // here prevents WordPress' global admin SVG and typography rules from
        // breaking the live preview.
        wp_enqueue_style('pdp-front-preview', plugin_dir_url(__FILE__).'assets/css/front.css', array(), self::VERSION);
        wp_enqueue_style('pdp-admin', plugin_dir_url(__FILE__).'assets/css/admin.css', array('pdp-front-preview'), self::VERSION);
        wp_enqueue_script('pdp-admin', plugin_dir_url(__FILE__).'assets/js/admin.js', array('jquery'), self::VERSION, true);
    }
    public static function front_assets() {
        $css_path = plugin_dir_path(__FILE__) . 'assets/css/front.css';
        $js_path  = plugin_dir_path(__FILE__) . 'assets/js/front.js';
        $css_ver  = file_exists($css_path) ? (string) filemtime($css_path) : self::VERSION;
        $js_ver   = file_exists($js_path) ? (string) filemtime($js_path) : self::VERSION;
        wp_enqueue_style('pdp-front', plugin_dir_url(__FILE__) . 'assets/css/front.css', array(), $css_ver);
        wp_enqueue_script('pdp-front', plugin_dir_url(__FILE__) . 'assets/js/front.js', array('jquery'), $js_ver, true);
    }

    public static function dashboard() {
        if (!current_user_can('manage_options')) return;
        $plans = get_posts(array('post_type'=>'pdp_plan','post_status'=>array('publish','draft'),'numberposts'=>-1));
        $requests = get_posts(array('post_type'=>'pdp_request','post_status'=>'publish','numberposts'=>-1,'orderby'=>'date','order'=>'DESC'));
        $licenses = get_posts(array('post_type'=>'pdp_license','post_status'=>'publish','numberposts'=>-1,'orderby'=>'date','order'=>'DESC'));
        $active=$suspended=$expiring=$sites=0; $customers=array(); $revenue=0;
        foreach($licenses as $license){
            $status=strtolower((string)get_post_meta($license->ID,'_pdp_status',true));
            if($status==='active')$active++; if($status==='suspended')$suspended++;
            $expires=get_post_meta($license->ID,'_pdp_expires',true); if($expires && strtotime($expires)>=time() && strtotime($expires)<=strtotime('+30 days'))$expiring++;
            $sites+=max(0,(int)get_post_meta($license->ID,'_pdp_activations',true));
            $email=strtolower((string)get_post_meta($license->ID,'_pdp_email',true)); if($email)$customers[$email]=1;
            $price=(string)get_post_meta($license->ID,'_pdp_price',true); if(preg_match('/([0-9]+(?:\.[0-9]+)?)/',$price,$m))$revenue+=(float)$m[1];
        }
        $new_requests=0;$awaiting=0;foreach($requests as $request){$st=get_post_meta($request->ID,'_pdp_status',true)?:'New';if($st==='New')$new_requests++;if($st==='Payment Link Sent')$awaiting++;}
        $zip_ok=(bool)self::license_zip_path();
        echo '<div class="wrap pdp-admin pdp-modern-admin pdp-dashboard-pro">';
        echo '<div class="pdp-admin-hero pdp-dashboard-hero"><div><span class="pdp-admin-kicker">LICENSE BUSINESS COMMAND CENTER</span><h1>Party Desk Pro Dashboard</h1><p>See customer growth, license health, pending sales, and the next actions that need your attention.</p></div><div class="pdp-admin-hero-actions"><a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page=pdp-license-email-template')).'">Customize License Email</a><a class="button button-primary" href="'.esc_url(admin_url('post-new.php?post_type=pdp_license')).'">+ Create License</a></div></div>';
        echo '<div class="pdp-dashboard-kpis">';
        $kpis=array(
            array('Active licenses',$active,'Customers currently licensed','dashicons-yes-alt','success'),
            array('Customers',count($customers),'Unique customer accounts','dashicons-groups','primary'),
            array('New requests',$new_requests,'Waiting for your review','dashicons-clipboard','warning'),
            array('Monthly value','$'.number_format($revenue,2),'Estimated from license pricing','dashicons-chart-line','purple'),
            array('Expiring soon',$expiring,'Within the next 30 days','dashicons-clock','danger'),
            array('Active websites',$sites,'Reported license activations','dashicons-admin-site-alt3','cyan')
        );
        foreach($kpis as $k)echo '<article class="pdp-dashboard-kpi is-'.$k[4].'"><div class="pdp-dashboard-kpi-icon"><span class="dashicons '.$k[3].'"></span></div><div><span>'.$k[0].'</span><strong>'.$k[1].'</strong><small>'.$k[2].'</small></div></article>';
        echo '</div>';
        echo '<div class="pdp-dashboard-grid"><main>';
        echo '<section class="pdp-dashboard-panel"><div class="pdp-dashboard-panel-head"><div><span class="pdp-admin-kicker">RECENT ACTIVITY</span><h2>Latest license requests</h2><p>Review new customers and keep onboarding moving.</p></div><a href="'.esc_url(admin_url('admin.php?page=pdp-license-requests')).'">View all requests →</a></div>';
        if(!$requests){echo '<div class="pdp-dashboard-empty"><span class="dashicons dashicons-clipboard"></span><strong>No license requests yet</strong><p>Your newest signup submissions will appear here.</p></div>';}else{echo '<div class="pdp-dashboard-list">';foreach(array_slice($requests,0,5) as $r){$business=get_post_meta($r->ID,'_pdp_business_name',true)?:$r->post_title;$email=get_post_meta($r->ID,'_pdp_email',true);$status=get_post_meta($r->ID,'_pdp_status',true)?:'New';$plan=get_the_title((int)get_post_meta($r->ID,'_pdp_plan_id',true));echo '<a class="pdp-dashboard-row" href="'.esc_url(get_edit_post_link($r->ID,'')).'"><span class="pdp-customer-avatar">'.esc_html(strtoupper(substr($business,0,1))).'</span><span class="pdp-dashboard-row-main"><strong>'.esc_html($business).'</strong><small>'.esc_html($email).'</small></span><span class="pdp-dashboard-row-plan">'.esc_html($plan?:'No plan').'</span><span class="pdp-status-pill status-'.esc_attr(self::admin_status_class($status)).'">'.esc_html($status).'</span><span class="dashicons dashicons-arrow-right-alt2"></span></a>';}echo '</div>';}
        echo '</section>';
        echo '<section class="pdp-dashboard-panel"><div class="pdp-dashboard-panel-head"><div><span class="pdp-admin-kicker">LICENSE HEALTH</span><h2>Recently created licenses</h2><p>Quickly open, email, or review your newest customer licenses.</p></div><a href="'.esc_url(admin_url('admin.php?page=pdp-licenses')).'">View all licenses →</a></div>';
        if(!$licenses){echo '<div class="pdp-dashboard-empty"><span class="dashicons dashicons-admin-network"></span><strong>No licenses created yet</strong><p>Create a license manually or approve a customer request.</p></div>';}else{echo '<div class="pdp-dashboard-list">';foreach(array_slice($licenses,0,5) as $l){$business=get_post_meta($l->ID,'_pdp_business',true)?:$l->post_title;$email=get_post_meta($l->ID,'_pdp_email',true);$status=get_post_meta($l->ID,'_pdp_status',true)?:'Pending';$plan=get_post_meta($l->ID,'_pdp_plan',true);echo '<a class="pdp-dashboard-row" href="'.esc_url(get_edit_post_link($l->ID,'')).'"><span class="pdp-customer-avatar">'.esc_html(strtoupper(substr($business,0,1))).'</span><span class="pdp-dashboard-row-main"><strong>'.esc_html($business).'</strong><small>'.esc_html($email).'</small></span><span class="pdp-dashboard-row-plan">'.esc_html($plan?:'No plan').'</span><span class="pdp-status-pill status-'.esc_attr(self::admin_status_class($status)).'">'.esc_html($status).'</span><span class="dashicons dashicons-arrow-right-alt2"></span></a>';}echo '</div>';}
        echo '</section></main><aside>';
        echo '<section class="pdp-dashboard-panel pdp-dashboard-setup"><div class="pdp-dashboard-panel-head"><div><span class="pdp-admin-kicker">SYSTEM READINESS</span><h2>Setup status</h2><p>Keep your customer delivery workflow ready.</p></div></div>';
        $checks=array(
          array(count($plans)>0,'Subscription plans','Create the packages customers can choose.','pdp-plans'),
          array($zip_ok,'Plugin download ZIP','Upload the latest Party Desk Pro plugin file.','pdp-license-settings'),
          array(!empty(get_option(self::OPT_SETTINGS,array())['square_token']),'Square connection','Add Square credentials for payment links.','pdp-square-settings'),
          array(!empty(get_option(self::OPT_SETTINGS,array())['portal_page_url']),'My Account page','Connect the customer My Account page.','pdp-portal-settings'),
          array(!empty(get_option(self::OPT_SETTINGS,array())['license_email_heading']),'License email template','Customize the customer delivery email.','pdp-license-email-template')
        );
        foreach($checks as $c)echo '<a class="pdp-dashboard-check '.($c[0]?'is-complete':'').'" href="'.esc_url(admin_url('admin.php?page='.$c[3])).'"><span class="dashicons '.($c[0]?'dashicons-yes-alt':'dashicons-marker').'"></span><span><strong>'.$c[1].'</strong><small>'.$c[2].'</small></span><span class="dashicons dashicons-arrow-right-alt2"></span></a>';
        echo '</section>';
        echo '<section class="pdp-dashboard-panel pdp-dashboard-quick"><div class="pdp-dashboard-panel-head"><div><span class="pdp-admin-kicker">QUICK ACTIONS</span><h2>Common tasks</h2></div></div><div class="pdp-dashboard-quick-grid"><a href="'.esc_url(admin_url('post-new.php?post_type=pdp_license')).'"><span class="dashicons dashicons-plus-alt2"></span><strong>Create license</strong><small>Add a customer manually</small></a><a href="'.esc_url(admin_url('admin.php?page=pdp-license-requests')).'"><span class="dashicons dashicons-clipboard"></span><strong>Review requests</strong><small>'.$new_requests.' waiting</small></a><a href="'.esc_url(admin_url('admin.php?page=pdp-license-email-template')).'"><span class="dashicons dashicons-email-alt"></span><strong>Edit email</strong><small>Customer delivery template</small></a><a href="'.esc_url(admin_url('admin.php?page=pdp-form-builder')).'"><span class="dashicons dashicons-feedback"></span><strong>Signup builder</strong><small>Update onboarding form</small></a></div></section>';
        echo '<section class="pdp-dashboard-panel pdp-dashboard-alerts"><div class="pdp-dashboard-panel-head"><div><span class="pdp-admin-kicker">ATTENTION NEEDED</span><h2>License alerts</h2></div></div><div class="pdp-dashboard-alert"><span class="dashicons dashicons-clock"></span><div><strong>'.$expiring.' expiring soon</strong><small>Licenses ending within 30 days</small></div><a href="'.esc_url(admin_url('admin.php?page=pdp-licenses&license_status=Active')).'">Review</a></div><div class="pdp-dashboard-alert"><span class="dashicons dashicons-warning"></span><div><strong>'.$suspended.' suspended licenses</strong><small>Customers without active access</small></div><a href="'.esc_url(admin_url('admin.php?page=pdp-licenses&license_status=Suspended')).'">Review</a></div><div class="pdp-dashboard-alert"><span class="dashicons dashicons-money-alt"></span><div><strong>'.$awaiting.' awaiting payment</strong><small>Payment links already delivered</small></div><a href="'.esc_url(admin_url('admin.php?page=pdp-license-requests&request_status=Payment+Link+Sent')).'">Review</a></div></section>';
        echo '</aside></div></div>';
    }

    private static function billing_label($billing) {
        $labels = array(
            'month' => 'Monthly',
            '6-months' => 'Every 6 Months',
            'year' => 'Yearly',
            'one-time' => 'One Time',
            'trial' => 'Free Trial',
        );
        return isset($labels[$billing]) ? $labels[$billing] : ucfirst(str_replace('-', ' ', (string) $billing));
    }

    private static function billing_price_suffix($billing) {
        $suffixes = array(
            'month' => '/month',
            '6-months' => '/6 months',
            'year' => '/year',
            'one-time' => ' one time',
            'trial' => '',
        );
        return isset($suffixes[$billing]) ? $suffixes[$billing] : '/' . trim((string) $billing);
    }

    private static function admin_status_class($status) {
        $key = sanitize_html_class(strtolower(str_replace(array(' ', '_'), '-', (string) $status)));
        return $key ?: 'new';
    }

    private static function site_limit_value($value, $default = 1) {
        if ($value === '' || $value === null) return absint($default);
        return absint($value); // 0 means unlimited.
    }

    private static function site_limit_label($value, $compact = false) {
        $limit = self::site_limit_value($value);
        if ($limit === 0) return $compact ? 'Unlimited' : 'Unlimited websites';
        return $limit . ' website' . ($limit === 1 ? '' : 's');
    }

    public static function plans_admin_page() {
        if (!current_user_can('manage_options')) return;
        $plans = get_posts(array('post_type'=>'pdp_plan','post_status'=>array('publish','draft'),'numberposts'=>-1,'orderby'=>array('menu_order'=>'ASC','title'=>'ASC')));
        $active=0; $featured=0; $monthly=0;
        foreach($plans as $plan){
            if(get_post_meta($plan->ID,'_pdp_active',true)==='1') $active++;
            if(get_post_meta($plan->ID,'_pdp_featured',true)==='1') $featured++;
            if(get_post_meta($plan->ID,'_pdp_billing',true)==='month') $monthly++;
        }
        $add=admin_url('post-new.php?post_type=pdp_plan');
        echo '<div class="wrap pdp-admin pdp-modern-admin"><div class="pdp-admin-hero"><div><span class="pdp-admin-kicker">SUBSCRIPTION CATALOG</span><h1>Plans</h1><p>Create and manage the packages customers select during signup.</p></div><div class="pdp-admin-hero-actions"><a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page=pdp-form-builder')).'">Open Signup Builder</a><a class="button button-primary" href="'.esc_url($add).'">+ Add New Plan</a></div></div>';
        echo '<div class="pdp-admin-stats"><article><span>Total plans</span><strong>'.count($plans).'</strong><small>Published and draft packages</small></article><article><span>Visible on signup</span><strong>'.$active.'</strong><small>Plans customers can choose</small></article><article><span>Featured plans</span><strong>'.$featured.'</strong><small>Highlighted recommendations</small></article><article><span>Monthly plans</span><strong>'.$monthly.'</strong><small>Recurring monthly packages</small></article></div>';
        if(!$plans){ echo '<div class="pdp-admin-empty"><div class="dashicons dashicons-tickets-alt"></div><h2>No plans created yet</h2><p>Create your first subscription package to begin accepting signup requests.</p><a class="button button-primary button-hero" href="'.esc_url($add).'">Create First Plan</a></div></div>'; return; }
        echo '<div class="pdp-plans-toolbar"><div><h2>Your subscription plans</h2><p>Pricing, website allowance, trial settings, and signup visibility at a glance.</p></div><span>'.count($plans).' plan'.(count($plans)===1?'':'s').'</span></div><div class="pdp-plan-admin-grid">';
        foreach($plans as $plan){
            $price=(float)get_post_meta($plan->ID,'_pdp_price',true); $billing=get_post_meta($plan->ID,'_pdp_billing',true) ?: 'month';
            $sites=self::site_limit_value(get_post_meta($plan->ID,'_pdp_sites',true)); $trial=(int)get_post_meta($plan->ID,'_pdp_trial_days',true);
            $activePlan=get_post_meta($plan->ID,'_pdp_active',true)==='1'; $featuredPlan=get_post_meta($plan->ID,'_pdp_featured',true)==='1';
            $description=get_post_meta($plan->ID,'_pdp_description',true); $features=array_values(array_filter(array_map('trim',preg_split('/\r\n|\r|\n/',(string)get_post_meta($plan->ID,'_pdp_features',true)))));
            $edit=get_edit_post_link($plan->ID,'');
            echo '<article class="pdp-plan-admin-card'.($featuredPlan?' is-featured':'').'"><div class="pdp-plan-card-top"><div><div class="pdp-plan-card-badges">'.($featuredPlan?'<span class="pdp-badge featured">Featured</span>':'').'<span class="pdp-badge '.($activePlan?'active':'hidden').'">'.($activePlan?'Visible':'Hidden').'</span></div><h3>'.esc_html($plan->post_title).'</h3><p>'.esc_html($description ?: 'No plan description has been added yet.').'</p></div><a class="pdp-icon-action" href="'.esc_url($edit).'" aria-label="Edit '.esc_attr($plan->post_title).'"><span class="dashicons dashicons-edit"></span></a></div>';
            echo '<div class="pdp-plan-price"><strong>$'.esc_html(number_format($price,2)).'</strong><span>'.self::billing_price_suffix($billing).'</span></div>';
            echo '<div class="pdp-plan-facts"><div><span class="dashicons dashicons-admin-site-alt3"></span><strong>'.esc_html(self::site_limit_label($sites,true)).'</strong><small>Activation limit</small></div><div><span class="dashicons dashicons-calendar-alt"></span><strong>'.($trial>0?$trial:'—').'</strong><small>Trial days</small></div><div><span class="dashicons dashicons-update"></span><strong>'.esc_html(self::billing_label($billing)).'</strong><small>Billing</small></div></div>';
            if($features){ echo '<ul class="pdp-plan-feature-list">'; foreach(array_slice($features,0,4) as $feature) echo '<li><span class="dashicons dashicons-yes-alt"></span>'.esc_html($feature).'</li>'; if(count($features)>4) echo '<li class="more">+'.(count($features)-4).' more features</li>'; echo '</ul>'; }
            echo '<div class="pdp-plan-card-actions"><a class="button button-primary" href="'.esc_url($edit).'">Edit Plan</a><a class="button" href="'.esc_url(get_delete_post_link($plan->ID,'','trash')).'">Move to Trash</a></div></article>';
        }
        echo '</div></div>';
    }

    public static function requests_admin_page() {
        if (!current_user_can('manage_options')) return;
        $selected=isset($_GET['request_status'])?sanitize_text_field(wp_unslash($_GET['request_status'])):'';
        $args=array('post_type'=>'pdp_request','post_status'=>'publish','numberposts'=>-1,'orderby'=>'date','order'=>'DESC');
        if($selected!=='') $args['meta_query']=array(array('key'=>'_pdp_status','value'=>$selected));
        $requests=get_posts($args);
        $all=get_posts(array('post_type'=>'pdp_request','post_status'=>'publish','numberposts'=>-1,'fields'=>'ids'));
        $counts=array('New'=>0,'Payment Link Sent'=>0,'Paid'=>0,'License Created'=>0,'Declined'=>0);
        foreach($all as $id){ $st=get_post_meta($id,'_pdp_status',true) ?: 'New'; if(!isset($counts[$st]))$counts[$st]=0; $counts[$st]++; }
        echo '<div class="wrap pdp-admin pdp-modern-admin"><div class="pdp-admin-hero"><div><span class="pdp-admin-kicker">CUSTOMER ONBOARDING</span><h1>License Requests</h1><p>Review signup submissions, collect payment, and create customer licenses from one organized queue.</p></div><div class="pdp-admin-hero-actions"><a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page=pdp-square-settings')).'">Square Settings</a><a class="button button-primary" href="'.esc_url(admin_url('admin.php?page=pdp-form-builder')).'">Signup Form Builder</a></div></div>';
        echo '<div class="pdp-admin-stats request-stats"><article><span>All requests</span><strong>'.count($all).'</strong><small>Total signup submissions</small></article><article><span>New</span><strong>'.intval($counts['New']).'</strong><small>Waiting for review</small></article><article><span>Awaiting payment</span><strong>'.intval($counts['Payment Link Sent']).'</strong><small>Payment links delivered</small></article><article><span>Completed</span><strong>'.intval($counts['License Created']).'</strong><small>Licenses successfully created</small></article></div>';
        echo '<div class="pdp-request-toolbar"><div class="pdp-request-filters"><a class="'.($selected===''?'is-active':'').'" href="'.esc_url(admin_url('admin.php?page=pdp-license-requests')).'">All <b>'.count($all).'</b></a>';
        foreach(array('New','Payment Link Sent','Paid','License Created','Declined') as $st){ echo '<a class="'.($selected===$st?'is-active':'').'" href="'.esc_url(add_query_arg(array('page'=>'pdp-license-requests','request_status'=>$st),admin_url('admin.php'))).'">'.esc_html($st).' <b>'.intval($counts[$st]??0).'</b></a>'; }
        echo '</div></div>';
        if(!$requests){ echo '<div class="pdp-admin-empty"><div class="dashicons dashicons-clipboard"></div><h2>No matching requests</h2><p>New customer signup requests will appear here automatically.</p></div></div>'; return; }
        echo '<div class="pdp-request-table-wrap"><table class="pdp-request-table"><thead><tr><th>Customer</th><th>Plan</th><th>Amount</th><th>Payment</th><th>Status</th><th>Submitted</th><th></th></tr></thead><tbody>';
        foreach($requests as $r){
            $business=get_post_meta($r->ID,'_pdp_business_name',true) ?: $r->post_title; $name=get_post_meta($r->ID,'_pdp_contact_name',true); $email=get_post_meta($r->ID,'_pdp_email',true); $website=get_post_meta($r->ID,'_pdp_website',true);
            $plan_id=(int)get_post_meta($r->ID,'_pdp_plan_id',true); $plan=$plan_id?get_the_title($plan_id):'Not selected'; $amount=(float)get_post_meta($r->ID,'_pdp_amount',true); $status=get_post_meta($r->ID,'_pdp_status',true) ?: 'New'; $square=get_post_meta($r->ID,'_pdp_square_url',true);
            $edit=get_edit_post_link($r->ID,'');
            echo '<tr><td><div class="pdp-customer-cell"><span class="pdp-customer-avatar">'.esc_html(strtoupper(substr($business,0,1))).'</span><div><strong>'.esc_html($business).'</strong><small>'.esc_html($name).($name&&$email?' · ':'').esc_html($email).'</small>'.($website?'<a href="'.esc_url($website).'" target="_blank" rel="noopener">'.esc_html(wp_parse_url($website,PHP_URL_HOST) ?: $website).'</a>':'').'</div></div></td>';
            echo '<td><strong>'.esc_html($plan).'</strong><small>'.esc_html(self::site_limit_label(get_post_meta($plan_id,'_pdp_sites',true))).' allowance</small></td><td><strong>$'.esc_html(number_format($amount,2)).'</strong></td>';
            echo '<td>'.($square?'<span class="pdp-payment-ready"><span class="dashicons dashicons-yes-alt"></span> Link created</span><a href="'.esc_url($square).'" target="_blank" rel="noopener">Open link</a>':'<span class="pdp-payment-missing">Not created</span>').'</td>';
            echo '<td><span class="pdp-request-status '.esc_attr(self::admin_status_class($status)).'">'.esc_html($status).'</span></td><td><strong>'.esc_html(get_the_date('M j, Y',$r)).'</strong><small>'.esc_html(get_the_time('g:i A',$r)).'</small></td><td><a class="button button-primary" href="'.esc_url($edit).'">Review</a></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

    public static function licenses_admin_page() {
        if (!current_user_can('manage_options')) return;

        $selected = isset($_GET['license_status']) ? sanitize_text_field(wp_unslash($_GET['license_status'])) : '';
        $search = isset($_GET['license_search']) ? sanitize_text_field(wp_unslash($_GET['license_search'])) : '';
        $all = get_posts(array('post_type'=>'pdp_license','post_status'=>array('publish','draft'),'numberposts'=>-1,'orderby'=>'date','order'=>'DESC'));
        $counts = array('Active'=>0,'Pending'=>0,'Suspended'=>0,'Expired'=>0);
        $expiring = 0; $activations_total = 0;
        $today = current_time('timestamp');
        foreach($all as $license){
            $status = get_post_meta($license->ID,'_pdp_status',true) ?: 'Pending';
            if(isset($counts[$status])) $counts[$status]++;
            $expires = get_post_meta($license->ID,'_pdp_expires',true);
            if($expires){ $ts = strtotime($expires.' 23:59:59'); if($ts >= $today && $ts <= strtotime('+30 days',$today)) $expiring++; }
            $acts = get_post_meta($license->ID,'_pdp_activations',true); if(is_array($acts)) $activations_total += count($acts);
        }

        $licenses = array_values(array_filter($all,function($license) use ($selected,$search){
            $status = get_post_meta($license->ID,'_pdp_status',true) ?: 'Pending';
            if($selected && $status !== $selected) return false;
            if($search){
                $haystack = implode(' ',array(
                    $license->post_title,
                    get_post_meta($license->ID,'_pdp_business',true),
                    get_post_meta($license->ID,'_pdp_email',true),
                    get_post_meta($license->ID,'_pdp_key',true),
                    get_post_meta($license->ID,'_pdp_plan',true),
                    get_post_meta($license->ID,'_pdp_website',true),
                ));
                if(stripos($haystack,$search)===false) return false;
            }
            return true;
        }));

        $add = admin_url('post-new.php?post_type=pdp_license');
        echo '<div class="wrap pdp-admin pdp-modern-admin pdp-licenses-admin"><div class="pdp-admin-hero"><div><span class="pdp-admin-kicker">LICENSE MANAGEMENT</span><h1>Licenses</h1><p>Manage customer access, subscription details, activations, expirations, and license delivery from one professional workspace.</p></div><div class="pdp-admin-hero-actions"><a class="button button-secondary" href="'.esc_url(admin_url('admin.php?page=pdp-license-requests')).'">License Requests</a><a class="button button-primary" href="'.esc_url($add).'">+ Create License</a></div></div>';
        echo '<div class="pdp-admin-stats pdp-license-stats"><article><span>Total licenses</span><strong>'.count($all).'</strong><small>All customer licenses</small></article><article><span>Active</span><strong>'.intval($counts['Active']).'</strong><small>Currently authorized</small></article><article><span>Expiring soon</span><strong>'.intval($expiring).'</strong><small>Within the next 30 days</small></article><article><span>Active websites</span><strong>'.intval($activations_total).'</strong><small>Registered installations</small></article></div>';

        echo '<div class="pdp-license-toolbar"><div class="pdp-request-filters"><a class="'.($selected===''?'is-active':'').'" href="'.esc_url(admin_url('admin.php?page=pdp-licenses')).'">All <b>'.count($all).'</b></a>';
        foreach(array('Active','Pending','Suspended','Expired') as $st){ echo '<a class="'.($selected===$st?'is-active':'').'" href="'.esc_url(add_query_arg(array('page'=>'pdp-licenses','license_status'=>$st),admin_url('admin.php'))).'">'.esc_html($st).' <b>'.intval($counts[$st]).'</b></a>'; }
        echo '</div><form method="get" class="pdp-license-search"><input type="hidden" name="page" value="pdp-licenses">'.($selected?'<input type="hidden" name="license_status" value="'.esc_attr($selected).'">':'').'<input type="search" name="license_search" value="'.esc_attr($search).'" placeholder="Search business, email, key, or plan"><button class="button" type="submit">Search</button>'.($search?'<a class="button button-link" href="'.esc_url(add_query_arg(array('page'=>'pdp-licenses','license_status'=>$selected),admin_url('admin.php'))).'">Clear</a>':'').'</form></div>';

        if(!$licenses){ echo '<div class="pdp-admin-empty"><div class="dashicons dashicons-admin-network"></div><h2>No matching licenses</h2><p>Try another status or search term, or create a new customer license.</p><a class="button button-primary button-hero" href="'.esc_url($add).'">Create License</a></div></div>'; return; }

        echo '<div class="pdp-license-table-wrap"><table class="pdp-license-table"><thead><tr><th>Customer</th><th>Subscription</th><th>License key</th><th>Activations</th><th>Expiration</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
        foreach($licenses as $license){
            $business = get_post_meta($license->ID,'_pdp_business',true) ?: $license->post_title;
            $email = get_post_meta($license->ID,'_pdp_email',true);
            $website = get_post_meta($license->ID,'_pdp_website',true);
            $plan = get_post_meta($license->ID,'_pdp_plan',true) ?: 'No plan';
            $price = get_post_meta($license->ID,'_pdp_price',true);
            $key = get_post_meta($license->ID,'_pdp_key',true);
            $status = get_post_meta($license->ID,'_pdp_status',true) ?: 'Pending';
            $sites = self::site_limit_value(get_post_meta($license->ID,'_pdp_sites',true));
            $acts = get_post_meta($license->ID,'_pdp_activations',true); $used = is_array($acts)?count($acts):0;
            $expires = get_post_meta($license->ID,'_pdp_expires',true);
            $expiry_text = $expires ? date_i18n('M j, Y',strtotime($expires)) : 'No expiration';
            $expiry_class = '';
            if($expires){ $ts=strtotime($expires.' 23:59:59'); if($ts<$today)$expiry_class=' is-expired'; elseif($ts<=strtotime('+30 days',$today))$expiry_class=' is-soon'; }
            $edit = get_edit_post_link($license->ID,'');
            $base = admin_url('admin-post.php');
            $email_url = wp_nonce_url(add_query_arg(array('action'=>'pdp_email_license','license_id'=>$license->ID),$base),'pdp_email_license_'.$license->ID);
            $toggle_url = wp_nonce_url(add_query_arg(array('action'=>'pdp_toggle_license_status','license_id'=>$license->ID),$base),'pdp_toggle_status_'.$license->ID);
            echo '<tr><td><div class="pdp-customer-cell"><span class="pdp-customer-avatar">'.esc_html(strtoupper(substr($business,0,1))).'</span><div><strong>'.esc_html($business).'</strong><small>'.esc_html($email ?: 'No email saved').'</small>'.($website?'<a href="'.esc_url($website).'" target="_blank" rel="noopener">'.esc_html(wp_parse_url($website,PHP_URL_HOST) ?: $website).'</a>':'').'</div></div></td>';
            echo '<td><strong>'.esc_html($plan).'</strong><small>'.esc_html($price ?: 'Price not set').'</small><span class="pdp-sites-note">'.esc_html(self::site_limit_label($sites)).' allowed</span></td>';
            echo '<td><div class="pdp-license-key-cell"><code>'.esc_html($key ?: 'Generated after save').'</code>'.($key?'<button type="button" class="pdp-copy-license" data-license-key="'.esc_attr($key).'" aria-label="Copy license key"><span class="dashicons dashicons-admin-page"></span></button>':'').'</div></td>';
            $activation_percent = $sites === 0 ? 0 : min(100, round(($used / $sites) * 100));
            echo '<td><div class="pdp-activation-count"><strong>'.intval($used).'<span>/'.($sites === 0 ? '∞' : intval($sites)).'</span></strong><div><i style="width:'.esc_attr($activation_percent).'%"></i></div><small>'.($used===1?'1 active website':intval($used).' active websites').($sites===0?' · Unlimited plan':'').'</small></div></td>';
            echo '<td><span class="pdp-expiry'.$expiry_class.'"><strong>'.esc_html($expiry_text).'</strong><small>'.($expires ? ($expiry_class===' is-expired'?'Expired':($expiry_class===' is-soon'?'Renewal approaching':'Expiration date')) : 'Lifetime or manual renewal').'</small></span></td>';
            echo '<td><span class="pdp-license-status '.esc_attr(self::admin_status_class($status)).'"><i></i>'.esc_html($status).'</span></td>';
            echo '<td><div class="pdp-license-row-actions"><a class="button button-primary" href="'.esc_url($edit).'">View / Edit</a><button type="button" class="button pdp-more-license-actions" aria-expanded="false">More</button><div class="pdp-license-action-menu"><a href="'.esc_url($email_url).'">Email License</a><a href="'.esc_url($toggle_url).'">'.($status==='Suspended'?'Reactivate License':'Suspend License').'</a><a class="is-danger" href="'.esc_url(get_delete_post_link($license->ID,'','trash')).'">Move to Trash</a></div></div></td></tr>';
        }
        echo '</tbody></table></div><div class="pdp-license-footer-note"><span class="dashicons dashicons-shield"></span><div><strong>License security</strong><p>License keys are only fully displayed to administrators with permission to manage Party Desk Pro.</p></div></div></div>';
        echo '<script>jQuery(function($){$(document).on("click",".pdp-copy-license",function(){var b=$(this),k=b.data("license-key");if(navigator.clipboard){navigator.clipboard.writeText(k).then(function(){b.addClass("is-copied");setTimeout(function(){b.removeClass("is-copied");},1200);});}});$(document).on("click",".pdp-more-license-actions",function(e){e.stopPropagation();var b=$(this),m=b.next();$(".pdp-license-action-menu").not(m).removeClass("is-open");m.toggleClass("is-open");b.attr("aria-expanded",m.hasClass("is-open")?"true":"false");});$(document).on("click",function(){$(".pdp-license-action-menu").removeClass("is-open");$(".pdp-more-license-actions").attr("aria-expanded","false");});});</script>';
    }

    public static function meta_boxes() {
        add_meta_box('pdp_plan_details','Plan Details',array(__CLASS__,'plan_box'),'pdp_plan','normal','high');
        add_meta_box('pdp_plan_preview','Signup Card Preview',array(__CLASS__,'plan_preview_box'),'pdp_plan','side','default');
        add_meta_box('pdp_request_details','Signup Information',array(__CLASS__,'request_box'),'pdp_request','normal','high');
        add_meta_box('pdp_request_actions','Payment & License Actions',array(__CLASS__,'request_actions_box'),'pdp_request','side','high');
        add_meta_box('pdp_license_details','License Details',array(__CLASS__,'license_box'),'pdp_license','normal','high');
    }

    public static function plan_box($post) {
        wp_nonce_field('pdp_save_plan','pdp_plan_nonce');
        $m = function($key,$default='') use ($post) { $v=get_post_meta($post->ID,$key,true); return $v!==''?$v:$default; };
        ?>
        <div class="pdp-plan-editor-pro">
            <header class="pdp-plan-editor-hero">
                <div class="pdp-plan-editor-icon"><span class="dashicons dashicons-tickets-alt"></span></div>
                <div><span class="pdp-plan-editor-kicker">SUBSCRIPTION PLAN</span><h2>Plan settings</h2><p>Control the pricing, billing schedule, customer-facing details, and signup visibility for this plan.</p></div>
            </header>

            <section class="pdp-plan-editor-section">
                <div class="pdp-plan-section-head"><span class="dashicons dashicons-edit-page"></span><div><h3>Plan presentation</h3><p>Describe how this plan appears to customers on the signup form.</p></div></div>
                <div class="pdp-plan-fields two-columns">
                    <label class="wide"><span>Plan description</span><textarea name="pdp_description" rows="4" placeholder="Explain who this plan is for and the value it provides."><?php echo esc_textarea($m('_pdp_description')); ?></textarea><small>Keep this clear and concise for the signup card.</small></label>
                    <label><span>Promotional badge</span><input type="text" name="pdp_badge" value="<?php echo esc_attr($m('_pdp_badge')); ?>" placeholder="Most Popular"><small>Examples: Most Popular, Best Value, Starter.</small></label>
                    <label><span>Button text</span><input type="text" name="pdp_button" value="<?php echo esc_attr($m('_pdp_button','Choose Plan')); ?>" placeholder="Choose Plan"><small>The call-to-action shown on the signup card.</small></label>
                </div>
            </section>

            <section class="pdp-plan-editor-section">
                <div class="pdp-plan-section-head"><span class="dashicons dashicons-money-alt"></span><div><h3>Pricing and billing</h3><p>Set the amount customers pay and how often the plan renews.</p></div></div>
                <div class="pdp-plan-fields three-columns">
                    <label><span>Plan price</span><div class="pdp-money-field"><b>$</b><input type="number" min="0" step="0.01" name="pdp_price" value="<?php echo esc_attr($m('_pdp_price','0')); ?>"></div><small>Subscription amount before any setup fee.</small></label>
                    <label><span>Billing period</span><select name="pdp_billing">
                        <option value="month" <?php selected($m('_pdp_billing'),'month'); ?>>Monthly</option>
                        <option value="6-months" <?php selected($m('_pdp_billing'),'6-months'); ?>>Every 6 Months</option>
                        <option value="year" <?php selected($m('_pdp_billing'),'year'); ?>>Yearly</option>
                        <option value="one-time" <?php selected($m('_pdp_billing'),'one-time'); ?>>One Time</option>
                        <option value="trial" <?php selected($m('_pdp_billing'),'trial'); ?>>Free Trial</option>
                    </select><small>Every 6 Months renews twice per year.</small></label>
                    <label><span>Setup fee</span><div class="pdp-money-field"><b>$</b><input type="number" min="0" step="0.01" name="pdp_setup_fee" value="<?php echo esc_attr($m('_pdp_setup_fee','0')); ?>"></div><small>Optional one-time onboarding or setup charge.</small></label>
                </div>
            </section>

            <section class="pdp-plan-editor-section pdp-license-settings-section">
                <div class="pdp-plan-section-head"><span class="dashicons dashicons-lock"></span><div><h3>License settings</h3><p>Control how many WordPress websites may use each license created for this plan.</p></div></div>
                <div class="pdp-plan-fields two-columns">
                    <label><span>Website activation limit</span><select name="pdp_sites">
                        <option value="1" <?php selected((string)$m('_pdp_sites','1'),'1'); ?>>1 Website (Recommended)</option>
                        <option value="2" <?php selected((string)$m('_pdp_sites','1'),'2'); ?>>2 Websites</option>
                        <option value="3" <?php selected((string)$m('_pdp_sites','1'),'3'); ?>>3 Websites</option>
                        <option value="5" <?php selected((string)$m('_pdp_sites','1'),'5'); ?>>5 Websites</option>
                        <option value="10" <?php selected((string)$m('_pdp_sites','1'),'10'); ?>>10 Websites</option>
                        <option value="0" <?php selected((string)$m('_pdp_sites','1'),'0'); ?>>Unlimited Websites</option>
                    </select><small>Use 1 for a standard customer license. Increase this only for multi-site or agency plans.</small></label>
                    <div class="pdp-license-setting-note"><span class="dashicons dashicons-info-outline"></span><div><strong>What counts as a website?</strong><p>Each separate WordPress site URL that activates Party Desk Pro counts toward this limit. Setting this to Unlimited removes the activation cap.</p></div></div>
                </div>
            </section>

            <section class="pdp-plan-editor-section">
                <div class="pdp-plan-section-head"><span class="dashicons dashicons-clock"></span><div><h3>Access and trial limits</h3><p>Configure the trial period customers receive with this plan.</p></div></div>
                <div class="pdp-plan-fields two-columns">
                    <label><span>Trial days</span><input type="number" min="0" name="pdp_trial_days" value="<?php echo esc_attr($m('_pdp_trial_days','0')); ?>"><small>Use 0 when this is not a trial plan.</small></label>
                    <div class="pdp-license-setting-note"><span class="dashicons dashicons-calendar-alt"></span><div><strong>Trial access</strong><p>The trial length applies when this plan is used to create a trial license. Paid billing periods continue to use their normal expiration schedule.</p></div></div>
                </div>
            </section>

            <section class="pdp-plan-editor-section">
                <div class="pdp-plan-section-head"><span class="dashicons dashicons-list-view"></span><div><h3>Included features</h3><p>Add one customer-facing feature per line. These are shown as checkmarks on the plan card.</p></div></div>
                <div class="pdp-plan-fields"><label class="wide"><span>Feature list</span><textarea name="pdp_features" rows="8" placeholder="Online booking form&#10;Customer portal&#10;Email automations"><?php echo esc_textarea($m('_pdp_features')); ?></textarea><small>Drag-and-drop ordering is not required—features appear in the order entered.</small></label></div>
            </section>

            <section class="pdp-plan-editor-section pdp-plan-visibility-section">
                <div class="pdp-plan-section-head"><span class="dashicons dashicons-visibility"></span><div><h3>Visibility and highlighting</h3><p>Choose whether customers can select this plan and whether it receives special emphasis.</p></div></div>
                <div class="pdp-plan-toggle-grid">
                    <label class="pdp-plan-toggle"><input type="checkbox" name="pdp_active" value="1" <?php checked($m('_pdp_active','1'),'1'); ?>><span class="pdp-toggle-track"></span><span><strong>Show on signup form</strong><small>Customers can view and select this plan.</small></span></label>
                    <label class="pdp-plan-toggle"><input type="checkbox" name="pdp_featured" value="1" <?php checked($m('_pdp_featured'),'1'); ?>><span class="pdp-toggle-track"></span><span><strong>Highlight as featured</strong><small>Adds featured styling to the customer plan card.</small></span></label>
                </div>
            </section>
        </div><?php
    }

    public static function plan_preview_box($post) {
        echo '<div id="pdp-plan-preview" class="pdp-preview-card"><small class="pdp-preview-badge"></small><h3>'.esc_html($post->post_title ?: 'Plan Name').'</h3><div class="pdp-preview-price">$0 <span>/month</span></div><p class="pdp-preview-desc">Plan description</p><ul></ul><button type="button">Choose Plan</button></div>';
    }

    public static function save_plan($post_id) {
        if (!isset($_POST['pdp_plan_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pdp_plan_nonce'])),'pdp_save_plan')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post',$post_id)) return;
        $text = array('pdp_description'=>'_pdp_description','pdp_badge'=>'_pdp_badge','pdp_billing'=>'_pdp_billing','pdp_button'=>'_pdp_button','pdp_features'=>'_pdp_features');
        foreach($text as $in=>$meta) update_post_meta($post_id,$meta,isset($_POST[$in])?sanitize_textarea_field(wp_unslash($_POST[$in])):'');
        foreach(array('pdp_price'=>'_pdp_price','pdp_setup_fee'=>'_pdp_setup_fee') as $in=>$meta) update_post_meta($post_id,$meta,isset($_POST[$in])?number_format((float)$_POST[$in],2,'.',''):'0');
        foreach(array('pdp_trial_days'=>'_pdp_trial_days','pdp_sites'=>'_pdp_sites') as $in=>$meta) update_post_meta($post_id,$meta,isset($_POST[$in])?absint($_POST[$in]):0);
        update_post_meta($post_id,'_pdp_active',isset($_POST['pdp_active'])?'1':'0');
        update_post_meta($post_id,'_pdp_featured',isset($_POST['pdp_featured'])?'1':'0');
    }

    public static function request_box($post) {
        $fields = get_option(self::OPT_FIELDS,self::default_fields());
        echo '<div class="pdp-detail-grid">';
        foreach($fields as $key=>$cfg) {
            if (empty($cfg['show'])) continue;
            $v=get_post_meta($post->ID,'_pdp_'.$key,true);
            if ($key==='website' && $v) $display='<a href="'.esc_url($v).'" target="_blank" rel="noopener">'.esc_html($v).'</a>'; else $display=nl2br(esc_html($v));
            echo '<div><span>'.esc_html($cfg['label']).'</span><strong>'.$display.'</strong></div>';
        }
        $plan_id=absint(get_post_meta($post->ID,'_pdp_plan_id',true));
        echo '<div><span>Selected Plan</span><strong>'.esc_html(get_the_title($plan_id)).'</strong></div>';
        echo '<div><span>Amount</span><strong>$'.esc_html(number_format((float)get_post_meta($post->ID,'_pdp_amount',true),2)).'</strong></div>';
        echo '<div><span>Status</span><strong>'.esc_html(get_post_meta($post->ID,'_pdp_status',true) ?: 'New').'</strong></div></div>';
    }
    public static function request_actions_box($post) {
        $link = get_post_meta($post->ID,'_pdp_square_url',true);
        $status = get_post_meta($post->ID,'_pdp_status',true) ?: 'New';
        $base = admin_url('admin-post.php');
        echo '<div class="pdp-action-stack"><p><strong>Request status</strong><br><span class="pdp-status-chip">'.esc_html($status).'</span></p>';
        echo '<p><a class="button button-primary button-large pdp-full" href="'.esc_url(wp_nonce_url(add_query_arg(array('action'=>'pdp_create_square_link','request_id'=>$post->ID),$base),'pdp_create_square_'.$post->ID)).'">Create Square Payment Link</a></p>';
        if ($link) {
            echo '<p><a class="button pdp-full" target="_blank" rel="noopener" href="'.esc_url($link).'">Open Payment Link</a></p>';
            echo '<p><a class="button pdp-full" href="'.esc_url(wp_nonce_url(add_query_arg(array('action'=>'pdp_email_square_link','request_id'=>$post->ID),$base),'pdp_email_square_'.$post->ID)).'">Email Payment Link</a></p>';
            echo '<code class="pdp-code">'.esc_html($link).'</code>';
        }
        echo '<hr><p><a class="button pdp-full" href="'.esc_url(wp_nonce_url(add_query_arg(array('action'=>'pdp_mark_request_paid','request_id'=>$post->ID),$base),'pdp_mark_paid_'.$post->ID)).'">Mark Payment Received</a></p>';
        echo '<p><a class="button button-primary pdp-full" href="'.esc_url(wp_nonce_url(add_query_arg(array('action'=>'pdp_create_license','request_id'=>$post->ID),$base),'pdp_create_license_'.$post->ID)).'">Approve & Create License</a></p>';
        echo '<p><a class="button pdp-full" href="'.esc_url(wp_nonce_url(add_query_arg(array('action'=>'pdp_create_trial_license','request_id'=>$post->ID),$base),'pdp_create_trial_'.$post->ID)).'">Create Trial License</a></p>';
        echo '<p><a class="button pdp-full pdp-danger-link" href="'.esc_url(wp_nonce_url(add_query_arg(array('action'=>'pdp_decline_request','request_id'=>$post->ID),$base),'pdp_decline_'.$post->ID)).'">Decline Request</a></p></div>';
    }
    public static function save_request_admin($post_id) { return; }

    public static function license_box($post) {
        wp_nonce_field('pdp_save_license','pdp_license_nonce');
        $g=function($k,$d='')use($post){$v=get_post_meta($post->ID,$k,true);return $v!==''?$v:$d;};
        $selected_plan_id = absint($g('_pdp_plan_id'));
        $plans = get_posts(array('post_type'=>'pdp_plan','post_status'=>'publish','numberposts'=>-1,'orderby'=>array('menu_order'=>'ASC','title'=>'ASC')));
        echo '<div class="pdp-license-editor">';
        echo '<div class="pdp-grid">';
        echo '<label>Business Name<input type="text" name="pdp_license_business" value="'.esc_attr($g('_pdp_business')).'" required></label>';
        echo '<label>Customer Email<input type="email" name="pdp_license_email" value="'.esc_attr($g('_pdp_email')).'" required></label>';
        echo '<label>Website<input type="url" name="pdp_license_website" value="'.esc_attr($g('_pdp_website')).'"></label>';
        echo '<label>Plan<select name="pdp_license_plan_id" id="pdp-license-plan" required><option value="">Select a plan</option>';
        foreach($plans as $plan){echo '<option value="'.intval($plan->ID).'" data-price="'.esc_attr(get_post_meta($plan->ID,'_pdp_price',true)).'" data-billing="'.esc_attr(get_post_meta($plan->ID,'_pdp_billing',true)).'" data-sites="'.esc_attr(get_post_meta($plan->ID,'_pdp_sites',true)).'" data-trial="'.esc_attr(get_post_meta($plan->ID,'_pdp_trial_days',true)).'" '.selected($selected_plan_id,$plan->ID,false).'>'.esc_html($plan->post_title).'</option>';}
        echo '</select></label>';
        echo '<label>Subscription Price<input type="text" name="pdp_license_price" id="pdp-license-price" value="'.esc_attr($g('_pdp_price')).'" placeholder="$79.00 / month"></label>';
        echo '<label>Website Activation Limit<input type="number" min="0" name="pdp_license_sites" id="pdp-license-sites" value="'.esc_attr($g('_pdp_sites','1')).'"><small>Enter 0 for unlimited websites.</small></label>';
        echo '<label>Expiration Date<input type="date" name="pdp_license_expires" id="pdp-license-expires" value="'.esc_attr($g('_pdp_expires')).'"></label>';
        echo '<label>Status<select name="pdp_license_status"><option '.selected($g('_pdp_status','Pending'),'Pending',false).'>Pending</option><option '.selected($g('_pdp_status'),'Active',false).'>Active</option><option '.selected($g('_pdp_status'),'Suspended',false).'>Suspended</option><option '.selected($g('_pdp_status'),'Expired',false).'>Expired</option></select></label>';
        echo '<label class="pdp-span">License Key<div class="pdp-key-field"><input type="text" name="pdp_license_key" value="'.esc_attr($g('_pdp_key')).'" readonly placeholder="Generated automatically when saved"><span>Unique key generated automatically</span></div></label>';
        echo '<label class="pdp-span">Admin Notes<textarea name="pdp_license_notes" rows="4">'.esc_textarea($g('_pdp_notes')).'</textarea></label>';
        echo '</div>';
        $token=$g('_pdp_token');
        if($post->ID && $token){
            $url=add_query_arg('pdp_portal_token',$token,home_url('/'));
            $base=admin_url('admin-post.php');
            echo '<div class="pdp-license-actions"><a class="button button-primary" href="'.esc_url(wp_nonce_url(add_query_arg(array('action'=>'pdp_email_license','license_id'=>$post->ID),$base),'pdp_email_license_'.$post->ID)).'">Email License</a> ';
            echo '<a class="button" href="'.esc_url(wp_nonce_url(add_query_arg(array('action'=>'pdp_regenerate_license_key','license_id'=>$post->ID),$base),'pdp_regenerate_key_'.$post->ID)).'">Regenerate Key</a> ';
            echo '<a class="button" href="'.esc_url(wp_nonce_url(add_query_arg(array('action'=>'pdp_toggle_license_status','license_id'=>$post->ID),$base),'pdp_toggle_status_'.$post->ID)).'">'.($g('_pdp_status')==='Suspended'?'Reactivate':'Suspend').'</a>';
            echo '<p><strong>My Account URL:</strong><br><code class="pdp-code">'.esc_html($url).'</code></p></div>';
        }
        echo '</div>';
    }

    public static function save_license($post_id) {
        if(!isset($_POST['pdp_license_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pdp_license_nonce'])),'pdp_save_license'))return;
        if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
        if(!current_user_can('edit_post',$post_id))return;
        $plan_id=isset($_POST['pdp_license_plan_id'])?absint($_POST['pdp_license_plan_id']):0;
        if(!$plan_id || get_post_type($plan_id)!=='pdp_plan') return;
        update_post_meta($post_id,'_pdp_plan_id',$plan_id);
        update_post_meta($post_id,'_pdp_plan',get_the_title($plan_id));
        foreach(array('email','business','website','price','expires','status','notes') as $k){
            $raw=isset($_POST['pdp_license_'.$k])?wp_unslash($_POST['pdp_license_'.$k]):'';
            $v=$k==='email'?sanitize_email($raw):($k==='website'?esc_url_raw($raw):sanitize_textarea_field($raw));
            update_post_meta($post_id,'_pdp_'.$k,$v);
        }
        $sites=isset($_POST['pdp_license_sites'])?absint($_POST['pdp_license_sites']):self::site_limit_value(get_post_meta($plan_id,'_pdp_sites',true));
        update_post_meta($post_id,'_pdp_sites',$sites);
        $key=get_post_meta($post_id,'_pdp_key',true);
        if(!$key) update_post_meta($post_id,'_pdp_key',self::generate_unique_license_key($plan_id));
        if(!get_post_meta($post_id,'_pdp_token',true)) update_post_meta($post_id,'_pdp_token',wp_generate_password(40,false,false));
        self::ensure_customer_account($post_id);
        $settings=get_option(self::OPT_SETTINGS,array());
        if(!empty($settings['license_auto_email']) && !get_post_meta($post_id,'_pdp_delivery_sent',true) && is_email(get_post_meta($post_id,'_pdp_email',true))){
            if(self::send_license_delivery($post_id)) update_post_meta($post_id,'_pdp_delivery_sent',current_time('mysql'));
        }
    }

    public static function form_builder() {
        if(isset($_POST['pdp_save_form'])&&check_admin_referer('pdp_save_form_action')){
            $s=get_option(self::OPT_SETTINGS,array());
            foreach(array('form_title','form_intro','submit_text','accent') as $k) if(isset($_POST[$k])) $s[$k]=sanitize_text_field(wp_unslash($_POST[$k]));
            update_option(self::OPT_SETTINGS,$s);
            $fields=get_option(self::OPT_FIELDS,self::default_fields());
            foreach($fields as $k=>$cfg){
                $fields[$k]['show']=isset($_POST['field_'.$k.'_show'])?1:0;
                $fields[$k]['required']=isset($_POST['field_'.$k.'_required'])?1:0;
                if(isset($_POST['field_'.$k.'_label']))$fields[$k]['label']=sanitize_text_field(wp_unslash($_POST['field_'.$k.'_label']));
                if(isset($_POST['field_'.$k.'_placeholder']))$fields[$k]['placeholder']=sanitize_text_field(wp_unslash($_POST['field_'.$k.'_placeholder']));
            }
            update_option(self::OPT_FIELDS,$fields); echo '<div class="notice notice-success is-dismissible"><p><strong>Signup form saved.</strong> Your latest field and appearance settings are now active.</p></div>';
        }
        $s=wp_parse_args(get_option(self::OPT_SETTINGS,array()),array('form_title'=>'Get Started with Party Desk Pro','form_intro'=>'Tell us about your business and choose the plan that fits you best.','submit_text'=>'Submit Request','accent'=>'#6d5dfc'));
        $fields=get_option(self::OPT_FIELDS,self::default_fields());
        $visible=0;$required=0;foreach($fields as $cfg){if(!empty($cfg['show']))$visible++;if(!empty($cfg['required']))$required++;}
        echo '<div class="wrap pdp-admin pdp-admin-pro pdp-form-admin">';
        echo '<div class="pdp-pro-hero"><div><span class="pdp-pro-eyebrow">CUSTOMER ONBOARDING</span><h1>Signup Form Builder</h1><p>Control the wording, branding, visible fields, and required information customers complete before selecting a Party Desk Pro plan.</p></div><div class="pdp-pro-hero-actions"><a class="button button-secondary" href="'.esc_url(home_url('/')).'" target="_blank">View Website</a></div></div>';
        echo '<div class="pdp-pro-stats"><article><span>Visible fields</span><strong>'.intval($visible).'</strong><small>Shown on the live form</small></article><article><span>Required fields</span><strong>'.intval($required).'</strong><small>Must be completed</small></article><article><span>Accent color</span><strong><i style="background:'.esc_attr($s['accent']).'"></i>'.esc_html(strtoupper($s['accent'])).'</strong><small>Buttons and highlights</small></article></div>';
        echo '<div class="pdp-pro-layout"><form method="post" class="pdp-pro-main">';wp_nonce_field('pdp_save_form_action');
        echo '<section class="pdp-pro-card"><div class="pdp-pro-card-head"><div class="pdp-pro-icon">✦</div><div><h2>Form Appearance</h2><p>Set the first message customers see and match the form to your brand.</p></div></div><div class="pdp-pro-grid">';
        echo '<label><span>Form title</span><input name="form_title" value="'.esc_attr($s['form_title']).'"><small>Use a short, welcoming headline.</small></label>';
        echo '<label><span>Submit button text</span><input name="submit_text" value="'.esc_attr($s['submit_text']).'"><small>The final action shown at the bottom of the form.</small></label>';
        echo '<label class="pdp-pro-full"><span>Introduction</span><textarea rows="4" name="form_intro">'.esc_textarea($s['form_intro']).'</textarea><small>Explain what happens after the customer submits the request.</small></label>';
        echo '<label><span>Accent color</span><div class="pdp-color-control"><input type="color" name="accent" value="'.esc_attr($s['accent']).'"><code>'.esc_html(strtoupper($s['accent'])).'</code></div></label></div></section>';
        echo '<section class="pdp-pro-card"><div class="pdp-pro-card-head"><div class="pdp-pro-icon">☷</div><div><h2>Customer Information Fields</h2><p>Rename fields, customize placeholders, and decide which details are visible or required.</p></div></div><div class="pdp-fields-pro">';
        foreach($fields as $k=>$cfg){
            echo '<article class="pdp-field-pro"><div class="pdp-field-pro-title"><div><strong>'.esc_html($cfg['label']).'</strong><code>'.esc_html($k).'</code></div><div class="pdp-field-switches"><label><input type="checkbox" name="field_'.$k.'_show" '.checked(!empty($cfg['show']),true,false).'><span>Show</span></label><label><input type="checkbox" name="field_'.$k.'_required" '.checked(!empty($cfg['required']),true,false).'><span>Required</span></label></div></div><div class="pdp-pro-grid"><label><span>Field label</span><input name="field_'.$k.'_label" value="'.esc_attr($cfg['label']).'"></label><label><span>Placeholder</span><input name="field_'.$k.'_placeholder" value="'.esc_attr($cfg['placeholder']).'"></label></div></article>';
        }
        echo '</div></section><div class="pdp-pro-savebar"><div><strong>Save Signup Form</strong><span>Changes apply to the signup shortcode immediately.</span></div>';submit_button('Save Signup Form','primary large','pdp_save_form',false);echo '</div></form>';
        echo '<aside class="pdp-pro-side"><section class="pdp-pro-card pdp-pro-preview"><div class="pdp-preview-toolbar"><div><h2>Live Preview</h2><p>Customer-facing sample using the current settings.</p></div><div class="pdp-device-switch" data-preview-target="pdp-form-preview-frame"><button type="button" class="button is-active" data-device="desktop" aria-pressed="true">Desktop</button><button type="button" class="button" data-device="mobile" aria-pressed="false">Mobile</button></div></div><div id="pdp-form-preview-frame" class="pdp-responsive-preview is-desktop" data-preview-width-desktop="1120" data-preview-width-mobile="390" data-preview-child=".pdp-signup"><div class="pdp-preview-stage"><div id="pdp-form-preview">'.do_shortcode('[party_desk_pro_license_request_form preview="1"]').'</div></div></div></section><section class="pdp-pro-card pdp-pro-help"><h3>Signup shortcode</h3><p>Add this shortcode to the WordPress page where customers should request a subscription.</p><code>[party_desk_pro_license_request_form]</code></section></aside></div></div>';
    }

    public static function portal_settings() {
        if (isset($_POST['pdp_save_portal']) && check_admin_referer('pdp_save_portal_action')) {
            $s = get_option(self::OPT_SETTINGS, array());
            $text_fields = array('portal_title','portal_subtitle','portal_brand','portal_logo','portal_layout','subscription_label','status_label','sites_label','license_heading','support_label','support_url');
            foreach ($text_fields as $k) { if (!isset($_POST[$k])) continue; $value=wp_unslash($_POST[$k]); $s[$k]=in_array($k,array('portal_logo','support_url'),true)?esc_url_raw($value):sanitize_text_field($value); }
            foreach (array('portal_accent','portal_header_end','portal_background','portal_card','portal_text','portal_muted') as $k) if(isset($_POST[$k])) $s[$k]=sanitize_hex_color(wp_unslash($_POST[$k]));
            $s['portal_radius']=isset($_POST['portal_radius'])?min(40,max(0,absint($_POST['portal_radius']))):24;
            foreach(array('show_subscription','show_status','show_sites','show_business','show_email','show_license_key','show_expiration') as $k)$s[$k]=isset($_POST[$k])?'1':'0';
            update_option(self::OPT_SETTINGS,$s); echo '<div class="notice notice-success is-dismissible"><p><strong>My Account settings saved.</strong> The customer portal design has been updated.</p></div>';
        }
        $s=wp_parse_args(get_option(self::OPT_SETTINGS,array()),array('portal_title'=>'My Account','portal_subtitle'=>'Manage your subscription, license, downloads, and account.','portal_brand'=>'PARTY DESK PRO','portal_logo'=>'','portal_accent'=>'#2563eb','portal_header_end'=>'#1e3a8a','portal_background'=>'#f8fafc','portal_card'=>'#ffffff','portal_text'=>'#0f172a','portal_muted'=>'#64748b','portal_radius'=>'24','portal_layout'=>'cards','subscription_label'=>'Current Subscription','status_label'=>'License Status','sites_label'=>'Website Allowance','license_heading'=>'License information','show_subscription'=>'1','show_status'=>'1','show_sites'=>'1','show_business'=>'1','show_email'=>'1','show_license_key'=>'1','show_expiration'=>'1','support_label'=>'Contact Support','support_url'=>'','portal_download_heading'=>'Download Party Desk Pro','portal_download_text'=>'Download the latest Party Desk Pro plugin ZIP file included with your subscription.','portal_download_button'=>'Download Plugin ZIP','license_zip_attachment_id'=>0,'license_zip_version'=>''));
        $sample=array('business'=>'Sample Business','email'=>'client@example.com','plan'=>'Professional','price'=>'$79 / month','key'=>'PDP-DEMO-1234','status'=>'Active','expires'=>date('Y-m-d',strtotime('+1 year')),'sites'=>'3');
        $enabled=0;foreach(array('show_subscription','show_status','show_sites','show_business','show_email','show_license_key','show_expiration') as $k)if($s[$k]==='1')$enabled++;
        echo '<div class="wrap pdp-admin pdp-admin-pro pdp-account-admin"><div class="pdp-pro-hero"><div><span class="pdp-pro-eyebrow">CUSTOMER EXPERIENCE</span><h1>My Account Builder</h1><p>Manage the branding, layout, wording, visible account information, and support access shown to Party Desk Pro customers.</p></div><div class="pdp-pro-hero-actions"><a class="button button-secondary" href="'.esc_url($s['portal_page_url']??home_url('/my-account/')).'" target="_blank">Open My Account</a></div></div>';
        echo '<div class="pdp-pro-stats"><article><span>Layout</span><strong>'.esc_html(ucfirst($s['portal_layout'])).'</strong><small>Current dashboard style</small></article><article><span>Visible items</span><strong>'.intval($enabled).'</strong><small>Customer details enabled</small></article><article><span>Primary color</span><strong><i style="background:'.esc_attr($s['portal_accent']).'"></i>'.esc_html(strtoupper($s['portal_accent'])).'</strong><small>Portal brand accent</small></article></div>';
        echo '<div class="pdp-pro-layout"><form method="post" class="pdp-pro-main" id="pdp-portal-customizer">';wp_nonce_field('pdp_save_portal_action');
        echo '<section class="pdp-pro-card"><div class="pdp-pro-card-head"><div class="pdp-pro-icon">◆</div><div><h2>Branding & Header</h2><p>Customize the identity and welcome message customers see after signing in.</p></div></div><div class="pdp-pro-grid"><label><span>Brand name</span><input name="portal_brand" value="'.esc_attr($s['portal_brand']).'" data-preview="brand"></label><label><span>Logo URL</span><input type="url" name="portal_logo" value="'.esc_attr($s['portal_logo']).'" placeholder="https://example.com/logo.png" data-preview="logo"></label><label><span>Page title</span><input name="portal_title" value="'.esc_attr($s['portal_title']).'" data-preview="title"></label><label class="pdp-pro-full"><span>Welcome message</span><textarea rows="3" name="portal_subtitle" data-preview="subtitle">'.esc_textarea($s['portal_subtitle']).'</textarea></label></div></section>';
        echo '<section class="pdp-pro-card"><div class="pdp-pro-card-head"><div class="pdp-pro-icon">◉</div><div><h2>Colors & Layout</h2><p>Build a portal that matches your brand across desktop and mobile.</p></div></div><div class="pdp-color-pro-grid">';
        foreach(array('portal_accent'=>'Primary color','portal_header_end'=>'Header gradient','portal_background'=>'Page background','portal_card'=>'Card background','portal_text'=>'Text color','portal_muted'=>'Secondary text') as $k=>$label)echo '<label><span>'.$label.'</span><div class="pdp-color-control"><input type="color" name="'.$k.'" value="'.esc_attr($s[$k]).'" data-preview="'.$k.'"><code>'.esc_html(strtoupper($s[$k])).'</code></div></label>';
        echo '</div><div class="pdp-pro-grid"><label><span>Corner roundness <output id="pdp-radius-output">'.intval($s['portal_radius']).'px</output></span><input type="range" min="0" max="40" name="portal_radius" value="'.intval($s['portal_radius']).'" data-preview="radius"></label><label><span>Dashboard layout</span><select name="portal_layout" data-preview="layout"><option value="cards" '.selected($s['portal_layout'],'cards',false).'>Card dashboard</option><option value="compact" '.selected($s['portal_layout'],'compact',false).'>Compact dashboard</option></select></label></div></section>';
        echo '<section class="pdp-pro-card"><div class="pdp-pro-card-head"><div class="pdp-pro-icon">Aa</div><div><h2>Section Wording</h2><p>Rename the most important customer dashboard sections.</p></div></div><div class="pdp-pro-grid">';foreach(array('subscription_label'=>'Subscription label','status_label'=>'License status label','sites_label'=>'Website allowance label','license_heading'=>'License section heading') as $k=>$label)echo '<label><span>'.$label.'</span><input name="'.$k.'" value="'.esc_attr($s[$k]).'" data-preview="'.$k.'"></label>';echo '</div></section>';
        echo '<section class="pdp-pro-card"><div class="pdp-pro-card-head"><div class="pdp-pro-icon">✓</div><div><h2>Visible Customer Information</h2><p>Choose exactly what customers can see inside My Account.</p></div></div><div class="pdp-toggle-pro-grid">';foreach(array('show_subscription'=>'Subscription card','show_status'=>'License status card','show_sites'=>'Website allowance card','show_business'=>'Business name','show_email'=>'Customer email','show_license_key'=>'License key','show_expiration'=>'Expiration date') as $k=>$label)echo '<label><input type="checkbox" name="'.$k.'" value="1" '.checked($s[$k],'1',false).' data-preview="'.$k.'"><span><strong>'.$label.'</strong><small>'.($s[$k]==='1'?'Currently visible':'Currently hidden').'</small></span></label>';echo '</div></section>';
        echo '<section class="pdp-pro-card"><div class="pdp-pro-card-head"><div class="pdp-pro-icon">?</div><div><h2>Support Access</h2><p>Add a clear support action to the customer dashboard.</p></div></div><div class="pdp-pro-grid"><label><span>Button text</span><input name="support_label" value="'.esc_attr($s['support_label']).'" data-preview="support_label"></label><label><span>Support URL</span><input type="url" name="support_url" value="'.esc_attr($s['support_url']).'" placeholder="https://your-site.com/support" data-preview="support_url"></label></div><p class="description">Leave the URL blank to hide the support button.</p></section>';
        echo '<div class="pdp-pro-savebar"><div><strong>Save My Account</strong><span>These settings control the customer-facing account dashboard.</span></div>';submit_button('Save My Account','primary large','pdp_save_portal',false);echo '</div></form>';
        echo '<aside class="pdp-pro-side"><section class="pdp-pro-card pdp-pro-preview"><div class="pdp-preview-toolbar"><div><h2>Live Preview</h2><p>Sample customer information is displayed below.</p></div><div class="pdp-device-switch"><button type="button" class="button is-active" data-device="desktop">Desktop</button><button type="button" class="button" data-device="mobile">Mobile</button></div></div><div id="pdp-portal-preview-frame" class="is-desktop"><div class="pdp-preview-stage">'.self::portal_html($sample,true).'</div></div></section><section class="pdp-pro-card pdp-pro-help"><h3>My Account shortcode</h3><p>Place this shortcode on the WordPress page customers use to access their account.</p><code>[pdpclientportal]</code></section></aside></div></div>';
    }

    public static function square_settings() {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to manage Square settings.');

        if (isset($_POST['pdp_save_square']) && check_admin_referer('pdp_save_square_action')) {
            $s = get_option(self::OPT_SETTINGS, array());
            $s['square_mode'] = (isset($_POST['square_mode']) && wp_unslash($_POST['square_mode']) === 'sandbox') ? 'sandbox' : 'production';
            // Preserve the current token when the password field is intentionally left blank.
            if (isset($_POST['square_token']) && trim((string) wp_unslash($_POST['square_token'])) !== '') {
                $s['square_token'] = sanitize_text_field(wp_unslash($_POST['square_token']));
            }
            $s['square_location'] = isset($_POST['square_location']) ? sanitize_text_field(wp_unslash($_POST['square_location'])) : '';
            $currency = isset($_POST['square_currency']) ? strtoupper(preg_replace('/[^A-Z]/', '', sanitize_text_field(wp_unslash($_POST['square_currency'])))) : 'USD';
            $s['square_currency'] = $currency ?: 'USD';
            $s['square_version'] = isset($_POST['square_version']) ? sanitize_text_field(wp_unslash($_POST['square_version'])) : '2026-05-20';
            $s['payment_email_subject'] = isset($_POST['payment_email_subject']) ? sanitize_text_field(wp_unslash($_POST['payment_email_subject'])) : '';
            $s['payment_email_intro'] = isset($_POST['payment_email_intro']) ? sanitize_textarea_field(wp_unslash($_POST['payment_email_intro'])) : '';
            update_option(self::OPT_SETTINGS, $s);
            echo '<div class="notice notice-success is-dismissible"><p><strong>Square Payments saved.</strong> Your payment-link settings are ready.</p></div>';
        }

        $s = wp_parse_args(get_option(self::OPT_SETTINGS, array()), array(
            'square_mode'=>'production','square_token'=>'','square_location'=>'','square_currency'=>'USD','square_version'=>'2026-05-20',
            'payment_email_subject'=>'Your Party Desk Pro payment link',
            'payment_email_intro'=>'Thank you for signing up. Use the secure Square link below to complete payment.'
        ));
        $has_token = !empty($s['square_token']);
        $has_location = !empty($s['square_location']);
        $ready = $has_token && $has_location;
        $mode_label = $s['square_mode'] === 'sandbox' ? 'Sandbox' : 'Production';
        $api_base = $s['square_mode'] === 'sandbox' ? 'connect.squareupsandbox.com' : 'connect.squareup.com';
        ?>
        <div class="wrap pdp-admin pdp-square-pro">
            <section class="pdp-sq-hero">
                <div>
                    <span class="pdp-sq-kicker">PAYMENT CONNECTION</span>
                    <h1>Square Payments</h1>
                    <p>Connect Square, control how payment links are created, and customize the email customers receive after you approve a signup request.</p>
                </div>
                <div class="pdp-sq-hero-status <?php echo $ready ? 'is-ready' : 'is-needed'; ?>">
                    <span><?php echo $ready ? '✓' : '!'; ?></span>
                    <div><strong><?php echo $ready ? 'Ready to create payment links' : 'Connection setup needed'; ?></strong><small><?php echo esc_html($mode_label); ?> environment</small></div>
                </div>
            </section>

            <div class="pdp-sq-status-grid">
                <article><span class="pdp-sq-dot <?php echo $has_token?'is-good':'is-warn'; ?>"></span><div><strong>Access Token</strong><small><?php echo $has_token?'Securely saved':'Not configured'; ?></small></div></article>
                <article><span class="pdp-sq-dot <?php echo $has_location?'is-good':'is-warn'; ?>"></span><div><strong>Location ID</strong><small><?php echo $has_location?esc_html($s['square_location']):'Required for checkout links'; ?></small></div></article>
                <article><span class="pdp-sq-dot is-good"></span><div><strong>Environment</strong><small><?php echo esc_html($mode_label); ?></small></div></article>
                <article><span class="pdp-sq-dot is-good"></span><div><strong>Currency</strong><small><?php echo esc_html($s['square_currency']); ?></small></div></article>
            </div>

            <form method="post" class="pdp-sq-form">
                <?php wp_nonce_field('pdp_save_square_action'); ?>
                <div class="pdp-sq-layout">
                    <main class="pdp-sq-main">
                        <section class="pdp-sq-card">
                            <div class="pdp-sq-card-head"><div class="pdp-sq-icon">▣</div><div><h2>Square connection</h2><p>Enter the credentials from your Square Developer Dashboard.</p></div></div>
                            <div class="pdp-sq-fields pdp-sq-fields-2">
                                <label><span>Environment</span><select name="square_mode"><option value="production" <?php selected($s['square_mode'],'production'); ?>>Production — accept real payments</option><option value="sandbox" <?php selected($s['square_mode'],'sandbox'); ?>>Sandbox — testing only</option></select><small>Use Production when sending payment links to customers.</small></label>
                                <label><span>Location ID</span><input type="text" name="square_location" value="<?php echo esc_attr($s['square_location']); ?>" placeholder="L123ABC456DEF"><small>Found under Locations in your Square account.</small></label>
                                <label class="pdp-sq-full"><span>Access token</span><div class="pdp-sq-secret"><input id="pdp-square-token" type="password" autocomplete="new-password" name="square_token" value="" placeholder="<?php echo $has_token?'Token saved — leave blank to keep it':'Paste your Square access token'; ?>"><button type="button" class="button" data-pdp-toggle-secret="#pdp-square-token">Show</button></div><small><?php echo $has_token?'A token is already stored. Enter a new token only when replacing it.':'This is the Production or Sandbox access token—not the Application ID.'; ?></small></label>
                            </div>
                            <div class="pdp-sq-security"><span>🔒</span><div><strong>Your token is stored in WordPress settings.</strong><p>It is never shown again on this page and is only sent to Square when Party Desk Pro creates a payment link.</p></div></div>
                        </section>

                        <section class="pdp-sq-card">
                            <div class="pdp-sq-card-head"><div class="pdp-sq-icon">⚙</div><div><h2>Checkout configuration</h2><p>Control the currency and Square API version used for new links.</p></div></div>
                            <div class="pdp-sq-fields pdp-sq-fields-2">
                                <label><span>Currency code</span><input type="text" maxlength="3" name="square_currency" value="<?php echo esc_attr($s['square_currency']); ?>" placeholder="USD"><small>Use the three-letter ISO code, such as USD or CAD.</small></label>
                                <label><span>Square API version</span><input type="text" name="square_version" value="<?php echo esc_attr($s['square_version']); ?>" placeholder="2026-05-20"><small>Keep the saved version unless Square requires an update.</small></label>
                            </div>
                        </section>

                        <section class="pdp-sq-card">
                            <div class="pdp-sq-card-head"><div class="pdp-sq-icon">✉</div><div><h2>Payment-link email</h2><p>Customize the message sent when you click “Email Payment Link” on a license request.</p></div></div>
                            <div class="pdp-sq-fields">
                                <label><span>Email subject</span><input type="text" name="payment_email_subject" value="<?php echo esc_attr($s['payment_email_subject']); ?>" placeholder="Your Party Desk Pro payment link"></label>
                                <label><span>Email introduction</span><textarea name="payment_email_intro" rows="6" placeholder="Thank the customer and explain the next step."><?php echo esc_textarea($s['payment_email_intro']); ?></textarea><small>The secure Square checkout URL is automatically added below this message.</small></label>
                            </div>
                            <div class="pdp-sq-email-preview"><span>EMAIL PREVIEW</span><strong><?php echo esc_html($s['payment_email_subject']); ?></strong><p><?php echo nl2br(esc_html($s['payment_email_intro'])); ?></p><a>Secure Square Payment Link →</a></div>
                        </section>
                    </main>

                    <aside class="pdp-sq-sidebar">
                        <section class="pdp-sq-card pdp-sq-sticky">
                            <div class="pdp-sq-card-head compact"><div class="pdp-sq-icon">✓</div><div><h2>Connection summary</h2><p>Review setup before saving.</p></div></div>
                            <div class="pdp-sq-summary"><div><span>Status</span><strong class="<?php echo $ready?'is-good':'is-warn'; ?>"><?php echo $ready?'Configured':'Incomplete'; ?></strong></div><div><span>Mode</span><strong><?php echo esc_html($mode_label); ?></strong></div><div><span>API host</span><code><?php echo esc_html($api_base); ?></code></div><div><span>Payment workflow</span><strong>Manual approval</strong></div></div>
                            <div class="pdp-sq-help"><h3>Where to find credentials</h3><ol><li>Open the Square Developer Dashboard.</li><li>Select your application.</li><li>Copy the correct Access Token.</li><li>Copy your business Location ID.</li></ol><p><strong>Important:</strong> The Application ID cannot be used as the access token.</p></div>
                        </section>
                    </aside>
                </div>
                <div class="pdp-sq-savebar"><div><strong>Save Square Payments</strong><span>Your existing token remains saved when the token field is blank.</span></div><?php submit_button('Save Square Settings','primary large','pdp_save_square',false); ?></div>
            </form>
        </div>
        <?php
    }

    public static function license_email_template() {
        if(!current_user_can('manage_options'))return;
        $s=get_option(self::OPT_SETTINGS,array());
        if(isset($_POST['pdp_save_license_email_template'])){
            check_admin_referer('pdp_save_license_email_template');
            $text_fields=array('license_email_subject','license_email_preheader','license_email_heading','license_email_button','license_email_download_button','license_email_from_name','license_email_reply_to','license_email_accent','license_email_accent_end','license_email_logo','license_email_company_name','license_email_company_url','license_email_support_email','license_email_support_phone','license_email_company_address');
            foreach($text_fields as $k){$s[$k]=isset($_POST[$k])?sanitize_text_field(wp_unslash($_POST[$k])):'';}
            if($s['license_email_reply_to']&&!is_email($s['license_email_reply_to']))$s['license_email_reply_to']=get_option('admin_email');
            foreach(array('license_email_intro','license_email_support_text','license_email_footer','license_setup_instructions') as $k)$s[$k]=isset($_POST[$k])?sanitize_textarea_field(wp_unslash($_POST[$k])):'';
            foreach(array('license_email_show_logo','license_email_show_subscription','license_email_show_activation','license_email_show_download','license_email_show_account') as $k)$s[$k]=isset($_POST[$k])?'1':'0';
            update_option(self::OPT_SETTINGS,$s);
            echo '<div class="notice notice-success is-dismissible"><p><strong>License email template saved.</strong> Future license emails will use this professional design.</p></div>';
        }
        $s=wp_parse_args($s,array(
          'license_email_subject'=>'Your Party Desk Pro license is ready','license_email_preheader'=>'Your license, subscription, download, and My Account information are inside.','license_email_heading'=>'Welcome to Party Desk Pro!','license_email_intro'=>'Your Party Desk Pro account has been prepared and your license is ready to activate.','license_email_button'=>'Open My Account','license_email_download_button'=>'Download Party Desk Pro','license_email_support_text'=>'Need help getting started? Reply to this email or visit your support page.','license_email_footer'=>'Thank you for choosing Party Desk Pro. We are excited to help you manage and grow your party business.','license_email_from_name'=>'Party Desk Pro','license_email_reply_to'=>get_option('admin_email'),'license_email_logo'=>'','license_email_company_name'=>'Party Desk Pro','license_email_company_url'=>home_url('/'),'license_email_support_email'=>get_option('admin_email'),'license_email_support_phone'=>'','license_email_company_address'=>'','license_email_accent'=>'#2563eb','license_email_accent_end'=>'#6d5dfc','license_email_show_logo'=>'1','license_email_show_subscription'=>'1','license_email_show_activation'=>'1','license_email_show_download'=>'1','license_email_show_account'=>'1','license_setup_instructions'=>"1. Download the Party Desk Pro ZIP file.\n2. Upload and activate it in WordPress.\n3. Open Party Desk Pro > License.\n4. Enter your server URL and license key.\n5. Click Activate License."
        ));
        $preview=self::build_license_email_html(array('business'=>'Sample Business','email'=>'client@example.com','plan'=>'Professional','price'=>'$79 / month','status'=>'Active','sites'=>'3','expires'=>date('F j, Y',strtotime('+1 year')),'key'=>'PDP-PRO-AB12-CD34-EF56-GH78','portal'=>'#','download'=>'#','version'=>'4.2.2','reset'=>'#'),$s,true);
        ?>
        <div class="wrap pdp-admin pdp-modern-admin pdp-email-builder">
          <div class="pdp-admin-hero"><div><span class="pdp-admin-kicker">CUSTOMER DELIVERY EXPERIENCE</span><h1>License Email Template</h1><p>Customize the polished email customers receive with their subscription, license key, plugin download, and My Account access.</p></div><div class="pdp-admin-hero-actions"><a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=pdp-license-settings')); ?>">License Settings</a><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=pdp-licenses')); ?>">View Licenses</a></div></div>
          <form method="post"><?php wp_nonce_field('pdp_save_license_email_template'); ?>
            <div class="pdp-email-builder-grid"><main>
              <section class="pdp-email-builder-card"><div class="pdp-email-card-head"><span class="dashicons dashicons-format-image"></span><div><h2>Email branding</h2><p>Add your company logo and contact details to every license email.</p></div></div><div class="pdp-email-logo-field"><div class="pdp-email-logo-preview<?php echo empty($s['license_email_logo'])?' is-empty':''; ?>" id="pdp-email-logo-preview"><?php if(!empty($s['license_email_logo'])): ?><img src="<?php echo esc_url($s['license_email_logo']); ?>" alt="Email logo preview"><?php else: ?><span class="dashicons dashicons-format-image"></span><strong>No logo selected</strong><small>Recommended: transparent PNG, up to 600 × 180 px</small><?php endif; ?></div><div><input type="hidden" id="pdp-license-email-logo" name="license_email_logo" value="<?php echo esc_attr($s['license_email_logo']); ?>"><button type="button" class="button button-primary" data-pdp-email-logo-upload>Upload Logo</button> <button type="button" class="button" data-pdp-email-logo-remove <?php echo empty($s['license_email_logo'])?'style=\"display:none\"':''; ?>>Remove</button><p class="description">PNG, JPG, GIF, SVG or WebP. The logo is centered at the top of the email.</p></div></div><div class="pdp-email-fields two"><label><span>Company name</span><input name="license_email_company_name" value="<?php echo esc_attr($s['license_email_company_name']); ?>"></label><label><span>Company website</span><input type="url" name="license_email_company_url" value="<?php echo esc_attr($s['license_email_company_url']); ?>"></label><label><span>Support email</span><input type="email" name="license_email_support_email" value="<?php echo esc_attr($s['license_email_support_email']); ?>"></label><label><span>Support phone</span><input name="license_email_support_phone" value="<?php echo esc_attr($s['license_email_support_phone']); ?>"></label></div><label><span>Company address <small>(optional)</small></span><input name="license_email_company_address" value="<?php echo esc_attr($s['license_email_company_address']); ?>"></label></section><section class="pdp-email-builder-card"><div class="pdp-email-card-head"><span class="dashicons dashicons-admin-generic"></span><div><h2>Email identity</h2><p>Control how the message appears in the customer’s inbox.</p></div></div><div class="pdp-email-fields two"><label><span>From name</span><input name="license_email_from_name" value="<?php echo esc_attr($s['license_email_from_name']); ?>"></label><label><span>Reply-to email</span><input type="email" name="license_email_reply_to" value="<?php echo esc_attr($s['license_email_reply_to']); ?>"></label></div><label><span>Email subject</span><input name="license_email_subject" value="<?php echo esc_attr($s['license_email_subject']); ?>"></label><label><span>Inbox preview text</span><input name="license_email_preheader" value="<?php echo esc_attr($s['license_email_preheader']); ?>"><small>This appears beside the subject in many email apps.</small></label></section>
              <section class="pdp-email-builder-card"><div class="pdp-email-card-head"><span class="dashicons dashicons-welcome-write-blog"></span><div><h2>Message content</h2><p>Write the welcome message and calls to action.</p></div></div><label><span>Main heading</span><input name="license_email_heading" value="<?php echo esc_attr($s['license_email_heading']); ?>"></label><label><span>Introduction</span><textarea name="license_email_intro" rows="4"><?php echo esc_textarea($s['license_email_intro']); ?></textarea></label><div class="pdp-email-fields two"><label><span>My Account button</span><input name="license_email_button" value="<?php echo esc_attr($s['license_email_button']); ?>"></label><label><span>Download button</span><input name="license_email_download_button" value="<?php echo esc_attr($s['license_email_download_button']); ?>"></label></div><label><span>Setup and activation instructions</span><textarea name="license_setup_instructions" rows="7"><?php echo esc_textarea($s['license_setup_instructions']); ?></textarea></label><label><span>Support message</span><textarea name="license_email_support_text" rows="3"><?php echo esc_textarea($s['license_email_support_text']); ?></textarea></label><label><span>Footer message</span><textarea name="license_email_footer" rows="3"><?php echo esc_textarea($s['license_email_footer']); ?></textarea></label></section>
              <section class="pdp-email-builder-card"><div class="pdp-email-card-head"><span class="dashicons dashicons-art"></span><div><h2>Design and visible sections</h2><p>Match your branding and choose the information included.</p></div></div><div class="pdp-email-fields two"><label><span>Primary color</span><div class="pdp-email-color"><input type="color" name="license_email_accent" value="<?php echo esc_attr($s['license_email_accent']); ?>"><code><?php echo esc_html($s['license_email_accent']); ?></code></div></label><label><span>Gradient color</span><div class="pdp-email-color"><input type="color" name="license_email_accent_end" value="<?php echo esc_attr($s['license_email_accent_end']); ?>"><code><?php echo esc_html($s['license_email_accent_end']); ?></code></div></label></div><div class="pdp-email-toggle-grid"><?php foreach(array('license_email_show_logo'=>'Company logo','license_email_show_subscription'=>'Subscription details','license_email_show_activation'=>'License activation','license_email_show_download'=>'Plugin download','license_email_show_account'=>'My Account access') as $k=>$label): ?><label><input type="checkbox" name="<?php echo esc_attr($k); ?>" value="1" <?php checked($s[$k],'1'); ?>><span class="pdp-email-toggle"></span><span><strong><?php echo esc_html($label); ?></strong><small>Include this section in the customer email</small></span></label><?php endforeach; ?></div></section>
            </main><aside><section class="pdp-email-preview-panel"><div class="pdp-email-preview-head"><div><span class="pdp-admin-kicker">LIVE PREVIEW</span><h2>Customer email</h2></div><span>Desktop email</span></div><div class="pdp-email-preview-frame"><?php echo $preview; ?></div><div class="pdp-email-placeholders"><strong>Information added automatically</strong><div><code>Business name</code><code>Plan and price</code><code>License key</code><code>Expiration</code><code>Website allowance</code><code>Plugin ZIP</code><code>My Account link</code><code>Password setup link</code></div></div></section></aside></div>
            <div class="pdp-email-savebar"><div><strong>License Email Template</strong><span>Save your professional customer delivery email.</span></div><button class="button button-primary button-hero" name="pdp_save_license_email_template" value="1">Save Email Template</button></div>
          </form>
        </div><?php
    }

    public static function license_settings() {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to manage license settings.');

        if (isset($_POST['pdp_save_license_settings']) && check_admin_referer('pdp_save_license_settings_action')) {
            $s = get_option(self::OPT_SETTINGS, array());
            $prefix = isset($_POST['license_prefix']) ? strtoupper(preg_replace('/[^A-Z0-9]/', '', sanitize_text_field(wp_unslash($_POST['license_prefix'])))) : 'PDP';
            $s['license_prefix'] = $prefix ?: 'PDP';
            $s['license_segments'] = isset($_POST['license_segments']) ? min(6, max(2, absint($_POST['license_segments']))) : 4;
            $s['license_segment_length'] = isset($_POST['license_segment_length']) ? min(8, max(3, absint($_POST['license_segment_length']))) : 4;
            $s['license_include_plan'] = isset($_POST['license_include_plan']) ? '1' : '0';
            $s['license_email_subject'] = isset($_POST['license_email_subject']) ? sanitize_text_field(wp_unslash($_POST['license_email_subject'])) : '';
            $s['license_email_intro'] = isset($_POST['license_email_intro']) ? sanitize_textarea_field(wp_unslash($_POST['license_email_intro'])) : '';
            $s['license_zip_attachment_id'] = isset($_POST['license_zip_attachment_id']) ? absint($_POST['license_zip_attachment_id']) : 0;
            $s['license_zip_version'] = isset($_POST['license_zip_version']) ? sanitize_text_field(wp_unslash($_POST['license_zip_version'])) : '';
            $s['license_auto_email'] = isset($_POST['license_auto_email']) ? '1' : '0';
            $s['license_setup_instructions'] = isset($_POST['license_setup_instructions']) ? sanitize_textarea_field(wp_unslash($_POST['license_setup_instructions'])) : '';
            $s['portal_download_heading'] = isset($_POST['portal_download_heading']) ? sanitize_text_field(wp_unslash($_POST['portal_download_heading'])) : 'Download Party Desk Pro';
            $s['portal_download_text'] = isset($_POST['portal_download_text']) ? sanitize_textarea_field(wp_unslash($_POST['portal_download_text'])) : '';
            $s['portal_download_button'] = isset($_POST['portal_download_button']) ? sanitize_text_field(wp_unslash($_POST['portal_download_button'])) : 'Download Plugin ZIP';
            $s['portal_page_url'] = isset($_POST['portal_page_url']) ? esc_url_raw(wp_unslash($_POST['portal_page_url'])) : home_url('/my-account/');
            update_option(self::OPT_SETTINGS, $s);
            echo '<div class="notice notice-success is-dismissible"><p><strong>License settings saved.</strong> Your license format, delivery file, email, and customer portal settings are updated.</p></div>';
        }

        $s = wp_parse_args(get_option(self::OPT_SETTINGS, array()), array(
            'license_prefix'=>'PDP','license_segments'=>'4','license_segment_length'=>'4','license_include_plan'=>'0',
            'license_email_subject'=>'Your Party Desk Pro license','license_email_intro'=>'Your Party Desk Pro license is ready.',
            'license_zip_attachment_id'=>0,'license_zip_version'=>'','license_auto_email'=>'1',
            'license_setup_instructions'=>"1. Download the Party Desk Pro ZIP file.\n2. Upload it under Plugins > Add New > Upload Plugin.\n3. Activate Party Desk Pro.\n4. Open Party Desk Pro > License.\n5. Enter the server URL and license key, then activate.",
            'portal_download_heading'=>'Download Party Desk Pro','portal_download_text'=>'Download the latest plugin ZIP included with your subscription.',
            'portal_download_button'=>'Download Plugin ZIP','portal_page_url'=>home_url('/my-account/')
        ));

        $zip_url = self::license_zip_url($s);
        $zip_name = '';
        if (!empty($s['license_zip_attachment_id'])) {
            $zip_name = basename((string) get_attached_file(absint($s['license_zip_attachment_id'])));
        }
        $api_url = rest_url('party-desk-license/v1/');
        $https_ok = is_ssl();
        $portal_ok = !empty($s['portal_page_url']);
        $zip_ok = !empty($zip_url);
        ?>
        <div class="wrap pdp-admin pdp-license-settings-pro">
            <div class="pdp-ls-hero">
                <div>
                    <span class="pdp-ls-kicker">PARTY DESK PRO SERVER</span>
                    <h1>License Settings</h1>
                    <p>Configure secure license keys, plugin delivery, customer emails, and the My Account download experience.</p>
                </div>
                <div class="pdp-ls-hero-actions">
                    <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=pdp_license')); ?>">View Licenses</a>
                    <button type="submit" form="pdp-license-settings-form" class="button button-primary">Save Changes</button>
                </div>
            </div>

            <div class="pdp-ls-status-grid">
                <article><span class="pdp-ls-status-icon <?php echo $https_ok?'is-good':'is-warn'; ?>">●</span><div><strong><?php echo $https_ok?'Secure HTTPS':'HTTPS Recommended'; ?></strong><small><?php echo esc_html(home_url('/')); ?></small></div></article>
                <article><span class="pdp-ls-status-icon is-good">●</span><div><strong>License API Ready</strong><small><?php echo esc_html($api_url); ?></small></div></article>
                <article><span class="pdp-ls-status-icon <?php echo $zip_ok?'is-good':'is-warn'; ?>">●</span><div><strong><?php echo $zip_ok?'Plugin ZIP Selected':'Plugin ZIP Missing'; ?></strong><small><?php echo esc_html($zip_name ?: 'Choose the customer download file below'); ?></small></div></article>
                <article><span class="pdp-ls-status-icon <?php echo $portal_ok?'is-good':'is-warn'; ?>">●</span><div><strong><?php echo $portal_ok?'My Account Connected':'Portal URL Needed'; ?></strong><small><?php echo esc_html($s['portal_page_url'] ?: 'No page URL configured'); ?></small></div></article>
            </div>

            <form method="post" id="pdp-license-settings-form">
                <?php wp_nonce_field('pdp_save_license_settings_action'); ?>
                <div class="pdp-ls-layout">
                    <main class="pdp-ls-main">
                        <section class="pdp-ls-card" id="pdp-license-format-card">
                            <div class="pdp-ls-card-head"><div class="pdp-ls-card-icon">🔑</div><div><h2>License Key Format</h2><p>Control how newly generated customer license keys are structured.</p></div></div>
                            <div class="pdp-ls-fields pdp-ls-fields-3">
                                <label><span>License prefix</span><input name="license_prefix" id="pdp-license-prefix" maxlength="12" value="<?php echo esc_attr($s['license_prefix']); ?>"><small>Letters and numbers only.</small></label>
                                <label><span>Number of segments</span><input type="number" min="2" max="6" name="license_segments" id="pdp-license-segments" value="<?php echo intval($s['license_segments']); ?>"></label>
                                <label><span>Characters per segment</span><input type="number" min="3" max="8" name="license_segment_length" id="pdp-license-length" value="<?php echo intval($s['license_segment_length']); ?>"></label>
                            </div>
                            <label class="pdp-ls-switch"><input type="checkbox" name="license_include_plan" id="pdp-license-plan" value="1" <?php checked($s['license_include_plan'],'1'); ?>><span></span><div><strong>Include plan abbreviation</strong><small>Adds a short plan label such as PRO to the key.</small></div></label>
                            <div class="pdp-ls-key-preview"><span>LIVE KEY PREVIEW</span><code id="pdp-live-key-preview"><?php echo esc_html(self::format_license_example($s)); ?></code><button type="button" class="button button-small" id="pdp-copy-key-preview">Copy</button></div>
                        </section>

                        <section class="pdp-ls-card">
                            <div class="pdp-ls-card-head"><div class="pdp-ls-card-icon">📦</div><div><h2>Plugin ZIP Delivery</h2><p>Select the full Party Desk Pro plugin customers receive with their license.</p></div></div>
                            <input type="hidden" id="pdp-license-zip-id" name="license_zip_attachment_id" value="<?php echo absint($s['license_zip_attachment_id']); ?>">
                            <div class="pdp-ls-upload <?php echo $zip_ok?'has-file':''; ?>" id="pdp-license-upload-box">
                                <div class="pdp-ls-upload-icon">ZIP</div>
                                <div class="pdp-ls-upload-copy"><strong id="pdp-license-zip-name"><?php echo esc_html($zip_name ?: 'No plugin ZIP selected'); ?></strong><small id="pdp-license-zip-url-label"><?php echo esc_html($zip_url ?: 'Choose a .zip file from the WordPress Media Library.'); ?></small></div>
                                <div class="pdp-ls-upload-actions"><button type="button" class="button button-primary" id="pdp-select-license-zip"><?php echo $zip_ok?'Replace ZIP':'Choose ZIP File'; ?></button><button type="button" class="button" id="pdp-remove-license-zip" <?php disabled(!$zip_ok); ?>>Remove</button></div>
                            </div>
                            <input type="hidden" id="pdp-license-zip-url" value="<?php echo esc_attr($zip_url); ?>">
                            <label class="pdp-ls-full"><span>Published plugin version</span><input name="license_zip_version" value="<?php echo esc_attr($s['license_zip_version']); ?>" placeholder="Example: 4.2.2"><small>This version appears in the customer portal and license email.</small></label>
                            <label class="pdp-ls-switch"><input type="checkbox" name="license_auto_email" value="1" <?php checked($s['license_auto_email'],'1'); ?>><span></span><div><strong>Automatically email new licenses</strong><small>Send the customer their key, account link, instructions, and plugin download when a license is created.</small></div></label>
                        </section>

                        <section class="pdp-ls-card">
                            <div class="pdp-ls-card-head"><div class="pdp-ls-card-icon">✉️</div><div><h2>License Email</h2><p>Customize the message sent with each new license.</p></div></div>
                            <label class="pdp-ls-full"><span>Email subject</span><input name="license_email_subject" value="<?php echo esc_attr($s['license_email_subject']); ?>"></label>
                            <label class="pdp-ls-full"><span>Email introduction</span><textarea name="license_email_intro" rows="4"><?php echo esc_textarea($s['license_email_intro']); ?></textarea><small>The customer’s subscription and license information is added automatically.</small></label>
                            <label class="pdp-ls-full"><span>Setup and activation instructions</span><textarea name="license_setup_instructions" rows="9"><?php echo esc_textarea($s['license_setup_instructions']); ?></textarea></label>
                        </section>

                        <section class="pdp-ls-card">
                            <div class="pdp-ls-card-head"><div class="pdp-ls-card-icon">👤</div><div><h2>Customer My Account Page</h2><p>Connect the My Account page and customize its plugin download card.</p></div></div>
                            <label class="pdp-ls-full"><span>My Account page URL</span><input type="url" name="portal_page_url" value="<?php echo esc_attr($s['portal_page_url']); ?>" placeholder="<?php echo esc_attr(home_url('/my-account/')); ?>"><small>Create a WordPress page containing <code>[pdpclientportal]</code>, then enter its URL here.</small></label>
                            <div class="pdp-ls-fields pdp-ls-fields-2">
                                <label><span>Download card heading</span><input name="portal_download_heading" value="<?php echo esc_attr($s['portal_download_heading']); ?>"></label>
                                <label><span>Download button text</span><input name="portal_download_button" value="<?php echo esc_attr($s['portal_download_button']); ?>"></label>
                            </div>
                            <label class="pdp-ls-full"><span>Download card description</span><textarea name="portal_download_text" rows="3"><?php echo esc_textarea($s['portal_download_text']); ?></textarea></label>
                        </section>
                    </main>

                    <aside class="pdp-ls-sidebar">
                        <section class="pdp-ls-card pdp-ls-sticky">
                            <div class="pdp-ls-card-head compact"><div class="pdp-ls-card-icon">⚡</div><div><h2>Server Tools</h2><p>Quick checks for your license connection.</p></div></div>
                            <div class="pdp-ls-tool"><span>License server URL</span><code id="pdp-server-url"><?php echo esc_html(home_url('/')); ?></code><button type="button" class="button" data-copy="#pdp-server-url">Copy URL</button></div>
                            <div class="pdp-ls-tool"><span>REST API endpoint</span><code id="pdp-api-url"><?php echo esc_html($api_url); ?></code><button type="button" class="button" data-copy="#pdp-api-url">Copy API</button></div>
                            <button type="button" class="button button-primary pdp-ls-test" id="pdp-test-license-api" data-rest-root="<?php echo esc_url(rest_url()); ?>">Test API Connection</button>
                            <div class="pdp-ls-test-result" id="pdp-api-test-result" aria-live="polite">Ready to test.</div>
                            <hr>
                            <div class="pdp-ls-help"><strong>Customer plugin setup</strong><ol><li>Enter the License server URL in Party Desk Pro.</li><li>Enter the customer license key.</li><li>Click Activate License.</li></ol></div>
                        </section>
                    </aside>
                </div>
                <div class="pdp-ls-savebar"><div><strong>License Server Settings</strong><span>Save after changing the key format, plugin ZIP, email, or portal.</span></div><button type="submit" name="pdp_save_license_settings" value="1" class="button button-primary button-hero">Save License Settings</button></div>
            </form>
        </div>
        <script>
        jQuery(function($){
            function previewKey(){
                var prefix=String($('#pdp-license-prefix').val()||'PDP').toUpperCase().replace(/[^A-Z0-9]/g,'')||'PDP';
                var segments=Math.min(6,Math.max(2,parseInt($('#pdp-license-segments').val(),10)||4));
                var length=Math.min(8,Math.max(3,parseInt($('#pdp-license-length').val(),10)||4));
                var parts=[prefix]; if($('#pdp-license-plan').is(':checked')) parts.push('PRO');
                for(var i=0;i<segments;i++) parts.push('AB12CD34'.substring(0,length));
                $('#pdp-live-key-preview').text(parts.join('-'));
            }
            $('#pdp-license-prefix,#pdp-license-segments,#pdp-license-length,#pdp-license-plan').on('input change',previewKey); previewKey();
            function copyText(text,button){if(navigator.clipboard){navigator.clipboard.writeText(text).then(function(){var old=button.text();button.text('Copied!');setTimeout(function(){button.text(old);},1400);});}}
            $('#pdp-copy-key-preview').on('click',function(){copyText($('#pdp-live-key-preview').text(),$(this));});
            $('[data-copy]').on('click',function(){copyText($($(this).data('copy')).text(),$(this));});
            var frame;
            $('#pdp-select-license-zip').on('click',function(e){e.preventDefault(); if(frame){frame.open();return;} frame=wp.media({title:'Choose Party Desk Pro ZIP',button:{text:'Use this ZIP'},multiple:false,library:{type:['application/zip','application/x-zip-compressed']}}); frame.on('select',function(){var a=frame.state().get('selection').first().toJSON(); $('#pdp-license-zip-id').val(a.id); $('#pdp-license-zip-url').val(a.url); $('#pdp-license-zip-name').text(a.filename||'Plugin ZIP selected'); $('#pdp-license-zip-url-label').text(a.url); $('#pdp-license-upload-box').addClass('has-file'); $('#pdp-remove-license-zip').prop('disabled',false); $('#pdp-select-license-zip').text('Replace ZIP');}); frame.open();});
            $('#pdp-remove-license-zip').on('click',function(){ $('#pdp-license-zip-id').val(0); $('#pdp-license-zip-url').val(''); $('#pdp-license-zip-name').text('No plugin ZIP selected'); $('#pdp-license-zip-url-label').text('Choose a .zip file from the WordPress Media Library.'); $('#pdp-license-upload-box').removeClass('has-file'); $(this).prop('disabled',true); $('#pdp-select-license-zip').text('Choose ZIP File');});
            $('#pdp-test-license-api').on('click',function(){var b=$(this),r=$('#pdp-api-test-result'),root=b.data('rest-root'); b.prop('disabled',true).text('Testing…'); r.removeClass('is-good is-bad').text('Connecting to the WordPress REST API…'); fetch(root,{credentials:'same-origin'}).then(function(res){if(!res.ok)throw new Error('HTTP '+res.status);return res.json();}).then(function(data){var namespaces=data.namespaces||[];if(namespaces.indexOf('party-desk-license/v1')===-1)throw new Error('License API namespace was not found.');r.addClass('is-good').text('Connection successful — Party Desk Pro License API is online.');}).catch(function(err){r.addClass('is-bad').text('Connection failed — '+err.message);}).finally(function(){b.prop('disabled',false).text('Test API Connection');});});
        });
        </script>
        <?php
    }

    private static function format_license_example($s){
        $parts=array(strtoupper($s['license_prefix']?:'PDP'));
        if(!empty($s['license_include_plan']))$parts[]='PRO';
        for($i=0;$i<(int)$s['license_segments'];$i++)$parts[]=substr('AB12CD34',0,(int)$s['license_segment_length']);
        return implode('-',$parts);
    }

    private static function generate_unique_license_key($plan_id=0){
        $s=wp_parse_args(get_option(self::OPT_SETTINGS,array()),array('license_prefix'=>'PDP','license_segments'=>'4','license_segment_length'=>'4','license_include_plan'=>'0'));
        $alphabet='ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for($attempt=0;$attempt<25;$attempt++){
            $parts=array(strtoupper($s['license_prefix']?:'PDP'));
            if($s['license_include_plan']==='1' && $plan_id){$slug=strtoupper(preg_replace('/[^A-Z0-9]/','',get_the_title($plan_id)));$parts[]=substr($slug,0,4)?:'PLAN';}
            for($i=0;$i<(int)$s['license_segments'];$i++){
                $segment='';for($j=0;$j<(int)$s['license_segment_length'];$j++)$segment.=$alphabet[wp_rand(0,strlen($alphabet)-1)];$parts[]=$segment;
            }
            $key=implode('-',$parts);
            $found=get_posts(array('post_type'=>'pdp_license','post_status'=>'any','numberposts'=>1,'fields'=>'ids','meta_key'=>'_pdp_key','meta_value'=>$key));
            if(!$found)return $key;
        }
        return strtoupper(($s['license_prefix']?:'PDP').'-'.wp_generate_password(24,false,false));
    }

    private static function plans() { return get_posts(array('post_type'=>'pdp_plan','post_status'=>'publish','numberposts'=>-1,'orderby'=>array('menu_order'=>'ASC','date'=>'ASC'),'meta_key'=>'_pdp_active','meta_value'=>'1')); }

    public static function signup_shortcode($atts=array()) {
        // Enqueue here as a second safeguard for Elementor, block themes, and cached pages.
        self::front_assets();
        $preview=!empty($atts['preview']);
        $s=get_option(self::OPT_SETTINGS,array());
        $fields=get_option(self::OPT_FIELDS,self::default_fields());
        $errors=array();
        $success=false;
        $submitted=!$preview && isset($_POST['pdp_signup_submit']);

        if($submitted){
            if(!isset($_POST['pdp_signup_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pdp_signup_nonce'])),'pdp_signup'))$errors[]='Please refresh and try again.';
            if(!empty($_POST['company_fax']))$errors[]='Unable to submit.';
            $data=array();
            foreach($fields as$k=>$cfg){
                if(empty($cfg['show']))continue;
                $raw=isset($_POST[$k])?wp_unslash($_POST[$k]):'';
                $v=$cfg['type']==='email'?sanitize_email($raw):($cfg['type']==='url'?esc_url_raw($raw):sanitize_textarea_field($raw));
                if(!empty($cfg['required'])&&$v==='')$errors[]=sprintf('%s is required.',$cfg['label']);
                if($k==='email'&&$v&&!is_email($v))$errors[]='Please enter a valid email address.';
                if($k==='website'&&$v&&!preg_match('~^https?://~i',$v))$v='https://'.$v;
                if($k==='website'&&$v&&!filter_var($v,FILTER_VALIDATE_URL))$errors[]='Please enter a valid website address.';
                $data[$k]=$v;
            }
            $plan_id=isset($_POST['plan_id'])?absint($_POST['plan_id']):0;
            $plan=get_post($plan_id);
            if(!$plan||$plan->post_type!=='pdp_plan'||get_post_meta($plan_id,'_pdp_active',true)!=='1')$errors[]='Please choose a valid plan.';
            if(!$errors){
                $title=($data['business_name']??'New signup').' — '.($data['contact_name']??'');
                $id=wp_insert_post(array('post_type'=>'pdp_request','post_status'=>'publish','post_title'=>$title));
                if($id&&!is_wp_error($id)){
                    foreach($data as$k=>$v)update_post_meta($id,'_pdp_'.$k,$v);
                    update_post_meta($id,'_pdp_plan_id',$plan_id);
                    $price=(float)get_post_meta($plan_id,'_pdp_price',true);
                    $setup=(float)get_post_meta($plan_id,'_pdp_setup_fee',true);
                    update_post_meta($id,'_pdp_amount',$price+$setup);
                    update_post_meta($id,'_pdp_status','New');
                    wp_mail(get_option('admin_email'),'New Party Desk Pro signup: '.$title,'A new signup request is ready for review.');
                    $success=true;
                }else $errors[]='The request could not be saved.';
            }
        }

        ob_start();
        $accent=esc_attr($s['accent']??'#6d5dfc');
        $initial_step=$errors?'2':'1';
        echo '<div class="pdp-signup" style="--pdp-accent:'.$accent.'" data-initial-step="'.$initial_step.'">';
        if($success){
            echo '<div class="pdp-success"><h3>Request received</h3><p>Thank you. We will review your information and send your payment link.</p></div></div>';
            return ob_get_clean();
        }
        if($errors)echo '<div class="pdp-errors"><strong>Please correct the following:</strong><ul><li>'.implode('</li><li>',array_map('esc_html',$errors)).'</li></ul></div>';

        echo '<div class="pdp-signup-head"><span>PARTY DESK PRO</span><h2>'.esc_html($s['form_title']??'Choose Your Plan').'</h2><p>'.esc_html($s['form_intro']??'').'</p><div class="pdp-signup-progress" aria-label="Signup progress"><button type="button" class="pdp-progress-step is-active" data-go-step="1"><b>1</b><span><strong>Choose a plan</strong><small>Select your package</small></span></button><i></i><button type="button" class="pdp-progress-step" data-go-step="2"><b>2</b><span><strong>Your information</strong><small>Business and contact details</small></span></button></div></div>';
        echo '<form method="post" class="pdp-multistep-form" novalidate>';
        wp_nonce_field('pdp_signup','pdp_signup_nonce');
        echo '<input class="pdp-hp" name="company_fax" tabindex="-1" autocomplete="off">';

        echo '<div class="pdp-form-panel is-active" data-step-panel="1"><section><div class="pdp-step"><b>1</b><div><h3>Select your package</h3><p>Choose the plan that fits your business. You can review your selection before submitting.</p></div></div><div class="pdp-plan-grid">';
        foreach(self::plans() as$p){
            $price=(float)get_post_meta($p->ID,'_pdp_price',true);
            $billing=get_post_meta($p->ID,'_pdp_billing',true);
            $featured=get_post_meta($p->ID,'_pdp_featured',true)==='1';
            $trial=(int)get_post_meta($p->ID,'_pdp_trial_days',true);
            $features=array_filter(array_map('trim',explode("\n",get_post_meta($p->ID,'_pdp_features',true))));
            $checked=isset($_POST['plan_id'])&&absint($_POST['plan_id'])===$p->ID;
            $price_text=($billing==='trial'||$price==0?'Free':'$'.number_format($price,2));
            $billing_text=self::billing_price_suffix($billing);
            echo '<label class="pdp-plan-card'.($featured?' featured':'').($checked?' selected':'').'"><input type="radio" name="plan_id" value="'.intval($p->ID).'" data-plan-name="'.esc_attr($p->post_title).'" data-plan-price="'.esc_attr($price_text.$billing_text).'" '.checked($checked,true,false).'><div class="pdp-plan-inner">';
            $badge=get_post_meta($p->ID,'_pdp_badge',true);
            if($badge)echo '<span class="pdp-badge">'.esc_html($badge).'</span>';
            echo '<h4>'.esc_html($p->post_title).'</h4><p>'.esc_html(get_post_meta($p->ID,'_pdp_description',true)).'</p><div class="pdp-price">'.$price_text.'<small>'.$billing_text.'</small></div>';
            if($trial)echo '<p class="pdp-trial">'.intval($trial).'-day trial</p>';
            echo '<ul>';
            foreach($features as$f)echo '<li>✓ '.esc_html($f).'</li>';
            echo '</ul><span class="pdp-select">'.esc_html(get_post_meta($p->ID,'_pdp_button',true)?:'Choose Plan').'</span></div></label>';
        }
        echo '</div><div class="pdp-step-actions pdp-step-actions-first"><div class="pdp-step-message" role="alert" aria-live="polite"></div><button type="button" class="pdp-next-step">Continue to Your Information <span>→</span></button></div></section></div>';

        echo '<div class="pdp-form-panel" data-step-panel="2"><section class="pdp-selected-plan-summary"><div><span>SELECTED PLAN</span><strong data-selected-plan-name>Choose a plan</strong><small data-selected-plan-price></small></div><button type="button" data-go-step="1">Change plan</button></section>';
        foreach(array('business'=>'Business information','contact'=>'Contact information')as$section=>$title){
            echo '<section><div class="pdp-step"><b>'.($section==='business'?'2A':'2B').'</b><div><h3>'.esc_html($title).'</h3><p>'.($section==='business'?'Tell us about the company that will use Party Desk Pro.':'Enter the best information for account setup and follow-up.').'</p></div></div><div class="pdp-fields">';
            foreach($fields as$k=>$cfg){
                if(empty($cfg['show'])||$cfg['section']!==$section)continue;
                $val=isset($_POST[$k])?wp_unslash($_POST[$k]):'';
                echo '<label class="'.($cfg['type']==='textarea'?'pdp-wide':'').'">'.esc_html($cfg['label']).(!empty($cfg['required'])?' <em>*</em>':'');
                if($cfg['type']==='textarea')echo '<textarea name="'.esc_attr($k).'" placeholder="'.esc_attr($cfg['placeholder']).'" '.(!empty($cfg['required'])?'required':'').'>'.esc_textarea($val).'</textarea>';
                else echo '<input type="'.esc_attr($cfg['type']).'" name="'.esc_attr($k).'" value="'.esc_attr($val).'" placeholder="'.esc_attr($cfg['placeholder']).'" '.(!empty($cfg['required'])?'required':'').'>';
                echo '</label>';
            }
            echo '</div></section>';
        }
        echo '<div class="pdp-submit pdp-final-actions"><button type="button" class="pdp-back-step">← Back to Plans</button><div><p>Submitting this form does not charge your card. We will review your request and send a secure Square payment link.</p><button type="submit" name="pdp_signup_submit">'.esc_html($s['submit_text']??'Submit Request').'</button></div></div></div></form></div>';
        return ob_get_clean();
    }

    public static function create_square_link() {
        $id=isset($_GET['request_id'])?absint($_GET['request_id']):0;if(!$id||!current_user_can('edit_post',$id)||!check_admin_referer('pdp_create_square_'.$id))wp_die('Not allowed.');$s=get_option(self::OPT_SETTINGS,array());
        if(empty($s['square_token'])||empty($s['square_location']))self::redirect_notice($id,'Square access token and location ID are required.','error');
        $amount=(int)round((float)get_post_meta($id,'_pdp_amount',true)*100);if($amount<1)self::redirect_notice($id,'This plan is free and does not need a payment link.','error');
        $plan_id=absint(get_post_meta($id,'_pdp_plan_id',true));$body=array('idempotency_key'=>wp_generate_uuid4(),'quick_pay'=>array('name'=>get_the_title($plan_id).' — '.get_post_meta($id,'_pdp_business_name',true),'price_money'=>array('amount'=>$amount,'currency'=>strtoupper($s['square_currency']??'USD')),'location_id'=>$s['square_location']),'pre_populated_data'=>array('buyer_email'=>get_post_meta($id,'_pdp_email',true),'buyer_phone_number'=>get_post_meta($id,'_pdp_phone',true)));
        $base=($s['square_mode']??'production')==='sandbox'?'https://connect.squareupsandbox.com':'https://connect.squareup.com';$r=wp_remote_post($base.'/v2/online-checkout/payment-links',array('timeout'=>30,'headers'=>array('Authorization'=>'Bearer '.$s['square_token'],'Square-Version'=>$s['square_version']??'2026-05-20','Content-Type'=>'application/json'),'body'=>wp_json_encode($body)));
        if(is_wp_error($r))self::redirect_notice($id,$r->get_error_message(),'error');$code=wp_remote_retrieve_response_code($r);$json=json_decode(wp_remote_retrieve_body($r),true);if($code<200||$code>=300||empty($json['payment_link']['url'])){$msg=!empty($json['errors'][0]['detail'])?$json['errors'][0]['detail']:'Square could not create the payment link.';self::redirect_notice($id,$msg,'error');}
        update_post_meta($id,'_pdp_square_url',esc_url_raw($json['payment_link']['url']));update_post_meta($id,'_pdp_square_id',sanitize_text_field($json['payment_link']['id']??''));update_post_meta($id,'_pdp_status','Payment link created');self::redirect_notice($id,'Square payment link created.','success');
    }
    public static function email_square_link() {$id=isset($_GET['request_id'])?absint($_GET['request_id']):0;if(!$id||!current_user_can('edit_post',$id)||!check_admin_referer('pdp_email_square_'.$id))wp_die('Not allowed.');$url=get_post_meta($id,'_pdp_square_url',true);$email=get_post_meta($id,'_pdp_email',true);$s=get_option(self::OPT_SETTINGS,array());if(!$url||!is_email($email))self::redirect_notice($id,'A valid email and payment link are required.','error');$body=($s['payment_email_intro']??'Complete payment using the link below.')."\n\n".$url;wp_mail($email,$s['payment_email_subject']??'Your payment link',$body);update_post_meta($id,'_pdp_status','Payment link emailed');self::redirect_notice($id,'Payment link emailed.','success');}
    public static function create_license_from_request() {
        $id=isset($_GET['request_id'])?absint($_GET['request_id']):0;
        if(!$id||!current_user_can('edit_post',$id)||!check_admin_referer('pdp_create_license_'.$id))wp_die('Not allowed.');
        self::create_license_for_request($id,false);
    }
    public static function create_trial_license_from_request() {
        $id=isset($_GET['request_id'])?absint($_GET['request_id']):0;
        if(!$id||!current_user_can('edit_post',$id)||!check_admin_referer('pdp_create_trial_'.$id))wp_die('Not allowed.');
        self::create_license_for_request($id,true);
    }
    private static function create_license_for_request($id,$force_trial){
        $plan_id=absint(get_post_meta($id,'_pdp_plan_id',true));
        if(!$plan_id || get_post_type($plan_id)!=='pdp_plan')self::redirect_notice($id,'Select a valid plan before creating the license.','error');
        $existing=absint(get_post_meta($id,'_pdp_license_id',true));
        if($existing && get_post($existing)){wp_safe_redirect(get_edit_post_link($existing,'url'));exit;}
        $business=get_post_meta($id,'_pdp_business_name',true);
        $title=($business?:'Customer').' — '.get_the_title($plan_id);
        $lid=wp_insert_post(array('post_type'=>'pdp_license','post_status'=>'publish','post_title'=>$title));
        if(!$lid||is_wp_error($lid))self::redirect_notice($id,'License could not be created.','error');
        $map=array('email'=>'email','business_name'=>'business','website'=>'website');foreach($map as$a=>$b)update_post_meta($lid,'_pdp_'.$b,get_post_meta($id,'_pdp_'.$a,true));
        $price=(float)get_post_meta($plan_id,'_pdp_price',true);$billing=get_post_meta($plan_id,'_pdp_billing',true);$trial_days=(int)get_post_meta($plan_id,'_pdp_trial_days',true);
        if($force_trial && $trial_days<1)$trial_days=14;
        update_post_meta($lid,'_pdp_plan_id',$plan_id);update_post_meta($lid,'_pdp_plan',get_the_title($plan_id));
        update_post_meta($lid,'_pdp_price',$force_trial?'Free trial':($price>0?'$'.number_format($price,2).' '.self::billing_price_suffix($billing):'Free'));
        update_post_meta($lid,'_pdp_key',self::generate_unique_license_key($plan_id));update_post_meta($lid,'_pdp_token',wp_generate_password(40,false,false));
        update_post_meta($lid,'_pdp_sites',self::site_limit_value(get_post_meta($plan_id,'_pdp_sites',true)));update_post_meta($lid,'_pdp_status','Active');
        $days=$force_trial?$trial_days:($trial_days>0&&$billing==='trial'?$trial_days:($billing==='6-months'?183:365));update_post_meta($lid,'_pdp_expires',date('Y-m-d',strtotime('+'.$days.' days')));
        update_post_meta($id,'_pdp_license_id',$lid);update_post_meta($id,'_pdp_status',$force_trial?'Trial license created':'License created');
        $settings=get_option(self::OPT_SETTINGS,array());
        if(!empty($settings['license_auto_email']) && self::send_license_delivery($lid)) update_post_meta($lid,'_pdp_delivery_sent',current_time('mysql'));
        wp_safe_redirect(get_edit_post_link($lid,'url'));exit;
    }
    public static function mark_request_paid(){
        $id=isset($_GET['request_id'])?absint($_GET['request_id']):0;if(!$id||!current_user_can('edit_post',$id)||!check_admin_referer('pdp_mark_paid_'.$id))wp_die('Not allowed.');
        update_post_meta($id,'_pdp_status','Payment received');update_post_meta($id,'_pdp_paid_at',current_time('mysql'));self::redirect_notice($id,'Request marked as paid.','success');
    }
    public static function decline_request(){
        $id=isset($_GET['request_id'])?absint($_GET['request_id']):0;if(!$id||!current_user_can('edit_post',$id)||!check_admin_referer('pdp_decline_'.$id))wp_die('Not allowed.');
        update_post_meta($id,'_pdp_status','Declined');self::redirect_notice($id,'Request declined.','success');
    }
    public static function email_license(){
        $id=isset($_GET['license_id'])?absint($_GET['license_id']):0;if(!$id||!current_user_can('edit_post',$id)||!check_admin_referer('pdp_email_license_'.$id))wp_die('Not allowed.');
        $sent=self::send_license_delivery($id);
        if($sent) update_post_meta($id,'_pdp_delivery_sent',current_time('mysql'));
        self::redirect_notice($id,$sent?'License email, subscription details, setup instructions, and plugin download were sent.':'WordPress could not send the license email.',$sent?'success':'error');
    }

    private static function license_zip_url($settings=null){
        if($settings===null)$settings=get_option(self::OPT_SETTINGS,array());
        $attachment_id=absint(isset($settings['license_zip_attachment_id'])?$settings['license_zip_attachment_id']:0);
        return $attachment_id?wp_get_attachment_url($attachment_id):'';
    }

    private static function license_zip_path($settings=null){
        if($settings===null)$settings=get_option(self::OPT_SETTINGS,array());
        $attachment_id=absint(isset($settings['license_zip_attachment_id'])?$settings['license_zip_attachment_id']:0);
        $path=$attachment_id?get_attached_file($attachment_id):'';
        return ($path && file_exists($path))?$path:'';
    }

    private static function send_license_delivery($id){
        $email=get_post_meta($id,'_pdp_email',true); if(!is_email($email))return false;
        $s=wp_parse_args(get_option(self::OPT_SETTINGS,array()),array('license_email_subject'=>'Your Party Desk Pro license is ready','license_email_from_name'=>'Party Desk Pro','license_email_reply_to'=>get_option('admin_email'),'license_zip_version'=>''));
        self::ensure_customer_account($id); $user_id=absint(get_post_meta($id,'_pdp_user_id',true)); $user=$user_id?get_userdata($user_id):false;
        $portal=!empty($s['portal_page_url'])?$s['portal_page_url']:home_url('/my-account/'); $reset_url='';
        if($user){$reset_key=get_password_reset_key($user);if(!is_wp_error($reset_key))$reset_url=network_site_url('wp-login.php?action=rp&key='.rawurlencode($reset_key).'&login='.rawurlencode($user->user_login),'login');}
        $data=array('business'=>get_post_meta($id,'_pdp_business',true),'email'=>$email,'plan'=>get_post_meta($id,'_pdp_plan',true),'price'=>get_post_meta($id,'_pdp_price',true),'status'=>get_post_meta($id,'_pdp_status',true),'sites'=>get_post_meta($id,'_pdp_sites',true),'expires'=>get_post_meta($id,'_pdp_expires',true),'key'=>get_post_meta($id,'_pdp_key',true),'portal'=>$portal,'download'=>self::license_zip_url($s),'version'=>$s['license_zip_version'],'reset'=>$reset_url);
        $body=self::build_license_email_html($data,$s,false); $headers=array('Content-Type: text/html; charset=UTF-8');
        if(!empty($s['license_email_from_name']))$headers[]='From: '.sanitize_text_field($s['license_email_from_name']).' <'.sanitize_email(get_option('admin_email')).'>';
        if(!empty($s['license_email_reply_to'])&&is_email($s['license_email_reply_to']))$headers[]='Reply-To: '.sanitize_email($s['license_email_reply_to']);
        $attachments=array();$zip_path=self::license_zip_path($s);if($zip_path)$attachments[]=$zip_path;
        return wp_mail($email,$s['license_email_subject'],$body,$headers,$attachments);
    }

    private static function build_license_email_html($d,$s,$preview=false){
        $s=wp_parse_args($s,array('license_email_preheader'=>'Your license information is inside.','license_email_heading'=>'Welcome to Party Desk Pro!','license_email_intro'=>'Your account is ready.','license_email_button'=>'Open My Account','license_email_download_button'=>'Download Party Desk Pro','license_email_support_text'=>'Need help? Reply to this email.','license_email_footer'=>'Thank you for choosing Party Desk Pro.','license_email_logo'=>'','license_email_company_name'=>'Party Desk Pro','license_email_company_url'=>home_url('/'),'license_email_support_email'=>get_option('admin_email'),'license_email_support_phone'=>'','license_email_company_address'=>'','license_email_accent'=>'#2563eb','license_email_accent_end'=>'#6d5dfc','license_email_show_logo'=>'1','license_email_show_subscription'=>'1','license_email_show_activation'=>'1','license_email_show_download'=>'1','license_email_show_account'=>'1','license_setup_instructions'=>'Install the plugin and activate your license.'));
        $accent=sanitize_hex_color($s['license_email_accent'])?:'#2563eb';$accent2=sanitize_hex_color($s['license_email_accent_end'])?:'#6d5dfc';
        $business=$d['business']?:'Customer';$portal=$d['portal']?:'#';$download=$d['download']?:'';
        ob_start(); ?><!doctype html><html><body style="margin:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#172033;"><div style="display:none;max-height:0;overflow:hidden;opacity:0;"><?php echo esc_html($s['license_email_preheader']); ?></div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f7;padding:28px 12px;"><tr><td align="center"><table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;background:#fff;border-radius:22px;overflow:hidden;box-shadow:0 18px 55px rgba(15,23,42,.12);"><tr><td style="padding:34px 38px;background:linear-gradient(135deg,<?php echo esc_attr($accent); ?>,<?php echo esc_attr($accent2); ?>);color:#fff;text-align:center;"><?php if($s['license_email_show_logo']==='1'&&!empty($s['license_email_logo'])): ?><div style="margin:0 0 22px;"><img src="<?php echo esc_url($s['license_email_logo']); ?>" alt="<?php echo esc_attr($s['license_email_company_name']); ?>" style="display:inline-block;max-width:260px;max-height:90px;width:auto;height:auto;border:0;"></div><?php endif; ?><div style="font-size:13px;font-weight:800;letter-spacing:1.7px;opacity:.85;"><?php echo esc_html(strtoupper($s['license_email_company_name']?:'Party Desk Pro')); ?></div><h1 style="margin:15px 0 10px;font-size:32px;line-height:1.12;color:#fff;"><?php echo esc_html($s['license_email_heading']); ?></h1><p style="margin:0;font-size:16px;line-height:1.65;color:#eef2ff;">Hi <?php echo esc_html($business); ?>, <?php echo nl2br(esc_html($s['license_email_intro'])); ?></p></td></tr><tr><td style="padding:34px 38px;">
        <?php if($s['license_email_show_subscription']==='1'): ?><div style="margin-bottom:22px;border:1px solid #e5eaf2;border-radius:16px;overflow:hidden;"><div style="padding:14px 18px;background:#f8fafc;color:#64748b;font-size:12px;font-weight:800;letter-spacing:1.2px;">SUBSCRIPTION INFORMATION</div><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;"><tr><td style="padding:14px 18px;color:#64748b;">Plan</td><td align="right" style="padding:14px 18px;font-weight:700;"><?php echo esc_html($d['plan']); ?></td></tr><tr><td style="padding:14px 18px;border-top:1px solid #eef2f7;color:#64748b;">Subscription</td><td align="right" style="padding:14px 18px;border-top:1px solid #eef2f7;font-weight:700;"><?php echo esc_html($d['price']); ?></td></tr><tr><td style="padding:14px 18px;border-top:1px solid #eef2f7;color:#64748b;">Status</td><td align="right" style="padding:14px 18px;border-top:1px solid #eef2f7;font-weight:700;color:#15803d;"><?php echo esc_html($d['status']); ?></td></tr><tr><td style="padding:14px 18px;border-top:1px solid #eef2f7;color:#64748b;">Website allowance</td><td align="right" style="padding:14px 18px;border-top:1px solid #eef2f7;font-weight:700;"><?php echo esc_html($d['sites']); ?> website(s)</td></tr><tr><td style="padding:14px 18px;border-top:1px solid #eef2f7;color:#64748b;">Expiration</td><td align="right" style="padding:14px 18px;border-top:1px solid #eef2f7;font-weight:700;"><?php echo esc_html($d['expires']); ?></td></tr></table></div><?php endif; ?>
        <?php if($s['license_email_show_activation']==='1'): ?><div style="margin-bottom:22px;padding:22px;border-radius:16px;background:#0f172a;color:#fff;"><div style="font-size:12px;font-weight:800;letter-spacing:1.2px;color:#93c5fd;">LICENSE ACTIVATION</div><p style="margin:14px 0 5px;color:#94a3b8;font-size:12px;">LICENSE KEY</p><div style="font-family:Consolas,Monaco,monospace;font-size:17px;font-weight:800;line-height:1.5;word-break:break-all;"><?php echo esc_html($d['key']); ?></div><p style="margin:16px 0 5px;color:#94a3b8;font-size:12px;">LICENSE SERVER URL</p><div style="font-family:Consolas,Monaco,monospace;font-size:13px;word-break:break-all;"><?php echo esc_html(home_url('/')); ?></div></div><div style="margin-bottom:22px;"><h2 style="font-size:18px;margin:0 0 10px;">Setup instructions</h2><div style="font-size:14px;line-height:1.75;color:#475569;white-space:pre-line;"><?php echo esc_html($s['license_setup_instructions']); ?></div></div><?php endif; ?>
        <?php if($s['license_email_show_download']==='1'&&$download): ?><div style="margin-bottom:22px;padding:22px;border:1px solid #dbeafe;background:#eff6ff;border-radius:16px;"><h2 style="margin:0 0 8px;font-size:19px;">Download Party Desk Pro<?php echo !empty($d['version'])?' v'.esc_html($d['version']):''; ?></h2><p style="margin:0 0 16px;color:#475569;font-size:14px;line-height:1.6;">The latest plugin ZIP is also attached to this email for easy installation.</p><a href="<?php echo esc_url($download); ?>" style="display:inline-block;padding:13px 20px;border-radius:10px;background:<?php echo esc_attr($accent); ?>;color:#fff;text-decoration:none;font-weight:800;"><?php echo esc_html($s['license_email_download_button']); ?> →</a></div><?php endif; ?>
        <?php if($s['license_email_show_account']==='1'): ?><div style="text-align:center;padding:22px;border-radius:16px;background:#f8fafc;border:1px solid #e5eaf2;"><h2 style="margin:0 0 8px;font-size:19px;">Your My Account dashboard</h2><p style="margin:0 0 17px;color:#64748b;font-size:14px;line-height:1.6;">View your subscription, license, downloads, and account information anytime.</p><a href="<?php echo esc_url($portal); ?>" style="display:inline-block;padding:14px 24px;border-radius:10px;background:linear-gradient(135deg,<?php echo esc_attr($accent); ?>,<?php echo esc_attr($accent2); ?>);color:#fff;text-decoration:none;font-weight:800;"><?php echo esc_html($s['license_email_button']); ?> →</a><?php if(!empty($d['reset'])): ?><p style="margin:15px 0 0;font-size:12px;color:#64748b;"><a href="<?php echo esc_url($d['reset']); ?>" style="color:<?php echo esc_attr($accent); ?>;">Set or reset your password</a></p><?php endif; ?></div><?php endif; ?>
        <p style="margin:25px 0 0;padding:18px;border-left:4px solid <?php echo esc_attr($accent); ?>;background:#f8fafc;color:#475569;font-size:14px;line-height:1.65;"><?php echo nl2br(esc_html($s['license_email_support_text'])); ?></p></td></tr><tr><td style="padding:24px 38px;background:#0f172a;text-align:center;color:#94a3b8;font-size:12px;line-height:1.6;"><?php echo nl2br(esc_html($s['license_email_footer'])); ?><?php if(!empty($s['license_email_support_email'])||!empty($s['license_email_support_phone'])): ?><div style="margin-top:12px;"><?php if(!empty($s['license_email_support_email'])): ?><a href="mailto:<?php echo esc_attr($s['license_email_support_email']); ?>" style="color:#cbd5e1;text-decoration:none;"><?php echo esc_html($s['license_email_support_email']); ?></a><?php endif; ?><?php if(!empty($s['license_email_support_email'])&&!empty($s['license_email_support_phone'])): ?> &nbsp;•&nbsp; <?php endif; ?><?php if(!empty($s['license_email_support_phone'])): ?><span style="color:#cbd5e1;"><?php echo esc_html($s['license_email_support_phone']); ?></span><?php endif; ?></div><?php endif; ?><?php if(!empty($s['license_email_company_address'])): ?><div style="margin-top:8px;color:#64748b;"><?php echo esc_html($s['license_email_company_address']); ?></div><?php endif; ?><div style="margin-top:10px;color:#64748b;">© <?php echo esc_html(date('Y')); ?> <?php echo esc_html($s['license_email_company_name']?:'Party Desk Pro'); ?></div></td></tr></table></td></tr></table></body></html><?php return ob_get_clean();
    }

    private static function ensure_customer_account($license_id){
        $email=sanitize_email(get_post_meta($license_id,'_pdp_email',true));
        if(!$email) return 0;
        $existing_id=absint(get_post_meta($license_id,'_pdp_user_id',true));
        if($existing_id && get_userdata($existing_id)) return $existing_id;
        $user=get_user_by('email',$email);
        if($user){$user_id=$user->ID;}else{
            $base=sanitize_user(strtok($email,'@'),true); if(!$base)$base='pdp-customer';
            $login=$base; $n=1; while(username_exists($login)){$login=$base.$n;$n++;}
            $user_id=wp_insert_user(array('user_login'=>$login,'user_email'=>$email,'user_pass'=>wp_generate_password(32,true,true),'display_name'=>get_post_meta($license_id,'_pdp_business',true)?:$email,'role'=>'pdp_customer'));
            if(is_wp_error($user_id)) return 0;
        }
        update_post_meta($license_id,'_pdp_user_id',absint($user_id));
        update_user_meta($user_id,'_pdp_license_id',absint($license_id));
        return absint($user_id);
    }

    public static function regenerate_license_key(){
        $id=isset($_GET['license_id'])?absint($_GET['license_id']):0;if(!$id||!current_user_can('edit_post',$id)||!check_admin_referer('pdp_regenerate_key_'.$id))wp_die('Not allowed.');
        update_post_meta($id,'_pdp_key',self::generate_unique_license_key(absint(get_post_meta($id,'_pdp_plan_id',true))));self::redirect_notice($id,'A new license key was generated.','success');
    }
    public static function toggle_license_status(){
        $id=isset($_GET['license_id'])?absint($_GET['license_id']):0;if(!$id||!current_user_can('edit_post',$id)||!check_admin_referer('pdp_toggle_status_'.$id))wp_die('Not allowed.');
        $current=get_post_meta($id,'_pdp_status',true);$next=$current==='Suspended'?'Active':'Suspended';update_post_meta($id,'_pdp_status',$next);self::redirect_notice($id,'License status changed to '.$next.'.','success');
    }
    private static function redirect_notice($id,$msg,$type){wp_safe_redirect(add_query_arg(array('post'=>$id,'action'=>'edit','pdp_notice'=>rawurlencode($msg),'pdp_notice_type'=>$type),admin_url('post.php')));exit;}

    public static function portal_shortcode() {
        $current_url=(is_singular()?get_permalink():home_url('/my-account/'));
        if(!is_user_logged_in()){
            $login=wp_login_form(array('echo'=>false,'redirect'=>$current_url,'label_username'=>'Email or Username','label_log_in'=>'Sign In to My Account','remember'=>true));
            return '<div class="pdp-portal-login"><h3>Party Desk Pro My Account</h3><p>Sign in with the WordPress account connected to your subscription.</p>'.$login.'<p><a href="'.esc_url(wp_lostpassword_url($current_url)).'">Forgot your password?</a></p></div>';
        }
        $user_id=get_current_user_id();
        $license_id=absint(get_user_meta($user_id,'_pdp_license_id',true));
        if(!$license_id){
            $user=wp_get_current_user();
            $q=new WP_Query(array('post_type'=>'pdp_license','post_status'=>'publish','posts_per_page'=>1,'meta_key'=>'_pdp_email','meta_value'=>$user->user_email));
            if($q->have_posts()){$license_id=$q->posts[0]->ID;update_user_meta($user_id,'_pdp_license_id',$license_id);update_post_meta($license_id,'_pdp_user_id',$user_id);}
        }
        if(!$license_id || get_post_type($license_id)!=='pdp_license') return '<div class="pdp-errors">No Party Desk Pro license is connected to this account. Contact support for assistance.</div>';
        $d=array();foreach(array('business','email','plan','price','key','status','expires','sites')as$k)$d[$k]=get_post_meta($license_id,'_pdp_'.$k,true);
        $d['license_id']=$license_id;
        $d['activations']=PDP_DB::get_license_activations($license_id,true);
        $d['events']=PDP_DB::get_license_events($license_id,8);
        $d['logout_url']=wp_logout_url($current_url);
        return self::portal_html($d);
    }
    private static function portal_html($d, $preview=false) {
        $s=wp_parse_args(get_option(self::OPT_SETTINGS,array()),array(
            'portal_title'=>'My Account','portal_subtitle'=>'Manage your subscription, license, downloads, and account.','portal_brand'=>'PARTY DESK PRO','portal_logo'=>'',
            'portal_accent'=>'#2563eb','portal_header_end'=>'#1e3a8a','portal_background'=>'#f8fafc','portal_card'=>'#ffffff','portal_text'=>'#0f172a','portal_muted'=>'#64748b',
            'portal_radius'=>'24','portal_layout'=>'cards','subscription_label'=>'Current Subscription','status_label'=>'License Status','sites_label'=>'Website Allowance',
            'license_heading'=>'License information','show_subscription'=>'1','show_status'=>'1','show_sites'=>'1','show_business'=>'1','show_email'=>'1','show_license_key'=>'1','show_expiration'=>'1',
            'support_label'=>'Contact Support','support_url'=>'','portal_download_heading'=>'Download Party Desk Pro','portal_download_text'=>'Download the latest Party Desk Pro plugin ZIP file included with your subscription.','portal_download_button'=>'Download Plugin ZIP','license_zip_attachment_id'=>0,'license_zip_version'=>''
        ));
        $style='--pdp-accent:'.esc_attr($s['portal_accent']).';--pdp-header-end:'.esc_attr($s['portal_header_end']).';--pdp-portal-bg:'.esc_attr($s['portal_background']).';--pdp-card:'.esc_attr($s['portal_card']).';--pdp-ink:'.esc_attr($s['portal_text']).';--pdp-muted:'.esc_attr($s['portal_muted']).';--pdp-radius:'.intval($s['portal_radius']).'px;';
        $status = !empty($d['status']) ? $d['status'] : 'Active';
        $status_class = strtolower(preg_replace('/[^a-z0-9]+/i','-', $status));
        $business = !empty($d['business']) ? $d['business'] : 'Client';
        $initial = strtoupper(substr($business,0,1));
        ob_start();
        echo '<div class="pdp-portal pdp-layout-'.esc_attr($s['portal_layout']).'" style="'.$style.'">';
        echo '<div class="pdp-portal-topbar"><div class="pdp-portal-branding">';
        if(!empty($s['portal_logo'])) echo '<img class="pdp-portal-logo" src="'.esc_url($s['portal_logo']).'" alt="">';
        else echo '<span class="pdp-brand-mark" aria-hidden="true">P</span>';
        echo '<div><span class="pdp-portal-brand">'.esc_html($s['portal_brand']).'</span><small>Customer workspace</small></div></div>'; if(!empty($d['logout_url'])) echo '<a class="pdp-portal-logout" href="'.esc_url($d['logout_url']).'">Sign out</a>';
        echo '<div class="pdp-client-chip"><span class="pdp-client-avatar">'.esc_html($initial).'</span><span><strong>'.esc_html($business).'</strong><small>'.esc_html($d['email']??'').'</small></span></div></div>';
        echo '<header><div class="pdp-hero-copy"><span class="pdp-eyebrow">ACCOUNT OVERVIEW</span><h2 class="pdp-portal-title">'.esc_html($s['portal_title']).'</h2><p class="pdp-portal-subtitle">'.esc_html($s['portal_subtitle']).'</p></div><div class="pdp-status-pill pdp-status-'.esc_attr($status_class).'"><span></span>'.esc_html($status).'</div></header>';
        echo '<main class="pdp-portal-content">';
        echo '<div class="pdp-welcome"><div><span>Welcome back</span><h3>'.esc_html($business).'</h3><p>Your subscription, license access, and account details are shown below.</p></div><div class="pdp-welcome-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 11V8a5 5 0 0 1 10 0v3"/><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M12 15v2"/></svg></div></div>';
        echo '<div class="pdp-portal-metrics">';
        if($s['show_subscription']==='1') echo '<article class="pdp-metric pdp-metric-primary" data-section="show_subscription"><div class="pdp-metric-icon"><svg viewBox="0 0 24 24"><path d="M4 7h16v10H4z"/><path d="M4 10h16"/><path d="M8 15h3"/></svg></div><div><span class="pdp-subscription-label">'.esc_html($s['subscription_label']).'</span><strong>'.esc_html($d['plan']??'').'</strong><small>'.esc_html($d['price']??'').'</small></div></article>';
        if($s['show_status']==='1') echo '<article class="pdp-metric" data-section="show_status"><div class="pdp-metric-icon"><svg viewBox="0 0 24 24"><path d="M12 3l7 3v5c0 5-3 8-7 10-4-2-7-5-7-10V6z"/><path d="m9 12 2 2 4-4"/></svg></div><div><span class="pdp-status-label">'.esc_html($s['status_label']).'</span><strong>'.esc_html($status).'</strong>'.($s['show_expiration']==='1'?'<small class="pdp-expiration">Renews / expires '.esc_html($d['expires']??'—').'</small>':'').'</div></article>';
        if($s['show_sites']==='1') echo '<article class="pdp-metric" data-section="show_sites"><div class="pdp-metric-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg></div><div><span class="pdp-sites-label">'.esc_html($s['sites_label']).'</span><strong>'.esc_html($d['sites']??'1').'</strong><small>licensed website(s)</small></div></article>';
        echo '</div>';
        echo '<div class="pdp-portal-grid"><section class="pdp-license-card"><div class="pdp-section-head"><div><span>ACCOUNT DETAILS</span><h3 class="pdp-license-heading">'.esc_html($s['license_heading']).'</h3></div><span class="pdp-secure-badge"><svg viewBox="0 0 24 24"><path d="M7 11V8a5 5 0 0 1 10 0v3"/><rect x="5" y="11" width="14" height="10" rx="2"/></svg> Secure</span></div><dl>';
        if($s['show_business']==='1') echo '<div data-section="show_business"><dt><span>Business</span></dt><dd>'.esc_html($business).'</dd></div>';
        if($s['show_email']==='1') echo '<div data-section="show_email"><dt><span>Email</span></dt><dd>'.esc_html($d['email']??'').'</dd></div>';
        if($s['show_license_key']==='1') echo '<div class="pdp-license-row" data-section="show_license_key"><dt><span>License key</span></dt><dd><code>'.esc_html($d['key']??'').'</code></dd></div>';
        if($s['show_expiration']==='1' && $s['show_status']!=='1') echo '<div data-section="show_expiration"><dt><span>Expiration</span></dt><dd>'.esc_html($d['expires']??'—').'</dd></div>';
        echo '</dl></section>';
        $download_url=self::license_zip_url($s);
        if($download_url || $preview){
            echo '<aside class="pdp-help-card pdp-download-card"><div class="pdp-help-icon"><svg viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5"/><path d="M5 21h14"/></svg></div><span>PLUGIN DOWNLOAD</span><h3>'.esc_html($s['portal_download_heading']).'</h3><p>'.esc_html($s['portal_download_text']).'</p>';
            if(!empty($s['license_zip_version'])) echo '<p><strong>Version '.esc_html($s['license_zip_version']).'</strong></p>';
            echo '<a class="pdp-support'.(!$download_url?' is-preview-hidden':'').'" href="'.esc_url($download_url?:'#').'" '.(!$preview?'download':'').'>'.esc_html($s['portal_download_button']).'<svg viewBox="0 0 24 24"><path d="M12 3v12M7 10l5 5 5-5"/><path d="M5 21h14"/></svg></a></aside>';
        }
        echo '<aside class="pdp-help-card"><div class="pdp-help-icon"><svg viewBox="0 0 24 24"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M8 9h8M8 13h5"/></svg></div><span>NEED HELP?</span><h3>We’re here for you</h3><p>Questions about your plan, license, or account? Contact our support team.</p>';
        if(!empty($s['support_url']) || $preview) echo '<a class="pdp-support'.(empty($s['support_url'])?' is-preview-hidden':'').'" href="'.esc_url($s['support_url']?:'#').'" '.(!$preview?'target="_blank" rel="noopener"':'').' data-preview-button>'.esc_html($s['support_label']).'<svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>';
        echo '</aside></div>';
        if(!$preview && !empty($d['license_id'])){
            $activations=is_array($d['activations']??null)?$d['activations']:array();
            $events=is_array($d['events']??null)?$d['events']:array();
            echo '<section class="pdp-portal-activity"><div class="pdp-section-head"><div><span>LICENSE SECURITY</span><h3>Authorized websites</h3></div><span class="pdp-secure-badge">'.count(array_filter($activations,function($a){return ($a['status']??'')==='active';})).' active</span></div>';
            if(!$activations){echo '<div class="pdp-portal-empty">No websites have activated this license yet.</div>';}
            else{echo '<div class="pdp-site-list">';foreach($activations as $a){echo '<article><div><strong>'.esc_html($a['site_name']?:wp_parse_url($a['site_url'],PHP_URL_HOST)).'</strong><small>'.esc_html($a['site_url']).'</small></div><span class="pdp-site-status is-'.esc_attr($a['status']).'">'.esc_html(ucfirst($a['status'])).'</span><small>Last checked '.esc_html($a['last_checked_at']).'</small></article>';}echo '</div>';}
            echo '<div class="pdp-section-head pdp-history-head"><div><span>RECENT ACTIVITY</span><h3>License history</h3></div></div>';
            if(!$events){echo '<div class="pdp-portal-empty">No license activity has been recorded yet.</div>';}
            else{echo '<div class="pdp-history-list">';foreach($events as $e){echo '<article><span class="pdp-history-dot"></span><div><strong>'.esc_html(ucwords(str_replace('_',' ',$e['event_type']))).'</strong><p>'.esc_html($e['message']).'</p></div><time>'.esc_html($e['created_at']).'</time></article>';}echo '</div>';}
            echo '</section>';
        }
        echo '</main><footer><span>Protected account dashboard</span><span>'.esc_html($s['portal_brand']).'</span></footer></div>';return ob_get_clean();
    }


    /**
     * REST API used by the Party Desk Pro customer plugin.
     * Client endpoint format: /wp-json/party-desk-license/v1/{action}
     */
    public static function register_rest_routes() {
        $common = array(
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => '__return_true',
        );
        foreach (array('activate','validate','deactivate','update-check') as $action) {
            register_rest_route('party-desk-license/v1', '/' . $action, array_merge($common, array(
                'callback' => function($request) use ($action) {
                    return PDP_License_Server::handle_license_api($request, $action);
                },
            )));
        }
    }

    private static function api_payload(WP_REST_Request $request) {
        $data = $request->get_json_params();
        if (!is_array($data) || !$data) $data = $request->get_params();
        return array(
            'license_key' => strtoupper(trim(sanitize_text_field(isset($data['license_key']) ? $data['license_key'] : ''))),
            'product' => sanitize_key(isset($data['product']) ? $data['product'] : ''),
            'site_url' => esc_url_raw(isset($data['site_url']) ? $data['site_url'] : ''),
            'site_name' => sanitize_text_field(isset($data['site_name']) ? $data['site_name'] : ''),
            'customer_email' => sanitize_email(isset($data['customer_email']) ? $data['customer_email'] : ''),
            'installed_version' => sanitize_text_field(isset($data['installed_version']) ? $data['installed_version'] : ''),
        );
    }

    private static function find_license_by_key($key) {
        if (!$key) return 0;
        $ids = get_posts(array(
            'post_type' => 'pdp_license',
            'post_status' => array('publish','draft','pending','private'),
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_key' => '_pdp_key',
            'meta_value' => $key,
        ));
        return $ids ? absint($ids[0]) : 0;
    }

    private static function license_api_state($license_id) {
        $status_raw = get_post_meta($license_id, '_pdp_status', true);
        $status = strtolower(trim($status_raw ? $status_raw : 'pending'));
        $expires = sanitize_text_field(get_post_meta($license_id, '_pdp_expires', true));
        if ($expires && strtotime($expires . ' 23:59:59') < current_time('timestamp')) {
            $status = 'expired';
        }
        if ($status === 'active') return array('ok'=>true,'status'=>'active','message'=>'License is active.','expires_at'=>$expires);
        if ($status === 'suspended' || $status === 'disabled') return array('ok'=>false,'status'=>'disabled','message'=>'This license has been disabled.','expires_at'=>$expires);
        if ($status === 'expired') return array('ok'=>false,'status'=>'expired','message'=>'This license has expired.','expires_at'=>$expires);
        return array('ok'=>false,'status'=>'inactive','message'=>'This license is not active yet.','expires_at'=>$expires);
    }

    private static function normalized_site($url) {
        $url = untrailingslashit(strtolower(trim($url)));
        return $url;
    }

    public static function handle_license_api(WP_REST_Request $request, $action) {
        $p = self::api_payload($request);
        if (!$p['license_key']) {
            return new WP_REST_Response(array('success'=>false,'status'=>'invalid','message'=>'A license key is required.'), 400);
        }
        if ($p['product'] && !in_array($p['product'], array('party-desk','party-desk-pro'), true)) {
            return new WP_REST_Response(array('success'=>false,'status'=>'invalid','message'=>'This license is not valid for that product.'), 400);
        }
        $license_id = self::find_license_by_key($p['license_key']);
        if (!$license_id) {
            return new WP_REST_Response(array('success'=>false,'status'=>'invalid','message'=>'License key not found.'), 404);
        }

        $saved_email = sanitize_email(get_post_meta($license_id, '_pdp_email', true));
        if ($saved_email && $p['customer_email'] && strtolower($saved_email) !== strtolower($p['customer_email'])) {
            return new WP_REST_Response(array('success'=>false,'status'=>'invalid','message'=>'The email address does not match this license.'), 403);
        }

        $state = self::license_api_state($license_id);
        if ($action !== 'deactivate' && !$state['ok']) {
            return new WP_REST_Response(array('success'=>false,'status'=>$state['status'],'message'=>$state['message'],'expires_at'=>$state['expires_at']), 403);
        }

        $site = self::normalized_site($p['site_url']);
        $activations = get_post_meta($license_id, '_pdp_activations', true);
        if (!is_array($activations)) $activations = array();

        if ($action === 'deactivate') {
            if ($site && isset($activations[$site])) {
                unset($activations[$site]);
                update_post_meta($license_id, '_pdp_activations', $activations);
            }
            return new WP_REST_Response(array('success'=>true,'status'=>'inactive','message'=>'License deactivated on this website.','expires_at'=>$state['expires_at']), 200);
        }

        if ($action === 'activate') {
            if (!$site) return new WP_REST_Response(array('success'=>false,'status'=>'invalid','message'=>'A valid website URL is required.'), 400);
            $limit = self::site_limit_value(get_post_meta($license_id, '_pdp_sites', true));
            if ($limit > 0 && !isset($activations[$site]) && count($activations) >= $limit) {
                return new WP_REST_Response(array('success'=>false,'status'=>'invalid','message'=>'This license has reached its website activation limit.'), 403);
            }
            $activations[$site] = array(
                'site_url' => $p['site_url'],
                'site_name' => $p['site_name'],
                'version' => $p['installed_version'],
                'activated_at' => current_time('mysql'),
                'last_checked' => current_time('mysql'),
            );
            update_post_meta($license_id, '_pdp_activations', $activations);
        } elseif ($site && isset($activations[$site])) {
            $activations[$site]['last_checked'] = current_time('mysql');
            $activations[$site]['version'] = $p['installed_version'];
            update_post_meta($license_id, '_pdp_activations', $activations);
        }

        $base = array(
            'success' => true,
            'status' => 'active',
            'message' => $action === 'activate' ? 'License activated successfully.' : 'License validated successfully.',
            'expires_at' => $state['expires_at'],
            'plan' => sanitize_text_field(get_post_meta($license_id, '_pdp_plan', true)),
            'site_limit' => self::site_limit_value(get_post_meta($license_id, '_pdp_sites', true)),
            'sites_used' => count($activations),
        );

        if ($action === 'update-check') {
            $base['update_available'] = false;
            $base['version'] = $p['installed_version'];
            $base['download_url'] = '';
            $base['changelog'] = '';
        }
        return new WP_REST_Response($base, 200);
    }



    public static function database_health_page() {
        if (!current_user_can('manage_options')) return;
        $health = PDP_DB::health_check();
        $healthy = true;
        foreach ($health as $item) { if (empty($item['exists'])) $healthy = false; }
        echo '<div class="wrap pdp-admin-wrap"><h1>Database Health</h1>';
        echo '<p>Version <strong>'.esc_html(PDP_DB::DB_VERSION).'</strong> &middot; Status: <strong>'.($healthy ? 'Healthy' : 'Repair needed').'</strong></p>';
        echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Component</th><th>Table</th><th>Status</th><th>Rows</th></tr></thead><tbody>';
        foreach ($health as $name=>$item) {
            echo '<tr><td><strong>'.esc_html(ucwords(str_replace('_',' ',$name))).'</strong></td><td><code>'.esc_html($item['table']).'</code></td><td>'.(!empty($item['exists']) ? '<span style="color:#137333;font-weight:700">Ready</span>' : '<span style="color:#b42318;font-weight:700">Missing</span>').'</td><td>'.esc_html((string)$item['rows']).'</td></tr>';
        }
        echo '</tbody></table>';
        echo '<div style="margin-top:20px"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="pdp_repair_database">';
        wp_nonce_field('pdp_repair_database');
        submit_button('Check & Repair Database', 'primary', 'submit', false);
        echo '</form></div><p class="description">This action is safe to run again. It creates missing tables and applies compatible schema updates without deleting subscription records.</p></div>';
    }

    public static function plan_columns($c){return array('cb'=>$c['cb'],'title'=>'Plan','price'=>'Price','billing'=>'Billing','sites'=>'Sites','active'=>'Signup Form','date'=>$c['date']);}
    public static function plan_column_data($col,$id){if($col==='price')echo '$'.esc_html(number_format((float)get_post_meta($id,'_pdp_price',true),2));if($col==='billing')echo esc_html(self::billing_label(get_post_meta($id,'_pdp_billing',true)));if($col==='sites')echo esc_html(self::site_limit_label(get_post_meta($id,'_pdp_sites',true),true));if($col==='active')echo get_post_meta($id,'_pdp_active',true)==='1'?'Active':'Hidden';}
    public static function request_columns($c){return array('cb'=>$c['cb'],'title'=>'Request','plan'=>'Plan','email'=>'Email','amount'=>'Amount','status'=>'Status','date'=>$c['date']);}
    public static function request_column_data($col,$id){if($col==='plan')echo esc_html(get_the_title(absint(get_post_meta($id,'_pdp_plan_id',true))));if($col==='email')echo esc_html(get_post_meta($id,'_pdp_email',true));if($col==='amount')echo '$'.esc_html(number_format((float)get_post_meta($id,'_pdp_amount',true),2));if($col==='status')echo esc_html(get_post_meta($id,'_pdp_status',true));}
    public static function license_columns($c){return array('cb'=>$c['cb'],'title'=>'License','plan'=>'Plan','email'=>'Email','key'=>'License Key','status'=>'Status','date'=>$c['date']);}
    public static function license_column_data($col,$id){if($col==='plan')echo esc_html(get_post_meta($id,'_pdp_plan',true));if($col==='email')echo esc_html(get_post_meta($id,'_pdp_email',true));if($col==='key')echo '<code>'.esc_html(get_post_meta($id,'_pdp_key',true)).'</code>';if($col==='status')echo esc_html(get_post_meta($id,'_pdp_status',true));}
}

register_activation_hook(__FILE__,array('PDP_License_Server','activate'));
register_activation_hook(__FILE__,array('PDP_Core','activate'));
register_deactivation_hook(__FILE__,array('PDP_License_Server','deactivate'));
PDP_License_Server::init();
PDP_Core::init();

add_action('admin_notices',function(){if(!empty($_GET['pdp_notice'])){$type=(!empty($_GET['pdp_notice_type'])&&$_GET['pdp_notice_type']==='error')?'error':'success';echo '<div class="notice notice-'.$type.' is-dismissible"><p>'.esc_html(rawurldecode(sanitize_text_field(wp_unslash($_GET['pdp_notice'])))).'</p></div>';}});
