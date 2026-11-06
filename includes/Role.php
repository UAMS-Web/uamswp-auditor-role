<?php

/**
 * The Auditor role: its name, the capabilities it holds and the ones it never holds.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

use WP_User;

defined('ABSPATH') || exit;

final class Role
{
    /** The role's name. */
    public const NAME = 'auditor';

    /**
     * The role definition's version. Raise it to rewrite the role on every site.
     */
    public const VERSION = '1';

    /** The option that records the role definition a site holds. */
    public const OPTION = 'uamswp_auditor_role_role_version';

    /** The filter that adds capabilities for a deployment. */
    public const FILTER = 'uamswp_auditor_role_capabilities';

    /**
     * The capabilities the role holds.
     *
     * `edit_posts` and `edit_pages` only open the list screens, `activate_plugins`
     * only opens the Plugins screen, and `manage_options` only opens settings
     * screens. The guard refuses every write those capabilities would allow.
     * `upload_files` opens the Media library and `manage_categories` the term
     * screens, for the same reason.
     *
     * @var list<string>
     */
    public const GRANTED = [
        'read',
        'read_private_posts',
        'read_private_pages',
        'edit_posts',
        'edit_pages',
        'upload_files',
        'manage_categories',
        'activate_plugins',
        'switch_themes',
        'edit_theme_options',
        'view_site_health_checks',
        'manage_options',
    ];

    /**
     * The capabilities the role never holds, whatever a filter or another plugin adds.
     *
     * @var list<string>
     */
    public const NEVER_GRANTED = [
        'install_plugins',
        'install_themes',
        'install_languages',
        'update_plugins',
        'update_themes',
        'update_core',
        'update_languages',
        'upload_plugins',
        'upload_themes',
        'delete_plugins',
        'delete_themes',
        'delete_users',
        'delete_sites',
        'delete_posts',
        'delete_pages',
        'delete_others_posts',
        'delete_others_pages',
        'delete_published_posts',
        'delete_published_pages',
        'delete_private_posts',
        'delete_private_pages',
        'delete_site',
        'edit_plugins',
        'edit_themes',
        'edit_files',
        'edit_users',
        'create_users',
        'promote_users',
        'remove_users',
        'add_users',
        'list_users',
        'export',
        'import',
        'unfiltered_html',
        'unfiltered_upload',
        'publish_posts',
        'publish_pages',
        'resume_plugins',
        'resume_themes',
        'create_sites',
        'manage_sites',
        'manage_network',
        'manage_network_users',
        'manage_network_plugins',
        'manage_network_themes',
        'manage_network_options',
        'upgrade_network',
        'setup_network',
    ];

    /**
     * The capabilities the role holds on this install: the granted list plus a
     * deployment's additions, less anything on the never-granted list.
     *
     * @return list<string>
     */
    public static function capabilities(): array
    {
        $added = apply_filters('uamswp_auditor_role_capabilities', self::GRANTED);
        $added = is_array($added) ? $added : self::GRANTED;

        $capabilities = [];

        foreach (array_merge(self::GRANTED, $added) as $capability) {
            if (is_string($capability) && $capability !== '' && ! self::is_never_granted($capability)) {
                $capabilities[$capability] = true;
            }
        }

        return array_keys($capabilities);
    }

    /**
     * Whether a capability is one the role can never hold.
     *
     * The prefix check catches a plugin's own install, update and delete
     * capabilities, which no list can name in advance. `upload_` is not a
     * prefix here: `upload_files` opens the Media library, and core's two
     * upload-and-install capabilities are on the list by name.
     */
    public static function is_never_granted(string $capability): bool
    {
        return in_array($capability, self::NEVER_GRANTED, true)
            || preg_match('/^(install|update|delete)_/', $capability) === 1;
    }

    /**
     * The stamp stored per site: the definition version and a hash of the
     * capability list, so a changed filter also rewrites the role.
     */
    public static function stamp(): string
    {
        return self::VERSION.':'.md5((string) wp_json_encode(self::capabilities()));
    }

    /**
     * Register or rewrite the role on the current site when its stamp is stale.
     *
     * A must-use plugin has no activation hook, so this runs on `init`. The write
     * is the plugin's own, so it runs with the database guard suspended.
     */
    public static function maybe_register(): void
    {
        if (get_option(self::OPTION) === self::stamp() && get_role(self::NAME) !== null) {
            return;
        }

        DatabaseGuard::bypass(static function (): void {
            self::register();
        });
    }

    /**
     * Write the role and its stamp on the current site.
     */
    public static function register(): void
    {
        remove_role(self::NAME);
        add_role(self::NAME, __('Auditor', 'uamswp-auditor-role'), array_fill_keys(self::capabilities(), true));
        update_option(self::OPTION, self::stamp(), true);
    }

    /**
     * Whether a user holds the Auditor role on the current site.
     */
    public static function user_is_auditor(mixed $user): bool
    {
        return $user instanceof WP_User && in_array(self::NAME, $user->roles, true);
    }
}
