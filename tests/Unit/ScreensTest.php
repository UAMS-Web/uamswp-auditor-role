<?php

/**
 * UAMSWeb\AuditorRole\Screens, with WordPress mocked.
 *
 * The hidden list is pinned against #10 and docs/WRITES-ON-LOAD.md, written out
 * here rather than read from the class, so dropping an entry fails a test.
 * Mutation-clean under `composer mutate -- --path=includes/Screens.php`, with
 * no equivalent mutants.
 *
 * @see includes/Screens.php
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Screens;

/** The hidden screens, as #10 groups them. */
const UAMSWP_AUDITOR_ROLE_G1_HIDDEN = [
    'options.php', 'export.php', 'import.php', 'export-personal-data.php', 'erase-personal-data.php', 'site-health.php?tab=debug',
    'page:wppusher', 'page:wppusher-plugins', 'page:wppusher-plugins-create', 'page:wppusher-themes', 'page:wppusher-themes-create', 'page:wppusher-settings',
    'page:wp-mail-smtp', 'page:wp-mail-smtp-logs', 'page:wp-mail-smtp-reports', 'page:wp-mail-smtp-tools', 'page:formidable-smtp',
    'page:gf_entries', 'page:gf_settings', 'page:gf_export', 'page:gf_system_status', 'page:formidable-entries', 'page:formidable-settings', 'page:formidable-import',
    'edit.php?post_type=nf_sub', 'page:nf-submissions', 'page:nf-settings', 'page:nf-import-export', 'page:nf-system-status',
    'page:transients-manager', 'page:acfe-options',
    'page:acf-settings-updates', 'page:seopress-license', 'page:seopress-google-analytics', 'page:relevanssi-premium/relevanssi.php', 'page:facetwp', 'page:asp_updates_help', 'page:geot-settings', 'page:wp-shortpixel-settings',
    'page:whl_settings',
    'page:uamswp-gmb-export', 'page:asp_export_import', 'page:tablepress_export',
    'page:wp-short-pixel-bulk',
];

/** The dashboard widgets docs/WRITES-ON-LOAD.md removes for the role. */
const UAMSWP_AUDITOR_ROLE_G1_WIDGETS = ['dashboard_quick_press', 'wp_mail_smtp_reports_widget_pro', 'wp_mail_smtp_reports_widget_lite'];

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    Functions\when('sanitize_text_field')->returnArg();
});

it('hooks the menu and the dashboard last', function (): void {
    Actions\expectAdded('admin_menu')->once()->with([Screens::class, 'remove_menu_entries'], PHP_INT_MAX);
    Actions\expectAdded('wp_dashboard_setup')->once()->with([Screens::class, 'remove_dashboard_writers'], PHP_INT_MAX);

    Screens::boot();
});

it('hides exactly the listed screens by default', function (): void {
    expect(Screens::defaults())->toBe(UAMSWP_AUDITOR_ROLE_G1_HIDDEN)
        ->and(Screens::hidden())->toBe(UAMSWP_AUDITOR_ROLE_G1_HIDDEN)
        ->and(Screens::default_widgets())->toBe(UAMSWP_AUDITOR_ROLE_G1_WIDGETS);
});

it('matches an entry against a screen', function (string $entry, string $script, array $params, bool $matches): void {
    expect(Screens::matches($entry, $script, $params))->toBe($matches);
})->with([
    'page under admin.php' => ['page:gf_entries', 'admin.php', ['page' => 'gf_entries'], true],
    'page under another parent' => ['page:whl_settings', 'options-general.php', ['page' => 'whl_settings'], true],
    'another page' => ['page:gf_entries', 'admin.php', ['page' => 'gf_edit_forms'], false],
    'no page' => ['page:gf_entries', 'admin.php', [], false],
    'numeric page' => ['page:5', 'admin.php', ['page' => 5], true],
    'array page' => ['page:x', 'admin.php', ['page' => ['x']], false],
    'script' => ['export.php', 'export.php', [], true],
    'script with extra params' => ['export.php', 'export.php', ['download' => '1'], true],
    'another script' => ['export.php', 'import.php', [], false],
    'a script named like the query' => ['export.php', 'export.php?', [], false],
    'script and param' => ['site-health.php?tab=debug', 'site-health.php', ['tab' => 'debug'], true],
    'script, param missing' => ['site-health.php?tab=debug', 'site-health.php', [], false],
    'script, other param value' => ['edit.php?post_type=nf_sub', 'edit.php', ['post_type' => 'page'], false],
    'script, numeric param' => ['edit.php?paged=2', 'edit.php', ['paged' => 2], true],
    'script, array param' => ['edit.php?post_type=x', 'edit.php', ['post_type' => ['x']], false],
    'a second question mark stays in the value' => ['x.php?tab=a?b', 'x.php', ['tab' => 'a?b'], true],
    'the whole query is required' => ['x.php?a=1&b=2', 'x.php', ['a' => '1'], false],
]);

it('reads a menu slug both as a script and as a page', function (string $slug, bool $hidden): void {
    expect(Screens::is_hidden_slug($slug))->toBe($hidden);
})->with([
    ['export.php', true],
    ['edit.php?post_type=nf_sub', true],
    'an encoded ampersand' => ['edit.php?foo=1&amp;post_type=nf_sub', true],
    ['edit.php?post_type=page', false],
    ['gf_entries', true],
    ['relevanssi-premium/relevanssi.php', true],
    ['wppusher', true],
    ['tablepress', false],
    ['options-general.php', false],
    ['site-health.php', false],
]);

it('labels a screen by script and page', function (): void {
    expect(Screens::label('admin.php', ['page' => 'gf_entries']))->toBe('admin.php?page=gf_entries')
        ->and(Screens::label('admin.php', ['page' => 5]))->toBe('admin.php?page=5')
        ->and(Screens::label('admin.php', ['page' => ['x']]))->toBe('admin.php')
        ->and(Screens::label('export.php', []))->toBe('export.php');
});

it('removes hidden entries from the menu for an Auditor, leaving malformed items alone', function (): void {
    uamswp_auditor_role_as(true);

    $GLOBALS['menu'] = [
        10 => ['Pusher', 'manage_options', 'wppusher'],
        20 => ['Tables', 'manage_options', 'tablepress'],
        30 => ['No slug', 'read'],
        40 => ['Not a string', 'read', 5],
        50 => 'not an item',
    ];
    $GLOBALS['submenu'] = [
        'tools.php' => [5 => ['Export', 'export', 'export.php'], 6 => ['Cron', 'manage_options', 'wp-crontrol'], 7 => ['Short']],
        'broken' => 'not a list',
    ];

    Screens::remove_menu_entries();

    expect(array_keys($GLOBALS['menu']))->toBe([20, 30, 40, 50])
        ->and(array_keys($GLOBALS['submenu']['tools.php']))->toBe([6, 7])
        ->and($GLOBALS['submenu']['broken'])->toBe('not a list');
});

it('leaves a menu that is not a list alone', function (): void {
    uamswp_auditor_role_as(true);

    $GLOBALS['menu'] = null;
    $GLOBALS['submenu'] = null;

    Screens::remove_menu_entries();

    expect($GLOBALS['menu'])->toBeNull()->and($GLOBALS['submenu'])->toBeNull();
});

it('leaves the menu alone for another role', function (): void {
    uamswp_auditor_role_as(false);

    $GLOBALS['menu'] = [10 => ['Pusher', 'manage_options', 'wppusher']];
    $GLOBALS['submenu'] = ['tools.php' => [5 => ['Export', 'export', 'export.php']]];

    Screens::remove_menu_entries();

    expect($GLOBALS['menu'])->toHaveCount(1)->and($GLOBALS['submenu']['tools.php'])->toHaveCount(1);
});

it('takes the list from the filter, keeping only names, as a list', function (): void {
    Filters\expectApplied(Screens::FILTER)->once()->with(UAMSWP_AUDITOR_ROLE_G1_HIDDEN)->andReturn([7, 'page:only-this', null]);

    expect(Screens::hidden())->toBe(['page:only-this']);
});

it('keeps the defaults when the filter returns something other than a list', function (): void {
    Filters\expectApplied(Screens::FILTER)->once()->andReturn('page:only-this');

    expect(Screens::hidden())->toBe(UAMSWP_AUDITOR_ROLE_G1_HIDDEN);
});

it('removes each hidden widget from every dashboard column for an Auditor', function (): void {
    uamswp_auditor_role_as(true);

    $removed = [];
    Functions\when('remove_meta_box')->alias(static function (string $id, string $screen, string $context) use (&$removed): void {
        $removed[] = "{$id} {$screen} {$context}";
    });

    Screens::remove_dashboard_writers();

    $expected = [];

    foreach (UAMSWP_AUDITOR_ROLE_G1_WIDGETS as $widget) {
        foreach (['normal', 'side', 'column3', 'column4'] as $context) {
            $expected[] = "{$widget} dashboard {$context}";
        }
    }

    expect($removed)->toBe($expected);
});

it('takes the widgets from the filter, skipping anything that is not a name', function (): void {
    uamswp_auditor_role_as(true);
    Filters\expectApplied('uamswp_auditor_role_hidden_dashboard_widgets')->once()->with(UAMSWP_AUDITOR_ROLE_G1_WIDGETS)->andReturn([5, 'my_widget']);

    $removed = [];
    Functions\when('remove_meta_box')->alias(static function (string $id) use (&$removed): void {
        $removed[] = $id;
    });

    Screens::remove_dashboard_writers();

    expect($removed)->toBe(['my_widget', 'my_widget', 'my_widget', 'my_widget']);
});

it('keeps the default widgets when the filter returns something other than a list', function (): void {
    uamswp_auditor_role_as(true);
    Filters\expectApplied('uamswp_auditor_role_hidden_dashboard_widgets')->once()->andReturn('my_widget');

    $removed = [];
    Functions\when('remove_meta_box')->alias(static function (string $id) use (&$removed): void {
        $removed[] = $id;
    });

    Screens::remove_dashboard_writers();

    expect(array_values(array_unique($removed)))->toBe(UAMSWP_AUDITOR_ROLE_G1_WIDGETS);
});

it('leaves the dashboard alone for another role', function (): void {
    uamswp_auditor_role_as(false);
    Functions\expect('remove_meta_box')->never();

    Screens::remove_dashboard_writers();
});
