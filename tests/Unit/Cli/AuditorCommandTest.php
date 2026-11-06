<?php

/**
 * UAMSWeb\AuditorRole\Cli\AuditorCommand, with WordPress and WP-CLI mocked.
 *
 * The reports run for real against the in-memory install in
 * tests/Support/report-fixture.php, and WP-CLI is the recording stand-in in
 * tests/Support/wp-cli-double.php, whose error() throws as the real one stops
 * the command. Mutation-clean under
 * `composer mutate -- --path=includes/Cli/AuditorCommand.php`, with no
 * equivalent mutants.
 *
 * @see includes/Cli/AuditorCommand.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Cli\AuditorCommand;
use UAMSWeb\AuditorRole\Report;
use UAMSWeb\AuditorRole\Role;

require_once __DIR__.'/../../Support/wp-cli-double.php';
require_once __DIR__.'/../../Support/report-fixture.php';

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    uamswp_auditor_role_g4_report_setup();
    UamswpAuditorRoleG4CliLog::reset();
    Functions\when('wp_json_encode')->alias(static fn (mixed $data, int $flags = 0): string|false => json_encode($data, $flags));
});

afterEach(function (): void {
    uamswp_auditor_role_g4_report_teardown();
});

/**
 * A report with its timestamp dropped, so two runs compare equal.
 *
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function uamswp_auditor_role_g4_untimed(array $report): array
{
    unset($report['generated_at']);

    return $report;
}

it('prints the discovery report as pretty JSON with slashes unescaped by default', function (): void {
    uamswp_auditor_role_g4_install(true);

    (new AuditorCommand)->discover([], []);

    $lines = UamswpAuditorRoleG4CliLog::calls('line');
    expect($lines)->toHaveCount(1);

    $printed = $lines[0][0];
    expect($printed)->toContain("\n    \"type\": \"multisite-subdirectory\"")
        ->toContain('"url": "https://example.org/"')
        ->and(uamswp_auditor_role_g4_untimed(json_decode($printed, true)))->toBe(uamswp_auditor_role_g4_untimed(Report::discovery()));
});

it('prints the discovery report as YAML when asked', function (): void {
    uamswp_auditor_role_g4_install(false);

    (new AuditorCommand)->discover([], ['format' => 'yaml']);

    $printed = UamswpAuditorRoleG4CliLog::calls('print_value');
    expect($printed)->toHaveCount(1)
        ->and(UamswpAuditorRoleG4CliLog::calls('line'))->toBe([])
        ->and($printed[0][1])->toBe(['format' => 'yaml'])
        ->and(uamswp_auditor_role_g4_untimed($printed[0][0]))->toBe(uamswp_auditor_role_g4_untimed(Report::discovery()));
});

it('prints an empty line when the report cannot be encoded', function (): void {
    uamswp_auditor_role_g4_install(false);
    Functions\when('wp_json_encode')->justReturn(false);

    (new AuditorCommand)->discover([], []);
    (new AuditorCommand)->network_export([], []);

    expect(UamswpAuditorRoleG4CliLog::calls('line'))->toBe([[''], ['']]);
});

it('prints the network export as pretty JSON with slashes unescaped', function (): void {
    uamswp_auditor_role_g4_install(true, false, null, ['site_name', 'wppusher_token']);

    (new AuditorCommand)->network_export([], []);

    $printed = UamswpAuditorRoleG4CliLog::calls('line')[0][0];
    expect($printed)->toContain("\n    \"wordpress\": \"6.8.8\"")
        ->toContain('"url": "https://example.org/two/"')
        ->and(str_contains($printed, 'ghp_abcdefghijklmnop'))->toBeFalse()
        ->and(uamswp_auditor_role_g4_untimed(json_decode($printed, true)))->toBe(uamswp_auditor_role_g4_untimed(Report::network_export()))
        ->and(UamswpAuditorRoleG4CliLog::calls('success'))->toBe([]);
});

it('writes the network export to a file, ending in a newline', function (): void {
    uamswp_auditor_role_g4_install(true, false, null, ['site_name', 'wppusher_token']);
    $file = sys_get_temp_dir().'/uamswp-auditor-role-g4-export-'.getmypid().'.json';

    try {
        (new AuditorCommand)->network_export([], ['file' => $file]);

        $written = (string) file_get_contents($file);
        expect($written)->toEndWith("}\n")
            ->toContain("\n    \"multisite\": true")
            ->and(str_contains($written, 'ghp_abcdefghijklmnop'))->toBeFalse()
            ->and(uamswp_auditor_role_g4_untimed(json_decode($written, true)))->toBe(uamswp_auditor_role_g4_untimed(Report::network_export()))
            ->and(UamswpAuditorRoleG4CliLog::calls('success'))->toBe([['Wrote '.$file]])
            ->and(UamswpAuditorRoleG4CliLog::calls('line'))->toBe([]);
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

it('stops with an error when the export file cannot be written', function (): void {
    uamswp_auditor_role_g4_install(false);
    $dir = sys_get_temp_dir();

    // Writing to a directory fails with a warning, which the command's own check reports.
    set_error_handler(static fn (): bool => true);

    try {
        expect(fn () => (new AuditorCommand)->network_export([], ['file' => $dir]))->toThrow(RuntimeException::class, 'Could not write '.$dir);
    } finally {
        restore_error_handler();
    }

    expect(UamswpAuditorRoleG4CliLog::calls('success'))->toBe([]);
});

it('refuses to sync sites on a single install', function (): void {
    uamswp_auditor_role_g4_install(false);
    Functions\expect('add_user_to_blog')->never();

    expect(fn () => (new AuditorCommand)->sync_sites())->toThrow(RuntimeException::class, 'This is not a multisite network.');
});

it('refuses to sync sites with no logins configured', function (): void {
    uamswp_auditor_role_g4_install(true);
    Filters\expectApplied('uamswp_auditor_role_logins')->andReturn([]);
    Functions\expect('add_user_to_blog')->never();

    expect(fn () => (new AuditorCommand)->sync_sites())->toThrow(RuntimeException::class, 'UAMSWP_AUDITOR_ROLE_LOGINS names no logins.');
});

it('syncs every configured login to every site and reports each count', function (): void {
    uamswp_auditor_role_g4_install(true);
    Filters\expectApplied('uamswp_auditor_role_logins')->andReturn(['audit-one', 'gone']);
    Functions\when('get_user_by')->alias(static fn (string $field, string $login): mixed => $login === 'audit-one' ? uamswp_auditor_role_user_with(9, 'audit-one', []) : false);
    Functions\when('is_user_member_of_blog')->alias(static fn (int $user, int $blog): bool => $blog === 1);
    Functions\when('get_role')->justReturn(new stdClass);
    Functions\when('get_option')->alias(static fn (string $name): mixed => $name === Role::OPTION ? Role::stamp() : false);
    Functions\expect('add_user_to_blog')->once()->with(2, 9, Role::NAME)->andReturn(true);

    (new AuditorCommand)->sync_sites();

    expect(UamswpAuditorRoleG4CliLog::calls('log'))->toBe([['audit-one: added to 1 site(s).'], ['gone: added to 0 site(s).']])
        ->and(UamswpAuditorRoleG4CliLog::calls('success'))->toBe([['Done.']]);
});

/**
 * Expect the role written on each of the given sites, in order.
 *
 * @param  list<int>  $sites
 */
function uamswp_auditor_role_g4_expect_role_writes(array $sites): void
{
    $written = new ArrayObject;
    Functions\when('__')->returnArg();
    Functions\when('remove_role')->justReturn();
    Functions\when('update_option')->justReturn(true);
    Functions\when('add_role')->alias(static function () use ($written): null {
        $written->append($GLOBALS['uamswp_auditor_role_g4_site']);

        return null;
    });
    $GLOBALS['uamswp_auditor_role_g4_written'] = $written;
    $GLOBALS['uamswp_auditor_role_g4_expected'] = $sites;
}

it('rewrites the role on the current site of a single install', function (array $assoc): void {
    uamswp_auditor_role_g4_install(false);
    $GLOBALS['uamswp_auditor_role_g4_site'] = 4;
    uamswp_auditor_role_g4_expect_role_writes([4]);

    (new AuditorCommand)->refresh_role([], $assoc);

    expect($GLOBALS['uamswp_auditor_role_g4_written']->getArrayCopy())->toBe([4])
        ->and($GLOBALS['uamswp_auditor_role_g4_switches'])->toBe([])
        ->and(UamswpAuditorRoleG4CliLog::calls('success'))->toBe([['Rewrote the role on 1 site(s).']]);
})->with(['without --network' => [[]], 'with --network' => [['network' => '']]]);

it('rewrites the role on the current site of a network, switched to it', function (): void {
    uamswp_auditor_role_g4_install(true);
    uamswp_auditor_role_g4_expect_role_writes([1]);

    (new AuditorCommand)->refresh_role([], []);

    expect($GLOBALS['uamswp_auditor_role_g4_written']->getArrayCopy())->toBe([1])
        ->and($GLOBALS['uamswp_auditor_role_g4_switches'])->toBe([1, 'restore'])
        ->and(UamswpAuditorRoleG4CliLog::calls('success'))->toBe([['Rewrote the role on 1 site(s).']]);
});

it('rewrites the role on every site of a network with --network', function (): void {
    uamswp_auditor_role_g4_install(true);
    uamswp_auditor_role_g4_expect_role_writes([1, 2]);

    (new AuditorCommand)->refresh_role([], ['network' => '']);

    expect($GLOBALS['uamswp_auditor_role_g4_written']->getArrayCopy())->toBe([1, 2])
        ->and($GLOBALS['uamswp_auditor_role_g4_switches'])->toBe([1, 'restore', 2, 'restore'])
        ->and(UamswpAuditorRoleG4CliLog::calls('success'))->toBe([['Rewrote the role on 2 site(s).']]);
});

it('writes no option or network option while reporting', function (): void {
    uamswp_auditor_role_g4_install(true);
    Functions\expect('update_option')->never();
    Functions\expect('add_option')->never();
    Functions\expect('delete_option')->never();
    Functions\expect('update_site_option')->never();

    (new AuditorCommand)->discover([], []);
    (new AuditorCommand)->network_export([], []);

    expect(UamswpAuditorRoleG4CliLog::calls('line'))->toHaveCount(2);
});
