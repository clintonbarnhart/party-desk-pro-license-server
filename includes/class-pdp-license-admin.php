<?php
if (!defined('ABSPATH')) { exit; }
final class PDP_License_Admin {
    private static function metric($label,$value,$icon,$tone='blue',$detail='') {
        echo '<article class="pdp-license-metric tone-'.esc_attr($tone).'"><span class="dashicons '.esc_attr($icon).'"></span><div><small>'.esc_html($label).'</small><strong>'.esc_html($value).'</strong>'.($detail?'<em>'.esc_html($detail).'</em>':'').'</div></article>';
    }
    public static function activity_page() {
        if (!current_user_can('manage_options')) { return; }
        global $wpdb;
        $license_id = absint($_GET['license_id'] ?? 0);
        $event_filter = sanitize_key($_GET['event_type'] ?? '');
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $activation_table=PDP_DB::table('license_activations');
        $event_table=PDP_DB::table('license_events');
        $active=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$activation_table` WHERE status='active'");
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$activation_table`");
        $today=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$event_table` WHERE created_at >= %s",current_time('Y-m-d').' 00:00:00'));
        $failed=(int)$wpdb->get_var("SELECT COUNT(*) FROM `$event_table` WHERE event_type LIKE '%fail%' OR event_type LIKE '%reject%' OR event_type LIKE '%denied%'");
        $licenses_count=(int)wp_count_posts('pdp_license')->publish;
        echo '<div class="wrap pdp-admin pdp-modern-admin pdp-license-operations"><div class="pdp-admin-hero"><div><span class="pdp-admin-kicker">LICENSE OPERATIONS</span><h1>License Activity</h1><p>Monitor activations, validation traffic, authorized websites, and security events.</p></div><div class="pdp-live-indicator"><i></i> Live server</div></div>';
        if (!empty($_GET['pdp_notice'])) { echo '<div class="notice notice-success is-dismissible"><p>'.esc_html(wp_unslash($_GET['pdp_notice'])).'</p></div>'; }
        echo '<div class="pdp-license-metrics">';
        self::metric('Issued licenses',$licenses_count,'dashicons-admin-network','purple','Published');
        self::metric('Active websites',$active,'dashicons-admin-site-alt3','green',$total.' total records');
        self::metric('Requests today',$today,'dashicons-chart-line','blue','API and admin events');
        self::metric('Failed validations',$failed,'dashicons-shield-alt','red','All-time security events');
        echo '</div>';
        $licenses = get_posts(array('post_type'=>'pdp_license','post_status'=>'publish','numberposts'=>-1,'orderby'=>'title','order'=>'ASC'));
        echo '<form method="get" class="pdp-pro-card pdp-license-filters"><input type="hidden" name="page" value="pdp-license-activity"><label><span>License</span><select name="license_id"><option value="0">All licenses</option>';
        foreach ($licenses as $license) { echo '<option value="'.absint($license->ID).'" '.selected($license_id,$license->ID,false).'>'.esc_html($license->post_title.' — '.get_post_meta($license->ID,'_pdp_email',true)).'</option>'; }
        echo '</select></label><label><span>Event type</span><select name="event_type"><option value="">All events</option>';
        foreach(array('activated'=>'Activated','validated'=>'Validated','deactivated'=>'Deactivated','update_check'=>'Update checks','validation_failed'=>'Failed validations') as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($event_filter,$k,false).'>'.esc_html($v).'</option>';
        echo '</select></label><label class="pdp-filter-search"><span>Search</span><input type="search" name="s" value="'.esc_attr($search).'" placeholder="Website, message, or IP"></label><button class="button button-primary">Apply filters</button><a class="button" href="'.esc_url(admin_url('admin.php?page=pdp-license-activity')).'">Reset</a></form>';
        $activations = PDP_DB::get_license_activations($license_id, true);
        if($search){$activations=array_values(array_filter($activations,function($r)use($search){return stripos(($r['site_name']??'').' '.($r['site_url']??'').' '.($r['installed_version']??''),$search)!==false;}));}
        echo '<section class="pdp-pro-card pdp-license-section"><div class="pdp-pro-card-head"><div><span class="pdp-section-kicker">INSTALLATIONS</span><h2>Authorized Websites</h2><p>Active and historical installations registered with the license server.</p></div><strong class="pdp-count-pill">'.count($activations).' records</strong></div><div class="pdp-table-scroll"><table class="widefat striped"><thead><tr><th>License</th><th>Website</th><th>Status</th><th>Version</th><th>Activated</th><th>Last checked</th><th>Actions</th></tr></thead><tbody>';
        if (!$activations) { echo '<tr><td colspan="7"><div class="pdp-empty-table"><span class="dashicons dashicons-admin-site-alt3"></span><strong>No website activations found</strong><p>Install the Party Desk Pro client plugin and activate a license to see it here.</p></div></td></tr>'; }
        foreach ($activations as $row) {
            $base = admin_url('admin-post.php'); $nonce = 'pdp_license_site_'.$row['license_id'].'_'.$row['id'];
            $deactivate = wp_nonce_url(add_query_arg(array('action'=>'pdp_license_site_action','task'=>'deactivate','license_id'=>$row['license_id'],'activation_id'=>$row['id']),$base),$nonce);
            $delete = wp_nonce_url(add_query_arg(array('action'=>'pdp_license_site_action','task'=>'delete','license_id'=>$row['license_id'],'activation_id'=>$row['id']),$base),$nonce);
            echo '<tr><td><a href="'.esc_url(get_edit_post_link($row['license_id'])).'">#'.absint($row['license_id']).'</a></td><td><strong>'.esc_html($row['site_name'] ?: wp_parse_url($row['site_url'],PHP_URL_HOST)).'</strong><br><code>'.esc_html($row['site_url']).'</code></td><td><span class="pdp-status pdp-status-'.esc_attr($row['status']).'">'.esc_html(ucfirst($row['status'])).'</span></td><td>'.esc_html($row['installed_version']?:'—').'</td><td>'.esc_html($row['activated_at']).'</td><td>'.esc_html($row['last_checked_at']).'</td><td>'.($row['status']==='active'?'<a class="button button-small" href="'.esc_url($deactivate).'">Deactivate</a> ':'').'<a class="button button-small pdp-danger-button" href="'.esc_url($delete).'" onclick="return confirm(\'Remove this activation record?\')"><span class="dashicons dashicons-trash"></span></a></td></tr>';
        }
        echo '</tbody></table></div></section>';
        $events = PDP_DB::get_license_events($license_id, 250);
        if($event_filter){$events=array_values(array_filter($events,function($e)use($event_filter){return ($e['event_type']??'')===$event_filter || strpos(($e['event_type']??''),$event_filter)!==false;}));}
        if($search){$events=array_values(array_filter($events,function($e)use($search){return stripos(($e['message']??'').' '.($e['ip_address']??'').' '.($e['event_type']??''),$search)!==false;}));}
        echo '<section class="pdp-pro-card pdp-license-section"><div class="pdp-pro-card-head"><div><span class="pdp-section-kicker">SECURITY LOG</span><h2>Security & Validation Timeline</h2><p>The latest license API and administrator events.</p></div><strong class="pdp-count-pill">'.count($events).' events</strong></div><div class="pdp-timeline">';
        if (!$events) { echo '<div class="pdp-empty-table"><span class="dashicons dashicons-shield"></span><strong>No license activity recorded</strong><p>Activation, validation, and update checks will appear here automatically.</p></div>'; }
        foreach ($events as $event) {
            $type=sanitize_key($event['event_type']); $is_bad=(strpos($type,'fail')!==false||strpos($type,'reject')!==false||strpos($type,'denied')!==false);
            echo '<article class="pdp-timeline-event '.($is_bad?'is-danger':'').'"><span class="pdp-event-dot"></span><div class="pdp-event-main"><div><strong>'.esc_html(ucwords(str_replace('_',' ',$type))).'</strong><span>License #'.absint($event['license_id']).'</span></div><p>'.esc_html($event['message']).'</p></div><div class="pdp-event-meta"><time>'.esc_html($event['created_at']).'</time><code>'.esc_html($event['ip_address']?:'Server').'</code></div></article>';
        }
        echo '</div></section></div>';
    }
}
