<?php

/**
 * Wires the plugin into WordPress.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

use UAMSWeb\AuditorRole\Cli\AuditorCommand;
use UAMSWeb\AuditorRole\Cli\LogCommand;

defined('ABSPATH') || exit;

final class Plugin
{
    /**
     * Register every hook the plugin uses.
     *
     * The guard layers boot first: the database guard is armed before any
     * other plugin hook runs, and the request guard runs on `plugins_loaded`.
     */
    public static function boot(): void
    {
        DatabaseGuard::boot();
        RequestGuard::boot();
        MetaCaps::boot();
        Screens::boot();
        Account::boot();
        Log::boot();
        Retention::boot();
        LogViewer::boot();
        Network::boot();
        AdminBar::boot();

        add_action('init', [Role::class, 'maybe_register'], 0);
        add_action('init', [self::class, 'load_textdomain']);

        if (defined('WP_CLI') && WP_CLI && class_exists('WP_CLI')) {
            \WP_CLI::add_command('auditor', AuditorCommand::class);
            \WP_CLI::add_command('auditor log', LogCommand::class);
        }
    }

    /**
     * Load the plugin's translations, from the must-use folder when it runs there.
     */
    public static function load_textdomain(): void
    {
        $path = dirname(plugin_basename(UAMSWP_AUDITOR_ROLE_FILE)).'/languages';

        if (defined('WPMU_PLUGIN_DIR') && str_starts_with(wp_normalize_path(UAMSWP_AUDITOR_ROLE_FILE), wp_normalize_path(WPMU_PLUGIN_DIR))) {
            load_muplugin_textdomain('uamswp-auditor-role', $path);

            return;
        }

        load_plugin_textdomain('uamswp-auditor-role', false, $path);
    }

    /**
     * Run on deactivation when installed as a regular plugin. Never deletes data:
     * removal belongs to uninstall.php alone.
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(Retention::HOOK);
    }
}
