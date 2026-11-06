<?php

/**
 * Multisite: adds each configured auditor login to every site with the
 * Auditor role, because roles are per site and users are network-wide.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

use WP_Site;
use WP_User;

defined('ABSPATH') || exit;

final class Network
{
    /** The constant in wp-config.php holding the logins, as an array or a comma-separated string. */
    public const CONSTANT = 'UAMSWP_AUDITOR_ROLE_LOGINS';

    public static function boot(): void
    {
        if (! is_multisite()) {
            return;
        }

        add_action('wp_login', [self::class, 'on_login'], 20, 2);
        add_action('init', [self::class, 'on_visit'], 2);
        add_action('wp_initialize_site', [self::class, 'on_new_site'], 100);
    }

    /**
     * The configured logins.
     *
     * @return list<string>
     */
    public static function logins(): array
    {
        $value = defined(self::CONSTANT) ? constant(self::CONSTANT) : [];

        return self::parse_logins($value);
    }

    /**
     * Logins from an array or a comma-separated string, trimmed and de-duplicated.
     *
     * @return list<string>
     */
    public static function parse_logins(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        $logins = [];

        foreach ($value as $login) {
            if (is_string($login) && trim($login) !== '') {
                $logins[trim($login)] = true;
            }
        }

        return array_keys($logins);
    }

    /**
     * Whether a user is one of the configured logins.
     */
    public static function is_configured(mixed $user): bool
    {
        return $user instanceof WP_User && in_array($user->user_login, self::logins(), true);
    }

    /**
     * Add a user to a site with the Auditor role when it is not a member yet.
     *
     * The plugin's own write, so it runs with the database guard suspended.
     */
    public static function add_to_site(int $blog_id, int $user_id): bool
    {
        if (is_user_member_of_blog($user_id, $blog_id)) {
            return false;
        }

        return DatabaseGuard::bypass(static function () use ($blog_id, $user_id): bool {
            switch_to_blog($blog_id);

            try {
                Role::maybe_register();

                return add_user_to_blog($blog_id, $user_id, Role::NAME) === true;
            } finally {
                restore_current_blog();
            }
        });
    }

    /**
     * On sign-in, add a configured login to the site it signed in on.
     */
    public static function on_login(mixed $login, mixed $user): void
    {
        if (self::is_configured($user)) {
            /** @var WP_User $user */
            self::add_to_site(get_current_blog_id(), (int) $user->ID);
        }
    }

    /**
     * On a visit to a site where a configured login is not a member yet, add it,
     * so a site created before the login was configured is covered too.
     */
    public static function on_visit(): void
    {
        $user = wp_get_current_user();

        if (! $user->exists() || ! self::is_configured($user) || ! self::add_to_site(get_current_blog_id(), (int) $user->ID)) {
            return;
        }

        // The current user's roles were read before the add; reread them so the
        // guard covers the rest of this request, not only the next one.
        $user->for_site(get_current_blog_id());
        RequestGuard::check();
    }

    /**
     * On a new site, register the role, create the log table and add each login.
     */
    public static function on_new_site(mixed $site): void
    {
        if (! $site instanceof WP_Site) {
            return;
        }

        switch_to_blog((int) $site->blog_id);

        try {
            Role::maybe_register();
            Log::maybe_install();
        } finally {
            restore_current_blog();
        }

        foreach (self::logins() as $login) {
            $user = get_user_by('login', $login);

            if ($user instanceof WP_User) {
                self::add_to_site((int) $site->blog_id, $user->ID);
            }
        }
    }

    /**
     * Add every configured login to every site. The WP-CLI fallback for sites
     * that existed before the logins were configured.
     *
     * @return array<string, int> Login to the number of sites it was added to.
     */
    public static function sync_all(): array
    {
        $added = [];

        foreach (self::logins() as $login) {
            $added[$login] = 0;
            $user = get_user_by('login', $login);

            if (! $user instanceof WP_User) {
                continue;
            }

            foreach (Report::site_ids() as $blog_id) {
                if (self::add_to_site((int) $blog_id, $user->ID)) {
                    $added[$login]++;
                }
            }
        }

        return $added;
    }
}
