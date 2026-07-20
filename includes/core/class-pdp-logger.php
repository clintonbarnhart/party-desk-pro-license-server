<?php
/**
 * Central logger with privacy-safe context handling.
 */

if (!defined('ABSPATH')) { exit; }

final class PDP_Logger {
    const OPTION = 'pdp_ls_log_entries';
    const MAX_ENTRIES = 250;

    public static function debug($message, $context = array()) { self::write('debug', $message, $context); }
    public static function info($message, $context = array()) { self::write('info', $message, $context); }
    public static function warning($message, $context = array()) { self::write('warning', $message, $context); }
    public static function error($message, $context = array()) { self::write('error', $message, $context); }

    public static function write($level, $message, $context = array()) {
        $allowed = array('debug', 'info', 'warning', 'error');
        $level = in_array($level, $allowed, true) ? $level : 'info';
        $entry = array(
            'time'    => current_time('mysql', true),
            'level'   => $level,
            'message' => sanitize_text_field($message),
            'context' => self::sanitize_context($context),
        );

        $entries = get_option(self::OPTION, array());
        if (!is_array($entries)) { $entries = array(); }
        array_unshift($entries, $entry);
        $entries = array_slice($entries, 0, self::MAX_ENTRIES);
        update_option(self::OPTION, $entries, false);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[Party Desk Pro][' . strtoupper($level) . '] ' . $entry['message']); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    public static function all() {
        $entries = get_option(self::OPTION, array());
        return is_array($entries) ? $entries : array();
    }

    public static function clear() { delete_option(self::OPTION); }

    private static function sanitize_context($context) {
        if (!is_array($context)) { return array(); }
        $blocked = array('token', 'access_token', 'secret', 'password', 'authorization', 'license_key');
        $clean = array();
        foreach ($context as $key => $value) {
            $safe_key = sanitize_key($key);
            if (in_array($safe_key, $blocked, true)) {
                $clean[$safe_key] = '[redacted]';
            } elseif (is_scalar($value) || null === $value) {
                $clean[$safe_key] = sanitize_text_field((string) $value);
            } else {
                $clean[$safe_key] = '[complex value]';
            }
        }
        return $clean;
    }
}
