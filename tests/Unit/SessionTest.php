<?php

/**
 * UAMSWeb\AuditorRole\Session, with WordPress mocked.
 *
 * Mutation-clean under `composer mutate -- --path=includes/Session.php`, with
 * no equivalent mutants. The exempt list is pinned against the class's
 * documented actions, written out here.
 *
 * @see includes/Session.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Session;

/**
 * did_action() answering per hook: whether WordPress has set the current user,
 * and whether the pluggable functions are loaded.
 */
function uamswp_auditor_role_g2_actions(bool $user_set, bool $pluggable = true): void
{
    Functions\when('did_action')->alias(static fn (string $hook): int => match ($hook) {
        'set_current_user' => $user_set ? 1 : 0,
        'plugins_loaded' => $pluggable ? 1 : 0,
        default => 0,
    });
}

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    unset($_SERVER['SCRIPT_NAME']);
    Functions\when('wp_unslash')->returnArg();
    Functions\when('sanitize_key')->alias('strtolower');
    Functions\when('sanitize_text_field')->returnArg();
});

it('lists exactly the exempt sign-in actions', function (): void {
    expect(Session::exempt_login_actions())->toBe(['login', 'lostpassword', 'retrievepassword', 'rp', 'resetpass', 'postpass', 'switch_to_olduser']);
});

it('follows the current user once WordPress has set it', function (): void {
    uamswp_auditor_role_as(true);
    expect(Session::is_auditor())->toBeTrue();

    uamswp_auditor_role_as(false);
    expect(Session::is_auditor())->toBeFalse();
});

it('reads the auth cookie first, then the logged-in cookie, and holds the answer', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false);
    Functions\expect('wp_validate_auth_cookie')->once()->with()->ordered()->andReturn(false);
    Functions\expect('wp_validate_auth_cookie')->once()->with('', 'logged_in')->ordered()->andReturn(7);
    Functions\expect('get_userdata')->once()->with(7)->andReturn(uamswp_auditor_role_user_with(7, 'audit', ['auditor']));

    expect(Session::is_auditor())->toBeTrue()
        ->and(Session::is_auditor())->toBeTrue();
});

it('takes the auth cookie alone when it is valid', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false);
    // Once for is_auditor(), which holds its answer, and once for user_id(), which does not.
    Functions\expect('wp_validate_auth_cookie')->twice()->with()->andReturn(5);
    Functions\when('get_userdata')->justReturn(uamswp_auditor_role_user_with(5, 'admin', ['administrator']));

    expect(Session::is_auditor())->toBeFalse()
        ->and(Session::user_id())->toBe(5);
});

it('is no session without a valid cookie, and never looks the user up', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false);
    Functions\when('wp_validate_auth_cookie')->justReturn(false);
    Functions\expect('get_userdata')->never();

    expect(Session::is_auditor())->toBeFalse()
        ->and(Session::user_id())->toBe(0);
});

it('reads no cookie before the pluggable functions load', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false, false);
    Functions\expect('wp_validate_auth_cookie')->never();

    expect(Session::user_id())->toBe(0)
        ->and(Session::is_auditor())->toBeFalse();
});

it('treats a cookie answer that is not an ID as none', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false);
    Functions\when('wp_validate_auth_cookie')->justReturn('7');

    expect(Session::user_id())->toBe(0);
});

it('answers no while the cookie is read and while its user is looked up, and yes after', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false);
    $during = [];
    $depth = 0;

    // A re-entry fails at once, so a mutant that drops the guard is killed by
    // this assertion instead of recursing until the mutation run times out.
    $ask = static function () use (&$during, &$depth): void {
        if ($depth > 0) {
            throw new RuntimeException('is_auditor() re-entered the cookie read');
        }

        $depth++;

        try {
            $during[] = Session::is_auditor();
        } finally {
            $depth--;
        }
    };

    Functions\when('wp_validate_auth_cookie')->alias(static function () use ($ask): int {
        $ask();

        return 7;
    });
    Functions\when('get_userdata')->alias(static function () use ($ask): WP_User {
        $ask();

        return uamswp_auditor_role_user_with(7, 'audit', ['auditor']);
    });

    expect(Session::is_auditor())->toBeTrue()
        ->and($during)->toBe([false, false]);
});

it('clears the flag after a cookie read on its own', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false);
    Functions\when('wp_validate_auth_cookie')->justReturn(7);
    Functions\when('get_userdata')->justReturn(uamswp_auditor_role_user_with(7, 'audit', ['auditor']));

    expect(Session::user_id())->toBe(7)
        ->and(Session::is_auditor())->toBeTrue();
});

it('names the current user once WordPress has set it, and the cookie before', function (): void {
    uamswp_auditor_role_g2_actions(true);
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('wp_validate_auth_cookie')->justReturn(9);

    expect(Session::user_id())->toBe(3);

    uamswp_auditor_role_g2_actions(false);

    expect(Session::user_id())->toBe(9);
});

it('names the script from $pagenow, or from SCRIPT_NAME when $pagenow is unset or empty', function (mixed $pagenow, ?string $script_name, string $script): void {
    $GLOBALS['pagenow'] = $pagenow;

    if ($script_name !== null) {
        $_SERVER['SCRIPT_NAME'] = $script_name;
    }

    expect(Session::script())->toBe($script);
})->with([
    'pagenow' => ['edit.php', '/wp-admin/upload.php', 'edit.php'],
    'empty pagenow' => ['', '/wp-admin/upload.php', 'upload.php'],
    'no pagenow' => [null, '/wp-admin/upload.php', 'upload.php'],
    'neither' => [null, null, ''],
]);

it('exempts sign-in and password-reset actions on wp-login.php', function (mixed $action, bool $exempt): void {
    uamswp_auditor_role_as(true, 'wp-login.php');

    if ($action !== null) {
        $_REQUEST['action'] = $action;
    }

    expect(Session::is_exempt_login())->toBe($exempt)
        ->and(Session::is_auditor())->toBe(! $exempt);
})->with([
    'no action' => [null, true],
    'empty action' => ['', true],
    'login' => ['login', true],
    'lostpassword' => ['lostpassword', true],
    'retrievepassword' => ['retrievepassword', true],
    'rp' => ['rp', true],
    'resetpass' => ['resetpass', true],
    'postpass' => ['postpass', true],
    'switch back' => ['switch_to_olduser', true],
    'logout' => ['logout', false],
    'confirm_admin_email' => ['confirm_admin_email', false],
    'register' => ['register', false],
]);

it('exempts nothing outside wp-login.php', function (): void {
    uamswp_auditor_role_as(true, 'admin.php');
    $_REQUEST['action'] = 'login';

    expect(Session::is_exempt_login())->toBeFalse();
});

it('takes the exempt list from the filter, and falls back when the filter returns no list', function (): void {
    uamswp_auditor_role_as(true, 'wp-login.php');
    $_REQUEST['action'] = 'custom_sso';

    Filters\expectApplied('uamswp_auditor_role_exempt_login_actions')->once()->with(Session::exempt_login_actions())->andReturn(['custom_sso']);
    expect(Session::is_exempt_login())->toBeTrue();

    $_REQUEST['action'] = 'rp';
    Filters\expectApplied('uamswp_auditor_role_exempt_login_actions')->once()->andReturn('custom_sso');
    expect(Session::is_exempt_login())->toBeTrue();
});
