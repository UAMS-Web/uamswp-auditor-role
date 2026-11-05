<?php

/**
 * UAMSWeb\AuditorRole\RequestGuard, with WordPress mocked.
 *
 * @see includes/RequestGuard.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\RequestGuard;

/**
 * A stand-in REST request: only the two getters the guard reads.
 */
function uamswp_auditor_role_rest_request(string $method, string $route = '/wp/v2/posts'): object
{
    return new class($method, $route)
    {
        public function __construct(private readonly string $method, private readonly string $route) {}

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }
    };
}

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    Functions\when('is_network_admin')->justReturn(false);
    Functions\when('sanitize_text_field')->returnArg();
});

it('decides each request', function (string $script, string $method, array $params, bool $admin, ?string $reason): void {
    expect(RequestGuard::reason($script, $method, $params, $admin))->toBe($reason);
})->with([
    'admin screen' => ['edit.php', 'GET', [], true, null],
    'admin post save' => ['post.php', 'POST', ['action' => 'editpost'], true, 'method:POST'],
    'options.php read' => ['options.php', 'GET', [], true, 'hidden:options.php'],
    'options.php save' => ['options.php', 'POST', [], true, 'method:POST'],
    'admin-post' => ['admin-post.php', 'GET', ['action' => 'x'], true, 'script:admin-post.php'],
    'new post auto-draft' => ['post-new.php', 'GET', [], true, 'script:post-new.php'],
    'opening the editor' => ['post.php', 'GET', ['action' => 'edit', 'post' => '1'], true, null],
    'trash by GET' => ['post.php', 'GET', ['action' => 'trash', '_wpnonce' => 'n'], true, 'action:trash'],
    'plugin activate by GET' => ['plugins.php', 'GET', ['action' => 'activate', 'plugin' => 'x', '_wpnonce' => 'n'], true, 'action:activate'],
    'theme switch by GET' => ['themes.php', 'GET', ['action' => 'activate', 'stylesheet' => 'x', '_wpnonce' => 'n'], true, 'action:activate'],
    'bulk action2' => ['edit.php', 'GET', ['action' => '-1', 'action2' => 'trash', '_wpnonce' => 'n'], true, 'action:trash'],
    'list filter form' => ['edit.php', 'GET', ['action' => '-1', 'action2' => '-1', '_wpnonce' => 'n', 'm' => '0'], true, null],
    'nonce on a plugin page' => ['tools.php', 'GET', ['page' => 'crontrol_admin_manage_page', 'crontrol_action' => 'run-cron', '_wpnonce' => 'n'], true, 'nonce:tools.php'],
    'ajax nonce on a plugin page' => ['admin.php', 'GET', ['page' => 'x', '_ajax_nonce' => 'n'], true, 'nonce:admin.php'],
    'hidden plugin page' => ['admin.php', 'GET', ['page' => 'gf_entries'], true, 'hidden:admin.php?page=gf_entries'],
    'site health info tab' => ['site-health.php', 'GET', ['tab' => 'debug'], true, 'hidden:site-health.php'],
    'site health status tab' => ['site-health.php', 'GET', [], true, null],
    'REST write' => ['index.php', 'POST', [], false, 'method:POST'],
    'REST delete' => ['index.php', 'DELETE', [], false, 'method:DELETE'],
    'REST method override' => ['index.php', 'GET', ['_method' => 'DELETE'], false, 'method-override'],
    'REST read override' => ['index.php', 'GET', ['_method' => 'GET'], false, null],
    'front end read' => ['index.php', 'GET', ['s' => 'x'], false, null],
    'front end form post' => ['index.php', 'POST', [], false, 'method:POST'],
    'XML-RPC' => ['xmlrpc.php', 'POST', [], false, 'xmlrpc'],
    'logout' => ['wp-login.php', 'GET', ['action' => 'logout', '_wpnonce' => 'n'], false, null],
    'admin email confirmation' => ['wp-login.php', 'GET', ['action' => 'confirm_admin_email'], false, 'confirm_admin_email'],
    'registration post' => ['wp-login.php', 'POST', ['action' => 'register'], false, 'login-write'],
    'heartbeat' => ['admin-ajax.php', 'POST', ['action' => 'heartbeat'], true, null],
    'list table ajax' => ['admin-ajax.php', 'POST', ['action' => 'fetch-list'], true, null],
    'unknown ajax' => ['admin-ajax.php', 'POST', ['action' => 'gf_save_form'], true, 'ajax:gf_save_form'],
    'ajax by GET' => ['admin-ajax.php', 'GET', ['action' => 'customize_save'], true, 'ajax:customize_save'],
    'ajax without action' => ['admin-ajax.php', 'POST', [], true, 'ajax:'],
]);

it('refuses the network admin', function (): void {
    Functions\when('is_network_admin')->justReturn(true);

    expect(RequestGuard::reason('index.php', 'GET', [], true))->toBe('network-admin');
});

it('allows an AJAX action added through the filter', function (): void {
    Filters\expectApplied('uamswp_auditor_role_ajax_actions')->andReturn(array_merge(RequestGuard::AJAX_ACTIONS, ['frm_forms_preview']));

    expect(RequestGuard::reason('admin-ajax.php', 'POST', ['action' => 'frm_forms_preview'], true))->toBeNull();
});

it('allows a GET action added through the filter', function (): void {
    Filters\expectApplied('uamswp_auditor_role_get_actions')->andReturn(array_merge(RequestGuard::GET_ACTIONS, ['show']));

    expect(RequestGuard::reason('admin.php', 'GET', ['page' => 'x', 'action' => 'show'], true))->toBeNull();
});

it('opens a hidden screen removed through the filter', function (): void {
    Filters\expectApplied('uamswp_auditor_role_hidden_screens')->andReturn(['export.php']);

    expect(RequestGuard::reason('admin.php', 'GET', ['page' => 'gf_entries'], true))->toBeNull()
        ->and(RequestGuard::reason('export.php', 'GET', [], true))->toBe('hidden:export.php');
});

it('refuses a REST write after a method override', function (): void {
    uamswp_auditor_role_as(true);
    Functions\when('__')->returnArg();

    $request = uamswp_auditor_role_rest_request('DELETE', '/wp/v2/posts/1');

    $result = RequestGuard::rest_pre_dispatch(null, null, $request);

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and(RequestGuard::refusal())->toBe('rest:DELETE /wp/v2/posts/1');
});

it('passes a REST read, and any request by another role', function (): void {
    $read = uamswp_auditor_role_rest_request('GET');

    uamswp_auditor_role_as(true);
    expect(RequestGuard::rest_pre_dispatch(null, null, $read))->toBeNull();

    uamswp_auditor_role_reset();
    uamswp_auditor_role_as(false);
    $write = uamswp_auditor_role_rest_request('POST');
    expect(RequestGuard::rest_pre_dispatch(null, null, $write))->toBeNull();
});

it('leaves a request by another role alone', function (): void {
    uamswp_auditor_role_as(false, 'options.php');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    Functions\expect('wp_die')->never();

    RequestGuard::check();

    expect(RequestGuard::refusal())->toBeNull();
});

it('refuses an Auditor POST with a clear message and records why', function (): void {
    uamswp_auditor_role_as(true, 'post.php');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    Functions\when('is_admin')->justReturn(true);
    Functions\when('wp_unslash')->returnArg();
    Functions\when('sanitize_key')->alias('strtolower');
    Functions\when('wp_doing_ajax')->justReturn(false);
    Functions\when('rest_get_url_prefix')->justReturn('wp-json');
    Functions\when('__')->returnArg();
    Functions\when('esc_html')->returnArg();
    Functions\when('esc_html__')->returnArg();
    Functions\expect('wp_die')->once()->with(Mockery::pattern('/read-only audit mode/'), 'Read-only audit mode', ['response' => 403, 'back_link' => true])
        ->andReturnUsing(static function (): never {
            throw new RuntimeException('wp_die');
        });

    expect(fn () => RequestGuard::check())->toThrow(RuntimeException::class, 'wp_die')
        ->and(RequestGuard::refusal())->toBe('method:POST');

    unset($_SERVER['REQUEST_METHOD']);
});

it('refuses XML-RPC sign-in for an Auditor only', function (): void {
    Functions\when('__')->returnArg();

    if (! defined('XMLRPC_REQUEST')) {
        define('XMLRPC_REQUEST', true);
    }

    $auditor = uamswp_auditor_role_user_with(1, 'a', ['auditor']);
    $admin = uamswp_auditor_role_user_with(2, 'b', ['administrator']);

    expect(RequestGuard::refuse_xmlrpc($auditor))->toBeInstanceOf(WP_Error::class)
        ->and(RequestGuard::refuse_xmlrpc($admin))->toBe($admin);
});
