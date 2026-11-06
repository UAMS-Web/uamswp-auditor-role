<?php

/**
 * UAMSWeb\AuditorRole\Retention, with WordPress and the database mocked.
 *
 * Mutation-clean under `composer mutate -- --path=includes/Retention.php`, with
 * no equivalent mutants.
 *
 * @see includes/Retention.php
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Log;
use UAMSWeb\AuditorRole\Retention;

require_once __DIR__.'/LogSupport.php';

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    uamswp_auditor_role_g3_wpdb();

    if (! defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
        define('DAY_IN_SECONDS', 86400);
    }
});

/**
 * Answer get_option() for the log schema and the retention.
 */
function uamswp_auditor_role_g3_options(string|false $schema, mixed $days = 365): void
{
    Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => match ($name) {
        Log::OPTION => $schema,
        Retention::OPTION => $days,
        default => $default,
    });
}

it('schedules on init and prunes on its hook', function (): void {
    Actions\expectAdded('init')->once()->with([Retention::class, 'schedule'], 20, 1);
    Actions\expectAdded(Retention::HOOK)->once()->with([Retention::class, 'run'], 10, 1);

    Retention::boot();
});

it('names its option, its record and its hook', function (): void {
    expect(Retention::OPTION)->toBe('uamswp_auditor_role_retention_days')
        ->and(Retention::LAST_PRUNE)->toBe('uamswp_auditor_role_last_prune')
        ->and(Retention::HOOK)->toBe('uamswp_auditor_role_prune');
});

it('keeps a year by default, between a day and ten years', function (): void {
    expect(Retention::default_days())->toBe(365)
        ->and(Retention::min_days())->toBe(1)
        ->and(Retention::max_days())->toBe(3650);
});

it('reads the default when no period is set', function (): void {
    Functions\expect('get_option')->once()->with(Retention::OPTION, 365)->andReturn(365);

    expect(Retention::days())->toBe(365);
});

it('reads a changed period', function (): void {
    Functions\when('get_option')->justReturn('30');

    expect(Retention::days())->toBe(30);
});

it('holds a submitted period to one day through ten years', function (mixed $days, int $held): void {
    expect(Retention::clamp($days))->toBe($held);
})->with([
    'a month' => ['30', 30],
    'the least' => [1, 1],
    'below the least' => [0, 1],
    'negative' => ['-5', 1],
    'the most' => [3650, 3650],
    'above the most' => ['3651', 3650],
    'a fraction' => ['7.9', 7],
    'not a number' => ['forever', 365],
    'nothing' => [null, 365],
]);

it('schedules the daily prune once, an hour out, on the main site', function (): void {
    uamswp_auditor_role_as(false);
    Functions\when('is_main_site')->justReturn(true);
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\expect('wp_schedule_event')->once()->with(Mockery::on(static fn (int $when): bool => abs($when - (time() + 3600)) <= 2), 'daily', Retention::HOOK);
    Functions\expect('wp_clear_scheduled_hook')->never();

    Retention::schedule();
});

it('leaves an existing schedule alone on the main site', function (): void {
    uamswp_auditor_role_as(false);
    Functions\when('is_main_site')->justReturn(true);
    Functions\expect('wp_next_scheduled')->once()->with(Retention::HOOK)->andReturn(1_800_000_000);
    Functions\expect('wp_schedule_event')->never();
    Functions\expect('wp_clear_scheduled_hook')->never();

    Retention::schedule();
});

it('clears a per-site schedule an earlier version left on a subsite', function (): void {
    uamswp_auditor_role_as(false);
    Functions\when('is_main_site')->justReturn(false);
    Functions\when('wp_next_scheduled')->justReturn(1_800_000_000);
    Functions\expect('wp_clear_scheduled_hook')->once()->with(Retention::HOOK);
    Functions\expect('wp_schedule_event')->never();

    Retention::schedule();
});

it('schedules nothing on a subsite without one', function (): void {
    uamswp_auditor_role_as(false);
    Functions\when('is_main_site')->justReturn(false);
    Functions\when('wp_next_scheduled')->justReturn(false);
    Functions\expect('wp_clear_scheduled_hook')->never();
    Functions\expect('wp_schedule_event')->never();

    Retention::schedule();
});

it('never schedules from an Auditor session', function (): void {
    uamswp_auditor_role_as(true);
    Functions\expect('wp_next_scheduled')->never();
    Functions\expect('wp_schedule_event')->never();
    Functions\expect('wp_clear_scheduled_hook')->never();

    Retention::schedule();
});

it('deletes rows older than the period in batches and records how many', function (): void {
    uamswp_auditor_role_as(false);
    uamswp_auditor_role_g3_options(Log::SCHEMA, '30');
    uamswp_auditor_role_g3_db()->query_returns = [1000, 1000, 5, 1000];
    Functions\expect('update_option')->once()->with(Retention::LAST_PRUNE, Mockery::on(
        static fn (array $record): bool => array_keys($record) === ['time', 'deleted'] && abs($record['time'] - time()) <= 2 && $record['deleted'] === 2005
    ), false);

    expect(Retention::prune())->toBe(2005)
        ->and(uamswp_auditor_role_g3_db()->queries)->toHaveCount(3);

    preg_match("/^DELETE FROM `wp_uamswp_auditor_log` WHERE logged_at < '([^']+)' LIMIT 1000$/", uamswp_auditor_role_g3_db()->queries[0], $m);

    expect($m)->toHaveCount(2)
        ->and(abs((int) strtotime(($m[1] ?? '').' UTC') - (time() - 30 * 86400)))->toBeLessThanOrEqual(2);
});

it('stops after a batch that deleted fewer than a full batch', function (): void {
    uamswp_auditor_role_as(false);
    uamswp_auditor_role_g3_options(Log::SCHEMA);
    uamswp_auditor_role_g3_db()->query_returns = [999, 1000];
    Functions\when('update_option')->justReturn(true);

    expect(Retention::prune())->toBe(999)
        ->and(uamswp_auditor_role_g3_db()->queries)->toHaveCount(1);
});

it('counts a failed statement as none deleted and stops', function (): void {
    uamswp_auditor_role_as(false);
    uamswp_auditor_role_g3_options(Log::SCHEMA);
    uamswp_auditor_role_g3_db()->query_returns = [false, 1000];
    Functions\when('update_option')->justReturn(true);

    expect(Retention::prune())->toBe(0)
        ->and(uamswp_auditor_role_g3_db()->queries)->toHaveCount(1);
});

it('issues at most 100 statements in one run', function (): void {
    uamswp_auditor_role_as(false);
    uamswp_auditor_role_g3_options(Log::SCHEMA);
    uamswp_auditor_role_g3_db()->query_returns = array_fill(0, 101, 1000);
    Functions\when('update_option')->justReturn(true);

    expect(Retention::prune())->toBe(100_000)
        ->and(uamswp_auditor_role_g3_db()->queries)->toHaveCount(100);
});

it('prunes nothing in an Auditor session or before the table exists', function (bool $auditor, string|false $schema): void {
    uamswp_auditor_role_as($auditor);
    uamswp_auditor_role_g3_options($schema);
    Functions\expect('update_option')->never();

    expect(Retention::prune())->toBe(0)
        ->and(uamswp_auditor_role_g3_db()->queries)->toBe([]);
})->with([
    'Auditor session' => [true, Log::SCHEMA],
    'no table' => [false, false],
]);

it('prunes the one site of a single install without switching', function (): void {
    uamswp_auditor_role_as(false);
    uamswp_auditor_role_g3_options(Log::SCHEMA);
    Functions\when('is_multisite')->justReturn(false);
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\expect('switch_to_blog')->never();
    Functions\expect('restore_current_blog')->never();
    uamswp_auditor_role_g3_db()->query_returns = [4];
    Functions\when('update_option')->justReturn(true);

    expect(Retention::prune_all())->toBe([1 => 4]);
});

it('prunes every site of a network, each by its own period, switching back after each', function (): void {
    uamswp_auditor_role_as(false);
    Functions\when('is_multisite')->justReturn(true);
    Functions\when('get_sites')->justReturn([(object) ['blog_id' => '1'], (object) ['blog_id' => '5']]);
    Functions\when('update_option')->justReturn(true);

    $switched = [];
    Functions\when('switch_to_blog')->alias(static function (int $id) use (&$switched): bool {
        $switched[] = $id;

        return true;
    });
    Functions\expect('restore_current_blog')->twice();
    Functions\when('get_option')->alias(static function (string $name, mixed $default = false) use (&$switched): mixed {
        return match ($name) {
            Log::OPTION => Log::SCHEMA,
            Retention::OPTION => end($switched) === 5 ? 7 : 400,
            default => $default,
        };
    });
    uamswp_auditor_role_g3_db()->query_returns = [3, 8];

    expect(Retention::prune_all())->toBe([1 => 3, 5 => 8])
        ->and($switched)->toBe([1, 5]);

    preg_match("/'([^']+)'/", uamswp_auditor_role_g3_db()->queries[1], $m);

    expect(abs((int) strtotime(($m[1] ?? '').' UTC') - (time() - 7 * 86400)))->toBeLessThanOrEqual(2);
});

it('switches back when a site\'s prune fails', function (): void {
    uamswp_auditor_role_as(false);
    uamswp_auditor_role_g3_options(Log::SCHEMA);
    Functions\when('is_multisite')->justReturn(true);
    Functions\when('get_sites')->justReturn([(object) ['blog_id' => '2']]);
    Functions\when('switch_to_blog')->justReturn(true);
    Functions\expect('restore_current_blog')->once();
    Functions\when('update_option')->alias(static function (): never {
        throw new RuntimeException('database gone');
    });

    expect(fn (): array => Retention::prune_all())->toThrow(RuntimeException::class, 'database gone');
});

it('runs the network prune from the scheduled hook', function (): void {
    uamswp_auditor_role_as(false);
    uamswp_auditor_role_g3_options(Log::SCHEMA);
    Functions\when('is_multisite')->justReturn(false);
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\expect('update_option')->once();

    Retention::run();
});
