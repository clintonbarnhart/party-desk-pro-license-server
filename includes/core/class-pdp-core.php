<?php
/**
 * Phase 1 core service bootstrap.
 */

if (!defined('ABSPATH')) { exit; }

final class PDP_Core {
    public static function init() {
        PDP_System_Health::init();
        add_action('plugins_loaded', array('PDP_Migrations', 'maybe_run'), 30);
    }

    public static function activate() {
        PDP_Migrations::run();
        PDP_Logger::info('Party Desk Pro core activated.', array('version' => PDP_LS_VERSION));
    }
}
