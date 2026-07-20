<?php
/**
 * Ordered and repeatable core migrations.
 */

if (!defined('ABSPATH')) { exit; }

final class PDP_Migrations {
    const OPTION = 'pdp_ls_core_schema_version';
    const VERSION = '1.0.0';

    public static function maybe_run() {
        if (version_compare((string) get_option(self::OPTION, '0.0.0'), self::VERSION, '<')) {
            self::run();
        }
    }

    public static function run() {
        if (class_exists('PDP_DB')) { PDP_DB::install(); }
        add_option(PDP_Settings::OPTION, PDP_Settings::defaults(), '', false);
        update_option(self::OPTION, self::VERSION, false);
        PDP_Logger::info('Core database migrations completed.', array('version' => self::VERSION));
    }
}
