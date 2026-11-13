<?php

/**
 * UAMSWeb\AuditorRole\Previews, with WordPress mocked: `edit_post` and
 * `edit_page` answer `read` for an Auditor only on a front-end read of an
 * unpublished post of a viewable type, and the front-end "Edit" links go.
 *
 * @see includes/Previews.php
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Previews;

/**
 * The request context: a front-end GET unless a part is turned off.
 */
function uamswp_auditor_role_g6_context(bool $admin = false, bool $ajax = false, bool $cron = false, bool $rest = false, string $method = 'GET'): void
{
    $_SERVER['REQUEST_METHOD'] = $method;
    Functions\when('is_admin')->justReturn($admin);
    Functions\when('wp_doing_ajax')->justReturn($ajax);
    Functions\when('wp_doing_cron')->justReturn($cron);
    Functions\when('wp_is_serving_rest_request')->justReturn($rest);
    Functions\when('wp_unslash')->returnArg();
    Functions\when('sanitize_key')->alias('strtolower');
}

/**
 * A post: its type, its status, whether the type is viewable, and whether the
 * status is protected (null for an unregistered status).
 */
function uamswp_auditor_role_g6_post(mixed $type = 'post', mixed $status = 'draft', bool $viewable = true, ?bool $protected = true): void
{
    Functions\when('get_post_type')->justReturn($type);
    Functions\when('get_post_status')->justReturn($status);
    Functions\when('is_post_type_viewable')->justReturn($viewable);
    Functions\when('get_post_status_object')->justReturn($protected === null ? null : (object) ['protected' => $protected]);
}

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    unset($_SERVER['REQUEST_METHOD']);
});

afterEach(function (): void {
    unset($_SERVER['REQUEST_METHOD']);
});

it('hooks the capability map before MetaCaps and the edit links last', function (): void {
    Filters\expectAdded('map_meta_cap')->once()->with([Previews::class, 'map'], PHP_INT_MAX - 1, 4);
    Actions\expectAdded('admin_bar_menu')->once()->with([Previews::class, 'remove_edit_link'], PHP_INT_MAX);
    Filters\expectAdded('edit_post_link')->once()->with([Previews::class, 'edit_post_link'], PHP_INT_MAX);

    Previews::boot();
});

it('returns only the string capabilities it was given, as a list', function (): void {
    expect(Previews::map(['a' => 'edit_posts', 'b' => 7, 'c' => null, 'd' => 'read'], 'edit_posts', 7, []))->toBe(['edit_posts', 'read'])
        ->and(Previews::map('not a list', 'edit_posts', 7, []))->toBe([]);
});

it('answers read for an Auditor viewing an unpublished post on the front end', function (string $cap, string $method): void {
    uamswp_auditor_role_as(true);
    uamswp_auditor_role_g6_context(method: $method);
    uamswp_auditor_role_g6_post();

    expect(Previews::map(['edit_others_posts'], $cap, 7, [12]))->toBe(['read'])
        ->and(Previews::map(['edit_others_posts'], $cap, 7, ['12']))->toBe(['read']);
})->with([
    'a post' => ['edit_post', 'GET'],
    'a page' => ['edit_page', 'GET'],
    'a HEAD request' => ['edit_post', 'HEAD'],
    'a lower-case method' => ['edit_post', 'get'],
]);

it('leaves the check alone outside a front-end read', function (bool $admin, bool $ajax, bool $cron, bool $rest, string $method): void {
    uamswp_auditor_role_as(true);
    uamswp_auditor_role_g6_context($admin, $ajax, $cron, $rest, $method);
    uamswp_auditor_role_g6_post();

    expect(Previews::map(['edit_others_posts'], 'edit_post', 7, [12]))->toBe(['edit_others_posts'])
        ->and(Previews::is_front_end_read())->toBeFalse();
})->with([
    'the admin' => [true, false, false, false, 'GET'],
    'AJAX' => [false, true, false, false, 'GET'],
    'cron' => [false, false, true, false, 'GET'],
    'REST' => [false, false, false, true, 'GET'],
    'a POST' => [false, false, false, false, 'POST'],
    'no method' => [false, false, false, false, ''],
]);

it('reads no method as no front-end read', function (): void {
    uamswp_auditor_role_g6_context();
    unset($_SERVER['REQUEST_METHOD']);

    expect(Previews::is_front_end_read())->toBeFalse();
});

it('leaves the check alone for a post the allowance is not for', function (mixed $type, mixed $status, bool $viewable, ?bool $protected): void {
    uamswp_auditor_role_as(true);
    uamswp_auditor_role_g6_context();
    uamswp_auditor_role_g6_post($type, $status, $viewable, $protected);

    expect(Previews::map(['edit_others_posts'], 'edit_post', 7, [12]))->toBe(['edit_others_posts']);
})->with([
    'a published post' => ['post', 'publish', true, false],
    'a type that is not viewable' => ['post', 'draft', false, true],
    'an unregistered status' => ['post', 'mystery', true, null],
    'no post type' => [false, 'draft', true, true],
    'no status' => ['post', false, true, true],
]);

it('leaves the check alone for another role', function (): void {
    uamswp_auditor_role_as(false);
    uamswp_auditor_role_g6_context();
    uamswp_auditor_role_g6_post();

    expect(Previews::map(['edit_others_posts'], 'edit_post', 7, [12]))->toBe(['edit_others_posts']);
});

it('leaves other capabilities and malformed checks alone', function (mixed $cap, mixed $user_id, mixed $args): void {
    uamswp_auditor_role_as(true);
    uamswp_auditor_role_g6_context();
    uamswp_auditor_role_g6_post();

    expect(Previews::map(['edit_others_posts'], $cap, $user_id, $args))->toBe(['edit_others_posts']);
})->with([
    'another capability' => ['delete_post', 7, [12]],
    'a primitive capability' => ['edit_posts', 7, []],
    'a user id that is not an int' => ['edit_post', '7', [12]],
    'no arguments' => ['edit_post', 7, null],
    'no post id' => ['edit_post', 7, []],
    'a post id that is not a number' => ['edit_post', 7, ['x']],
]);

it('returns a list for capabilities that are not a list', function (): void {
    expect(Previews::map(null, 'read', 7, []))->toBe([])
        ->and(Previews::map(['a' => 'read'], 'read', 7, []))->toBe(['read']);
});

it('removes the front-end Edit link for an Auditor', function (): void {
    uamswp_auditor_role_as(true);
    Functions\when('is_admin')->justReturn(false);
    $bar = Mockery::mock(WP_Admin_Bar::class);
    $bar->shouldReceive('remove_node')->once()->with('edit');

    Previews::remove_edit_link($bar);
});

it('leaves the Edit link in the admin, for another role, and without an admin bar', function (bool $auditor, bool $admin, bool $real_bar): void {
    uamswp_auditor_role_as($auditor);
    Functions\when('is_admin')->justReturn($admin);
    $bar = $real_bar ? Mockery::mock(WP_Admin_Bar::class) : Mockery::mock();
    $bar->shouldReceive('remove_node')->never();

    Previews::remove_edit_link($bar);
})->with([
    'in the admin' => [true, true, true],
    'another role' => [false, false, true],
    'not an admin bar' => [true, false, false],
]);

it('prints no theme Edit link for an Auditor and keeps it for another role', function (): void {
    uamswp_auditor_role_as(true);
    expect(Previews::edit_post_link('<a>Edit</a>'))->toBe('');

    uamswp_auditor_role_reset();
    uamswp_auditor_role_as(false);
    expect(Previews::edit_post_link('<a>Edit</a>'))->toBe('<a>Edit</a>');
});
