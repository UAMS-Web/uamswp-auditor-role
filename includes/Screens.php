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
     * parent, `page:<prefix>*` for every plugin page whose slug starts with the
     * prefix, or `<script>.php` with optional query parameters that must all match.
     * A method, not a constant, so each entry is a line a test is seen to cover.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [
            // Core: every raw option, export and import, personal-data requests, and
            // the Site Health info tab, which shows paths, database and server details.
            'options.php',
            'export.php',
            'import.php',
            'export-personal-data.php',
            'erase-personal-data.php',
            'site-health.php?tab=debug',
            // Settings > Connectors (WordPress 7.0): connections to external services
            // and their credentials.
            'options-connectors.php',
            // The Customizer, a write surface in every control (see MetaCaps).
            'customize.php',
            // Comment moderation, the Site Editor and the Font Library: screens that only change things.
            'edit-comments.php',
            'site-editor.php',
            'font-library.php',
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
            'page:wp-shortpixel-settings',
            // The hidden login address.
            'page:whl_settings',
            // Bulk data exports.
            'page:uamswp-gmb-export',
            'page:asp_export_import',
            'page:tablepress_export',
            // Bulk actions: a screen that only starts a run.
            'page:wp-short-pixel-bulk',
            // Image maintenance: Better Image Sizes' settings and cached-image tools.
            'page:better-image-sizes/better-image-sizes.php',
            // Image maintenance: Regenerate Thumbnails, a screen that only starts a run.
            'page:regenerate-thumbnails',
            // ACF's data model: field groups, post types, taxonomies, options pages, tools,
            // and ACF Extended's settings and field group categories.
            'edit.php?post_type=acf-field-group',
            'edit.php?post_type=acf-post-type',
            'edit.php?post_type=acf-taxonomy',
            'edit.php?post_type=acf-ui-options-page',
            'edit-tags.php?taxonomy=acf-field-group-category',
            'page:acf-tools',
            'page:acfe-settings',
            // Ajax Search Pro: search, index and cache settings, and runs that rebuild or purge.
            'page:asp_main_settings',
            'page:asp_index_table',
            'page:asp_priorities',
            'page:asp_statistics',
            'page:asp_analytics',
            'page:asp_cache_settings',
            'page:asp_performance',
            'page:asp_compatibility_settings',
            'page:asp_maintenance',
            // Edit Flow: the settings screen of every module, enabled or not.
            'page:ef-settings',
            'page:ef-calendar-settings',
            'page:ef-custom-status-settings',
            'page:ef-dashboard-settings',
            'page:ef-editorial-comments-settings',
            'page:ef-editorial-metadata-settings',
            'page:ef-notifications-settings',
            'page:ef-story-budget-settings',
            'page:ef-user-groups-settings',
            // SEOPress and SEOPress PRO: site-wide titles, sitemaps, indexing (with its API keys),
            // redirections, broken links, bots and schemas.
            'page:seopress-option',
            'page:seopress-titles',
            'page:seopress-xml-sitemap',
            'page:seopress-social',
            'page:seopress-instant-indexing',
            'page:seopress-advanced',
            'page:seopress-import-export',
            'page:seopress-pro-page',
            'page:seopress-schemas',
            'page:seopress-redirections',
            'page:seopress-broken-links',
            'edit.php?post_type=seopress_404',
            'edit.php?post_type=seopress_bot',
            'edit.php?post_type=seopress_schemas',
            // Genesis: theme settings, import and export, SEO settings, the onboarding that
            // imports demo content, and each post type's archive settings.
            'page:genesis',
            'page:genesis-import-export',
            'page:seo-settings',
            'page:genesis-getting-started',
            'page:genesis-cpt-archive-*',
            // UAMS Settings (the theme's options page) and the UAMS Search settings under it.
            'page:uamswp-settings',
            'page:uams-search-site',
            // WP Accessibility: the site's front-end fixes and its testing tools.
            'page:wp-accessibility',
            'page:wp-accessibility-overlay',
            'page:wp-accessibility-admin',
            'page:wp-accessibility-help',
            // Media Library Assistant: every settings tab (the Media > Assistant list stays).
            'page:mla-settings-menu*',
        ];
    }

    /**
     * The dashboard widgets an Auditor does not see.
     *
     * @return list<string>
     */
    public static function default_widgets(): array
    {
        return [
            'dashboard_quick_press',
            'wp_mail_smtp_reports_widget_pro',
            'wp_mail_smtp_reports_widget_lite',
        ];
    }

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'remove_menu_entries'], PHP_INT_MAX);
        add_action('admin_menu', [self::class, 'open_plugins_menu'], PHP_INT_MAX);
        add_action('wp_dashboard_setup', [self::class, 'remove_dashboard_writers'], PHP_INT_MAX);
        add_filter('bulk_actions-plugins', [self::class, 'remove_plugin_bulk_actions'], PHP_INT_MAX);
        add_filter('show_network_active_plugins', [self::class, 'show_network_active_plugins'], PHP_INT_MAX);
    }

    /**
     * List network-active plugins, and inactive network-only ones, on a site's
     * Plugins screen for an Auditor. Core shows them only to a user who can
     * `manage_network_plugins`, labels each "Network Active" or "Network Only"
     * in place of its actions, and gives it no bulk checkbox, so nothing on the
     * row acts.
     */
    public static function show_network_active_plugins(mixed $show): mixed
    {
        return Session::is_auditor() ? true : $show;
    }

    /**
     * Offer an Auditor no bulk actions on the Plugins screen. Core adds
     * "Activate" and "Deactivate" there without a capability check of its own,
     * and both submit a write the guard refuses.
     */
    public static function remove_plugin_bulk_actions(mixed $actions): mixed
    {
        return Session::is_auditor() ? [] : $actions;
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

        $defaults = self::default_widgets();
        $widgets = apply_filters('uamswp_auditor_role_hidden_dashboard_widgets', $defaults);

        foreach (is_array($widgets) ? $widgets : $defaults as $widget) {
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
        $defaults = self::defaults();
        $hidden = apply_filters('uamswp_auditor_role_hidden_screens', $defaults);

        return is_array($hidden) ? array_values(array_filter($hidden, is_string(...))) : $defaults;
    }

    /**
     * Whether a screen, given its script and query parameters, is hidden.
     *
     * @param  array<array-key, mixed>  $params
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
     * @param  array<array-key, mixed>  $params
     */
    public static function matches(string $entry, string $script, array $params): bool
    {
        if (str_starts_with($entry, 'page:')) {
            if (! isset($params['page']) || ! is_scalar($params['page'])) {
                return false;
            }

            $slug = substr($entry, 5);
            $page = (string) $params['page'];

            return str_ends_with($slug, '*') ? str_starts_with($page, substr($slug, 0, -1)) : $slug === $page;
        }

        [$path, $query] = self::split($entry);

        if ($path !== $script) {
            return false;
        }

        parse_str($query, $required);

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
        [$path, $query] = self::split($slug);
        parse_str($query, $params);

        return self::is_hidden($path, $params) || self::is_hidden('admin.php', ['page' => $slug]);
    }

    /**
     * A screen's script and its query string, split at the first `?`.
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $screen): array
    {
        $at = strpos($screen, '?');

        return $at === false ? [$screen, ''] : [substr($screen, 0, $at), substr($screen, $at + 1)];
    }

    /**
     * A short name for a screen in the log.
     *
     * @param  array<array-key, mixed>  $params
     */
    public static function label(string $script, array $params): string
    {
        return isset($params['page']) && is_scalar($params['page'])
            ? $script.'?page='.sanitize_text_field("{$params['page']}")
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
                foreach (is_array($items) ? $items : [] as $index => $item) {
                    if (self::is_hidden_item($item)) {
                        unset($submenu[$parent][$index]);
                    }
                }
            }
        }

        if (is_array($menu)) {
            foreach ($menu as $index => $item) {
                if (self::is_hidden_item($item)) {
                    unset($menu[$index]);
                }
            }
        }
    }

    /**
     * Keep core's Plugins menu entry for an Auditor on every screen. Core files
     * it under `activate_plugins`, which MetaCaps passes for an Auditor only on
     * the Plugins screen, so the entry asks for `read` instead. The screen
     * itself still checks `activate_plugins`. WordPress keeps the capability at
     * index 1 and the slug at index 2, and drops the entries a user cannot open
     * after `admin_menu`.
     */
    public static function open_plugins_menu(): void
    {
        global $menu, $submenu;

        if (! Session::is_auditor()) {
            return;
        }

        if (is_array($menu)) {
            foreach ($menu as $index => $item) {
                if (self::is_core_plugins_item($item)) {
                    $menu[$index][1] = 'read';
                }
            }
        }

        if (is_array($submenu) && is_array($submenu['plugins.php'] ?? null)) {
            foreach ($submenu['plugins.php'] as $index => $item) {
                if (self::is_core_plugins_item($item)) {
                    $submenu['plugins.php'][$index][1] = 'read';
                }
            }
        }
    }

    /**
     * Whether a menu or submenu item is core's entry for the Plugins screen.
     */
    private static function is_core_plugins_item(mixed $item): bool
    {
        return is_array($item) && ($item[1] ?? null) === 'activate_plugins' && ($item[2] ?? null) === 'plugins.php';
    }

    /**
     * Whether a menu or submenu item opens a hidden screen. WordPress keeps the
     * slug at index 2; an item without a string there is left alone.
     */
    private static function is_hidden_item(mixed $item): bool
    {
        $slug = is_array($item) ? ($item[2] ?? null) : null;

        return is_string($slug) && self::is_hidden_slug($slug);
    }
}
