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
 * The value at a path of keys through nested arrays, or null where the path ends early.
 */
function uamswp_auditor_role_at(mixed $value, int|string ...$path): mixed
{
    foreach ($path as $key) {
        if (! is_array($value) || ! array_key_exists($key, $value)) {
            return null;
        }

        $value = $value[$key];
    }

    return $value;
}

/**
 * A value that must be an array: anything else stops the test with its type.
 *
 * @return array<array-key, mixed>
 */
function uamswp_auditor_role_array(mixed $value): array
{
    if (! is_array($value)) {
        throw new RuntimeException('Expected an array, got '.get_debug_type($value).'.');
    }

    return $value;
}

/**
 * A value that must be a string: anything else stops the test with its type.
 */
function uamswp_auditor_role_string(mixed $value): string
{
    return is_string($value) ? $value : throw new TypeError('Expected a string, got '.get_debug_type($value).'.');
}

/**
 * A value as the text a stand-in prints: null is empty, a scalar is cast, anything else stops the test.
 */
function uamswp_auditor_role_text(mixed $value): string
{
    return match (true) {
        $value === null => '',
        is_scalar($value) => (string) $value,
        default => throw new TypeError('Expected text, got '.get_debug_type($value).'.'),
    };
}

/**
 * A value that must be an instance of a class: anything else stops the test with its type.
 *
 * @template T of object
 *
 * @param  class-string<T>  $class
 * @return T
 */
function uamswp_auditor_role_instance(mixed $value, string $class): object
{
    if (! $value instanceof $class) {
        throw new RuntimeException('Expected '.$class.', got '.get_debug_type($value).'.');
    }

    return $value;
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
