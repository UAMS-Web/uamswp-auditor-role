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
     * True while the cookie is read, whose own queries pass through the guard.
     * Null otherwise; a literal default would be a line no test can cover.
     */
    private static ?bool $resolving = null;

    /**
     * Whether the request is an Auditor session.
     *
     * Once WordPress has set the current user, that user decides. Before then
     * (the request guard runs on `plugins_loaded`) the auth cookies decide.
     */
    public static function is_auditor(): bool
    {
        if (self::$resolving === true || self::is_exempt_login()) {
            return false;
        }

        if (did_action('set_current_user') > 0) {
            return Role::user_is_auditor(wp_get_current_user());
        }

        self::$from_cookie ??= self::resolve_from_cookie();

        return self::$from_cookie;
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

        $script = isset($_SERVER['SCRIPT_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SCRIPT_NAME'])) : '';

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
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

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
