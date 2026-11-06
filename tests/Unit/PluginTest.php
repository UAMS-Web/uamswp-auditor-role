<?php

/**
 * UAMSWeb\AuditorRole\Plugin, with WordPress mocked.
 *
 * @see includes/Plugin.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\MetaCaps;
use UAMSWeb\AuditorRole\Plugin;
use UAMSWeb\AuditorRole\RequestGuard;
use UAMSWeb\AuditorRole\Retention;
use UAMSWeb\AuditorRole\Role;

beforeEach(function (): void {
    if (! defined('UAMSWP_AUDITOR_ROLE_FILE')) {
        define('UAMSWP_AUDITOR_ROLE_FILE', '/wp-content/mu-plugins/uamswp-auditor-role/uamswp-auditor-role.php');
        define('UAMSWP_AUDITOR_ROLE_VERSION', '0.1.0');
    }

    Functions\when('is_multisite')->justReturn(false);
});

it('arms both guard layers and registers the role on boot', function (): void {
    Plugin::boot();

    expect(has_filter('query', [DatabaseGuard::class, 'filter']))->toBe(PHP_INT_MAX)
        ->and(has_action('plugins_loaded', [RequestGuard::class, 'check']))->toBe(PHP_INT_MIN)
        ->and(has_action('init', [RequestGuard::class, 'check']))->toBe(PHP_INT_MIN)
        ->and(has_action('init', [Role::class, 'maybe_register']))->toBe(0)
        ->and(has_filter('map_meta_cap', [MetaCaps::class, 'map']))->toBe(PHP_INT_MAX)
        ->and(has_filter('rest_pre_dispatch', [RequestGuard::class, 'rest_pre_dispatch']))->toBe(PHP_INT_MIN);
});

it('loads translations from the must-use folder there', function (): void {
    if (! defined('WPMU_PLUGIN_DIR')) {
        define('WPMU_PLUGIN_DIR', '/wp-content/mu-plugins');
    }

    Functions\when('plugin_basename')->justReturn('uamswp-auditor-role/uamswp-auditor-role.php');
    Functions\when('wp_normalize_path')->returnArg();
    Functions\expect('load_muplugin_textdomain')->once()->with('uamswp-auditor-role', 'uamswp-auditor-role/languages');
    Functions\expect('load_plugin_textdomain')->never();

    Plugin::load_textdomain();
});

it('clears the prune job on deactivation and deletes nothing', function (): void {
    Functions\expect('wp_clear_scheduled_hook')->once()->with(Retention::HOOK);
    Functions\expect('delete_option')->never();

    Plugin::deactivate();
});
