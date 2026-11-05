<?php

/**
 * UAMSWeb\AuditorRole\Screens, with WordPress mocked.
 *
 * @see includes/Screens.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Screens;

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    Functions\when('sanitize_text_field')->returnArg();
});

it('matches an entry against a screen', function (string $entry, string $script, array $params, bool $matches): void {
    expect(Screens::matches($entry, $script, $params))->toBe($matches);
})->with([
    'page under admin.php' => ['page:gf_entries', 'admin.php', ['page' => 'gf_entries'], true],
    'page under another parent' => ['page:whl_settings', 'options-general.php', ['page' => 'whl_settings'], true],
    'another page' => ['page:gf_entries', 'admin.php', ['page' => 'gf_edit_forms'], false],
    'no page' => ['page:gf_entries', 'admin.php', [], false],
    'script' => ['export.php', 'export.php', [], true],
    'script with extra params' => ['export.php', 'export.php', ['download' => '1'], true],
    'another script' => ['export.php', 'import.php', [], false],
    'script and param' => ['site-health.php?tab=debug', 'site-health.php', ['tab' => 'debug'], true],
    'script, param missing' => ['site-health.php?tab=debug', 'site-health.php', [], false],
    'script, other param value' => ['edit.php?post_type=nf_sub', 'edit.php', ['post_type' => 'page'], false],
    'array param' => ['page:x', 'admin.php', ['page' => ['x']], false],
]);

it('reads a menu slug both as a script and as a page', function (string $slug, bool $hidden): void {
    expect(Screens::is_hidden_slug($slug))->toBe($hidden);
})->with([
    ['export.php', true],
    ['edit.php?post_type=nf_sub', true],
    ['edit.php?post_type=page', false],
    ['gf_entries', true],
    ['relevanssi-premium/relevanssi.php', true],
    ['wppusher', true],
    ['tablepress', false],
    ['options-general.php', false],
]);

it('removes hidden entries from the menu for an Auditor', function (): void {
    uamswp_auditor_role_as(true);

    $GLOBALS['menu'] = [10 => ['Pusher', 'manage_options', 'wppusher'], 20 => ['Tables', 'manage_options', 'tablepress']];
    $GLOBALS['submenu'] = ['tools.php' => [5 => ['Export', 'export', 'export.php'], 6 => ['Cron', 'manage_options', 'wp-crontrol']]];

    Screens::remove_menu_entries();

    expect(array_column($GLOBALS['menu'], 2))->toBe(['tablepress'])
        ->and(array_column($GLOBALS['submenu']['tools.php'], 2))->toBe(['wp-crontrol']);
});

it('leaves the menu alone for another role', function (): void {
    uamswp_auditor_role_as(false);

    $GLOBALS['menu'] = [10 => ['Pusher', 'manage_options', 'wppusher']];
    $GLOBALS['submenu'] = [];

    Screens::remove_menu_entries();

    expect($GLOBALS['menu'])->toHaveCount(1);
});

it('takes the list from the filter', function (): void {
    Filters\expectApplied(Screens::FILTER)->andReturn(['page:only-this', 7]);

    expect(Screens::hidden())->toBe(['page:only-this']);
});
