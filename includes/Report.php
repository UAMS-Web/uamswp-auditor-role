<?php

/**
 * Builds the read-only reports behind the WP-CLI commands: the discovery
 * report and the network export. Nothing here writes.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class Report
{
    /**
     * The administrator capabilities core itself grants, so a diff shows the
     * ones plugins added.
     *
     * @var list<string>
     */
    public const CORE_ADMIN_CAPS = [
        'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins', 'edit_users', 'edit_files',
        'manage_options', 'moderate_comments', 'manage_categories', 'manage_links', 'upload_files', 'import',
        'unfiltered_html', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts',
        'edit_pages', 'read', 'level_10', 'level_9', 'level_8', 'level_7', 'level_6', 'level_5', 'level_4',
        'level_3', 'level_2', 'level_1', 'level_0', 'edit_others_pages', 'edit_published_pages',
        'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_posts',
        'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_private_posts',
        'read_private_posts', 'delete_private_pages', 'edit_private_pages', 'read_private_pages',
        'delete_users', 'create_users', 'unfiltered_upload', 'edit_dashboard', 'update_plugins',
        'delete_plugins', 'install_plugins', 'update_themes', 'install_themes', 'update_core', 'list_users',
        'remove_users', 'promote_users', 'edit_theme_options', 'delete_themes', 'export',
    ];

    /**
     * The discovery report: what an install needs known before the role is deployed.
     *
     * Option names only, never their values.
     *
     * @return array<string, mixed>
     */
    public static function discovery(): array
    {
        global $wpdb, $wp_version;

        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $sites = self::site_ids();
        $per_site = [];
        $extra_caps = [];
        $secret_options = [];

        foreach ($sites as $blog_id) {
            self::on_site($blog_id, static function () use ($blog_id, &$per_site, &$extra_caps, &$secret_options): void {
                $admin = get_role('administrator');
                $caps = $admin === null ? [] : array_keys(array_filter($admin->capabilities));
                $added = array_values(array_diff($caps, self::CORE_ADMIN_CAPS));

                foreach ($added as $cap) {
                    $extra_caps[$cap] = true;
                }

                $names = self::secret_option_names();

                foreach ($names as $name) {
                    $secret_options[$name] = true;
                }

                $per_site[] = [
                    'blog_id' => $blog_id,
                    'url' => home_url('/'),
                    'theme' => get_stylesheet(),
                    'active_plugins' => array_values((array) get_option('active_plugins', [])),
                ];
            });
        }

        ksort($extra_caps);
        ksort($secret_options);

        return [
            'generated_at' => gmdate('c'),
            'type' => is_multisite() ? (is_subdomain_install() ? 'multisite-subdomain' : 'multisite-subdirectory') : 'single',
            'versions' => [
                'wordpress' => $wp_version,
                'php' => PHP_VERSION,
                'mysql' => $wpdb->db_version(),
                'plugin' => defined('UAMSWP_AUDITOR_ROLE_VERSION') ? UAMSWP_AUDITOR_ROLE_VERSION : '',
            ],
            'sites' => $per_site,
            'network_active_plugins' => is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', [])) : [],
            'installed_plugins' => self::plugins(),
            'must_use_plugins' => array_keys(get_mu_plugins()),
            'dropins' => array_keys(get_dropins()),
            'themes' => array_keys(wp_get_themes()),
            'administrator_caps_not_in_core' => array_keys($extra_caps),
            'secret_looking_option_names' => array_keys($secret_options),
            'secret_looking_network_option_names' => is_multisite() ? self::secret_network_option_names() : [],
        ];
    }

    /**
     * The network export: network settings, plugins, themes and sites, with
     * every secret-bearing value masked.
     *
     * @return array<string, mixed>
     */
    public static function network_export(): array
    {
        global $wp_version;

        if (! function_exists('get_plugins')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $sites = [];

        foreach (self::site_ids() as $blog_id) {
            self::on_site($blog_id, static function () use ($blog_id, &$sites): void {
                $details = is_multisite() ? get_site($blog_id) : null;

                $sites[] = [
                    'blog_id' => $blog_id,
                    'url' => home_url('/'),
                    'name' => get_option('blogname'),
                    'registered' => $details === null ? '' : $details->registered,
                    'last_updated' => $details === null ? '' : $details->last_updated,
                    'public' => $details === null ? (int) get_option('blog_public') : (int) $details->public,
                    'archived' => $details === null ? 0 : (int) $details->archived,
                    'deleted' => $details === null ? 0 : (int) $details->deleted,
                    'spam' => $details === null ? 0 : (int) $details->spam,
                    'theme' => get_stylesheet(),
                    'active_plugins' => array_values((array) get_option('active_plugins', [])),
                ];
            });
        }

        return [
            'generated_at' => gmdate('c'),
            'wordpress' => $wp_version,
            'multisite' => is_multisite(),
            'network_settings' => is_multisite() ? self::network_settings() : [],
            'super_admins' => is_multisite() ? array_values(get_super_admins()) : [],
            'network_active_plugins' => is_multisite() ? array_keys((array) get_site_option('active_sitewide_plugins', [])) : [],
            'network_allowed_themes' => is_multisite() ? array_keys((array) get_site_option('allowedthemes', [])) : [],
            'installed_plugins' => self::plugins(),
            'must_use_plugins' => array_keys(get_mu_plugins()),
            'themes' => array_keys(wp_get_themes()),
            'sites' => $sites,
        ];
    }

    /**
     * Every network option, masked, keyed by name.
     *
     * @return array<string, mixed>
     */
    public static function network_settings(): array
    {
        global $wpdb;

        $names = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT meta_key FROM %i WHERE site_id = %d ORDER BY meta_key', $wpdb->sitemeta, get_current_network_id())); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $settings = [];

        foreach ((array) $names as $name) {
            $name = (string) $name;

            // Transients are caches, not settings.
            if (str_starts_with($name, '_site_transient_')) {
                continue;
            }

            $settings[$name] = Secrets::mask($name, get_site_option($name));
        }

        return $settings;
    }

    /**
     * Option names on the current site that look secret-bearing.
     *
     * @return list<string>
     */
    public static function secret_option_names(): array
    {
        global $wpdb;

        $names = $wpdb->get_col($wpdb->prepare('SELECT option_name FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s', $wpdb->options, '\_transient\_%', '\_site\_transient\_%')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return array_values(array_filter(array_map(strval(...), (array) $names), [Secrets::class, 'is_secret_name']));
    }

    /**
     * Network option names that look secret-bearing.
     *
     * @return list<string>
     */
    public static function secret_network_option_names(): array
    {
        global $wpdb;

        $names = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT meta_key FROM %i WHERE meta_key NOT LIKE %s', $wpdb->sitemeta, '\_site\_transient\_%')); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        return array_values(array_filter(array_map(strval(...), (array) $names), [Secrets::class, 'is_secret_name']));
    }

    /**
     * Installed plugins with their versions.
     *
     * @return array<string, string>
     */
    private static function plugins(): array
    {
        $plugins = [];

        foreach (get_plugins() as $file => $data) {
            $plugins[(string) $file] = (string) ($data['Version'] ?? '');
        }

        return $plugins;
    }

    /**
     * Every site ID, or the one site on a single install.
     *
     * @return list<int>
     */
    public static function site_ids(): array
    {
        if (! is_multisite()) {
            return [get_current_blog_id()];
        }

        $ids = [];

        foreach (get_sites(['number' => 0]) as $site) {
            $ids[] = (int) $site->blog_id;
        }

        return $ids;
    }

    /**
     * Run a read on one site.
     */
    private static function on_site(int $blog_id, callable $callback): void
    {
        if (! is_multisite()) {
            $callback();

            return;
        }

        switch_to_blog($blog_id);

        try {
            $callback();
        } finally {
            restore_current_blog();
        }
    }
}
