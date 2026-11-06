<?php

/**
 * UAMSWeb\AuditorRole\MetaCaps, with WordPress mocked.
 *
 * The default list is pinned against #7 and the class's documented additions,
 * written out here rather than read from the class, so dropping an entry fails
 * a test. Mutation-clean under `composer mutate -- --path=includes/MetaCaps.php`,
 * with no equivalent mutants.
 *
 * @see includes/MetaCaps.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\MetaCaps;
use UAMSWeb\AuditorRole\Role;

/** The acting meta capabilities, as #7 and the class state them. */
const UAMSWP_AUDITOR_ROLE_SPEC_DENIED = [
    'activate_plugin',
    'deactivate_plugin',
    'deactivate_plugins',
    'resume_plugin',
    'resume_theme',
    'switch_theme',
    'publish_post',
    'remove_user',
    'promote_user',
    'create_app_password',
    'edit_app_password',
    'erase_others_personal_data',
    'export_others_personal_data',
    'edit_css',
    'upgrade_db',
];

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    Functions\when('did_action')->justReturn(0);
});

function uamswp_auditor_role_user(bool $auditor): void
{
    Functions\when('get_userdata')->justReturn(uamswp_auditor_role_user_with(7, 'u', [$auditor ? 'auditor' : 'administrator']));
}

it('hooks map_meta_cap last, with all four arguments', function (): void {
    Filters\expectAdded('map_meta_cap')->once()->with([MetaCaps::class, 'map'], PHP_INT_MAX, 4);

    MetaCaps::boot();
});

it('denies exactly the listed meta capabilities by default', function (): void {
    expect(MetaCaps::defaults())->toBe(UAMSWP_AUDITOR_ROLE_SPEC_DENIED)
        ->and(MetaCaps::denied())->toBe(UAMSWP_AUDITOR_ROLE_SPEC_DENIED);
});

it('denies every listed meta capability to an Auditor', function (string $cap): void {
    uamswp_auditor_role_user(true);

    expect(MetaCaps::map(['activate_plugins'], $cap, 7, []))->toBe(['do_not_allow']);
})->with(UAMSWP_AUDITOR_ROLE_SPEC_DENIED);

it('denies every never-granted capability to an Auditor, by name or by prefix', function (string $cap): void {
    uamswp_auditor_role_user(true);

    expect(MetaCaps::map([$cap], $cap, 7, []))->toBe(['do_not_allow']);
})->with([...Role::never_granted(), 'delete_post', 'delete_term', 'update_php', 'install_languages', 'delete_app_passwords']);

it('denies a check that maps to a never-granted capability', function (): void {
    uamswp_auditor_role_user(true);

    expect(MetaCaps::map(['read', 'delete_others_posts'], 'some_plugin_meta_cap', 7, []))->toBe(['do_not_allow']);
});

it('passes a read capability for an Auditor, as a list', function (): void {
    uamswp_auditor_role_user(true);

    expect(MetaCaps::map(['a' => 'edit_posts', 'b' => 7], 'edit_posts', 7, []))->toBe(['edit_posts', 7]);
});

it('lets an Auditor open only its own profile', function (mixed $args, array $expected): void {
    uamswp_auditor_role_user(true);

    expect(MetaCaps::map(['edit_users'], 'edit_user', 7, $args))->toBe($expected);
})->with([
    'itself' => [[7], ['edit_users']],
    'itself, as a string' => [['7'], ['edit_users']],
    'another user' => [[8], ['do_not_allow']],
    'no user named' => [[], ['do_not_allow']],
    'arguments that are not a list' => ['7', ['do_not_allow']],
]);

it('leaves every capability alone for another role, as a list', function (string $cap): void {
    uamswp_auditor_role_user(false);

    expect(MetaCaps::map(['k' => 'x'], $cap, 7, []))->toBe(['x']);
})->with(['activate_plugin', 'promote_users', 'delete_post', 'edit_user']);

it('leaves a check alone when the capability or the user is not what WordPress passes', function (mixed $cap, mixed $user_id): void {
    uamswp_auditor_role_user(true);

    expect(MetaCaps::map(['k' => 'x'], $cap, $user_id, []))->toBe(['x']);
})->with([
    'capability not a string' => [5, 7],
    'user not an integer' => ['activate_plugin', '7'],
]);

it('treats caps that are not a list as none', function (): void {
    uamswp_auditor_role_user(false);

    expect(MetaCaps::map('read', 'read', 7, []))->toBe([]);
});

it('opens the log to administrators and never to an Auditor', function (): void {
    uamswp_auditor_role_user(false);
    expect(MetaCaps::map([MetaCaps::VIEW_LOG], MetaCaps::VIEW_LOG, 7, []))->toBe(['manage_options']);

    uamswp_auditor_role_user(true);
    expect(MetaCaps::map([MetaCaps::VIEW_LOG], MetaCaps::VIEW_LOG, 7, []))->toBe(['do_not_allow']);
});

it('adds a capability through the filter but never drops a default', function (): void {
    Filters\expectApplied('uamswp_auditor_role_denied_caps')->once()->with(UAMSWP_AUDITOR_ROLE_SPEC_DENIED)
        // A default repeated before a new name, so dropping the duplicate leaves a gap in the keys.
        ->andReturn([5, 'activate_plugin', 'frm_change_settings', 'frm_change_settings', null]);

    expect(MetaCaps::denied())->toBe([...UAMSWP_AUDITOR_ROLE_SPEC_DENIED, 'frm_change_settings']);
});

it('ignores a filter that returns something other than a list', function (): void {
    Filters\expectApplied('uamswp_auditor_role_denied_caps')->once()->andReturn('frm_change_settings');

    expect(MetaCaps::denied())->toBe(UAMSWP_AUDITOR_ROLE_SPEC_DENIED);
});

it('holds the list once init has run, and not before', function (): void {
    Filters\expectApplied('uamswp_auditor_role_denied_caps')->twice()->andReturn(['first'], ['second']);

    expect(MetaCaps::denied())->toContain('first');
    $second = MetaCaps::denied();
    expect($second)->toContain('second');
    expect($second)->not->toContain('first');

    MetaCaps::reset();
    Functions\when('did_action')->justReturn(1);
    Filters\expectApplied('uamswp_auditor_role_denied_caps')->once()->andReturn(['held']);

    expect(MetaCaps::denied())->toContain('held');
    expect(MetaCaps::denied())->toContain('held');
});
