<?php

/**
 * UAMSWeb\AuditorRole\Log, with WordPress and the database mocked.
 *
 * Mutation-clean under `composer mutate -- --path=includes/Log.php`, with no
 * equivalent mutants: the table install is reached through a mocked dbDelta(),
 * and the database through the in-memory stand-in in LogSupport.php.
 *
 * @see includes/Log.php
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\Log;
use UAMSWeb\AuditorRole\RequestGuard;

require_once __DIR__.'/LogSupport.php';

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    Log::reset();
    uamswp_auditor_role_g3_wpdb();
    Functions\when('get_current_blog_id')->justReturn(3);
    Functions\when('wp_unslash')->returnArg();
    Functions\when('sanitize_key')->alias('strtolower');
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('esc_url_raw')->returnArg();
    Functions\when('wp_is_serving_rest_request')->justReturn(false);
    unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
});

afterEach(function (): void {
    unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
});

it('installs on init, records on shutdown, and hears sign-in and sign-out', function (): void {
    Actions\expectAdded('init')->once()->with([Log::class, 'maybe_install'], 1, 1);
    Actions\expectAdded('shutdown')->once()->with([Log::class, 'record_request'], 0, 1);
    Actions\expectAdded('wp_login')->once()->with([Log::class, 'record_login'], 10, 2);
    Actions\expectAdded('wp_logout')->once()->with([Log::class, 'record_logout'], 10, 1);

    Log::boot();
});

it('records exactly the six events', function (): void {
    expect(Log::events())->toBe(['view', 'refused', 'db_refused', 'login', 'logout', 'login_refused']);
});

it('reads dbDelta() from core under ABSPATH', function (): void {
    expect(Log::upgrade_file())->toBe(ABSPATH.'wp-admin/includes/upgrade.php');
});

it('names the table after the site prefix', function (): void {
    uamswp_auditor_role_g3_db()->prefix = 'wp_4_';

    expect(Log::table())->toBe('wp_4_uamswp_auditor_log');
});

it('installs the table only when the schema is stale', function (string|false $stored, int $installs): void {
    Functions\when('get_option')->justReturn($stored);
    Functions\expect('dbDelta')->times($installs);
    Functions\expect('update_option')->times($installs);

    Log::maybe_install();
})->with([
    'never installed' => [false, 1],
    'older schema' => ['0', 1],
    'current' => [Log::SCHEMA, 0],
]);

it('creates the table with every column and records the schema, past the guard', function (): void {
    uamswp_auditor_role_as(true);

    Functions\expect('dbDelta')->once()->with(Mockery::on(static function (string $sql): bool {
        // The plugin's own write: inside install(), the guard lets it pass.
        expect(DatabaseGuard::filter('CREATE TABLE x (id int)'))->toBe('CREATE TABLE x (id int)');

        foreach (['CREATE TABLE wp_uamswp_auditor_log (', 'logged_at datetime', 'user_id bigint(20)', 'user_login varchar(60)', 'blog_id bigint(20)', 'event varchar(20)', 'method varchar(10)', 'screen varchar(191)', 'url text', 'detail text', 'PRIMARY KEY  (id)', 'KEY user_logged (user_id,logged_at)', ') DEFAULT CHARSET=utf8mb4;'] as $part) {
            expect($sql)->toContain($part);
        }

        return true;
    }));
    Functions\expect('update_option')->once()->with(Log::OPTION, Log::SCHEMA, true);

    Log::install();

    expect(DatabaseGuard::filter('CREATE TABLE x (id int)'))->toBe('');
});

it('writes one row with every field, cut to its column, past the guard', function (): void {
    uamswp_auditor_role_as(true, 'admin.php');
    Functions\when('get_option')->justReturn(Log::SCHEMA);
    $_SERVER['REQUEST_METHOD'] = 'propfindxyz';
    $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?_wpnonce=abc&'.str_repeat('a', 2100);
    $_GET['page'] = str_repeat('p', 200);

    Log::write('refused', 7, str_repeat('L', 61), str_repeat('d', 1001));

    $row = uamswp_auditor_role_g3_db()->inserted[0];

    $loggedAt = uamswp_auditor_role_string($row['logged_at']);
    $url = uamswp_auditor_role_string($row['url']);

    expect(array_keys($row))->toBe(['__table', 'logged_at', 'user_id', 'user_login', 'blog_id', 'event', 'method', 'screen', 'url', 'detail'])
        ->and($row['__table'])->toBe('wp_uamswp_auditor_log')
        ->and($loggedAt)->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/')
        ->and(abs(strtotime($loggedAt.' UTC') - time()))->toBeLessThan(3)
        ->and($row['user_id'])->toBe(7)
        ->and($row['user_login'])->toBe(str_repeat('L', 60))
        ->and($row['blog_id'])->toBe(3)
        ->and($row['event'])->toBe('refused')
        ->and($row['method'])->toBe('PROPFINDXY')
        ->and($row['screen'])->toBe(substr('admin.php?page='.str_repeat('p', 200), 0, 191))
        ->and(strlen($url))->toBe(2048)
        ->and($row['url'])->toStartWith('/wp-admin/admin.php?_wpnonce=REDACTED&aaa')
        ->and($row['detail'])->toBe(str_repeat('d', 1000))
        ->and(uamswp_auditor_role_g3_db()->formats[0])->toBe(['%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s']);

    // The bypass ended with the write.
    expect(DatabaseGuard::filter('INSERT INTO x VALUES (1)'))->toBe('');
});

it('keeps short values whole and leaves method and URL empty outside a request', function (): void {
    uamswp_auditor_role_as(true, 'index.php');
    Functions\when('get_option')->justReturn(Log::SCHEMA);

    Log::write('login', 7, 'audit');

    $row = uamswp_auditor_role_g3_db()->inserted[0];

    expect($row['user_login'])->toBe('audit')
        ->and($row['method'])->toBe('')
        ->and($row['url'])->toBe('')
        ->and($row['screen'])->toBe('index.php')
        ->and($row['detail'])->toBe('');
});

it('writes nothing before the table is installed', function (): void {
    Functions\when('get_option')->justReturn(false);

    Log::write('view', 7, 'audit');

    expect(uamswp_auditor_role_g3_db()->inserted)->toBe([]);
});

it('decides each request row', function (?string $refusal, int $count, array $statements, bool $page_load, ?array $row): void {
    expect(Log::request_row($refusal, $count, array_values(array_filter($statements, is_string(...))), $page_load))->toBe($row);
})->with([
    'page load' => [null, 0, [], true, ['view', '']],
    'allowed AJAX or REST' => [null, 0, [], false, null],
    'refused request' => ['method:POST', 0, [], true, ['refused', 'method:POST']],
    'refused AJAX' => ['ajax:x', 0, [], false, ['refused', 'ajax:x']],
    'page load with refused statements' => [null, 2, ['UPDATE wp_options (a)', 'INSERT wp_options (b)'], true, ['db_refused', '2 refused: UPDATE wp_options (a); INSERT wp_options (b)']],
    'AJAX with a refused statement' => [null, 1, ['UPDATE wp_options (a)'], false, ['db_refused', '1 refused: UPDATE wp_options (a)']],
    'refused request with refused statements' => ['method:POST', 12, ['UPDATE wp_options (a)'], true, ['refused', 'method:POST; 12 refused: UPDATE wp_options (a)']],
]);

it('calls a request a page load unless it is AJAX, cron or REST', function (bool $ajax, bool $cron, bool $rest, bool $page_load): void {
    Functions\when('wp_doing_ajax')->justReturn($ajax);
    Functions\when('wp_doing_cron')->justReturn($cron);
    Functions\when('wp_is_serving_rest_request')->justReturn($rest);

    expect(Log::is_page_load())->toBe($page_load);
})->with([
    'page' => [false, false, false, true],
    'AJAX' => [true, false, false, false],
    'cron' => [false, true, false, false],
    'REST' => [false, false, true, false],
]);

it('records one row for an Auditor request, once', function (): void {
    uamswp_auditor_role_as(true, 'edit.php');
    Functions\when('get_option')->justReturn(Log::SCHEMA);
    Functions\when('wp_doing_ajax')->justReturn(false);
    Functions\when('wp_doing_cron')->justReturn(false);
    DatabaseGuard::filter("UPDATE `wp_options` SET `option_value` = 'x' WHERE `option_name` = 'a'");

    Log::record_request();
    Log::record_request();

    expect(uamswp_auditor_role_g3_db()->inserted)->toHaveCount(1)
        ->and(uamswp_auditor_role_g3_db()->inserted[0]['event'])->toBe('db_refused')
        ->and(uamswp_auditor_role_g3_db()->inserted[0]['detail'])->toBe('1 refused: UPDATE wp_options (a)')
        ->and(uamswp_auditor_role_g3_db()->inserted[0]['user_id'])->toBe(7)
        ->and(uamswp_auditor_role_g3_db()->inserted[0]['user_login'])->toBe('audit');
});

it('records the refusal the request guard made', function (): void {
    uamswp_auditor_role_as(true, 'index.php');
    Functions\when('get_option')->justReturn(Log::SCHEMA);
    Functions\when('wp_doing_ajax')->justReturn(false);
    Functions\when('wp_doing_cron')->justReturn(false);
    Functions\when('__')->returnArg();
    RequestGuard::rest_pre_dispatch(null, null, new class
    {
        public function get_method(): string
        {
            return 'POST';
        }

        public function get_route(): string
        {
            return '/wp/v2/posts';
        }
    });

    Log::record_request();

    expect(uamswp_auditor_role_g3_db()->inserted[0]['event'])->toBe('refused')
        ->and(uamswp_auditor_role_g3_db()->inserted[0]['detail'])->toBe('rest:POST /wp/v2/posts');
});

it('records a page load with no login when the user cannot be read', function (): void {
    uamswp_auditor_role_as(true, 'index.php');
    Functions\when('get_userdata')->justReturn(false);
    Functions\when('get_option')->justReturn(Log::SCHEMA);
    Functions\when('wp_doing_ajax')->justReturn(false);
    Functions\when('wp_doing_cron')->justReturn(false);

    // Session::is_auditor() reads the current user, which stays an Auditor.
    Log::record_request();

    expect(uamswp_auditor_role_g3_db()->inserted[0]['event'])->toBe('view')
        ->and(uamswp_auditor_role_g3_db()->inserted[0]['user_login'])->toBe('');
});

it('records nothing for an allowed AJAX request', function (): void {
    uamswp_auditor_role_as(true, 'admin-ajax.php');
    Functions\when('get_option')->justReturn(Log::SCHEMA);
    Functions\when('wp_doing_ajax')->justReturn(true);
    Functions\when('wp_doing_cron')->justReturn(false);

    Log::record_request();

    expect(uamswp_auditor_role_g3_db()->inserted)->toBe([]);
});

it('records nothing for another role', function (): void {
    uamswp_auditor_role_as(false, 'edit.php');
    Functions\when('get_option')->justReturn(Log::SCHEMA);
    Functions\when('wp_doing_ajax')->justReturn(false);
    Functions\when('wp_doing_cron')->justReturn(false);

    Log::record_request();

    expect(uamswp_auditor_role_g3_db()->inserted)->toBe([]);
});

it('records an Auditor signing in and out, and no one else', function (): void {
    $GLOBALS['pagenow'] = 'wp-login.php';
    Functions\when('get_option')->justReturn(Log::SCHEMA);
    $auditor = uamswp_auditor_role_user_with(7, 'audit', ['auditor']);
    $admin = uamswp_auditor_role_user_with(8, 'admin', ['administrator']);

    Log::record_login('audit', $auditor);
    Log::record_login('admin', $admin);
    Log::record_login('audit', null);

    $other = uamswp_auditor_role_user_with(9, 'other', ['auditor']);

    // A user ID that is not an integer is ignored, never looked up.
    Functions\when('get_userdata')->alias(static fn (mixed $id): WP_User|false => match ($id) {
        7 => $auditor,
        '9' => $other,
        8 => $admin,
        default => false,
    });

    Log::record_logout(7);
    Log::record_logout(8);
    Log::record_logout('9');

    expect(array_map(static fn (array $row): array => [$row['event'], $row['user_id'], $row['user_login']], uamswp_auditor_role_g3_db()->inserted))
        ->toBe([['login', 7, 'audit'], ['logout', 7, 'audit']]);
});

it('drops secret-bearing values from a logged URL', function (string $url, string $logged): void {
    expect(Log::redact_url($url))->toBe($logged);
})->with([
    'no query' => ['/wp-admin/edit.php', '/wp-admin/edit.php'],
    'empty query' => ['/wp-admin/edit.php?', '/wp-admin/edit.php'],
    'plain query' => ['/wp-admin/edit.php?post_type=page&paged=2', '/wp-admin/edit.php?post_type=page&paged=2'],
    'nonce' => ['/wp-login.php?action=logout&_wpnonce=abc123', '/wp-login.php?action=logout&_wpnonce=REDACTED'],
    'reset key' => ['/wp-login.php?action=rp&key=XYZ&login=a', '/wp-login.php?action=rp&key=REDACTED&login=a'],
    'token and password' => ['/x?token=1&pwd=2&api_key=3', '/x?token=REDACTED&pwd=REDACTED&api_key=REDACTED'],
    'encoded name' => ['/x?_wp%6Eonce=1', '/x?_wpnonce=REDACTED'],
    'name without a value' => ['/x?token&a=1', '/x?token=REDACTED&a=1'],
    'value holding an equals sign' => ['/x?a=b=token', '/x?a=b=token'],
    'second question mark' => ['/x?a=1?token=2', '/x?a=1?token=2'],
    'one-letter secret-free name' => ['/x?t=1', '/x?t=1'],
]);

it('labels the screen with its script, and its page when there is one', function (): void {
    $GLOBALS['pagenow'] = 'tools.php';

    expect(Log::screen())->toBe('tools.php');

    $_GET['page'] = 'uamswp-auditor-log';

    expect(Log::screen())->toBe('tools.php?page=uamswp-auditor-log');
});
