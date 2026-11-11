<?php

/**
 * The capability layer of the guard: maps every capability that changes the
 * site to `do_not_allow` for an Auditor, so no link or button that needs one
 * is shown and no check that asks for one passes.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class MetaCaps
{
    /** The capability that opens the activity log, for administrators only. */
    public const VIEW_LOG = 'uamswp_auditor_role_view_log';

    /**
     * The meta capabilities that act, denied on top of the never-granted ones.
     *
     * A capability that starts with `install_`, `update_` or `delete_` is denied
     * by Role::is_never_granted() in map(), so `delete_post`, `update_php` and
     * the like are not listed here. A method, not a constant, so each entry is
     * a line a test is seen to cover.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [
            'activate_plugin',
            'deactivate_plugin',
            'deactivate_plugins',
            'resume_plugin',
            'resume_theme',
            'switch_theme',
            'publish_post',
            'remove_user',
            'promote_user',
            'create_app_password',
            'edit_app_password',
            'erase_others_personal_data',
            'export_others_personal_data',
            'edit_css',
            'upgrade_db',
            'customize',
        ];
    }

    /** @var list<string>|null The denied list, held after `init`. */
    private static ?array $denied = null;

    public static function boot(): void
    {
        add_filter('map_meta_cap', [self::class, 'map'], PHP_INT_MAX, 4);
    }

    /**
     * The meta capabilities denied to an Auditor on this install: the defaults
     * and a deployment's additions, each name once. A filter can add to the
     * list but not drop a default. The never-granted capabilities are checked
     * by name in map(), so they are not repeated here.
     *
     * @return list<string>
     */
    public static function denied(): array
    {
        if (self::$denied !== null) {
            return self::$denied;
        }

        $defaults = self::defaults();
        $added = apply_filters('uamswp_auditor_role_denied_caps', $defaults);
        $added = is_array($added) ? array_filter($added, is_string(...)) : [];
        $denied = array_values(array_unique(array_merge($defaults, $added)));

        // Held once `init` has run, when every plugin has had the chance to filter it.
        if (did_action('init') > 0) {
            self::$denied = $denied;
        }

        return $denied;
    }

    /**
     * Forget the held list. Used by the tests.
     */
    public static function reset(): void
    {
        self::$denied = null;
    }

    /**
     * Map a capability check for an Auditor.
     *
     * @return list<string>
     */
    public static function map(mixed $caps, mixed $cap, mixed $user_id, mixed $args): array
    {
        $caps = is_array($caps) ? array_values($caps) : [];

        if (! is_string($cap) || ! is_int($user_id)) {
            return $caps;
        }

        $is_auditor = Role::user_is_auditor(get_userdata($user_id));

        if ($cap === self::VIEW_LOG) {
            return $is_auditor ? ['do_not_allow'] : ['manage_options'];
        }

        if (! $is_auditor) {
            return $caps;
        }

        if ($cap === 'edit_user') {
            if (! is_array($args) || ! isset($args[0])) {
                return ['do_not_allow'];
            }

            return (int) $args[0] === $user_id ? $caps : ['do_not_allow'];
        }

        if (in_array($cap, self::denied(), true) || Role::is_never_granted($cap)) {
            return ['do_not_allow'];
        }

        // On a network that has not enabled the Plugins menu for site
        // administrators, core adds `manage_network_plugins` to this check.
        // The role may still list plugins: every action on the screen checks a
        // capability the role never passes (`activate_plugin`, `deactivate_plugin`,
        // and the `install_`, `update_` and `delete_` families).
        if ($cap === 'activate_plugins') {
            $caps = array_values(array_diff($caps, ['manage_network_plugins']));
        }

        foreach ($caps as $mapped) {
            if (is_string($mapped) && Role::is_never_granted($mapped)) {
                return ['do_not_allow'];
            }
        }

        return $caps;
    }
}
