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
    'options.php', 'export.php', 'import.php', 'export-personal-data.php', 'erase-personal-data.php', 'site-health.php?tab=debug', 'options-connectors.php', 'customize.php',
    'edit-comments.php', 'site-editor.php', 'font-library.php',
    'page:wppusher', 'page:wppusher-plugins', 'page:wppusher-plugins-create', 'page:wppusher-themes', 'page:wppusher-themes-create', 'page:wppusher-settings',
    'page:wp-mail-smtp', 'page:wp-mail-smtp-logs', 'page:wp-mail-smtp-reports', 'page:wp-mail-smtp-tools', 'page:formidable-smtp',
    'page:gf_entries', 'page:gf_settings', 'page:gf_export', 'page:gf_system_status', 'page:formidable-entries', 'page:formidable-settings', 'page:formidable-import',
    'edit.php?post_type=nf_sub', 'page:nf-submissions', 'page:nf-settings', 'page:nf-import-export', 'page:nf-system-status',
    'page:transients-manager', 'page:acfe-options',
    'page:acf-settings-updates', 'page:seopress-license', 'page:seopress-google-analytics', 'page:relevanssi-premium/relevanssi.php', 'page:facetwp', 'page:asp_updates_help', 'page:geot-settings', 'page:wp-shortpixel-settings',
    'page:whl_settings',
    'page:uamswp-gmb-export', 'page:asp_export_import', 'page:tablepress_export',
    'page:wp-short-pixel-bulk',
    'page:better-image-sizes/better-image-sizes.php',
    'page:regenerate-thumbnails',
    'edit.php?post_type=acf-field-group', 'edit.php?post_type=acf-post-type', 'edit.php?post_type=acf-taxonomy', 'edit.php?post_type=acf-ui-options-page',
    'edit-tags.php?taxonomy=acf-field-group-category', 'page:acf-tools', 'page:acfe-settings',
    'page:asp_main_settings', 'page:asp_index_table', 'page:asp_priorities', 'page:asp_statistics', 'page:asp_analytics', 'page:asp_cache_settings', 'page:asp_performance', 'page:asp_compatibility_settings', 'page:asp_maintenance',
    'page:ef-settings', 'page:ef-calendar-settings', 'page:ef-custom-status-settings', 'page:ef-dashboard-settings', 'page:ef-editorial-comments-settings', 'page:ef-editorial-metadata-settings', 'page:ef-notifications-settings', 'page:ef-story-budget-settings', 'page:ef-user-groups-settings',
    'page:seopress-option', 'page:seopress-titles', 'page:seopress-xml-sitemap', 'page:seopress-social', 'page:seopress-instant-indexing', 'page:seopress-advanced', 'page:seopress-import-export', 'page:seopress-pro-page', 'page:seopress-schemas', 'page:seopress-redirections', 'page:seopress-broken-links', 'page:seopress-setup',
    'edit.php?post_type=seopress_404', 'edit.php?post_type=seopress_bot', 'edit.php?post_type=seopress_schemas',
    'page:genesis', 'page:genesis-import-export', 'page:seo-settings', 'page:genesis-getting-started', 'page:genesis-cpt-archive-*',
    'page:uamswp-settings', 'page:uams-search-site',
    'page:wp-accessibility', 'page:wp-accessibility-overlay', 'page:wp-accessibility-admin', 'page:wp-accessibility-help',
    'page:mla-settings-menu*',
];

/** The dashboard widgets docs/WRITES-ON-LOAD.md removes for the role. */
const UAMSWP_AUDITOR_ROLE_G1_WIDGETS = ['dashboard_quick_press', 'wp_mail_smtp_reports_widget_pro', 'wp_mail_smtp_reports_widget_lite'];

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    Functions\when('sanitize_text_field')->returnArg();
});

it('hooks the menu, the dashboard and the Plugins screen filters last', function (): void {
    Actions\expectAdded('admin_menu')->once()->with([Screens::class, 'remove_menu_entries'], PHP_INT_MAX);
    Actions\expectAdded('admin_menu')->once()->with([Screens::class, 'open_plugins_menu'], PHP_INT_MAX);
    Actions\expectAdded('wp_dashboard_setup')->once()->with([Screens::class, 'remove_dashboard_writers'], PHP_INT_MAX);
    Filters\expectAdded('bulk_actions-plugins')->once()->with([Screens::class, 'remove_plugin_bulk_actions'], PHP_INT_MAX);
    Filters\expectAdded('show_network_active_plugins')->once()->with([Screens::class, 'show_network_active_plugins'], PHP_INT_MAX);

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
    'prefix, a page under it' => ['page:genesis-cpt-archive-*', 'edit.php', ['page' => 'genesis-cpt-archive-post'], true],
    'prefix, the prefix itself' => ['page:mla-settings-menu*', 'options-general.php', ['page' => 'mla-settings-menu'], true],
    'prefix, another page' => ['page:mla-settings-menu*', 'upload.php', ['page' => 'mla-menu'], false],
    'prefix, no page' => ['page:mla-settings-menu*', 'options-general.php', [], false],
    'prefix, one character short of it' => ['page:mla-settings-menu*', 'options-general.php', ['page' => 'mla-settings-men'], false],
    'an exact entry is not a prefix' => ['page:genesis', 'admin.php', ['page' => 'genesis-import-export'], false],
    'a star mid-slug is literal' => ['page:a*b', 'admin.php', ['page' => 'a*b'], true],
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

it('offers an Auditor no bulk actions on the Plugins screen', function (): void {
    uamswp_auditor_role_as(true, 'plugins.php');

    expect(Screens::remove_plugin_bulk_actions(['activate-selected' => 'Activate', 'deactivate-selected' => 'Deactivate']))->toBe([]);
});

it('leaves the Plugins bulk actions alone for another role', function (): void {
    uamswp_auditor_role_as(false, 'plugins.php');

    expect(Screens::remove_plugin_bulk_actions(['activate-selected' => 'Activate']))->toBe(['activate-selected' => 'Activate']);
});

it('lists network-active plugins for an Auditor, whatever the answer so far', function (mixed $show): void {
    uamswp_auditor_role_as(true, 'plugins.php');

    expect(Screens::show_network_active_plugins($show))->toBeTrue();
})->with([
    'hidden' => [false],
    'shown' => [true],
    'not a boolean' => [null],
]);

it('leaves the network-active answer alone for another role', function (mixed $show): void {
    uamswp_auditor_role_as(false, 'plugins.php');

    expect(Screens::show_network_active_plugins($show))->toBe($show);
})->with([
    'hidden' => [false],
    'shown' => [true],
    'not a boolean' => [null],
]);

it('files core\'s Plugins menu entries under read for an Auditor, and nothing else', function (): void {
    uamswp_auditor_role_as(true);
    $GLOBALS['menu'] = [
        60 => ['Appearance', 'switch_themes', 'themes.php'],
        65 => ['Plugins', 'activate_plugins', 'plugins.php'],
        66 => ['Other', 'activate_plugins', 'other.php'],
        67 => 'not an item',
    ];
    $GLOBALS['submenu'] = [
        'plugins.php' => [
            5 => ['Installed Plugins', 'activate_plugins', 'plugins.php'],
            10 => ['Add Plugin', 'install_plugins', 'plugin-install.php'],
            12 => ['Settings', 'manage_options', 'plugins.php'],
        ],
        'tools.php' => [5 => ['Tools', 'activate_plugins', 'plugins.php']],
    ];

    Screens::open_plugins_menu();

    expect(array_column(array_filter($GLOBALS['menu'], is_array(...)), 1))->toBe(['switch_themes', 'read', 'activate_plugins'])
        ->and($GLOBALS['menu'][67])->toBe('not an item')
        ->and(array_column($GLOBALS['submenu']['plugins.php'], 1))->toBe(['read', 'install_plugins', 'manage_options'])
        ->and($GLOBALS['submenu']['tools.php'][5][1])->toBe('activate_plugins');
});

it('leaves the Plugins menu entries alone for another role', function (): void {
    uamswp_auditor_role_as(false);
    $GLOBALS['menu'] = [65 => ['Plugins', 'activate_plugins', 'plugins.php']];
    $GLOBALS['submenu'] = ['plugins.php' => [5 => ['Installed Plugins', 'activate_plugins', 'plugins.php']]];

    Screens::open_plugins_menu();

    expect($GLOBALS['menu'][65][1])->toBe('activate_plugins')
        ->and($GLOBALS['submenu']['plugins.php'][5][1])->toBe('activate_plugins');
});

it('opens the Plugins menu with no submenu, and with none at all', function (mixed $submenu): void {
    uamswp_auditor_role_as(true);
    $GLOBALS['menu'] = [65 => ['Plugins', 'activate_plugins', 'plugins.php']];
    $GLOBALS['submenu'] = $submenu;

    Screens::open_plugins_menu();

    expect($GLOBALS['menu'][65][1])->toBe('read')
        ->and($GLOBALS['submenu'])->toBe($submenu);
})->with([
    'no menus' => [null],
    'no Plugins submenu' => [['tools.php' => []]],
    'a Plugins submenu that is not a list' => [['plugins.php' => 'x']],
]);
