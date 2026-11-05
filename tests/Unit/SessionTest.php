<?php

/**
 * UAMSWeb\AuditorRole\Session, with WordPress mocked.
 *
 * @see includes/Session.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Session;

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    Functions\when('wp_unslash')->returnArg();
    Functions\when('sanitize_key')->alias('strtolower');
});

it('follows the current user once WordPress has set it', function (): void {
    uamswp_auditor_role_as(true);
    expect(Session::is_auditor())->toBeTrue();

    uamswp_auditor_role_as(false);
    expect(Session::is_auditor())->toBeFalse();
});

it('reads the auth cookies before WordPress sets the current user', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    Functions\when('did_action')->justReturn(0);
    Functions\expect('wp_validate_auth_cookie')->twice()->andReturn(false, 7);
    Functions\when('get_userdata')->justReturn(uamswp_auditor_role_user_with(7, 'audit', ['auditor']));

    expect(Session::is_auditor())->toBeTrue()
        ->and(Session::is_auditor())->toBeTrue();
});

it('is no session without a valid cookie', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    Functions\when('did_action')->justReturn(0);
    Functions\when('wp_validate_auth_cookie')->justReturn(false);

    expect(Session::is_auditor())->toBeFalse();
});

it('exempts sign-in and password-reset actions on wp-login.php', function (string $action, bool $exempt): void {
    uamswp_auditor_role_as(true, 'wp-login.php');
    $_REQUEST['action'] = $action;

    expect(Session::is_exempt_login())->toBe($exempt)
        ->and(Session::is_auditor())->toBe(! $exempt);
})->with([
    ['', true],
    ['login', true],
    ['lostpassword', true],
    ['rp', true],
    ['resetpass', true],
    ['logout', false],
    ['confirm_admin_email', false],
    ['register', false],
]);
