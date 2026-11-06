<?php

/**
 * UAMSWeb\AuditorRole\MetaCaps, with WordPress mocked.
 *
 * @see includes/MetaCaps.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\MetaCaps;
use UAMSWeb\AuditorRole\Role;

beforeEach(function (): void {
    uamswp_auditor_role_reset();
});

function uamswp_auditor_role_user(bool $auditor): void
{
    Functions\when('get_userdata')->justReturn(uamswp_auditor_role_user_with(7, 'u', [$auditor ? 'auditor' : 'administrator']));
}

it('denies every listed meta capability to an Auditor', function (string $cap): void {
    uamswp_auditor_role_user(true);

    expect(MetaCaps::map(['activate_plugins'], $cap, 7, []))->toBe(['do_not_allow']);
})->with(MetaCaps::DENIED);

it('denies every never-granted capability to an Auditor', function (string $cap): void {
    uamswp_auditor_role_user(true);

    expect(MetaCaps::map([$cap], $cap, 7, []))->toBe(['do_not_allow']);
})->with(Role::NEVER_GRANTED);

it('denies a check that maps to a never-granted capability', function (): void {
    uamswp_auditor_role_user(true);

    expect(MetaCaps::map(['delete_others_posts'], 'some_plugin_meta_cap', 7, []))->toBe(['do_not_allow']);
});

it('passes a read capability for an Auditor', function (): void {
    uamswp_auditor_role_user(true);

    expect(MetaCaps::map(['edit_posts'], 'edit_posts', 7, []))->toBe(['edit_posts']);
});

it('lets an Auditor open only its own profile', function (): void {
    uamswp_auditor_role_user(true);

    expect(MetaCaps::map(['edit_users'], 'edit_user', 7, [7]))->toBe(['edit_users'])
        ->and(MetaCaps::map(['edit_users'], 'edit_user', 7, [8]))->toBe(['do_not_allow']);
});

it('leaves every capability alone for another role', function (string $cap): void {
    uamswp_auditor_role_user(false);

    expect(MetaCaps::map(['x'], $cap, 7, []))->toBe(['x']);
})->with(['activate_plugin', 'promote_users', 'delete_post']);

it('opens the log to administrators and never to an Auditor', function (): void {
    uamswp_auditor_role_user(false);
    expect(MetaCaps::map([MetaCaps::VIEW_LOG], MetaCaps::VIEW_LOG, 7, []))->toBe(['manage_options']);

    uamswp_auditor_role_user(true);
    expect(MetaCaps::map([MetaCaps::VIEW_LOG], MetaCaps::VIEW_LOG, 7, []))->toBe(['do_not_allow']);
});

it('adds a capability through the filter but never drops a never-granted one', function (): void {
    uamswp_auditor_role_user(true);
    Functions\when('did_action')->justReturn(0);
    Filters\expectApplied('uamswp_auditor_role_denied_caps')->andReturn(['frm_change_settings']);

    expect(MetaCaps::denied())->toContain('frm_change_settings')->toContain('promote_users')->toContain('activate_plugin');
});
