<?php

/**
 * UAMSWeb\AuditorRole\Report, with WordPress mocked.
 *
 * Every read runs against a small in-memory install: per-site options, roles
 * and plugins that follow switch_to_blog(), and a `$wpdb` that answers the
 * option-name queries. A test then compares the whole report, so dropping or
 * changing any field fails it. Mutation-clean under
 * `composer mutate -- --path=includes/Report.php`, with no equivalent mutants.
 *
 * @see includes/Report.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Report;
use UAMSWeb\AuditorRole\Secrets;

require_once __DIR__.'/../Support/report-fixture.php';

beforeEach(function (): void {
    uamswp_auditor_role_g4_report_setup();
});

afterEach(function (): void {
    uamswp_auditor_role_g4_report_teardown();
});

it('lists core\'s administrator capabilities', function (): void {
    expect(Report::core_admin_caps())->toBe(UAMSWP_AUDITOR_ROLE_G4_CORE_CAPS);
});

it('reports a multisite network: every site, plugin, theme, added capability and secret-looking name, but no value', function (): void {
    uamswp_auditor_role_g4_install(true, false, null, ['site_name', 'wppusher_token', 'smtp', 5]);

    $report = Report::discovery();

    expect($report['generated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/');
    unset($report['generated_at']);

    expect($report)->toBe([
        'type' => 'multisite-subdirectory',
        'versions' => ['wordpress' => '6.8.8', 'php' => PHP_VERSION, 'mysql' => '8.0.36', 'plugin' => UAMSWP_AUDITOR_ROLE_VERSION],
        'sites' => [
            ['blog_id' => 1, 'url' => 'https://example.org/', 'theme' => 'uams-2020', 'active_plugins' => ['gravityforms/gravityforms.php', 'acf/acf.php']],
            ['blog_id' => 2, 'url' => 'https://example.org/two/', 'theme' => 'genesis', 'active_plugins' => []],
        ],
        'network_active_plugins' => ['uamswp-pusher/pusher.php'],
        'installed_plugins' => ['gravityforms/gravityforms.php' => '2.9.1', 'acf/acf.php' => '6', 'hello.php' => ''],
        'must_use_plugins' => ['uamswp-auditor-role-loader.php'],
        'dropins' => ['sunrise.php'],
        'themes' => ['uams-2020', 'genesis'],
        'administrator_caps_not_in_core' => ['edit_physicians', 'gform_full_access'],
        'secret_looking_option_names' => ['facetwp_license', 'wp_mail_smtp', 'zz_api_key'],
        'secret_looking_network_option_names' => ['wppusher_token', 'smtp'],
    ])
        ->and($GLOBALS['uamswp_auditor_role_g4_switches'])->toBe([1, 'restore', 2, 'restore'])
        ->and(str_contains((string) json_encode($report), 'hunter2'))->toBeFalse()
        ->and(str_contains((string) json_encode($report), 'ghp_'))->toBeFalse();
});

it('names a subdomain network as one', function (): void {
    uamswp_auditor_role_g4_install(true, true);

    expect(Report::discovery()['type'])->toBe('multisite-subdomain');
});

it('reports a single site without switching sites or reading network options', function (): void {
    uamswp_auditor_role_g4_install(false);
    Functions\expect('get_site_option')->never();
    Functions\expect('get_sites')->never();

    $report = Report::discovery();

    expect($report['type'])->toBe('single')
        ->and($report['sites'])->toBe([['blog_id' => 1, 'url' => 'https://example.org/', 'theme' => 'uams-2020', 'active_plugins' => ['gravityforms/gravityforms.php', 'acf/acf.php']]])
        ->and($report['network_active_plugins'])->toBe([])
        ->and($report['secret_looking_network_option_names'])->toBe([])
        ->and($report['secret_looking_option_names'])->toBe(['wp_mail_smtp', 'zz_api_key'])
        ->and($GLOBALS['uamswp_auditor_role_g4_switches'])->toBe([]);
});

it('scans option names with transients excluded in the query', function (): void {
    uamswp_auditor_role_g4_install(false);

    Report::secret_option_names();
    Report::secret_network_option_names();

    expect($GLOBALS['wpdb']->prepared)->toBe([
        'SELECT option_name FROM %i WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s | wp_options, \_transient\_%, \_site\_transient\_%',
        'SELECT DISTINCT meta_key FROM %i WHERE meta_key NOT LIKE %s | wp_sitemeta, \_site\_transient\_%',
    ]);
});

it('lists secret-looking names as a list, and none from a failed query', function (): void {
    uamswp_auditor_role_g4_install(false, false, [1 => ['blogname', 'api_key', 'siteurl', 'smtp_pass']], null);

    expect(Report::secret_option_names())->toBe(['api_key', 'smtp_pass'])
        ->and(Report::secret_network_option_names())->toBe([]);
});

it('exports a network with every secret-bearing value masked', function (): void {
    uamswp_auditor_role_g4_install(true, false, null, ['site_name', 'wppusher_token', 'smtp', '_site_transient_update_core', 5]);

    $export = Report::network_export();

    expect($export['generated_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/');
    unset($export['generated_at']);

    expect($export)->toBe([
        'wordpress' => '6.8.8',
        'multisite' => true,
        'network_settings' => [
            'site_name' => 'UAMS Network',
            'wppusher_token' => Secrets::MASK,
            'smtp' => Secrets::MASK,
            '5' => false,
        ],
        'super_admins' => ['root', 'ops'],
        'network_active_plugins' => ['uamswp-pusher/pusher.php'],
        'network_allowed_themes' => ['uams-2020', 'genesis'],
        'installed_plugins' => ['gravityforms/gravityforms.php' => '2.9.1', 'acf/acf.php' => '6', 'hello.php' => ''],
        'must_use_plugins' => ['uamswp-auditor-role-loader.php'],
        'themes' => ['uams-2020', 'genesis'],
        'sites' => [
            ['blog_id' => 1, 'url' => 'https://example.org/', 'name' => 'Main', 'registered' => '2020-01-01 00:00:00', 'last_updated' => '2026-11-01 00:00:00', 'public' => 1, 'archived' => 0, 'deleted' => 0, 'spam' => 0, 'theme' => 'uams-2020', 'active_plugins' => ['gravityforms/gravityforms.php', 'acf/acf.php']],
            ['blog_id' => 2, 'url' => 'https://example.org/two/', 'name' => 'Two', 'registered' => '2021-02-02 00:00:00', 'last_updated' => '2026-10-01 00:00:00', 'public' => 0, 'archived' => 1, 'deleted' => 1, 'spam' => 1, 'theme' => 'genesis', 'active_plugins' => []],
        ],
    ])
        ->and(str_contains((string) json_encode($export), 'hunter2'))->toBeFalse()
        ->and(str_contains((string) json_encode($export), 'ghp_abcdefghijklmnop'))->toBeFalse()
        ->and($GLOBALS['uamswp_auditor_role_g4_switches'])->toBe([1, 'restore', 2, 'restore']);
});

it('reads network settings for the current network only', function (): void {
    uamswp_auditor_role_g4_install(true);
    Functions\when('get_current_network_id')->justReturn(3);

    Report::network_settings();

    expect($GLOBALS['wpdb']->prepared)->toBe(['SELECT DISTINCT meta_key FROM %i WHERE site_id = %d ORDER BY meta_key | wp_sitemeta, 3']);
});

it('reads no network settings from a failed query', function (): void {
    uamswp_auditor_role_g4_install(true, false, null, null);

    expect(Report::network_settings())->toBe([]);
});

it('exports a single site from its own options, without network fields', function (): void {
    uamswp_auditor_role_g4_install(false);
    Functions\expect('get_site')->never();
    Functions\expect('get_super_admins')->never();
    Functions\expect('get_site_option')->never();

    $export = Report::network_export();

    expect($export['multisite'])->toBeFalse()
        ->and($export['network_settings'])->toBe([])
        ->and($export['super_admins'])->toBe([])
        ->and($export['network_active_plugins'])->toBe([])
        ->and($export['network_allowed_themes'])->toBe([])
        ->and($export['sites'])->toBe([
            ['blog_id' => 1, 'url' => 'https://example.org/', 'name' => 'Main', 'registered' => '', 'last_updated' => '', 'public' => 1, 'archived' => 0, 'deleted' => 0, 'spam' => 0, 'theme' => 'uams-2020', 'active_plugins' => ['gravityforms/gravityforms.php', 'acf/acf.php']],
        ])
        ->and($GLOBALS['uamswp_auditor_role_g4_switches'])->toBe([]);
});

it('lists every site ID as an integer on a network, and the current one on a single site', function (): void {
    uamswp_auditor_role_g4_install(true);
    expect(Report::site_ids())->toBe([1, 2]);

    uamswp_auditor_role_g4_install(false);
    $GLOBALS['uamswp_auditor_role_g4_site'] = 4;
    expect(Report::site_ids())->toBe([4]);
});

it('restores the site even when a read on it fails', function (): void {
    uamswp_auditor_role_g4_install(true);
    Functions\when('get_stylesheet')->alias(static function (): never {
        throw new RuntimeException('theme gone');
    });

    expect(fn (): array => Report::discovery())->toThrow(RuntimeException::class)
        ->and($GLOBALS['uamswp_auditor_role_g4_switches'])->toBe([1, 'restore']);
});

it('reads a network list saved as something other than an array as empty', function (): void {
    uamswp_auditor_role_g4_install(true);
    Functions\when('get_site_option')->justReturn(false);

    $export = Report::network_export();
    $report = Report::discovery();

    expect($export['network_active_plugins'])->toBe([])
        ->and($export['network_allowed_themes'])->toBe([])
        ->and($report['network_active_plugins'])->toBe([]);
});

it('reads an option name the database returns as a number as a name, and none from a failed query', function (): void {
    uamswp_auditor_role_g4_install(false, false, [1 => [5, 'api_key']], [7, 'smtp']);

    expect(Report::secret_option_names())->toBe(['api_key'])
        ->and(Report::secret_network_option_names())->toBe(['smtp']);

    uamswp_auditor_role_g4_install(false, false, [1 => null]);

    expect(Report::secret_option_names())->toBe([]);
});
