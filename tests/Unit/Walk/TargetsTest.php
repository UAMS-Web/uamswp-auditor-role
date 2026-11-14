<?php

/**
 * UAMSWeb\AuditorRole\Walk\Targets.
 *
 * @see tests/Walk/src/Targets.php
 */

declare(strict_types=1);

use UAMSWeb\AuditorRole\Walk\Targets;

it('opens a script slug as that script and any other slug as a page', function (string $slug, ?string $url): void {
    expect(Targets::screen_url('https://example.org/wp-admin/', $slug))->toBe($url);
})->with([
    'script' => ['edit.php', 'https://example.org/wp-admin/edit.php'],
    'script with a query' => ['edit.php?post_type=page', 'https://example.org/wp-admin/edit.php?post_type=page'],
    'encoded query' => ['edit-tags.php?taxonomy=x&amp;post_type=y', 'https://example.org/wp-admin/edit-tags.php?taxonomy=x&post_type=y'],
    'page' => ['gf_entries', 'https://example.org/wp-admin/admin.php?page=gf_entries'],
    'plugin file page' => ['relevanssi-premium/relevanssi.php', 'https://example.org/wp-admin/admin.php?page=relevanssi-premium%2Frelevanssi.php'],
    'spaces trimmed' => ['  tools.php ', 'https://example.org/wp-admin/tools.php'],
    'external link' => ['https://example.com/upgrade', null],
    'mail link' => ['mailto:a@example.org', null],
    'empty' => ['', null],
    'blank' => ['   ', null],
]);

it('visits each screen of each site, and each AJAX action once', function (): void {
    $targets = Targets::from_inventories([
        ['admin_url' => 'https://example.org/wp-admin', 'menu' => ['index.php', 'gf_entries', 'index.php', 'https://x.example', 5], 'ajax' => ['heartbeat', 'gf_save', '', 7]],
        ['admin_url' => 'https://example.org/two/wp-admin/', 'menu' => ['index.php'], 'ajax' => ['heartbeat', 'two_only']],
        ['url' => 'https://example.org/broken/', 'menu' => ['index.php']],
        ['admin_url' => 5, 'menu' => ['index.php']],
        ['admin_url' => 'https://example.org/three/wp-admin/', 'menu' => 'index.php', 'ajax' => 'heartbeat'],
        ['admin_url' => 'https://example.org/four/wp-admin/', 'menu' => ['tools.php']],
    ]);

    expect($targets)->toBe([
        ['kind' => 'screen', 'label' => 'https://example.org/wp-admin/index.php', 'url' => 'https://example.org/wp-admin/index.php', 'method' => 'GET', 'body' => []],
        ['kind' => 'screen', 'label' => 'https://example.org/wp-admin/admin.php?page=gf_entries', 'url' => 'https://example.org/wp-admin/admin.php?page=gf_entries', 'method' => 'GET', 'body' => []],
        ['kind' => 'ajax', 'label' => 'ajax:heartbeat @ https://example.org/wp-admin/', 'url' => 'https://example.org/wp-admin/admin-ajax.php', 'method' => 'POST', 'body' => ['action' => 'heartbeat']],
        ['kind' => 'ajax', 'label' => 'ajax:gf_save @ https://example.org/wp-admin/', 'url' => 'https://example.org/wp-admin/admin-ajax.php', 'method' => 'POST', 'body' => ['action' => 'gf_save']],
        ['kind' => 'screen', 'label' => 'https://example.org/two/wp-admin/index.php', 'url' => 'https://example.org/two/wp-admin/index.php', 'method' => 'GET', 'body' => []],
        ['kind' => 'ajax', 'label' => 'ajax:two_only @ https://example.org/two/wp-admin/', 'url' => 'https://example.org/two/wp-admin/admin-ajax.php', 'method' => 'POST', 'body' => ['action' => 'two_only']],
        ['kind' => 'screen', 'label' => 'https://example.org/four/wp-admin/tools.php', 'url' => 'https://example.org/four/wp-admin/tools.php', 'method' => 'GET', 'body' => []],
    ]);
});

it('makes nothing of no inventories', function (): void {
    expect(Targets::from_inventories([]))->toBe([]);
});

it('opens a hidden-list script entry as that script, and nothing else', function (string $entry, ?string $url): void {
    expect(Targets::hidden_url('https://example.org/wp-admin/', $entry))->toBe($url);
})->with([
    'script' => ['customize.php', 'https://example.org/wp-admin/customize.php'],
    'script with a query' => ['edit.php?post_type=seopress_404', 'https://example.org/wp-admin/edit.php?post_type=seopress_404'],
    'page entry' => ['page:seopress-setup', null],
    'page prefix' => ['page:genesis-cpt-archive-*', null],
    'a path' => ['network/sites.php', null],
    'a prefix before the script' => ['x customize.php', null],
    'a suffix after the script' => ['customize.php x', null],
    'not php' => ['customize.html', null],
    'no dot before php' => ['customizexphp', null],
    'empty' => ['', null],
]);

it('visits each site\'s hidden script entries after its menu, once, before its AJAX actions', function (): void {
    $targets = Targets::from_inventories([
        ['admin_url' => 'https://example.org/wp-admin/', 'menu' => ['index.php', 'edit-comments.php'], 'hidden' => ['customize.php', 'edit-comments.php', 'page:seopress-setup', 'customize.php', 7, ['options.php'], null, 'options.php'], 'ajax' => ['heartbeat']],
        ['admin_url' => 'https://example.org/two/wp-admin/', 'menu' => ['index.php'], 'hidden' => ['customize.php']],
        ['admin_url' => 'https://example.org/three/wp-admin/', 'menu' => ['index.php'], 'hidden' => 'customize.php'],
    ]);

    expect(array_column($targets, 'url'))->toBe([
        'https://example.org/wp-admin/index.php',
        'https://example.org/wp-admin/edit-comments.php',
        'https://example.org/wp-admin/customize.php',
        'https://example.org/wp-admin/options.php',
        'https://example.org/wp-admin/admin-ajax.php',
        'https://example.org/two/wp-admin/index.php',
        'https://example.org/two/wp-admin/customize.php',
        'https://example.org/three/wp-admin/index.php',
    ])
        ->and($targets[2])->toBe(['kind' => 'screen', 'label' => 'https://example.org/wp-admin/customize.php', 'url' => 'https://example.org/wp-admin/customize.php', 'method' => 'GET', 'body' => []]);
});
