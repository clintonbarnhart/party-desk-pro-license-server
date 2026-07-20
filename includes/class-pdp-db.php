<?php
if (!defined('ABSPATH')) { exit; }

final class PDP_DB {
    const DB_VERSION = '1.0.0';
    const OPTION_DB_VERSION = 'pdp_ls_db_version';

    public static function init() {
        add_action('plugins_loaded', array(__CLASS__, 'maybe_upgrade'), 20);
        add_action('admin_post_pdp_repair_database', array(__CLASS__, 'handle_repair'));
    }

    public static function table($name) {
        global $wpdb;
        $allowed = array('subscriptions','subscription_events','webhook_logs','square_customers','square_sync');
        if (!in_array($name, $allowed, true)) { return ''; }
        return $wpdb->prefix . 'pdp_' . $name;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql = array();
        $sql[] = "CREATE TABLE " . self::table('subscriptions') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            customer_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            license_id bigint(20) unsigned NOT NULL DEFAULT 0,
            plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'pending',
            billing_cycle varchar(30) NOT NULL DEFAULT '',
            amount decimal(12,2) NOT NULL DEFAULT 0.00,
            currency varchar(10) NOT NULL DEFAULT 'USD',
            square_customer_id varchar(100) NOT NULL DEFAULT '',
            square_subscription_id varchar(100) NOT NULL DEFAULT '',
            trial_ends_at datetime NULL,
            current_period_start datetime NULL,
            current_period_end datetime NULL,
            next_billing_at datetime NULL,
            last_payment_at datetime NULL,
            grace_ends_at datetime NULL,
            cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
            canceled_at datetime NULL,
            metadata longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY customer_user_id (customer_user_id),
            KEY license_id (license_id),
            KEY plan_id (plan_id),
            KEY status (status),
            KEY square_customer_id (square_customer_id),
            UNIQUE KEY square_subscription_id (square_subscription_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('subscription_events') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subscription_id bigint(20) unsigned NOT NULL DEFAULT 0,
            event_type varchar(100) NOT NULL,
            event_source varchar(30) NOT NULL DEFAULT 'system',
            message text NULL,
            event_data longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY subscription_id (subscription_id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('webhook_logs') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(30) NOT NULL DEFAULT 'square',
            event_id varchar(150) NOT NULL DEFAULT '',
            event_type varchar(120) NOT NULL DEFAULT '',
            signature_valid tinyint(1) NOT NULL DEFAULT 0,
            processing_status varchar(30) NOT NULL DEFAULT 'received',
            http_status smallint(5) unsigned NOT NULL DEFAULT 200,
            payload longtext NULL,
            response_body longtext NULL,
            error_message text NULL,
            received_at datetime NOT NULL,
            processed_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_event (provider,event_id),
            KEY event_type (event_type),
            KEY processing_status (processing_status),
            KEY received_at (received_at)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('square_customers') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            request_id bigint(20) unsigned NOT NULL DEFAULT 0,
            square_customer_id varchar(100) NOT NULL,
            email varchar(190) NOT NULL DEFAULT '',
            display_name varchar(190) NOT NULL DEFAULT '',
            company_name varchar(190) NOT NULL DEFAULT '',
            environment varchar(20) NOT NULL DEFAULT 'sandbox',
            metadata longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY square_customer_id (square_customer_id),
            KEY user_id (user_id),
            KEY email (email)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::table('square_sync') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            object_type varchar(50) NOT NULL,
            local_object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            square_object_id varchar(150) NOT NULL DEFAULT '',
            square_version bigint(20) unsigned NOT NULL DEFAULT 0,
            environment varchar(20) NOT NULL DEFAULT 'sandbox',
            sync_status varchar(30) NOT NULL DEFAULT 'pending',
            last_error text NULL,
            synced_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY local_object (object_type,local_object_id,environment),
            KEY square_object_id (square_object_id),
            KEY sync_status (sync_status)
        ) $charset;";

        foreach ($sql as $statement) { dbDelta($statement); }
        update_option(self::OPTION_DB_VERSION, self::DB_VERSION, false);
        return self::health_check();
    }

    public static function maybe_upgrade() {
        if (get_option(self::OPTION_DB_VERSION) !== self::DB_VERSION) { self::install(); }
    }

    public static function health_check() {
        global $wpdb;
        $results = array();
        foreach (array('subscriptions','subscription_events','webhook_logs','square_customers','square_sync') as $name) {
            $table = self::table($name);
            $exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
            $results[$name] = array('table'=>$table, 'exists'=>$exists, 'rows'=>$exists ? (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}`") : 0);
        }
        return $results;
    }

    public static function create_subscription($data) {
        global $wpdb;
        $defaults = array('customer_user_id'=>0,'license_id'=>0,'plan_id'=>0,'status'=>'pending','billing_cycle'=>'','amount'=>0,'currency'=>'USD','square_customer_id'=>'','square_subscription_id'=>'','trial_ends_at'=>null,'current_period_start'=>null,'current_period_end'=>null,'next_billing_at'=>null,'last_payment_at'=>null,'grace_ends_at'=>null,'cancel_at_period_end'=>0,'canceled_at'=>null,'metadata'=>null);
        $row = wp_parse_args($data, $defaults);
        $row['metadata'] = is_array($row['metadata']) ? wp_json_encode($row['metadata']) : $row['metadata'];
        $row['created_at'] = current_time('mysql');
        $row['updated_at'] = current_time('mysql');
        $ok = $wpdb->insert(self::table('subscriptions'), $row);
        return $ok ? (int)$wpdb->insert_id : new WP_Error('pdp_db_insert_failed', $wpdb->last_error ?: 'Unable to create subscription.');
    }

    public static function get_subscription($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . self::table('subscriptions') . '` WHERE id = %d', absint($id)), ARRAY_A);
    }

    public static function update_subscription($id, $data) {
        global $wpdb;
        unset($data['id'], $data['created_at']);
        if (isset($data['metadata']) && is_array($data['metadata'])) { $data['metadata'] = wp_json_encode($data['metadata']); }
        $data['updated_at'] = current_time('mysql');
        $result = $wpdb->update(self::table('subscriptions'), $data, array('id'=>absint($id)));
        return false === $result ? new WP_Error('pdp_db_update_failed', $wpdb->last_error ?: 'Unable to update subscription.') : $result;
    }

    public static function log_event($subscription_id, $event_type, $message='', $data=array(), $source='system') {
        global $wpdb;
        $ok = $wpdb->insert(self::table('subscription_events'), array('subscription_id'=>absint($subscription_id),'event_type'=>sanitize_key($event_type),'event_source'=>sanitize_key($source),'message'=>sanitize_textarea_field($message),'event_data'=>empty($data)?null:wp_json_encode($data),'created_at'=>current_time('mysql')));
        return $ok ? (int)$wpdb->insert_id : false;
    }

    public static function save_square_customer($data) {
        global $wpdb;
        $square_id = sanitize_text_field($data['square_customer_id'] ?? '');
        if (!$square_id) { return new WP_Error('pdp_missing_square_customer', 'Square customer ID is required.'); }
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM `' . self::table('square_customers') . '` WHERE square_customer_id=%s', $square_id));
        $row = array('user_id'=>absint($data['user_id'] ?? 0),'request_id'=>absint($data['request_id'] ?? 0),'square_customer_id'=>$square_id,'email'=>sanitize_email($data['email'] ?? ''),'display_name'=>sanitize_text_field($data['display_name'] ?? ''),'company_name'=>sanitize_text_field($data['company_name'] ?? ''),'environment'=>in_array(($data['environment'] ?? 'sandbox'),array('sandbox','production'),true)?$data['environment']:'sandbox','metadata'=>empty($data['metadata'])?null:wp_json_encode($data['metadata']),'updated_at'=>current_time('mysql'));
        if ($existing) { $wpdb->update(self::table('square_customers'), $row, array('id'=>(int)$existing)); return (int)$existing; }
        $row['created_at'] = current_time('mysql');
        return $wpdb->insert(self::table('square_customers'), $row) ? (int)$wpdb->insert_id : new WP_Error('pdp_db_insert_failed', $wpdb->last_error ?: 'Unable to save Square customer.');
    }

    public static function log_webhook($data) {
        global $wpdb;
        $defaults = array('provider'=>'square','event_id'=>'','event_type'=>'','signature_valid'=>0,'processing_status'=>'received','http_status'=>200,'payload'=>null,'response_body'=>null,'error_message'=>null,'processed_at'=>null);
        $row = wp_parse_args($data, $defaults);
        foreach (array('payload','response_body') as $field) { if (isset($row[$field]) && is_array($row[$field])) $row[$field] = wp_json_encode($row[$field]); }
        $row['received_at'] = current_time('mysql');
        $ok = $wpdb->insert(self::table('webhook_logs'), $row);
        if (!$ok && strpos((string)$wpdb->last_error, 'Duplicate') !== false && $row['event_id']) {
            return (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM `' . self::table('webhook_logs') . '` WHERE provider=%s AND event_id=%s', $row['provider'], $row['event_id']));
        }
        return $ok ? (int)$wpdb->insert_id : false;
    }

    public static function handle_repair() {
        if (!current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to repair the Party Desk Pro database.', 'party-desk-pro-license-server')); }
        check_admin_referer('pdp_repair_database');
        self::install();
        wp_safe_redirect(add_query_arg(array('page'=>'pdp-database-health','pdp_notice'=>rawurlencode('Database tables were checked and repaired.')), admin_url('admin.php')));
        exit;
    }
}
