<?php

/**
 * UAMSWeb\AuditorRole\Role, with WordPress mocked.
 *
 * The capability lists are pinned against the contract in #5 and the two
 * additions recorded in docs/DECISIONS.md (`upload_files`, `manage_categories`),
 * written out here rather than read from the class, so dropping an entry from
 * either list fails a test.
 *
 * Mutation-clean under `composer mutate -- --path=includes/Role.php`, with no
 * equivalent mutants.
 *
 * @see includes/Role.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\Role;

/** The granted list, as #5 and docs/DECISIONS.md state it. */
const UAMSWP_AUDITOR_ROLE_SPEC_GRANTED = [
    'read',
    'read_private_posts',
    'read_private_pages',
    'edit_posts',
    'edit_pages',
    'upload_files',
    'manage_categories',
    'activate_plugins',
    'switch_themes',
    'edit_theme_options',
    'view_site_health_checks',
    'manage_options',
    'edit_physicians',
    'read_private_physicians',
    'edit_locations',
    'read_private_locations',
];

beforeEach(function (): void {
    uamswp_auditor_role_reset();
});

it('grants exactly the capabilities the contract lists, in order', function (): void {
    expect(Role::granted())->toBe(UAMSWP_AUDITOR_ROLE_SPEC_GRANTED)
        ->and(Role::capabilities())->toBe(UAMSWP_AUDITOR_ROLE_SPEC_GRANTED);
});

it('never grants a capability the contract forbids', function (string $capability): void {
    expect(Role::is_never_granted($capability))->toBeTrue();

    Filters\expectApplied(Role::FILTER)->once()->andReturn([$capability, 'view_reports']);

    expect(Role::capabilities())->not->toContain($capability)->toContain('view_reports');
})->with([
    // Named in #5.
    'edit_plugins', 'edit_themes', 'edit_users', 'create_users', 'promote_users', 'export', 'import',
    'unfiltered_html', 'unfiltered_upload', 'list_users',
    // Named in docs/DECISIONS.md and the class: upload-and-install, removal, publishing, resume, network.
    'upload_plugins', 'upload_themes', 'edit_files', 'remove_users', 'add_users', 'publish_posts', 'publish_pages',
    'resume_plugins', 'resume_themes', 'create_sites', 'manage_sites', 'manage_network', 'manage_network_users',
    'manage_network_plugins', 'manage_network_themes', 'manage_network_options', 'upgrade_network', 'setup_network',
    // The prefix rule: core's and any plugin's install, update and delete capabilities.
    'install_plugins', 'install_themes', 'install_languages', 'update_plugins', 'update_core', 'delete_users',
    'delete_posts', 'delete_others_pages', 'delete_site', 'install_anything', 'update_foo', 'delete_bar',
]);

it('keeps the prefix rule to the start of a name', function (string $capability): void {
    expect(Role::is_never_granted($capability))->toBeFalse();
})->with(['upload_files', 'read', 'view_delete_log', 'can_update_x', 'installer_view']);

it('adds a capability through the filter, after the granted list', function (): void {
    Filters\expectApplied(Role::FILTER)->once()->with(UAMSWP_AUDITOR_ROLE_SPEC_GRANTED)->andReturn(['view_reports']);

    expect(Role::capabilities())->toBe([...UAMSWP_AUDITOR_ROLE_SPEC_GRANTED, 'view_reports']);
});

it('keeps the granted list when the filter drops from it', function (): void {
    Filters\expectApplied(Role::FILTER)->once()->andReturn([]);

    expect(Role::capabilities())->toBe(UAMSWP_AUDITOR_ROLE_SPEC_GRANTED);
});

it('drops empty names and values that are not names from the filter, and lists each name once', function (): void {
    Filters\expectApplied(Role::FILTER)->once()->andReturn(['', 'view_reports', 'read', 7, null, ['x'], 'view_reports']);

    expect(Role::capabilities())->toBe([...UAMSWP_AUDITOR_ROLE_SPEC_GRANTED, 'view_reports']);
});

it('ignores a filter that returns something other than a list', function (): void {
    Filters\expectApplied(Role::FILTER)->once()->andReturn('view_reports');

    expect(Role::capabilities())->toBe(UAMSWP_AUDITOR_ROLE_SPEC_GRANTED);
});

it('stamps the version and a hash of the capability list', function (): void {
    expect(Role::stamp())->toBe(Role::VERSION.':'.md5(implode(',', UAMSWP_AUDITOR_ROLE_SPEC_GRANTED)));
});

it('changes the stamp when the capability list changes', function (): void {
    $before = Role::stamp();
    Filters\expectApplied(Role::FILTER)->andReturn(['view_reports']);

    expect(Role::stamp())->not->toBe($before)->toStartWith(Role::VERSION.':');
});

it('writes the role and its stamp, every capability true', function (): void {
    Functions\when('__')->returnArg();
    Functions\expect('remove_role')->once()->with(Role::NAME)->ordered();
    Functions\expect('add_role')->once()->with(Role::NAME, 'Auditor', array_fill_keys(UAMSWP_AUDITOR_ROLE_SPEC_GRANTED, true))->ordered();
    Functions\expect('update_option')->once()->with(Role::OPTION, Role::stamp(), true)->ordered();

    Role::register();
});

it('rewrites the role inside the guard bypass when the stamp is stale', function (): void {
    uamswp_auditor_role_as(true);
    Functions\when('get_option')->justReturn('0:stale');
    Functions\when('get_role')->justReturn(new stdClass);
    Functions\when('__')->returnArg();
    Functions\when('remove_role')->justReturn();
    Functions\expect('add_role')->once()->andReturnUsing(static function (): void {
        // The plugin's own write: inside register(), the guard lets it pass.
        expect(DatabaseGuard::filter('UPDATE wp_options SET x = 1'))->toBe('UPDATE wp_options SET x = 1');
    });
    Functions\expect('update_option')->once();

    Role::maybe_register();

    expect(DatabaseGuard::filter('UPDATE wp_options SET x = 1'))->toBe('');
});

it('rewrites the role when the stamp is current but the role is missing', function (): void {
    Functions\when('get_option')->justReturn(Role::stamp());
    Functions\when('get_role')->justReturn();
    Functions\when('__')->returnArg();
    Functions\when('remove_role')->justReturn();
    Functions\expect('add_role')->once();
    Functions\expect('update_option')->once();

    Role::maybe_register();
});

it('leaves the role alone when the stamp is current and the role exists', function (): void {
    Functions\when('get_option')->justReturn(Role::stamp());
    Functions\when('get_role')->justReturn(new stdClass);
    Functions\expect('remove_role')->never();
    Functions\expect('add_role')->never();
    Functions\expect('update_option')->never();

    Role::maybe_register();
});

it('recognizes an Auditor by role on the current site', function (): void {
    expect(Role::user_is_auditor(uamswp_auditor_role_user_with(1, 'a', ['auditor'])))->toBeTrue()
        ->and(Role::user_is_auditor(uamswp_auditor_role_user_with(1, 'a', ['editor', 'auditor'])))->toBeTrue()
        ->and(Role::user_is_auditor(uamswp_auditor_role_user_with(1, 'a', ['administrator'])))->toBeFalse()
        ->and(Role::user_is_auditor(uamswp_auditor_role_user_with(1, 'a', [])))->toBeFalse()
        ->and(Role::user_is_auditor(false))->toBeFalse()
        ->and(Role::user_is_auditor(null))->toBeFalse();
});
