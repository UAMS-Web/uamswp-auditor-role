<?php

/**
 * The second layer under the request guard: a `query` filter that refuses every
 * statement that is not a read while an Auditor session runs.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class DatabaseGuard
{
    /**
     * True while a bypass() call is open: the plugin's own writes pass. Null,
     * not false, when none is, so the starting state is the absence of a value.
     */
    private static ?bool $bypass = null;

    /** @var list<string> Every refused statement in this request, described without its values. */
    private static array $refused = [];

    /**
     * Register the filter last, so it sees the statement every other filter built.
     */
    public static function boot(): void
    {
        add_filter('query', [self::class, 'filter'], PHP_INT_MAX);
        add_filter('session_token_manager', [self::class, 'session_token_manager'], PHP_INT_MAX);
    }

    /**
     * Pass a read, or a write outside an Auditor session; refuse anything else.
     *
     * An empty string makes wpdb return false without running anything, so the
     * write fails the way a lost connection would and the page goes on.
     */
    public static function filter(mixed $query): mixed
    {
        if (! is_string($query) || self::$bypass === true || self::is_read($query) || ! Session::is_auditor()) {
            return $query;
        }

        $allowed = apply_filters('uamswp_auditor_role_allow_query', false, $query);

        if ($allowed === true) {
            return $query;
        }

        self::$refused[] = self::describe($query);

        return '';
    }

    /**
     * Run a callback with the guard suspended: the plugin's own writes only.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function bypass(callable $callback): mixed
    {
        // Saved and restored, so a nested call leaves the outer one open.
        $outer = self::$bypass;
        self::$bypass = true;

        try {
            return $callback();
        } finally {
            self::$bypass = $outer;
        }
    }

    /**
     * Whether a statement only reads.
     *
     * Default-deny: a statement is a read only when its first keyword is on the
     * list. `SET` passes only for session variables and the connection charset,
     * and a `SELECT` that writes a file is refused.
     */
    public static function is_read(string $query): bool
    {
        $query = self::strip_leading($query);

        if (preg_match('/^[a-z]+\b/i', $query, $match) !== 1) {
            return false;
        }

        $verb = strtoupper($match[0]);

        if ($verb === 'SET') {
            return preg_match('/^SET\s+(SESSION\s|NAMES\s|@)/i', $query) === 1;
        }

        if (! in_array($verb, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
            return false;
        }

        return preg_match('/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $query) !== 1;
    }

    /**
     * The verb and table of a statement, never its values.
     */
    public static function describe(string $query): string
    {
        $query = self::strip_leading($query);

        if (preg_match('/^(INSERT|REPLACE)\s+(?:LOW_PRIORITY\s+|DELAYED\s+|HIGH_PRIORITY\s+|IGNORE\s+)*(?:INTO\s+)?`?([\w$.]+)`?/i', $query, $m) === 1
            || preg_match('/^(UPDATE)\s+(?:LOW_PRIORITY\s+|IGNORE\s+)*`?([\w$.]+)`?/i', $query, $m) === 1
            || preg_match('/^(DELETE)\s+(?:LOW_PRIORITY\s+|QUICK\s+|IGNORE\s+)*FROM\s+`?([\w$.]+)`?/i', $query, $m) === 1
            || preg_match('/^(TRUNCATE|DROP|ALTER|CREATE)\s+(?:TEMPORARY\s+)?(?:TABLE\s+)?(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([\w$.]+)`?/i', $query, $m) === 1) {
            return strtoupper($m[1]).' '.$m[2].self::describe_option($query, $m[2]);
        }

        return preg_match('/^[a-z]+/i', $query, $m) === 1 ? strtoupper($m[0]) : 'UNKNOWN';
    }

    /**
     * The option or network option a statement names, so a refusal can be
     * decided case by case. A name is never a secret; the value is not read.
     */
    private static function describe_option(string $query, string $table): string
    {
        if (preg_match('/(options|sitemeta)$/', $table) !== 1) {
            return '';
        }

        if (preg_match("/`?(?:option_name|meta_key)`?\\s*=\\s*'([^']{1,191})'/", $query, $m) === 1
            || preg_match("/\\(\\s*`?option_name`?[^)]*\\)\\s*VALUES\\s*\\(\\s*'([^']{1,191})'/i", $query, $m) === 1) {
            return ' ('.$m[1].')';
        }

        return '';
    }

    /**
     * The first ten statements refused so far in this request, described: what
     * a log row keeps.
     *
     * @return list<string>
     */
    public static function refused(): array
    {
        return array_slice(self::$refused, 0, 10);
    }

    /**
     * How many statements were refused so far in this request, kept or not.
     */
    public static function refused_count(): int
    {
        return count(self::$refused);
    }

    /**
     * Forget the refused statements. Used by the tests.
     */
    public static function reset(): void
    {
        self::$refused = [];
        self::$bypass = null;
    }

    /**
     * Swap in the session token manager whose writes pass the guard, so an
     * auditor can sign out and a session can be revoked.
     */
    public static function session_token_manager(mixed $manager): mixed
    {
        return $manager === 'WP_User_Meta_Session_Tokens' ? SessionTokens::class : $manager;
    }

    /**
     * Drop leading whitespace, comments and opening parentheses.
     */
    private static function strip_leading(string $query): string
    {
        if (preg_match('/^(?:\s+|\(|\/\*.*?\*\/|(?:--\s|#)[^\n]*(?:\n|$))+/s', $query, $m) === 1) {
            return substr($query, strlen($m[0]));
        }

        return $query;
    }
}
