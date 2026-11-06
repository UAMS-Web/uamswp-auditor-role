<?php

/**
 * Test support for the activity-log classes: a stand-in for `$wpdb` that keeps
 * rows in memory and records every statement, and a WP-CLI double.
 *
 * Loaded with require_once by the log tests; the file name does not end in
 * Test.php, so Pest does not run it as a test.
 */

declare(strict_types=1);

// wpdb's output type for an associative row, as WordPress defines it.
if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

/**
 * The database the log classes reach through `global $wpdb`.
 */
final class Uamswp_Auditor_Role_G3_Wpdb
{
    public string $prefix = 'wp_';

    /** @var list<array<string, mixed>> Inserted rows, in order. */
    public array $inserted = [];

    /** @var list<list<string>> The formats passed with each insert. */
    public array $formats = [];

    /** @var list<string> Every statement prepared, in order. */
    public array $prepared = [];

    /** @var list<string> Every statement run through query(). */
    public array $queries = [];

    /** @var list<mixed> What each get_results() call returns, in order. */
    public array $results = [];

    /** @var list<mixed> What each get_var() call returns, in order. */
    public array $vars = [];

    /** @var list<int|false> What each query() call returns, in order. */
    public array $query_returns = [];

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $format
     */
    public function insert(string $table, array $data, array $format): int
    {
        $this->inserted[] = ['__table' => $table] + $data;
        $this->formats[] = $format;

        return 1;
    }

    /**
     * Substitute the placeholders the plugin uses, so a test can read the statement.
     */
    public function prepare(string $sql, mixed ...$values): string
    {
        $index = 0;
        $prepared = (string) preg_replace_callback('/%[ids]/', static function (array $m) use (&$index, $values): string {
            $value = $values[$index++] ?? '';

            return match ($m[0]) {
                '%i' => '`'.$value.'`',
                '%d' => (string) (int) $value,
                default => "'".$value."'",
            };
        }, $sql);

        $this->prepared[] = $prepared;

        return $prepared;
    }

    /**
     * The next queued result. A read nothing was queued for is a test failure,
     * not an empty result: a mutant that keeps reading (a loop whose stop
     * condition was inverted) then fails at once, instead of running until it
     * times out or exhausts memory, which scores it differently by machine.
     */
    public function get_results(): mixed
    {
        if ($this->results === []) {
            throw new LogicException('Unexpected read: get_results()');
        }

        return array_shift($this->results);
    }

    public function get_var(): mixed
    {
        if ($this->vars === []) {
            throw new LogicException('Unexpected read: get_var()');
        }

        return array_shift($this->vars);
    }

    /**
     * Runs a statement. With nothing queued it deletes nothing, but past 200
     * statements it fails, for the same reason as get_results().
     */
    public function query(string $sql): int|false
    {
        $this->queries[] = $sql;

        if (count($this->queries) > 200) {
            throw new LogicException('Unexpected statement: more than 200 run.');
        }

        return array_shift($this->query_returns) ?? 0;
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }
}

/**
 * Install a fresh stand-in as `$wpdb` and return it.
 */
function uamswp_auditor_role_g3_wpdb(): Uamswp_Auditor_Role_G3_Wpdb
{
    $wpdb = new Uamswp_Auditor_Role_G3_Wpdb;
    $GLOBALS['wpdb'] = $wpdb;

    return $wpdb;
}

/**
 * The stand-in installed as `$wpdb`.
 */
function uamswp_auditor_role_g3_db(): Uamswp_Auditor_Role_G3_Wpdb
{
    $wpdb = $GLOBALS['wpdb'] ?? null;

    if (! $wpdb instanceof Uamswp_Auditor_Role_G3_Wpdb) {
        throw new LogicException('No database stand-in is installed.');
    }

    return $wpdb;
}

/*
 * A WP-CLI double: error() throws, the way the real one exits, and every call
 * is recorded. Defined through eval so the analyzer, which reads WP-CLI's own
 * stubs, never sees a second WP_CLI class.
 */
if (! class_exists('WP_CLI', false)) {
    eval(<<<'PHP'
        final class WP_CLI
        {
            public static function error(string $message): never
            {
                $GLOBALS['uamswp_auditor_role_g3_cli'][] = ['error', $message];

                throw new RuntimeException('WP_CLI::error '.$message);
            }

            public static function success(string $message): void
            {
                $GLOBALS['uamswp_auditor_role_g3_cli'][] = ['success', $message];
            }
        }
        PHP);

    eval(<<<'PHP'
        namespace WP_CLI\Utils;

        function format_items(string $format, array $items, array $fields): void
        {
            $GLOBALS['uamswp_auditor_role_g3_formatted'][] = [$format, $items, $fields];
        }
        PHP);
}

/**
 * Forget what the WP-CLI double recorded.
 */
function uamswp_auditor_role_g3_cli_reset(): void
{
    $GLOBALS['uamswp_auditor_role_g3_cli'] = [];
    $GLOBALS['uamswp_auditor_role_g3_formatted'] = [];
}

/**
 * A log row as the table returns it.
 *
 * @return array<string, string>
 */
function uamswp_auditor_role_g3_row(int $id, string $logged_at = '2026-11-05 10:00:00', int $blog_id = 1, string $url = '/wp-admin/'): array
{
    return [
        'id' => (string) $id,
        'logged_at' => $logged_at,
        'user_id' => '7',
        'user_login' => 'audit',
        'blog_id' => (string) $blog_id,
        'event' => 'view',
        'method' => 'GET',
        'screen' => 'index.php',
        'url' => $url,
        'detail' => '',
    ];
}
