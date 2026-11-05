<?php

/**
 * Wires the plugin into WordPress.
 */

declare(strict_types=1);

namespace UAMSWeb\PluginTemplate;

defined('ABSPATH') || exit;

final class Plugin
{
    /**
     * Register every hook the plugin uses.
     */
    public static function boot(): void
    {
        add_action('init', [self::class, 'load_textdomain']);
        add_action('admin_menu', [Settings::class, 'register_page']);
        add_action('admin_init', [Settings::class, 'register_setting']);
        add_action('admin_post_'.Settings::ACTION, [Settings::class, 'handle_save']);
    }

    /**
     * Load the plugin's translations.
     *
     * WordPress loads them on demand for plugins on WordPress.org; a plugin
     * that is not hosted there loads them itself.
     */
    public static function load_textdomain(): void
    {
        load_plugin_textdomain(
            'uams-plugin-template',
            false,
            dirname(plugin_basename(UAMS_PLUGIN_TEMPLATE_FILE)).'/languages'
        );
    }

    /**
     * Run on activation. Never deletes data.
     *
     * The first activation records the version so a later release can tell
     * which migrations to run. Network activation runs this once for the
     * network; per-site setup belongs in a `wp_initialize_site` callback.
     */
    public static function activate(): void
    {
        add_option('uams_plugin_template_version', UAMS_PLUGIN_TEMPLATE_VERSION, '', false);
    }

    /**
     * Run on deactivation. Never deletes data: removal belongs to uninstall.php alone.
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('uams_plugin_template_cron');
    }
}
