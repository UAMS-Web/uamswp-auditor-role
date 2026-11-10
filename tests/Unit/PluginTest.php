<?php

/**
 * UAMSWeb\AuditorRole\Plugin, with WordPress mocked.
 *
 * boot() is pinned by one hook from each class it boots, so dropping any boot
 * call fails a test. The WP-CLI branch runs once without the WP_CLI constant
 * and once with it, in that order: a constant cannot be undefined, so the
 * "outside WP-CLI" test comes first in this file. Mutation-clean under
 * `composer mutate -- --path=includes/Plugin.php`, with no equivalent mutants.
 *
 * @see includes/Plugin.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Account;
use UAMSWeb\AuditorRole\AdminBar;
use UAMSWeb\AuditorRole\BetterImageSizes;
use UAMSWeb\AuditorRole\Cli\AuditorCommand;
use UAMSWeb\AuditorRole\Cli\LogCommand;
use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\Log;
use UAMSWeb\AuditorRole\LogViewer;
use UAMSWeb\AuditorRole\MetaCaps;
use UAMSWeb\AuditorRole\Network;
use UAMSWeb\AuditorRole\Plugin;
use UAMSWeb\AuditorRole\RequestGuard;
use UAMSWeb\AuditorRole\Retention;
use UAMSWeb\AuditorRole\Role;
use UAMSWeb\AuditorRole\Screens;
use UAMSWeb\AuditorRole\ShortPixel;

require_once __DIR__.'/../Support/wp-cli-double.php';

beforeEach(function (): void {
    if (! defined('UAMSWP_AUDITOR_ROLE_FILE')) {
        define('UAMSWP_AUDITOR_ROLE_FILE', '/wp-content/mu-plugins/uamswp-auditor-role/uamswp-auditor-role.php');
    }

    if (! defined('UAMSWP_AUDITOR_ROLE_VERSION')) {
        define('UAMSWP_AUDITOR_ROLE_VERSION', '0.1.0');
    }

    if (! defined('WPMU_PLUGIN_DIR')) {
        define('WPMU_PLUGIN_DIR', '/wp-content/mu-plugins');
    }

    Functions\when('is_multisite')->justReturn(true);
    UamswpAuditorRoleG4CliLog::reset();
});

it('boots every layer and registers the role and the translations on init', function (): void {
    Plugin::boot();

    expect(has_filter('query', [DatabaseGuard::class, 'filter']))->toBe(PHP_INT_MAX)
        ->and(has_action('plugins_loaded', [RequestGuard::class, 'check']))->toBe(PHP_INT_MIN)
        ->and(has_filter('map_meta_cap', [MetaCaps::class, 'map']))->toBe(PHP_INT_MAX)
        ->and(has_action('admin_menu', [Screens::class, 'remove_menu_entries']))->toBe(PHP_INT_MAX)
        ->and(has_filter('authenticate', [Account::class, 'refuse_expired_login']))->toBe(99)
        ->and(has_action('shutdown', [Log::class, 'record_request']))->toBe(0)
        ->and(has_action('init', [Retention::class, 'schedule']))->toBe(20)
        ->and(has_action('admin_menu', [LogViewer::class, 'register_page']))->toBe(10)
        ->and(has_action('wp_initialize_site', [Network::class, 'on_new_site']))->toBe(100)
        ->and(has_action('admin_bar_menu', [AdminBar::class, 'add_badge']))->toBe(0)
        ->and(has_filter('shortpixel/processorjs/disable', [ShortPixel::class, 'disable_processor']))->toBe(PHP_INT_MAX)
        ->and(has_filter('media_row_actions', [BetterImageSizes::class, 'remove_row_action']))->toBe(PHP_INT_MAX)
        ->and(has_filter('attachment_fields_to_edit', [BetterImageSizes::class, 'remove_field']))->toBe(PHP_INT_MAX)
        ->and(has_action('init', [Role::class, 'maybe_register']))->toBe(0)
        ->and(has_action('init', [Plugin::class, 'load_textdomain']))->toBe(10);
});

it('registers no WP-CLI command outside WP-CLI', function (): void {
    expect(defined('WP_CLI'))->toBeFalse();

    Plugin::boot();

    expect(UamswpAuditorRoleG4CliLog::calls('add_command'))->toBe([]);
});

it('registers both WP-CLI commands under WP-CLI', function (): void {
    define('WP_CLI', true);

    Plugin::boot();

    expect(UamswpAuditorRoleG4CliLog::calls('add_command'))->toBe([
        ['auditor', AuditorCommand::class],
        ['auditor log', LogCommand::class],
    ]);
});

it('loads translations from the must-use folder there', function (): void {
    Functions\when('plugin_basename')->justReturn('uamswp-auditor-role/uamswp-auditor-role.php');
    Functions\when('wp_normalize_path')->returnArg();
    Functions\expect('load_muplugin_textdomain')->once()->with('uamswp-auditor-role', 'uamswp-auditor-role/languages');
    Functions\expect('load_plugin_textdomain')->never();

    Plugin::load_textdomain();
});

it('loads translations as a regular plugin outside the must-use folder', function (): void {
    Functions\when('plugin_basename')->justReturn('uamswp-auditor-role/uamswp-auditor-role.php');
    Functions\when('wp_normalize_path')->alias(static fn (string $path): string => $path === UAMSWP_AUDITOR_ROLE_FILE ? '/wp-content/plugins/uamswp-auditor-role/uamswp-auditor-role.php' : $path);
    Functions\expect('load_plugin_textdomain')->once()->with('uamswp-auditor-role', false, 'uamswp-auditor-role/languages');
    Functions\expect('load_muplugin_textdomain')->never();

    Plugin::load_textdomain();
});

it('clears the prune job on deactivation and deletes nothing', function (): void {
    Functions\expect('wp_clear_scheduled_hook')->once()->with(Retention::HOOK);
    Functions\expect('delete_option')->never();

    Plugin::deactivate();
});
