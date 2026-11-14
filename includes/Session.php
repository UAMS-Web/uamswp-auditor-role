<?php

/**
 * Whether the current request belongs to an Auditor session.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class Session
{
    /**
     * The `wp-login.php` actions that are not an Auditor session: signing in,
     * a password reset by email, a post password, and a User Switching switch
     * back, which that plugin verifies itself. Each runs before or instead of
     * the account's own session, so none can change the site as the auditor.
     *
     * A method, not a constant, so each entry is a line a test is seen to cover.
     *
     * @return list<string>
     */
    public static function exempt_login_actions(): array
    {
        return [
            'login',
            'lostpassword',
            'retrievepassword',
            'rp',
            'resetpass',
            'postpass',
            'switch_to_olduser',
        ];
    }

    /** The cookie result, held until WordPress sets the current user. */
    private static ?bool $from_cookie = null;

    /**
     * The answer before the cookie can be validated, held for the plugin load:
     * whether an auth cookie names an Auditor of this site.
     */
    private static ?bool $before_pluggable = null;

    /**
     * True while the cookie is read, whose own queries pass through the guard.
     * Null otherwise; a literal default would be a line no test can cover.
     */
    private static ?bool $resolving = null;

    /**
     * Whether the request is an Auditor session.
     *
     * Once WordPress has set the current user, that user decides. From
     * `plugins_loaded` (the request guard runs there) the auth cookies decide.
     *
     * Before then, while regular plugins load, a cookie cannot be validated:
     * the functions that do it are pluggable and load last. A plugin that
     * writes as it loads would pass, so the answer fails closed: an auth cookie
     * that names an Auditor of this site is taken as an Auditor session, its
     * HMAC unchecked. A forged cookie can only refuse its own request's writes.
     * That answer is held apart from the validated one, which it never replaces.
     */
    public static function is_auditor(): bool
    {
        if (self::$resolving === true || self::is_exempt_login()) {
            return false;
        }

        if (did_action('set_current_user') > 0) {
            return Role::user_is_auditor(wp_get_current_user());
        }

        if (did_action('plugins_loaded') === 0) {
            self::$before_pluggable ??= self::cookie_names_auditor();

            return self::$before_pluggable;
        }

        self::$from_cookie ??= self::resolve_from_cookie();

        return self::$from_cookie;
    }

    /**
     * The logins the request's auth cookies name, unvalidated: each cookie
     * WordPress's auth, secure-auth or logged-in constant names, or that has
     * the default name, whose value has the four parts of an auth cookie.
     *
     * @param  array<array-key, mixed>  $cookies
     * @param  list<string>  $names  The cookie names the constants define.
     * @return list<string>
     */
    public static function cookie_logins(array $cookies, array $names): array
    {
        $logins = [];

        foreach ($cookies as $key => $value) {
            $named = in_array((string) $key, $names, true)
                || preg_match('/^wordpress_(?:sec_|logged_in_)?[0-9a-f]{32}$/', (string) $key) === 1;

            if (! $named || ! is_string($value)) {
                continue;
            }

            $parts = explode('|', $value);

            if (count($parts) === 4 && $parts[0] !== '') {
                $logins[$parts[0]] = $parts[0];
            }
        }

        return array_values($logins);
    }

    /**
     * The auth cookie names among a set of defined constants. WordPress
     * defines them after the must-use plugins and before the regular ones.
     *
     * @param  array<string, mixed>  $constants  As get_defined_constants() returns them.
     * @return list<string>
     */
    public static function cookie_names(array $constants): array
    {
        $names = [];

        foreach (['AUTH_COOKIE', 'SECURE_AUTH_COOKIE', 'LOGGED_IN_COOKIE'] as $constant) {
            if (isset($constants[$constant]) && is_string($constants[$constant])) {
                $names[] = $constants[$constant];
            }
        }

        return $names;
    }

    /**
     * Whether a login holds the Auditor role on the current site, read from
     * the database directly, because the user API needs the pluggable layer.
     */
    public static function login_is_auditor(string $login): bool
    {
        $wpdb = Database::wpdb();

        // One read, joined by login, so no user ID passes through PHP.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $caps = $wpdb->get_var($wpdb->prepare(
            'SELECT m.meta_value FROM %i AS u INNER JOIN %i AS m ON m.user_id = u.ID WHERE u.user_login = %s AND m.meta_key = %s',
            $wpdb->users,
            $wpdb->usermeta,
            $login,
            $wpdb->get_blog_prefix().'capabilities'
        ));

        if (! is_string($caps)) {
            return false;
        }

        // Capabilities are an array of names; an object in them is not instantiated.
        $caps = unserialize($caps, ['allowed_classes' => false]);

        return is_array($caps) && ! empty($caps[Role::NAME]);
    }

    /**
     * Whether any auth cookie names an Auditor of this site.
     */
    private static function cookie_names_auditor(): bool
    {
        // The lookup runs queries, and the guard asks this class about each.
        self::$resolving = true;

        try {
            foreach (self::cookie_logins($_COOKIE, self::cookie_names(get_defined_constants())) as $login) {
                if (self::login_is_auditor($login)) {
                    return true;
                }
            }

            return false;
        } finally {
            self::$resolving = false;
        }
    }

    /**
     * The user ID behind the current request, or zero.
     */
    public static function user_id(): int
    {
        if (did_action('set_current_user') > 0) {
            return get_current_user_id();
        }

        return self::cookie_user_id();
    }

    /**
     * Forget the cookie result. Used by the tests.
     */
    public static function reset(): void
    {
        self::$from_cookie = null;
        self::$before_pluggable = null;
        self::$resolving = null;
    }

    /**
     * The script the request is for, as WordPress names it.
     */
    public static function script(): string
    {
        global $pagenow;

        if (is_string($pagenow) && $pagenow !== '') {
            return $pagenow;
        }

        $script = isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME'])) : '';

        return basename($script);
    }

    /**
     * Whether the request is a `wp-login.php` action that is never an Auditor session.
     */
    public static function is_exempt_login(): bool
    {
        if (self::script() !== 'wp-login.php') {
            return false;
        }

        // A read of the routing parameter only; wp-login.php verifies each action itself.
        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $defaults = self::exempt_login_actions();
        $exempt = apply_filters('uamswp_auditor_role_exempt_login_actions', $defaults);

        return in_array($action === '' ? 'login' : $action, is_array($exempt) ? $exempt : $defaults, true);
    }

    private static function resolve_from_cookie(): bool
    {
        $id = self::cookie_user_id();

        if ($id === 0) {
            return false;
        }

        self::$resolving = true;

        try {
            return Role::user_is_auditor(get_userdata($id));
        } finally {
            self::$resolving = false;
        }
    }

    /**
     * The user a valid auth or logged-in cookie names, or zero.
     *
     * Both are read because the admin trusts the auth cookie and the front end
     * the logged-in one, and a request carrying either is the account's.
     */
    private static function cookie_user_id(): int
    {
        // The cookie functions are pluggable, loaded just before `plugins_loaded`.
        if (did_action('plugins_loaded') === 0) {
            return 0;
        }

        // The cookie's own user lookup runs queries, and the guard asks this
        // class about each one; while the flag is set the answer is no.
        self::$resolving = true;

        try {
            $id = wp_validate_auth_cookie();

            if ($id === false) {
                $id = wp_validate_auth_cookie('', 'logged_in');
            }
        } finally {
            self::$resolving = false;
        }

        return is_int($id) ? $id : 0;
    }
}
