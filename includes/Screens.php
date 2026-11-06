<?php

/**
 * The admin screens an Auditor cannot open, because viewing them is the
 * exposure: secrets, deploy controls and personal data.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class Screens
{
    /** The filter that changes the list per deployment. */
    public const FILTER = 'uamswp_auditor_role_hidden_screens';

    /**
     * The hidden screens. An entry is `page:<slug>` for a plugin page under any
     * parent, or `<script>.php` with optional query parameters that must all match.
     *
     * @var list<string>
     */
    public const HIDDEN = [
        // Core: every raw option, export and import, personal-data requests, and
        // the Site Health info tab, which shows paths, database and server details.
        'options.php',
        'export.php',
        'import.php',
        'export-personal-data.php',
        'erase-personal-data.php',
        'site-health.php?tab=debug',
        // Deploy tools: a settings save can deploy at once, and the screens carry tokens.
        'page:wppusher',
        'page:wppusher-plugins',
        'page:wppusher-plugins-create',
        'page:wppusher-themes',
        'page:wppusher-themes-create',
        'page:wppusher-settings',
        // Mail: SMTP credentials and the log of every message sent.
        'page:wp-mail-smtp',
        'page:wp-mail-smtp-logs',
        'page:wp-mail-smtp-reports',
        'page:wp-mail-smtp-tools',
        'page:formidable-smtp',
        // Form entries, form settings with API and reCAPTCHA keys, and form exports.
        'page:gf_entries',
        'page:gf_settings',
        'page:gf_export',
        'page:gf_system_status',
        'page:formidable-entries',
        'page:formidable-settings',
        'page:formidable-import',
        'edit.php?post_type=nf_sub',
        'page:nf-submissions',
        'page:nf-settings',
        'page:nf-import-export',
        'page:nf-system-status',
        // Raw option and transient values, which hold tokens.
        'page:transients-manager',
        'page:acfe-options',
        // License and API keys.
        'page:acf-settings-updates',
        'page:seopress-license',
        'page:seopress-google-analytics',
        'page:relevanssi-premium/relevanssi.php',
        'page:facetwp',
        'page:asp_updates_help',
        'page:geot-settings',
        // The hidden login address.
        'page:whl_settings',
        // Bulk data exports.
        'page:uamswp-gmb-export',
        'page:asp_export_import',
        'page:tablepress_export',
    ];

    /**
     * The dashboard widgets an Auditor does not see.
     *
     * @var list<string>
     */
    public const HIDDEN_WIDGETS = [
        'dashboard_quick_press',
        'wp_mail_smtp_reports_widget_pro',
        'wp_mail_smtp_reports_widget_lite',
    ];

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'remove_menu_entries'], PHP_INT_MAX);
        add_action('wp_dashboard_setup', [self::class, 'remove_dashboard_writers'], PHP_INT_MAX);
    }

    /**
     * Take dashboard widgets off an Auditor's dashboard: Quick Draft, which
     * creates an auto-draft on every load and fails when the guard refuses the
     * insert, and widgets that summarize a hidden screen.
     */
    public static function remove_dashboard_writers(): void
    {
        if (! Session::is_auditor()) {
            return;
        }

        $widgets = apply_filters('uamswp_auditor_role_hidden_dashboard_widgets', self::HIDDEN_WIDGETS);

        foreach (is_array($widgets) ? $widgets : self::HIDDEN_WIDGETS as $widget) {
            if (is_string($widget)) {
                foreach (['normal', 'side', 'column3', 'column4'] as $context) {
                    remove_meta_box($widget, 'dashboard', $context);
                }
            }
        }
    }

    /**
     * The hidden screens on this install, after a deployment's filter.
     *
     * @return list<string>
     */
    public static function hidden(): array
    {
        $hidden = apply_filters('uamswp_auditor_role_hidden_screens', self::HIDDEN);

        return is_array($hidden) ? array_values(array_filter($hidden, is_string(...))) : self::HIDDEN;
    }

    /**
     * Whether a screen, given its script and query parameters, is hidden.
     *
     * @param  array<string, mixed>  $params
     */
    public static function is_hidden(string $script, array $params): bool
    {
        foreach (self::hidden() as $entry) {
            if (self::matches($entry, $script, $params)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether one entry matches a screen.
     *
     * @param  array<string, mixed>  $params
     */
    public static function matches(string $entry, string $script, array $params): bool
    {
        if (str_starts_with($entry, 'page:')) {
            return isset($params['page']) && is_scalar($params['page']) && substr($entry, 5) === (string) $params['page'];
        }

        $parts = explode('?', $entry, 2);

        if ($parts[0] !== $script) {
            return false;
        }

        parse_str($parts[1] ?? '', $required);

        foreach ($required as $key => $value) {
            if (! isset($params[$key]) || ! is_scalar($params[$key]) || (string) $params[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a menu slug opens a hidden screen.
     *
     * A slug is either a script with its query, or a page slug that WordPress
     * opens as `admin.php?page=<slug>`; both readings are checked.
     */
    public static function is_hidden_slug(string $slug): bool
    {
        $slug = html_entity_decode($slug);
        $parts = explode('?', $slug, 2);
        parse_str($parts[1] ?? '', $parsed);

        $params = [];

        foreach ($parsed as $key => $value) {
            $params[(string) $key] = $value;
        }

        return self::is_hidden($parts[0], $params) || self::is_hidden('admin.php', ['page' => $slug]);
    }

    /**
     * A short name for a screen in the log.
     *
     * @param  array<string, mixed>  $params
     */
    public static function label(string $script, array $params): string
    {
        return isset($params['page']) && is_scalar($params['page'])
            ? $script.'?page='.sanitize_text_field((string) $params['page'])
            : $script;
    }

    /**
     * Take hidden screens out of the menu for an Auditor.
     */
    public static function remove_menu_entries(): void
    {
        global $menu, $submenu;

        if (! Session::is_auditor()) {
            return;
        }

        if (is_array($submenu)) {
            foreach ($submenu as $parent => $items) {
                foreach ((array) $items as $index => $item) {
                    if (isset($item[2]) && is_string($item[2]) && self::is_hidden_slug($item[2])) {
                        unset($submenu[$parent][$index]);
                    }
                }
            }
        }

        if (is_array($menu)) {
            foreach ($menu as $index => $item) {
                if (isset($item[2]) && is_string($item[2]) && self::is_hidden_slug($item[2])) {
                    unset($menu[$index]);
                }
            }
        }
    }
}
