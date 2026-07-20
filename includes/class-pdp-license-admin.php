<?php
if (!defined('ABSPATH')) { exit; }
final class PDP_License_Admin {
    public static function activity_page() {
        if (!current_user_can('manage_options')) { return; }
        $license_id = absint($_GET['license_id'] ?? 0);
        echo '<div class="wrap pdp-admin pdp-modern-admin"><div class="pdp-admin-hero"><div><span class="pdp-admin-kicker">LICENSE OPERATIONS</span><h1>License Activity</h1><p>Review website activations, validation activity, and security events.</p></div></div>';
        if (!empty($_GET['pdp_notice'])) { echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(wp_unslash($_GET['pdp_notice'])).'</p></div>'; }
        $licenses = get_posts(array('post_type'=>'pdp_license','post_status'=>'publish','numberposts'=>-1,'orderby'=>'title','order'=>'ASC'));
        echo '<form method="get" class="pdp-pro-card" style="padding:20px;margin-bottom:20px"><input type="hidden" name="page" value="pdp-license-activity"><label><strong>Select license</strong> <select name="license_id"><option value="0">All licenses</option>';
        foreach ($licenses as $license) { echo '<option value="'.absint($license->ID).'" '.selected($license_id,$license->ID,false).'>'.esc_html($license->post_title.' — '.get_post_meta($license->ID,'_pdp_email',true)).'</option>'; }
        echo '</select></label> <button class="button button-primary">View Activity</button></form>';
        $activations = PDP_DB::get_license_activations($license_id, true);
        echo '<section class="pdp-pro-card"><div class="pdp-pro-card-head"><div><h2>Authorized Websites</h2><p>Active and historical installations registered with the license server.</p></div></div><table class="widefat striped"><thead><tr><th>License</th><th>Website</th><th>Status</th><th>Version</th><th>Activated</th><th>Last checked</th><th>Actions</th></tr></thead><tbody>';
        if (!$activations) { echo '<tr><td colspan="7">No website activations found.</td></tr>'; }
        foreach ($activations as $row) {
            $base = admin_url('admin-post.php'); $nonce = 'pdp_license_site_'.$row['license_id'].'_'.$row['id'];
            $deactivate = wp_nonce_url(add_query_arg(array('action'=>'pdp_license_site_action','task'=>'deactivate','license_id'=>$row['license_id'],'activation_id'=>$row['id']),$base),$nonce);
            $delete = wp_nonce_url(add_query_arg(array('action'=>'pdp_license_site_action','task'=>'delete','license_id'=>$row['license_id'],'activation_id'=>$row['id']),$base),$nonce);
            echo '<tr><td><a href="'.esc_url(get_edit_post_link($row['license_id'])).'">#'.absint($row['license_id']).'</a></td><td><strong>'.esc_html($row['site_name'] ?: $row['site_url']).'</strong><br><code>'.esc_html($row['site_url']).'</code></td><td>'.esc_html(ucfirst($row['status'])).'</td><td>'.esc_html($row['installed_version']).'</td><td>'.esc_html($row['activated_at']).'</td><td>'.esc_html($row['last_checked_at']).'</td><td>'.($row['status']==='active'?'<a class="button" href="'.esc_url($deactivate).'">Deactivate</a> ':'').'<a class="button" href="'.esc_url($delete).'" onclick="return confirm(\'Remove this activation record?\')">Remove</a></td></tr>';
        }
        echo '</tbody></table></section>';
        $events = PDP_DB::get_license_events($license_id, 100);
        echo '<section class="pdp-pro-card" style="margin-top:20px"><div class="pdp-pro-card-head"><div><h2>Security & Validation Timeline</h2><p>The latest license API and administrator events.</p></div></div><table class="widefat striped"><thead><tr><th>Time</th><th>License</th><th>Event</th><th>Message</th><th>IP address</th></tr></thead><tbody>';
        if (!$events) { echo '<tr><td colspan="5">No license activity recorded yet.</td></tr>'; }
        foreach ($events as $event) { echo '<tr><td>'.esc_html($event['created_at']).'</td><td>#'.absint($event['license_id']).'</td><td><strong>'.esc_html(ucwords(str_replace('_',' ',$event['event_type']))).'</strong></td><td>'.esc_html($event['message']).'</td><td><code>'.esc_html($event['ip_address']).'</code></td></tr>'; }
        echo '</tbody></table></section></div>';
    }
}
