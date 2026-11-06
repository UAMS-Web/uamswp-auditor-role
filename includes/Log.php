<?php

/**
 * The activity log: one custom table per site recording what each Auditor
 * session opened and what the guard refused.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

use WP_User;

defined('ABSPATH') || exit;

final class Log
{
    /** The table name, after the site's prefix. */
    public const TABLE = 'uamswp_auditor_log';

    /** The schema version. Raise it with a change to install(). */
    public const SCHEMA = '1';

    /** The option that records the installed schema on a site. */
    public const OPTION = 'uamswp_auditor_role_log_schema';

    /** The events a row can record. */
    public const EVENTS = ['view', 'refused', 'db_refused', 'login', 'logout', 'login_refused'];

    /**
     * Query parameters whose values are dropped from a logged URL, because they
     * carry a nonce, a password, a key or a token.
     */
    private const SECRET_PARAM = '/nonce|pass|pwd|token|key|secret|auth|hash|code|signature|session/i';

    /** Whether the request's own row was written. */
    private static bool $recorded = false;

    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_install'], 1);
        add_action('shutdown', [self::class, 'record_request'], 0);
        add_action('wp_login', [self::class, 'record_login'], 10, 2);
        add_action('wp_logout', [self::class, 'record_logout']);
    }

    /**
     * The table name on the current site.
     */
    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.self::TABLE;
    }

    /**
     * Create or upgrade the table on the current site when its schema is stale.
     */
    public static function maybe_install(): void
    {
        if (get_option(self::OPTION) !== self::SCHEMA) {
            self::install();
        }
    }

    /**
     * Create or upgrade the table on the current site.
     */
    public static function install(): void
    {
        global $wpdb;

        DatabaseGuard::bypass(static function () use ($wpdb): void {
            require_once ABSPATH.'wp-admin/includes/upgrade.php';

            $table = self::table();
            $collate = $wpdb->get_charset_collate();

            dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  logged_at datetime NOT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_login varchar(60) NOT NULL DEFAULT '',
  blog_id bigint(20) unsigned NOT NULL DEFAULT 0,
  event varchar(20) NOT NULL DEFAULT '',
  method varchar(10) NOT NULL DEFAULT '',
  screen varchar(191) NOT NULL DEFAULT '',
  url text NOT NULL,
  detail text NOT NULL,
  PRIMARY KEY  (id),
  KEY logged_at (logged_at),
  KEY user_logged (user_id,logged_at),
  KEY event (event)
) {$collate};");

            update_option(self::OPTION, self::SCHEMA, true);
        });
    }

    /**
     * Write one row. The plugin's own write, so the database guard lets it pass.
     *
     * Never given a request body, a password or a token: the URL is redacted and
     * the detail is a refusal reason or a statement's verb and table.
     */
    public static function write(string $event, int $user_id, string $login, string $detail = ''): void
    {
        global $wpdb;

        if (get_option(self::OPTION) !== self::SCHEMA) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        $uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        DatabaseGuard::bypass(static function () use ($wpdb, $event, $user_id, $login, $detail, $method, $uri): void {
            $wpdb->insert(self::table(), [
                'logged_at' => gmdate('Y-m-d H:i:s'),
                'user_id' => $user_id,
                'user_login' => substr($login, 0, 60),
                'blog_id' => get_current_blog_id(),
                'event' => $event,
                'method' => substr($method, 0, 10),
                'screen' => substr(self::screen(), 0, 191),
                'url' => substr(self::redact_url($uri), 0, 2048),
                'detail' => substr($detail, 0, 1000),
            ], ['%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s']);
        });
    }

    /**
     * At the end of an Auditor request, record the page load or the refusal,
     * and any refused statements.
     *
     * Allowed AJAX, REST and cron requests are not page loads and are left out,
     * so Heartbeat does not fill the log.
     */
    public static function record_request(): void
    {
        if (self::$recorded || ! Session::is_auditor()) {
            return;
        }

        self::$recorded = true;

        $user_id = Session::user_id();
        $user = get_userdata($user_id);
        $login = $user instanceof WP_User ? $user->user_login : '';
        $refusal = RequestGuard::refusal();

        if ($refusal !== null) {
            self::write('refused', $user_id, $login, $refusal);
        } elseif (! wp_doing_ajax() && ! wp_doing_cron() && (! defined('REST_REQUEST') || ! REST_REQUEST)) {
            self::write('view', $user_id, $login);
        }

        if (DatabaseGuard::refused_count() > 0) {
            self::write('db_refused', $user_id, $login, sprintf(
                '%d refused: %s',
                DatabaseGuard::refused_count(),
                implode('; ', DatabaseGuard::refused())
            ));
        }
    }

    /**
     * Record an Auditor signing in.
     */
    public static function record_login(mixed $login, mixed $user): void
    {
        if (Role::user_is_auditor($user)) {
            /** @var WP_User $user */
            self::write('login', (int) $user->ID, $user->user_login);
        }
    }

    /**
     * Record an Auditor signing out.
     */
    public static function record_logout(mixed $user_id): void
    {
        $user = get_userdata(is_int($user_id) ? $user_id : 0);

        if (Role::user_is_auditor($user)) {
            /** @var WP_User $user */
            self::write('logout', (int) $user->ID, $user->user_login);
        }
    }

    /**
     * A URL with the values of secret-bearing query parameters removed.
     */
    public static function redact_url(string $url): string
    {
        $parts = explode('?', $url, 2);

        if (! isset($parts[1]) || $parts[1] === '') {
            return $parts[0];
        }

        $pairs = [];

        foreach (explode('&', $parts[1]) as $pair) {
            $key = urldecode(explode('=', $pair, 2)[0]);
            $pairs[] = preg_match(self::SECRET_PARAM, $key) === 1 ? rawurlencode($key).'=REDACTED' : $pair;
        }

        return $parts[0].'?'.implode('&', $pairs);
    }

    /**
     * The screen the request is for: its script, and its page for a plugin screen.
     */
    public static function screen(): string
    {
        // A read for the log label only; the value is sanitized and never acted on.
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return Screens::label(Session::script(), $page === '' ? [] : ['page' => $page]);
    }

    /**
     * Forget that the request was recorded. Used by the tests.
     */
    public static function reset(): void
    {
        self::$recorded = false;
    }
}
