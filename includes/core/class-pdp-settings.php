<?php
/**
 * Typed settings facade for core configuration.
 */

if (!defined('ABSPATH')) { exit; }

final class PDP_Settings {
    const OPTION = 'pdp_ls_core_settings';

    public static function defaults() {
        return array(
            'environment'       => 'production',
            'logging_enabled'   => '1',
            'log_retention'     => '250',
            'api_rate_limit'    => '120',
            'delete_on_uninstall' => '0',
        );
    }

    public static function all() {
        return wp_parse_args(get_option(self::OPTION, array()), self::defaults());
    }

    public static function get($key, $default = null) {
        $settings = self::all();
        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public static function update($values) {
        $current = self::all();
        $clean = array();
        if (isset($values['environment'])) {
            $clean['environment'] = in_array($values['environment'], array('sandbox', 'production'), true) ? $values['environment'] : 'production';
        }
        foreach (array('logging_enabled', 'delete_on_uninstall') as $boolean) {
            if (isset($values[$boolean])) { $clean[$boolean] = '1' === (string) $values[$boolean] ? '1' : '0'; }
        }
        if (isset($values['log_retention'])) { $clean['log_retention'] = (string) min(1000, max(50, absint($values['log_retention']))); }
        if (isset($values['api_rate_limit'])) { $clean['api_rate_limit'] = (string) min(1000, max(10, absint($values['api_rate_limit']))); }
        return update_option(self::OPTION, array_merge($current, $clean), false);
    }
}
