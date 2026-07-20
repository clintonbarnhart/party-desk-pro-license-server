<?php
/**
 * Phase 1 system status and maintenance screen.
 */

if (!defined('ABSPATH')) { exit; }

final class PDP_System_Health {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'menu'), 90);
        add_action('admin_post_pdp_save_core_settings', array(__CLASS__, 'save_settings'));
        add_action('admin_post_pdp_run_core_migrations', array(__CLASS__, 'run_migrations'));
        add_action('admin_post_pdp_clear_core_logs', array(__CLASS__, 'clear_logs'));
    }

    public static function menu() {
        add_submenu_page(
            'pdp-dashboard',
            __('System & Diagnostics', 'party-desk-pro-license-server'),
            __('System & Diagnostics', 'party-desk-pro-license-server'),
            'manage_options',
            'pdp-system-health',
            array(__CLASS__, 'render')
        );
    }

    public static function save_settings() {
        PDP_Security::verify_admin_action('pdp_save_core_settings');
        PDP_Settings::update(wp_unslash($_POST));
        PDP_Logger::info('Core settings updated.');
        self::redirect('Core settings saved.');
    }

    public static function run_migrations() {
        PDP_Security::verify_admin_action('pdp_run_core_migrations');
        PDP_Migrations::run();
        self::redirect('Database migrations completed.');
    }

    public static function clear_logs() {
        PDP_Security::verify_admin_action('pdp_clear_core_logs');
        PDP_Logger::clear();
        self::redirect('System logs cleared.');
    }

    public static function render() {
        PDP_Security::require_capability();
        $settings = PDP_Settings::all();
        $logs = array_slice(PDP_Logger::all(), 0, 25);
        $db = class_exists('PDP_DB') ? PDP_DB::health_check() : array();
        $healthy = true;
        foreach ($db as $item) { if (empty($item['exists'])) { $healthy = false; } }
        ?>
        <div class="wrap pdp-admin pdp-modern-admin">
            <div class="pdp-admin-hero">
                <div>
                    <span class="pdp-admin-kicker">PHASE 1 CORE FRAMEWORK</span>
                    <h1><?php esc_html_e('System & Diagnostics', 'party-desk-pro-license-server'); ?></h1>
                    <p><?php esc_html_e('Manage core behavior, verify database readiness, and review privacy-safe system activity.', 'party-desk-pro-license-server'); ?></p>
                </div>
            </div>

            <div class="pdp-settings-grid" style="display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.7fr);gap:24px;align-items:start;">
                <section class="pdp-dashboard-panel" style="padding:24px;">
                    <h2><?php esc_html_e('Core settings', 'party-desk-pro-license-server'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="pdp_save_core_settings">
                        <?php wp_nonce_field('pdp_save_core_settings'); ?>
                        <table class="form-table" role="presentation">
                            <tr><th><label for="pdp-environment"><?php esc_html_e('Environment', 'party-desk-pro-license-server'); ?></label></th><td><select id="pdp-environment" name="environment"><option value="production" <?php selected($settings['environment'], 'production'); ?>>Production</option><option value="sandbox" <?php selected($settings['environment'], 'sandbox'); ?>>Sandbox</option></select></td></tr>
                            <tr><th><?php esc_html_e('Logging', 'party-desk-pro-license-server'); ?></th><td><label><input type="hidden" name="logging_enabled" value="0"><input type="checkbox" name="logging_enabled" value="1" <?php checked($settings['logging_enabled'], '1'); ?>> Enable privacy-safe application logging</label></td></tr>
                            <tr><th><label for="pdp-retention"><?php esc_html_e('Log retention', 'party-desk-pro-license-server'); ?></label></th><td><input id="pdp-retention" type="number" min="50" max="1000" name="log_retention" value="<?php echo esc_attr($settings['log_retention']); ?>"> entries</td></tr>
                            <tr><th><label for="pdp-rate-limit"><?php esc_html_e('API requests per hour', 'party-desk-pro-license-server'); ?></label></th><td><input id="pdp-rate-limit" type="number" min="10" max="1000" name="api_rate_limit" value="<?php echo esc_attr($settings['api_rate_limit']); ?>"></td></tr>
                            <tr><th><?php esc_html_e('Uninstall cleanup', 'party-desk-pro-license-server'); ?></th><td><label><input type="hidden" name="delete_on_uninstall" value="0"><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked($settings['delete_on_uninstall'], '1'); ?>> Delete plugin data when uninstalling</label><p class="description">Keep disabled for commercial installations unless a full reset is intended.</p></td></tr>
                        </table>
                        <?php submit_button(__('Save Core Settings', 'party-desk-pro-license-server')); ?>
                    </form>
                </section>

                <aside class="pdp-dashboard-panel" style="padding:24px;">
                    <h2><?php esc_html_e('System readiness', 'party-desk-pro-license-server'); ?></h2>
                    <p><strong>Plugin:</strong> <?php echo esc_html(defined('PDP_LS_VERSION') ? PDP_LS_VERSION : 'Unknown'); ?></p>
                    <p><strong>Core schema:</strong> <?php echo esc_html((string) get_option(PDP_Migrations::OPTION, 'Not installed')); ?></p>
                    <p><strong>Database:</strong> <?php echo $healthy ? '<span style="color:#137333;font-weight:700">Healthy</span>' : '<span style="color:#b42318;font-weight:700">Attention required</span>'; ?></p>
                    <p><strong>WordPress:</strong> <?php echo esc_html(get_bloginfo('version')); ?></p>
                    <p><strong>PHP:</strong> <?php echo esc_html(PHP_VERSION); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="pdp_run_core_migrations"><?php wp_nonce_field('pdp_run_core_migrations'); ?>
                        <?php submit_button(__('Run Safe Migrations', 'party-desk-pro-license-server'), 'secondary', 'submit', false); ?>
                    </form>
                </aside>
            </div>

            <section class="pdp-dashboard-panel" style="padding:24px;margin-top:24px;">
                <div style="display:flex;justify-content:space-between;gap:16px;align-items:center;"><div><h2 style="margin-bottom:4px"><?php esc_html_e('Recent system logs', 'party-desk-pro-license-server'); ?></h2><p class="description">Sensitive tokens, passwords, and license keys are automatically redacted.</p></div><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="pdp_clear_core_logs"><?php wp_nonce_field('pdp_clear_core_logs'); ?><?php submit_button(__('Clear Logs', 'party-desk-pro-license-server'), 'secondary', 'submit', false); ?></form></div>
                <table class="widefat striped"><thead><tr><th>UTC time</th><th>Level</th><th>Message</th></tr></thead><tbody>
                <?php if (!$logs) : ?><tr><td colspan="3">No log entries yet.</td></tr><?php else : foreach ($logs as $entry) : ?><tr><td><?php echo esc_html($entry['time']); ?></td><td><code><?php echo esc_html(strtoupper($entry['level'])); ?></code></td><td><?php echo esc_html($entry['message']); ?></td></tr><?php endforeach; endif; ?>
                </tbody></table>
            </section>
        </div>
        <?php
    }

    private static function redirect($message) {
        wp_safe_redirect(add_query_arg(array('page' => 'pdp-system-health', 'pdp_notice' => rawurlencode($message)), admin_url('admin.php')));
        exit;
    }
}
