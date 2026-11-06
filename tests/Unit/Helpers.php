<?php

/**
 * Shared setup for the Unit suite: a request that is, or is not, an Auditor session.
 */

declare(strict_types=1);

use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\MetaCaps;
use UAMSWeb\AuditorRole\RequestGuard;
use UAMSWeb\AuditorRole\Session;

use function Brain\Monkey\Functions\when;

/**
 * A user with an ID, a login and roles, built the way the stand-in allows.
 *
 * @param  list<string>  $roles
 */
function uamswp_auditor_role_user_with(int $id, string $login, array $roles): WP_User
{
    $user = new WP_User;
    $user->ID = $id;
    $user->user_login = $login;
    $user->roles = $roles;

    return $user;
}

/**
 * Make the current user an Auditor, or another user, once WordPress has set it.
 */
function uamswp_auditor_role_as(bool $auditor, string $script = 'index.php'): WP_User
{
    $GLOBALS['pagenow'] = $script;

    $user = uamswp_auditor_role_user_with(7, $auditor ? 'audit' : 'admin', [$auditor ? 'auditor' : 'administrator']);

    when('did_action')->justReturn(1);
    when('wp_get_current_user')->justReturn($user);
    when('get_current_user_id')->justReturn($user->ID);
    when('get_userdata')->justReturn($user);

    return $user;
}

/**
 * Forget every class's per-request state.
 */
function uamswp_auditor_role_reset(): void
{
    Session::reset();
    DatabaseGuard::reset();
    RequestGuard::reset();
    MetaCaps::reset();
    unset($GLOBALS['pagenow']);
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
}
