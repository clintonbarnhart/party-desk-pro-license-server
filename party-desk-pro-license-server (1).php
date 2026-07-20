<?php
if (!defined('ABSPATH')) { exit; }

final class PDP_Subscriptions_Admin {
    public static function subscriptions_page() {
        if (!current_user_can('manage_options')) { return; }
        global $wpdb;
        $table = PDP_DB::table('subscriptions');
        $status = sanitize_key(wp_unslash($_GET['status'] ?? ''));
        $where = $status ? $wpdb->prepare(' WHERE status=%s', $status) : '';
        $rows = $wpdb->get_results("SELECT * FROM `{$table}` {$where} ORDER BY id DESC LIMIT 100", ARRAY_A);
        $counts = array();
        foreach ((array)$wpdb->get_results("SELECT status,COUNT(*) total FROM `{$table}` GROUP BY status", ARRAY_A) as $item) { $counts[$item['status']] = (int)$item['total']; }
        self::header('Subscriptions', 'View Square subscription records and their relationship to licenses, plans, and customers.');
        self::notice();
        echo '<div class="pdp-sub-stats">';
        foreach (array('active'=>'Active','trial'=>'Trial','pending'=>'Pending','past_due'=>'Past Due','canceled'=>'Canceled') as $key=>$label) {
            echo '<a class="pdp-sub-stat" href="'.esc_url(add_query_arg(array('page'=>'pdp-subscriptions','status'=>$key),admin_url('admin.php'))).'"><span>'.esc_html($label).'</span><strong>'.esc_html($counts[$key] ?? 0).'</strong></a>';
        }
        echo '</div><div class="pdp-admin-card"><div class="pdp-admin-card-head"><h2>Subscription Records</h2><a class="button" href="'.esc_url(admin_url('admin.php?page=pdp-subscriptions')).'">Clear Filter</a></div>';
        echo '<div class="pdp-table-wrap"><table class="widefat striped"><thead><tr><th>ID</th><th>Customer</th><th>Plan</th><th>Status</th><th>Billing</th><th>Amount</th><th>Next Billing</th><th>Square Subscription</th></tr></thead><tbody>';
        if (!$rows) { echo '<tr><td colspan="8"><div class="pdp-empty-state"><span class="dashicons dashicons-update"></span><h3>No subscriptions yet</h3><p>Subscription records will appear here after checkout is added in the next milestone or records are created through the database API.</p></div></td></tr>'; }
        foreach ($rows as $row) {
            $user = $row['customer_user_id'] ? get_userdata((int)$row['customer_user_id']) : false;
            $plan = $row['plan_id'] ? get_the_title((int)$row['plan_id']) : '—';
            echo '<tr><td>#'.esc_html($row['id']).'</td><td>'.esc_html($user ? $user->display_name : ('User #'.$row['customer_user_id'])).'</td><td>'.esc_html($plan ?: '—').'</td><td><span class="pdp-status pdp-status-'.esc_attr($row['status']).'">'.esc_html(ucwords(str_replace('_',' ',$row['status']))).'</span></td><td>'.esc_html(ucwords(str_replace('_',' ',$row['billing_cycle'])) ?: '—').'</td><td>'.esc_html($row['currency'].' '.number_format((float)$row['amount'],2)).'</td><td>'.esc_html($row['next_billing_at'] ?: '—').'</td><td><code>'.esc_html($row['square_subscription_id'] ?: 'Not linked').'</code></td></tr>';
        }
        echo '</tbody></table></div></div></div>';
    }

    public static function square_settings_page() {
        if (!current_user_can('manage_options')) { return; }
        $s = PDP_Subscriptions::settings();
        self::header('Square Subscriptions', 'Configure the credentials and webhook security used by the recurring billing system.'); self::notice();
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('pdp_save_square_subscription_settings');
        echo '<input type="hidden" name="action" value="pdp_save_square_subscription_settings">';
        echo '<div class="pdp-settings-grid"><div class="pdp-admin-card"><div class="pdp-admin-card-head"><div><h2>Environment</h2><p>Use Sandbox until the full checkout and renewal workflow has been tested.</p></div></div>';
        echo '<div class="pdp-choice-row"><label class="pdp-choice"><input type="radio" name="environment" value="sandbox" '.checked($s['environment'],'sandbox',false).'><strong>Sandbox</strong><span>Test payments only</span></label><label class="pdp-choice"><input type="radio" name="environment" value="production" '.checked($s['environment'],'production',false).'><strong>Production</strong><span>Real customer charges</span></label></div></div>';
        echo '<div class="pdp-admin-card"><div class="pdp-admin-card-head"><div><h2>Webhook Receiver</h2><p>Add this exact notification URL in your Square Developer Dashboard.</p></div><span class="pdp-health-badge '.($s['webhook_enabled']==='1'?'ready':'warning').'">'.($s['webhook_enabled']==='1'?'Enabled':'Disabled').'</span></div><label class="pdp-field"><span>Notification URL</span><div class="pdp-copy-field"><input type="text" readonly value="'.esc_attr(PDP_Subscriptions::webhook_url()).'"><button type="button" class="button pdp-copy-button" data-copy="'.esc_attr(PDP_Subscriptions::webhook_url()).'">Copy</button></div></label><label class="pdp-toggle"><input type="checkbox" name="webhook_enabled" value="1" '.checked($s['webhook_enabled'],'1',false).'><span>Accept and validate Square webhook notifications</span></label></div>';
        echo '<div class="pdp-admin-card pdp-card-full"><div class="pdp-admin-card-head"><div><h2>Square API Credentials</h2><p>Credentials are stored in your WordPress options table and are never displayed publicly.</p></div></div><div class="pdp-field-grid">';
        self::field('Application ID','application_id',$s['application_id'],'sq0idp-…');
        self::field('Location ID','location_id',$s['location_id'],'L…');
        self::field('Access Token','access_token',$s['access_token']?'********':'','Paste the token from Square',true);
        self::field('Webhook Signature Key','webhook_signature_key',$s['webhook_signature_key']?'********':'','Paste the webhook signature key',true);
        self::field('Square API Version','api_version',$s['api_version'],'2026-05-20');
        echo '</div></div></div><div class="pdp-sticky-save"><div><strong>Square subscription foundation</strong><span>Checkout and live API synchronization are added in the next milestone.</span></div><button class="button button-primary button-hero">Save Square Settings</button></div></form></div>';
    }

    public static function webhook_logs_page() {
        if (!current_user_can('manage_options')) { return; }
        global $wpdb;
        $table = PDP_DB::table('webhook_logs');
        $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY id DESC LIMIT 100", ARRAY_A);
        $valid = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE signature_valid=1");
        $rejected = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE processing_status='rejected'");
        self::header('Webhook Logs', 'Audit incoming Square notifications, signature validation, processing results, and stored payloads.'); self::notice();
        echo '<div class="pdp-sub-stats"><div class="pdp-sub-stat"><span>Total Logged</span><strong>'.count($rows).'</strong></div><div class="pdp-sub-stat"><span>Validated</span><strong>'.$valid.'</strong></div><div class="pdp-sub-stat"><span>Rejected</span><strong>'.$rejected.'</strong></div><div class="pdp-sub-stat"><span>Endpoint</span><strong class="pdp-small-stat">REST v3</strong></div></div>';
        echo '<div class="pdp-admin-card"><div class="pdp-admin-card-head"><h2>Recent Notifications</h2><div class="pdp-button-row"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('pdp_test_webhook_log','_wpnonce',true,false).'<input type="hidden" name="action" value="pdp_test_webhook_log"><button class="button">Create Test Log</button></form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Clear all webhook logs?\');">'.wp_nonce_field('pdp_clear_webhook_logs','_wpnonce',true,false).'<input type="hidden" name="action" value="pdp_clear_webhook_logs"><button class="button button-link-delete">Clear Logs</button></form></div></div>';
        echo '<div class="pdp-table-wrap"><table class="widefat striped"><thead><tr><th>Received</th><th>Event</th><th>Event ID</th><th>Signature</th><th>Status</th><th>HTTP</th><th>Payload</th></tr></thead><tbody>';
        if (!$rows) { echo '<tr><td colspan="7"><div class="pdp-empty-state"><span class="dashicons dashicons-shield"></span><h3>No webhook activity yet</h3><p>Use Create Test Log to verify database logging, then configure the notification URL in Square.</p></div></td></tr>'; }
        foreach ($rows as $row) {
            $payload = (string)$row['payload'];
            echo '<tr><td>'.esc_html($row['received_at']).'</td><td><strong>'.esc_html($row['event_type']).'</strong></td><td><code>'.esc_html($row['event_id']).'</code></td><td>'.($row['signature_valid']?'<span class="pdp-health-badge ready">Valid</span>':'<span class="pdp-health-badge danger">Invalid</span>').'</td><td><span class="pdp-status pdp-status-'.esc_attr($row['processing_status']).'">'.esc_html(ucwords($row['processing_status'])).'</span></td><td>'.esc_html($row['http_status']).'</td><td><details><summary>View JSON</summary><pre>'.esc_html(self::pretty_json($payload)).'</pre></details></td></tr>';
        }
        echo '</tbody></table></div></div></div>';
    }

    private static function pretty_json($payload) { $decoded=json_decode($payload,true); return is_array($decoded)?wp_json_encode($decoded,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES):$payload; }
    private static function field($label,$name,$value,$placeholder='',$secret=false) { echo '<label class="pdp-field"><span>'.esc_html($label).'</span><input type="'.($secret?'password':'text').'" name="'.esc_attr($name).'" value="'.esc_attr($value).'" placeholder="'.esc_attr($placeholder).'" autocomplete="off"></label>'; }
    private static function header($title,$subtitle) { echo '<div class="wrap pdp-subscriptions-admin"><div class="pdp-page-hero"><div><span class="pdp-eyebrow">Party Desk Pro · Version 3.0 Alpha</span><h1>'.esc_html($title).'</h1><p>'.esc_html($subtitle).'</p></div><div class="pdp-hero-icon"><span class="dashicons dashicons-money-alt"></span></div></div>'; }
    private static function notice() { if (!empty($_GET['pdp_notice'])) echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(rawurldecode(wp_unslash($_GET['pdp_notice']))).'</p></div>'; }
}
