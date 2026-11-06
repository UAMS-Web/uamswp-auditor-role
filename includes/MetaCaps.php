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
     * The meta capabilities that act, denied on top of the never-granted list.
     *
     * @var list<string>
     */
    public const DENIED = [
        'activate_plugin',
        'deactivate_plugin',
        'deactivate_plugins',
        'resume_plugin',
        'resume_theme',
        'switch_theme',
        'delete_post',
        'delete_page',
        'publish_post',
        'delete_term',
        'delete_comment',
        'delete_user',
        'remove_user',
        'promote_user',
        'create_app_password',
        'edit_app_password',
        'delete_app_password',
        'delete_app_passwords',
        'update_https',
        'update_php',
        'erase_others_personal_data',
        'export_others_personal_data',
        'edit_css',
        'install_languages',
        'upgrade_db',
    ];

    /** @var list<string>|null The denied list, held after `init`. */
    private static ?array $denied = null;

    public static function boot(): void
    {
        add_filter('map_meta_cap', [self::class, 'map'], PHP_INT_MAX, 4);
    }

    /**
     * The capabilities denied to an Auditor on this install.
     *
     * A filter can add to the list; the never-granted capabilities stay on it.
     *
     * @return list<string>
     */
    public static function denied(): array
    {
        if (self::$denied !== null) {
            return self::$denied;
        }

        $denied = apply_filters('uamswp_auditor_role_denied_caps', self::DENIED);
        $denied = is_array($denied) ? array_filter($denied, is_string(...)) : [];
        $denied = array_values(array_unique(array_merge(self::DENIED, Role::NEVER_GRANTED, $denied)));

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
            $target = is_array($args) && isset($args[0]) ? (int) $args[0] : 0;

            return $target === $user_id ? $caps : ['do_not_allow'];
        }

        if (in_array($cap, self::denied(), true) || Role::is_never_granted($cap)) {
            return ['do_not_allow'];
        }

        foreach ($caps as $mapped) {
            if (is_string($mapped) && Role::is_never_granted($mapped)) {
                return ['do_not_allow'];
            }
        }

        return $caps;
    }
}
