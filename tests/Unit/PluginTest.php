<?php

/**
 * UAMSWeb\AuditorRole\Plugin, with WordPress mocked.
 *
 * @see includes/Plugin.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Plugin;
use UAMSWeb\AuditorRole\Settings;

use function Brain\Monkey\Actions\expectAdded;

beforeEach(function (): void {
    if (! defined('UAMSWP_AUDITOR_ROLE_FILE')) {
        define('UAMSWP_AUDITOR_ROLE_FILE', '/plugins/uamswp-auditor-role/uamswp-auditor-role.php');
        define('UAMSWP_AUDITOR_ROLE_VERSION', '0.1.0');
    }
});

it('registers its hooks on boot', function (): void {
    expectAdded('init')->once()->with([Plugin::class, 'load_textdomain']);
    expectAdded('admin_menu')->once()->with([Settings::class, 'register_page']);
    expectAdded('admin_init')->once()->with([Settings::class, 'register_setting']);
    expectAdded('admin_post_'.Settings::ACTION)->once()->with([Settings::class, 'handle_save']);

    Plugin::boot();
});

it('loads the translations from the languages folder', function (): void {
    Functions\when('plugin_basename')->justReturn('uamswp-auditor-role/uamswp-auditor-role.php');
    Functions\expect('load_plugin_textdomain')
        ->once()
        ->with('uamswp-auditor-role', false, 'uamswp-auditor-role/languages');

    Plugin::load_textdomain();
});

it('records the version on activation and deletes nothing', function (): void {
    Functions\expect('add_option')->once()->with('uamswp_auditor_role_version', '0.1.0', '', false);
    Functions\expect('delete_option')->never();

    Plugin::activate();
});

it('deletes nothing on deactivation', function (): void {
    Functions\expect('wp_clear_scheduled_hook')->once()->with('uamswp_auditor_role_cron');
    Functions\expect('delete_option')->never();

    Plugin::deactivate();
});
