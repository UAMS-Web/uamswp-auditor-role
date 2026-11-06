<?php

/**
 * UAMSWeb\AuditorRole\Cli\LogCommand, with WordPress, the database and WP-CLI
 * mocked. The WP-CLI double in LogSupport.php throws from error(), the way the
 * real one exits.
 *
 * Mutation-clean under `composer mutate -- --path=includes/Cli/LogCommand.php`,
 * with no equivalent mutants.
 *
 * @see includes/Cli/LogCommand.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Cli\LogCommand;
use UAMSWeb\AuditorRole\Log;
use UAMSWeb\AuditorRole\LogQuery;

require_once __DIR__.'/LogSupport.php';

beforeEach(function (): void {
    uamswp_auditor_role_g3_wpdb();
    uamswp_auditor_role_g3_cli_reset();
    Functions\when('get_option')->justReturn(Log::SCHEMA);
    $GLOBALS['uamswp_auditor_role_g3_out'] = sys_get_temp_dir().'/uamswp-auditor-role-g3-'.uniqid('', true).'.csv';
});

afterEach(function (): void {
    if (is_file(uamswp_auditor_role_g3_out())) {
        unlink(uamswp_auditor_role_g3_out());
    }
});

/**
 * The file this test exports to.
 */
function uamswp_auditor_role_g3_out(): string
{
    $out = $GLOBALS['uamswp_auditor_role_g3_out'] ?? '';

    return is_string($out) ? $out : '';
}

/**
 * Run a subcommand and return the WP-CLI error it raised, or null.
 *
 * @param  array<string, string>  $assoc
 */
function uamswp_auditor_role_g3_run(LogCommand $command, string $subcommand, array $assoc): ?string
{
    try {
        $command->{$subcommand}([], $assoc);
    } catch (RuntimeException $e) {
        return str_replace('WP_CLI::error ', '', $e->getMessage());
    }

    return null;
}

/**
 * What the command handed WP-CLI's formatter.
 *
 * @return array{0: string, 1: list<array<string, string>>, 2: list<string>}
 */
function uamswp_auditor_role_g3_formatted(): array
{
    return $GLOBALS['uamswp_auditor_role_g3_formatted'][0];
}

it('lists rows newest first in the format asked for, with every column', function (string $format): void {
    uamswp_auditor_role_g3_db()->results = [[uamswp_auditor_role_g3_row(2), uamswp_auditor_role_g3_row(1)]];

    expect(uamswp_auditor_role_g3_run(new LogCommand, 'list', ['format' => $format]))->toBeNull();

    [$used, $rows, $fields] = uamswp_auditor_role_g3_formatted();

    expect($used)->toBe($format)
        ->and(array_column($rows, 'id'))->toBe(['2', '1'])
        ->and($fields)->toBe(LogQuery::columns())
        ->and(uamswp_auditor_role_g3_db()->prepared[0])->toBe('SELECT * FROM `wp_uamswp_auditor_log` WHERE 1=1 ORDER BY id DESC LIMIT 100 OFFSET 0');
})->with(['table', 'csv', 'json', 'yaml']);

it('lists as a table by default', function (): void {
    uamswp_auditor_role_g3_db()->results = [[]];

    uamswp_auditor_role_g3_run(new LogCommand, 'list', []);

    expect(uamswp_auditor_role_g3_formatted()[0])->toBe('table');
});

it('filters by auditor login, dates and event, and limits the rows', function (): void {
    Functions\expect('get_user_by')->once()->with('login', 'audit')->andReturn(uamswp_auditor_role_user_with(7, 'audit', ['auditor']));
    uamswp_auditor_role_g3_db()->results = [[uamswp_auditor_role_g3_row(3), uamswp_auditor_role_g3_row(2), uamswp_auditor_role_g3_row(1)]];

    uamswp_auditor_role_g3_run(new LogCommand, 'list', ['user' => 'audit', 'from' => '2026-11-01', 'to' => '2026-11-05', 'event' => 'refused', 'limit' => '2']);

    expect(uamswp_auditor_role_g3_db()->prepared[0])->toBe("SELECT * FROM `wp_uamswp_auditor_log` WHERE 1=1 AND user_id = 7 AND logged_at >= '2026-11-01 00:00:00' AND logged_at <= '2026-11-05 23:59:59' AND event = 'refused' ORDER BY id DESC LIMIT 2 OFFSET 0")
        ->and(array_column(uamswp_auditor_role_g3_formatted()[1], 'id'))->toBe(['3', '2']);
});

it('filters by auditor ID', function (): void {
    Functions\expect('get_userdata')->once()->with(Mockery::on(static fn (mixed $id): bool => $id === 12))->andReturn(uamswp_auditor_role_user_with(12, 'other', ['auditor']));
    Functions\expect('get_user_by')->never();
    uamswp_auditor_role_g3_db()->results = [[]];

    uamswp_auditor_role_g3_run(new LogCommand, 'list', ['user' => '12']);

    expect(uamswp_auditor_role_g3_db()->prepared[0])->toContain('AND user_id = 12 ');
});

it('lists at least one row', function (): void {
    uamswp_auditor_role_g3_db()->results = [[uamswp_auditor_role_g3_row(1)]];

    uamswp_auditor_role_g3_run(new LogCommand, 'list', ['limit' => '0']);

    expect(uamswp_auditor_role_g3_db()->prepared[0])->toEndWith('LIMIT 1 OFFSET 0');
});

it('refuses a filter it cannot read, before reading the log', function (array $assoc, string $error): void {
    Functions\when('get_user_by')->justReturn(false);
    Functions\when('get_userdata')->justReturn(false);

    expect(uamswp_auditor_role_g3_run(new LogCommand, 'list', $assoc))->toBe($error)
        ->and(uamswp_auditor_role_g3_db()->prepared)->toBe([]);
})->with([
    'unknown login' => [['user' => 'nobody'], 'No such user: nobody'],
    'unknown ID' => [['user' => '999'], 'No such user: 999'],
    'bad from date' => [['from' => '2026-02-30'], '--from must be a date in Y-m-d form.'],
    'bad to date' => [['to' => 'yesterday'], '--to must be a date in Y-m-d form.'],
    'unknown event' => [['event' => 'deleted'], '--event must be one of: view, refused, db_refused, login, logout, login_refused'],
]);

it('accepts every event and both dates', function (): void {
    uamswp_auditor_role_g3_db()->results = [[]];

    expect(uamswp_auditor_role_g3_run(new LogCommand, 'list', ['event' => 'login_refused', 'from' => '2026-11-01', 'to' => '2026-11-01']))->toBeNull();
});

it('refuses to read a site whose log table is not installed', function (string $subcommand): void {
    Functions\when('get_option')->justReturn(false);

    expect(uamswp_auditor_role_g3_run(new LogCommand, $subcommand, ['file' => uamswp_auditor_role_g3_out()]))->toBe('The log table is not installed on this site yet. It is created on the first web request.')
        ->and(uamswp_auditor_role_g3_db()->prepared)->toBe([]);
})->with(['list', 'export']);

it('lists every site of a network newest first, skipping sites without the table, switching back after each', function (): void {
    Functions\when('is_multisite')->justReturn(true);
    Functions\when('get_sites')->justReturn([(object) ['blog_id' => '1'], (object) ['blog_id' => '2'], (object) ['blog_id' => '3']]);
    $current = 0;
    Functions\when('switch_to_blog')->alias(static function (int $id) use (&$current): bool {
        $current = $id;

        return true;
    });
    Functions\expect('restore_current_blog')->times(3);
    Functions\when('get_option')->alias(static function () use (&$current): string|false {
        return $current === 2 ? false : Log::SCHEMA;
    });
    uamswp_auditor_role_g3_db()->results = [
        [uamswp_auditor_role_g3_row(8, '2026-11-05 09:00:00', 1), uamswp_auditor_role_g3_row(7, '2026-11-05 08:00:00', 1)],
        [uamswp_auditor_role_g3_row(5, '2026-11-05 09:30:00', 3), uamswp_auditor_role_g3_row(4, '2026-11-05 09:00:00', 3)],
    ];

    expect(uamswp_auditor_role_g3_run(new LogCommand, 'list', ['all-sites' => '1', 'limit' => '3']))->toBeNull();

    $rows = uamswp_auditor_role_g3_formatted()[1];

    expect(array_map(static fn (array $r): string => $r['blog_id'].':'.$r['id'], $rows))->toBe(['3:5', '3:4', '1:8'])
        ->and(uamswp_auditor_role_g3_db()->prepared)->toHaveCount(2)
        ->and(uamswp_auditor_role_g3_db()->prepared[0])->toEndWith('LIMIT 3 OFFSET 0');
});

it('orders rows logged in the same second by site, then by row', function (): void {
    Functions\when('is_multisite')->justReturn(true);
    Functions\when('get_sites')->justReturn([(object) ['blog_id' => '1'], (object) ['blog_id' => '2']]);
    Functions\when('switch_to_blog')->justReturn(true);
    Functions\when('restore_current_blog')->justReturn(true);
    uamswp_auditor_role_g3_db()->results = [
        [uamswp_auditor_role_g3_row(9, '2026-11-05 09:00:00', 1), uamswp_auditor_role_g3_row(3, '2026-11-05 09:00:00', 1)],
        [uamswp_auditor_role_g3_row(2, '2026-11-05 09:00:00', 2)],
    ];

    uamswp_auditor_role_g3_run(new LogCommand, 'list', ['all-sites' => '1']);

    expect(array_map(static fn (array $r): string => $r['blog_id'].':'.$r['id'], uamswp_auditor_role_g3_formatted()[1]))->toBe(['2:2', '1:9', '1:3']);
});

it('compares IDs as numbers, not text', function (): void {
    uamswp_auditor_role_g3_db()->results = [[uamswp_auditor_role_g3_row(9), uamswp_auditor_role_g3_row(10)]];

    uamswp_auditor_role_g3_run(new LogCommand, 'list', []);

    expect(array_column(uamswp_auditor_role_g3_formatted()[1], 'id'))->toBe(['10', '9']);
});

it('switches back when a site cannot be read', function (): void {
    Functions\when('is_multisite')->justReturn(true);
    Functions\when('get_sites')->justReturn([(object) ['blog_id' => '4']]);
    Functions\when('switch_to_blog')->justReturn(true);
    Functions\expect('restore_current_blog')->once();
    Functions\when('get_option')->alias(static function (): never {
        throw new RuntimeException('database gone');
    });

    expect(uamswp_auditor_role_g3_run(new LogCommand, 'list', ['all-sites' => '1']))->toBe('database gone');
});

it('refuses every site on a single install', function (): void {
    Functions\when('is_multisite')->justReturn(false);
    Functions\expect('switch_to_blog')->never();

    expect(uamswp_auditor_role_g3_run(new LogCommand, 'list', ['all-sites' => '1']))->toBe('--all-sites needs a multisite network.');
});

it('exports the filtered rows as CSV to a file and says where', function (): void {
    uamswp_auditor_role_g3_db()->results = [[uamswp_auditor_role_g3_row(4)]];

    expect(uamswp_auditor_role_g3_run(new LogCommand, 'export', ['file' => uamswp_auditor_role_g3_out(), 'event' => 'view']))->toBeNull()
        ->and(file_get_contents(uamswp_auditor_role_g3_out()))->toBe("id,logged_at,user_id,user_login,blog_id,event,method,screen,url,detail\n4,\"2026-11-05 10:00:00\",7,audit,1,view,GET,index.php,/wp-admin/,\n")
        ->and(uamswp_auditor_role_g3_db()->prepared[0])->toContain("AND event = 'view'")
        ->and($GLOBALS['uamswp_auditor_role_g3_cli'])->toBe([['success', 'Wrote '.uamswp_auditor_role_g3_out()]]);
});

it('exports every site of a network under one header row', function (): void {
    Functions\when('is_multisite')->justReturn(true);
    Functions\when('get_sites')->justReturn([(object) ['blog_id' => '1'], (object) ['blog_id' => '2']]);
    Functions\when('switch_to_blog')->justReturn(true);
    Functions\when('restore_current_blog')->justReturn(true);
    uamswp_auditor_role_g3_db()->results = [[uamswp_auditor_role_g3_row(4, '2026-11-05 10:00:00', 1)], [uamswp_auditor_role_g3_row(6, '2026-11-05 10:00:00', 2)]];

    uamswp_auditor_role_g3_run(new LogCommand, 'export', ['file' => uamswp_auditor_role_g3_out(), 'all-sites' => '1']);

    $csv = (string) file_get_contents(uamswp_auditor_role_g3_out());

    expect(substr_count($csv, 'id,logged_at'))->toBe(1)
        ->and(substr_count($csv, "\n"))->toBe(3)
        ->and($csv)->toContain("\n4,")->toContain("\n6,");
});

it('says nothing when it exports to standard output', function (): void {
    uamswp_auditor_role_g3_db()->results = [[]];

    ob_start();
    $error = uamswp_auditor_role_g3_run(new LogCommand, 'export', []);
    ob_end_clean();

    expect($error)->toBeNull()
        ->and($GLOBALS['uamswp_auditor_role_g3_cli'])->toBe([]);
});

it('refuses an output it cannot open, before reading the log', function (): void {
    // fopen() warns as well as failing; the warning is PHP's, not the command's.
    set_error_handler(static fn (): bool => true, E_WARNING);

    try {
        $error = uamswp_auditor_role_g3_run(new LogCommand, 'export', ['file' => sys_get_temp_dir().'/no-such-directory-g3/x.csv']);
    } finally {
        restore_error_handler();
    }

    expect($error)->toBe('Could not open the output.')
        ->and(uamswp_auditor_role_g3_db()->prepared)->toBe([]);
});

it('has only the two subcommands that read', function (): void {
    $methods = array_map(static fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(LogCommand::class))->getMethods(ReflectionMethod::IS_PUBLIC));

    expect($methods)->toBe(['list', 'export']);
});
