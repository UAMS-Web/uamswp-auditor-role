<?php

/**
 * Keeps Advanced Custom Fields' admin out of an Auditor session: the field
 * groups, post types, taxonomies, options pages and tools that define the
 * site's data model. Those screens are also hidden screens (see Screens).
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class Acf
{
    /** ACF's filter for whether any of its admin menus are added. */
    public const SHOW_ADMIN = 'acf/settings/show_admin';

    /** ACF's filter for the capability every ACF admin screen checks. */
    public const CAPABILITY = 'acf/settings/capability';

    public static function boot(): void
    {
        add_filter(self::SHOW_ADMIN, [self::class, 'show_admin'], PHP_INT_MAX);
        add_filter(self::CAPABILITY, [self::class, 'capability'], PHP_INT_MAX);
    }

    /**
     * Turn ACF's admin menus off for an Auditor, and leave any other answer alone.
     */
    public static function show_admin(mixed $show): mixed
    {
        return Session::is_auditor() ? false : $show;
    }

    /**
     * Answer `do_not_allow` for an Auditor, and leave any other answer alone.
     *
     * ACF checks this capability, `manage_options` by default, for its menu,
     * for the edit screens of its own post types (field groups, post types,
     * taxonomies, options pages) and for its Tools screen, and ACF Extended
     * checks it for its settings and categories. `show_admin` alone removes
     * only the menu: ACF registers those post types with `show_ui`, so their
     * screens would still open by URL for a role that holds `manage_options`.
     * Fields on content screens and options pages registered with
     * `acf_add_options_page()` check their own capabilities and are unchanged.
     */
    public static function capability(mixed $capability): mixed
    {
        return Session::is_auditor() ? 'do_not_allow' : $capability;
    }
}
