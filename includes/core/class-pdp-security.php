<?php
/**
 * Shared security helpers.
 */

if (!defined('ABSPATH')) { exit; }

final class PDP_Security {
    public static function require_capability($capability = 'manage_options') {
        if (!current_user_can($capability)) {
            wp_die(esc_html__('You do not have permission to perform this action.', 'party-desk-pro-license-server'), 403);
        }
    }

    public static function verify_admin_action($action, $capability = 'manage_options') {
        self::require_capability($capability);
        check_admin_referer($action);
    }

    public static function normalize_site_url($url) {
        $url = esc_url_raw(trim((string) $url));
        if (!$url) { return ''; }
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) { return ''; }
        $scheme = !empty($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
        $host = strtolower($parts['host']);
        $port = !empty($parts['port']) ? ':' . absint($parts['port']) : '';
        $path = !empty($parts['path']) ? untrailingslashit($parts['path']) : '';
        return $scheme . '://' . $host . $port . $path;
    }

    public static function hash_identifier($value) {
        return hash_hmac('sha256', (string) $value, wp_salt('auth'));
    }
}
