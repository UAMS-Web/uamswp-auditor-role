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

    /**
     * Query parameters whose values are dropped from a logged URL, because they
     * carry a nonce, a password, a key or a token.
     */
    private const SECRET_PARAM = '/nonce|pass|pwd|token|key|secret|auth|hash|code|signature|session/i';

    /** True once the request's own row was written; null until then. */
    private static ?bool $recorded = null;

    public static function boot(): void
    {
        add_action('init', [self::class, 'maybe_install'], 1);
        add_action('shutdown', [self::class, 'record_request'], 0);
        add_action('wp_login', [self::class, 'record_login'], 10, 2);
        add_action('wp_logout', [self::class, 'record_logout']);
    }

    /**
     * The events a row can record. A method, not a constant, so each entry is
     * a line a test is seen to cover.
     *
     * @return list<string>
     */
    public static function events(): array
    {
        return ['view', 'refused', 'db_refused', 'login', 'logout', 'login_refused'];
    }

    /**
     * The core file that defines dbDelta().
     */
    public static function upgrade_file(): string
    {
        return ABSPATH.'wp-admin/includes/upgrade.php';
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
            if (! function_exists('dbDelta')) {
                require_once self::upgrade_file();
            }

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

        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';

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
     * At the end of an Auditor request, record it in one row.
     *
     * A refused request is a `refused` row. A request whose statements the
     * database guard refused is a `db_refused` row, unless the request itself
     * was refused, whose row then carries both. Any other page load is a `view`
     * row. Allowed AJAX, REST and cron requests are not page loads and get no
     * row unless a statement was refused, so Heartbeat does not fill the log.
     */
    public static function record_request(): void
    {
        if (self::$recorded === true || ! Session::is_auditor()) {
            return;
        }

        self::$recorded = true;

        $row = self::request_row(RequestGuard::refusal(), DatabaseGuard::refused_count(), DatabaseGuard::refused(), self::is_page_load());

        if ($row === null) {
            return;
        }

        $user_id = Session::user_id();
        $user = get_userdata($user_id);

        self::write($row[0], $user_id, $user instanceof WP_User ? $user->user_login : '', $row[1]);
    }

    /**
     * The event and detail of a request's row, or null for no row.
     *
     * @param  list<string>  $statements  The refused statements, described.
     * @return array{0: string, 1: string}|null
     */
    public static function request_row(?string $refusal, int $refused_count, array $statements, bool $page_load): ?array
    {
        $statements_detail = $refused_count > 0 ? $refused_count.' refused: '.implode('; ', $statements) : '';

        if ($refusal !== null) {
            return ['refused', $statements_detail === '' ? $refusal : $refusal.'; '.$statements_detail];
        }

        if ($statements_detail !== '') {
            return ['db_refused', $statements_detail];
        }

        return $page_load ? ['view', ''] : null;
    }

    /**
     * Whether the request is a page load: not AJAX, not cron, not REST.
     */
    public static function is_page_load(): bool
    {
        return ! wp_doing_ajax() && ! wp_doing_cron() && ! wp_is_serving_rest_request();
    }

    /**
     * Record an Auditor signing in.
     */
    public static function record_login(mixed $login, mixed $user): void
    {
        if (Role::user_is_auditor($user)) {
            /** @var WP_User $user */
            self::write('login', $user->ID, $user->user_login);
        }
    }

    /**
     * Record an Auditor signing out.
     */
    public static function record_logout(mixed $user_id): void
    {
        if (! is_int($user_id)) {
            return;
        }

        $user = get_userdata($user_id);

        if (Role::user_is_auditor($user)) {
            /** @var WP_User $user */
            self::write('logout', $user->ID, $user->user_login);
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
            $equals = strpos($pair, '=');
            $key = urldecode($equals === false ? $pair : substr($pair, 0, $equals));
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
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return Screens::label(Session::script(), $page === '' ? [] : ['page' => $page]);
    }

    /**
     * Forget that the request was recorded. Used by the tests.
     */
    public static function reset(): void
    {
        self::$recorded = null;
    }
}
