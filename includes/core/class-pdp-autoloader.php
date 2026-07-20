<?php
/**
 * Lightweight autoloader for Party Desk Pro core classes.
 */

if (!defined('ABSPATH')) { exit; }

final class PDP_Autoloader {
    /** @var string */
    private static $base_dir = '';

    public static function register($base_dir) {
        self::$base_dir = trailingslashit($base_dir);
        spl_autoload_register(array(__CLASS__, 'autoload'));
    }

    public static function autoload($class) {
        if (strpos($class, 'PDP_') !== 0) { return; }

        $slug = strtolower(str_replace('_', '-', $class));
        $file = self::$base_dir . 'class-' . $slug . '.php';
        if (is_readable($file)) { require_once $file; }
    }
}
