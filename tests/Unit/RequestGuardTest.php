<?php

/**
 * UAMSWeb\AuditorRole\RequestGuard, with WordPress mocked.
 *
 * Mutation-clean under `composer mutate -- --path=includes/RequestGuard.php`,
 * with no equivalent mutants. The lists are pinned against #6, #7 and #8 and
 * the class's documented additions, written out here.
 *
 * The XML-RPC test that reads the constant runs before the one that defines
 * it: a constant cannot be undefined within a process.
 *
 * @see includes/RequestGuard.php
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\RequestGuard;

/**
 * A stand-in REST request: only the getters named.
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

/**
 * The WordPress functions check() and refuse() reach, for an Auditor on a script.
 */
function uamswp_auditor_role_g2_request(string $script, string $method, bool $admin = true): void
{
    uamswp_auditor_role_as(true, $script);
    $_SERVER['REQUEST_METHOD'] = $method;
    Functions\when('is_admin')->justReturn($admin);
    Functions\when('wp_unslash')->returnArg();
    Functions\when('sanitize_key')->alias('strtolower');
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('wp_doing_ajax')->justReturn(false);
    Functions\when('rest_get_url_prefix')->justReturn('wp-json');
    Functions\when('__')->returnArg();
    Functions\when('esc_html')->returnArg();
    Functions\when('esc_html__')->returnArg();
}

/**
 * A wp_die() that records its arguments and stops, as WordPress' does.
 *
 * @param  list<mixed>  $calls
 */
function uamswp_auditor_role_g2_die(array &$calls): void
{
    Functions\when('wp_die')->alias(static function (...$args) use (&$calls): never {
        $calls[] = $args;

        throw new RuntimeException('wp_die');
    });
}

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
    Functions\when('is_network_admin')->justReturn(false);
    Functions\when('sanitize_text_field')->returnArg();
});

it('lists exactly the reading methods, the GET actions, the nonce list scripts, the blocked scripts and the AJAX actions', function (): void {
    expect(RequestGuard::read_methods())->toBe(['GET', 'HEAD', 'OPTIONS'])
        ->and(RequestGuard::get_actions())->toBe(['', '-1', 'edit', 'view', 'preview'])
        ->and(RequestGuard::nonce_list_scripts())->toBe(['edit.php', 'upload.php', 'edit-tags.php', 'edit-comments.php', 'plugins.php', 'themes.php'])
        ->and(RequestGuard::blocked_scripts())->toBe(['admin-post.php', 'post-new.php', 'media-new.php', 'press-this.php', 'update.php', 'upgrade.php', 'async-upload.php', 'plugin-install.php', 'theme-install.php', 'user-new.php'])
        ->and(RequestGuard::ajax_defaults())->toBe(['heartbeat', 'fetch-list', 'ajax-tag-search', 'query-attachments', 'get-attachment', 'find_posts', 'menu-get-metabox', 'menu-quick-search', 'get-revision-diffs', 'dashboard-widgets', 'wp-link-ajax'])
        ->and(RequestGuard::ajax_actions())->toBe(RequestGuard::ajax_defaults());
});

it('hooks each check where it must run, with its argument count', function (): void {
    Actions\expectAdded('plugins_loaded')->once()->with([RequestGuard::class, 'check'], PHP_INT_MIN);
    Actions\expectAdded('init')->once()->with([RequestGuard::class, 'check'], PHP_INT_MIN);
    Filters\expectAdded('rest_pre_dispatch')->once()->with([RequestGuard::class, 'rest_pre_dispatch'], PHP_INT_MIN, 3);
    Filters\expectAdded('authenticate')->once()->with([RequestGuard::class, 'refuse_xmlrpc'], PHP_INT_MAX);
    Actions\expectAdded('admin_init')->once()->with([RequestGuard::class, 'remove_heartbeat_writes']);

    RequestGuard::boot();
});

it('decides each request', function (string $script, string $method, array $params, bool $admin, ?string $reason): void {
    expect(RequestGuard::reason($script, $method, $params, $admin))->toBe($reason);
})->with([
    'admin screen' => ['edit.php', 'GET', [], true, null],
    'admin HEAD' => ['edit.php', 'HEAD', [], true, null],
    'admin OPTIONS' => ['edit.php', 'OPTIONS', [], true, null],
    'admin post save' => ['post.php', 'POST', ['action' => 'editpost'], true, 'method:POST'],
    'admin PUT' => ['post.php', 'PUT', [], true, 'method:PUT'],
    'options.php read' => ['options.php', 'GET', [], true, 'hidden:options.php'],
    'options.php save' => ['options.php', 'POST', [], true, 'method:POST'],
    'connectors read' => ['options-connectors.php', 'GET', [], true, 'hidden:options-connectors.php'],
    'admin-post' => ['admin-post.php', 'GET', ['action' => 'x'], true, 'script:admin-post.php'],
    'new post auto-draft' => ['post-new.php', 'GET', [], true, 'script:post-new.php'],
    'new upload' => ['media-new.php', 'GET', [], true, 'script:media-new.php'],
    'press this' => ['press-this.php', 'GET', [], true, 'script:press-this.php'],
    'update run' => ['update.php', 'GET', [], true, 'script:update.php'],
    'database upgrade' => ['upgrade.php', 'GET', [], true, 'script:upgrade.php'],
    'async upload' => ['async-upload.php', 'GET', [], true, 'script:async-upload.php'],
    'plugin install' => ['plugin-install.php', 'GET', [], true, 'script:plugin-install.php'],
    'theme install' => ['theme-install.php', 'GET', [], true, 'script:theme-install.php'],
    'user new' => ['user-new.php', 'GET', [], true, 'script:user-new.php'],
    'opening the editor' => ['post.php', 'GET', ['action' => 'edit', 'post' => '1'], true, null],
    'view action' => ['admin.php', 'GET', ['page' => 'x', 'action' => 'view'], true, null],
    'preview action' => ['admin.php', 'GET', ['page' => 'x', 'action' => 'preview'], true, null],
    'action that is not a name' => ['admin.php', 'GET', ['page' => 'x', 'action' => ['edit']], true, null],
    'trash by GET' => ['post.php', 'GET', ['action' => 'trash', '_wpnonce' => 'n'], true, 'action:trash'],
    'plugin activate by GET' => ['plugins.php', 'GET', ['action' => 'activate', 'plugin' => 'x', '_wpnonce' => 'n'], true, 'action:activate'],
    'plugin deactivate by GET' => ['plugins.php', 'GET', ['action' => 'deactivate', 'plugin' => 'x', '_wpnonce' => 'n'], true, 'action:deactivate'],
    'theme switch by GET' => ['themes.php', 'GET', ['action' => 'activate', 'stylesheet' => 'x', '_wpnonce' => 'n'], true, 'action:activate'],
    'untrash by GET' => ['post.php', 'GET', ['action' => 'untrash', '_wpnonce' => 'n'], true, 'action:untrash'],
    'bulk action2' => ['edit.php', 'GET', ['action' => '-1', 'action2' => 'trash', '_wpnonce' => 'n'], true, 'action:trash'],
    'list filter form' => ['edit.php', 'GET', ['action' => '-1', 'action2' => '-1', '_wpnonce' => 'n', 'm' => '0'], true, null],
    'list filter form, no action' => ['edit.php', 'GET', ['_wpnonce' => 'n'], true, null],
    'media list filter' => ['upload.php', 'GET', ['_wpnonce' => 'n'], true, null],
    'term list filter' => ['edit-tags.php', 'GET', ['_wpnonce' => 'n'], true, null],
    'comment list filter' => ['edit-comments.php', 'GET', ['_wpnonce' => 'n'], true, null],
    'plugin list filter' => ['plugins.php', 'GET', ['_wpnonce' => 'n'], true, null],
    'theme list filter' => ['themes.php', 'GET', ['_wpnonce' => 'n'], true, null],
    'nonce with an edit action on a list' => ['edit.php', 'GET', ['action' => 'edit', '_wpnonce' => 'n'], true, 'nonce:edit.php'],
    'nonce on a plugin page' => ['tools.php', 'GET', ['page' => 'crontrol_admin_manage_page', 'crontrol_action' => 'run-cron', '_wpnonce' => 'n'], true, 'nonce:tools.php'],
    'ajax nonce on a plugin page' => ['admin.php', 'GET', ['page' => 'x', '_ajax_nonce' => 'n'], true, 'nonce:admin.php'],
    'hidden plugin page' => ['admin.php', 'GET', ['page' => 'gf_entries'], true, 'hidden:admin.php?page=gf_entries'],
    'site health info tab' => ['site-health.php', 'GET', ['tab' => 'debug'], true, 'hidden:site-health.php'],
    'site health status tab' => ['site-health.php', 'GET', [], true, null],
    'REST write' => ['index.php', 'POST', [], false, 'method:POST'],
    'REST delete' => ['index.php', 'DELETE', [], false, 'method:DELETE'],
    'REST patch' => ['index.php', 'PATCH', [], false, 'method:PATCH'],
    'REST method override' => ['index.php', 'GET', ['_method' => 'DELETE'], false, 'method-override'],
    'REST lower-case read override' => ['index.php', 'GET', ['_method' => 'get'], false, null],
    'REST read override' => ['index.php', 'GET', ['_method' => 'GET'], false, null],
    'REST override that is not a name' => ['index.php', 'GET', ['_method' => 5], false, 'method-override'],
    'REST override as an array' => ['index.php', 'GET', ['_method' => ['DELETE']], false, null],
    'front end read' => ['index.php', 'GET', ['s' => 'x'], false, null],
    'front end read of a hidden page name' => ['index.php', 'GET', ['page' => 'gf_entries'], false, null],
    'front end form post' => ['index.php', 'POST', [], false, 'method:POST'],
    'XML-RPC' => ['xmlrpc.php', 'POST', [], false, 'xmlrpc'],
    'logout' => ['wp-login.php', 'GET', ['action' => 'logout', '_wpnonce' => 'n'], false, null],
    'sign-in screen read' => ['wp-login.php', 'GET', ['action' => 'register'], false, null],
    'logout by POST' => ['wp-login.php', 'POST', ['action' => 'logout'], false, null],
    'admin email confirmation' => ['wp-login.php', 'GET', ['action' => 'confirm_admin_email'], false, 'confirm_admin_email'],
    'registration post' => ['wp-login.php', 'POST', ['action' => 'register'], false, 'login-write'],
    'unknown ajax' => ['admin-ajax.php', 'POST', ['action' => 'gf_save_form'], true, 'ajax:gf_save_form'],
    'ajax by GET' => ['admin-ajax.php', 'GET', ['action' => 'customize_save'], true, 'ajax:customize_save'],
    'ajax without action' => ['admin-ajax.php', 'POST', [], true, 'ajax:'],
]);

it('allows each read-only AJAX action', function (string $action): void {
    expect(RequestGuard::reason('admin-ajax.php', 'POST', ['action' => $action], true))->toBeNull();
})->with(['heartbeat', 'fetch-list', 'ajax-tag-search', 'query-attachments', 'get-attachment', 'find_posts', 'menu-get-metabox', 'menu-quick-search', 'get-revision-diffs', 'dashboard-widgets', 'wp-link-ajax']);

it('refuses the network admin', function (): void {
    Functions\when('is_network_admin')->justReturn(true);

    expect(RequestGuard::reason('index.php', 'GET', [], true))->toBe('network-admin');
});

it('reads an action that is not a string as its name', function (): void {
    Filters\expectApplied('uamswp_auditor_role_ajax_actions')->andReturn(['5']);
    Filters\expectApplied('uamswp_auditor_role_get_actions')->andReturn(['', '5']);

    expect(RequestGuard::reason('admin-ajax.php', 'POST', ['action' => 5], true))->toBeNull()
        ->and(RequestGuard::reason('admin.php', 'GET', ['page' => 'x', 'action' => 5], true))->toBeNull();
});

it('allows an AJAX action added through the filter, keeping only names, as a list', function (): void {
    Filters\expectApplied('uamswp_auditor_role_ajax_actions')->andReturn([5, 'frm_forms_preview', null]);

    expect(RequestGuard::ajax_actions())->toBe(['frm_forms_preview'])
        ->and(RequestGuard::reason('admin-ajax.php', 'POST', ['action' => 'frm_forms_preview'], true))->toBeNull();
});

it('keeps the AJAX defaults when the filter returns no list', function (): void {
    Filters\expectApplied('uamswp_auditor_role_ajax_actions')->andReturn('heartbeat');

    expect(RequestGuard::ajax_actions())->toBe(RequestGuard::ajax_defaults());
});

it('allows a GET action added through the filter, and keeps the defaults when it returns no list', function (): void {
    Filters\expectApplied('uamswp_auditor_role_get_actions')->once()->with(RequestGuard::get_actions(), 'admin.php', ['page' => 'x', 'action' => 'show'])->andReturn([...RequestGuard::get_actions(), 'show']);
    expect(RequestGuard::reason('admin.php', 'GET', ['page' => 'x', 'action' => 'show'], true))->toBeNull();

    Filters\expectApplied('uamswp_auditor_role_get_actions')->once()->andReturn('show');
    expect(RequestGuard::reason('admin.php', 'GET', ['page' => 'x', 'action' => 'show'], true))->toBe('action:show');
});

it('opens a hidden screen removed through the filter', function (): void {
    Filters\expectApplied('uamswp_auditor_role_hidden_screens')->andReturn(['export.php']);

    expect(RequestGuard::reason('admin.php', 'GET', ['page' => 'gf_entries'], true))->toBeNull()
        ->and(RequestGuard::reason('export.php', 'GET', [], true))->toBe('hidden:export.php');
});

it('refuses a REST write after a method override, with a 403', function (): void {
    uamswp_auditor_role_as(true);
    Functions\when('__')->returnArg();

    $result = RequestGuard::rest_pre_dispatch(null, null, uamswp_auditor_role_rest_request('DELETE', '/wp/v2/posts/1'));

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->code)->toBe('uamswp_auditor_role_read_only')
        ->and($result->data)->toBe(['status' => 403])
        ->and(RequestGuard::refusal())->toBe('rest:DELETE /wp/v2/posts/1');
});

it('passes a REST read, and any request by another role', function (string $method): void {
    uamswp_auditor_role_as(true);
    expect(RequestGuard::rest_pre_dispatch('kept', null, uamswp_auditor_role_rest_request($method)))->toBe('kept');
})->with(['GET', 'HEAD', 'OPTIONS']);

it('passes a REST write by another role', function (): void {
    uamswp_auditor_role_as(false);

    expect(RequestGuard::rest_pre_dispatch('kept', null, uamswp_auditor_role_rest_request('POST')))->toBe('kept')
        ->and(RequestGuard::refusal())->toBeNull();
});

it('passes anything that is not a REST request untouched', function (mixed $request): void {
    uamswp_auditor_role_as(true);

    expect(RequestGuard::rest_pre_dispatch('kept', null, $request))->toBe('kept');
})->with([
    'not an object' => ['request'],
    'no method getter' => [new class
    {
        public function get_route(): string
        {
            return '/x';
        }
    }],
    'no route getter' => [new class
    {
        public function get_method(): string
        {
            return 'POST';
        }
    }],
]);

it('lets an Auditor sign in when the request is not XML-RPC', function (): void {
    expect(defined('XMLRPC_REQUEST'))->toBeFalse();

    $auditor = uamswp_auditor_role_user_with(1, 'a', ['auditor']);

    expect(RequestGuard::refuse_xmlrpc($auditor))->toBe($auditor);
});

it('refuses XML-RPC sign-in for an Auditor only', function (): void {
    Functions\when('__')->returnArg();
    $auditor = uamswp_auditor_role_user_with(1, 'a', ['auditor']);
    $admin = uamswp_auditor_role_user_with(2, 'b', ['administrator']);

    expect(RequestGuard::xmlrpc_refusal($auditor, true))->toBeInstanceOf(WP_Error::class)
        ->and(RequestGuard::xmlrpc_refusal($auditor, false))->toBe($auditor)
        ->and(RequestGuard::xmlrpc_refusal($admin, true))->toBe($admin)
        ->and(RequestGuard::xmlrpc_refusal(null, true))->toBeNull();

    if (! defined('XMLRPC_REQUEST')) {
        define('XMLRPC_REQUEST', true);
    }

    expect(RequestGuard::refuse_xmlrpc($auditor))->toBeInstanceOf(WP_Error::class);
});

it('takes the autosave and lock refreshes out of Heartbeat for an Auditor only', function (bool $auditor): void {
    uamswp_auditor_role_as($auditor);
    add_filter('heartbeat_received', 'wp_refresh_post_lock', 10, 3);
    add_filter('heartbeat_received', 'heartbeat_autosave', 500, 2);
    add_filter('heartbeat_received', 'wp_refresh_metabox_loader_nonces', 10, 2);

    RequestGuard::remove_heartbeat_writes();

    $left = ! $auditor;
    expect(has_filter('heartbeat_received', 'wp_refresh_post_lock') !== false)->toBe($left)
        ->and(has_filter('heartbeat_received', 'heartbeat_autosave') !== false)->toBe($left)
        ->and(has_filter('heartbeat_received', 'wp_refresh_metabox_loader_nonces') !== false)->toBe($left);
})->with(['Auditor' => [true], 'another role' => [false]]);

it('leaves a request by another role alone', function (): void {
    uamswp_auditor_role_as(false, 'options.php');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    Functions\expect('wp_die')->never();

    RequestGuard::check();

    expect(RequestGuard::refusal())->toBeNull();
});

it('refuses an Auditor POST with the read-only message, and checks a request once', function (): void {
    uamswp_auditor_role_g2_request('post.php', 'POST');
    $calls = [];
    uamswp_auditor_role_g2_die($calls);

    expect(fn () => RequestGuard::check())->toThrow(RuntimeException::class, 'wp_die')
        ->and(RequestGuard::refusal())->toBe('method:POST')
        ->and($calls)->toBe([['This account is in read-only audit mode. Changes are refused and recorded.', 'Read-only audit mode', ['response' => 403, 'back_link' => true]]]);
});

it('checks each request once, however often it is asked', function (): void {
    uamswp_auditor_role_g2_request('edit.php', 'GET');
    $calls = [];
    uamswp_auditor_role_g2_die($calls);

    RequestGuard::check();
    $GLOBALS['pagenow'] = 'post-new.php';
    RequestGuard::check();

    expect($calls)->toBe([])
        ->and(RequestGuard::refusal())->toBeNull();
});

it('reads a method it is not given as GET', function (): void {
    uamswp_auditor_role_g2_request('edit.php', 'GET');
    unset($_SERVER['REQUEST_METHOD']);
    Functions\expect('wp_die')->never();

    RequestGuard::check();

    expect(RequestGuard::refusal())->toBeNull();
});

it('says a hidden screen is hidden, not read-only', function (): void {
    uamswp_auditor_role_g2_request('export.php', 'GET');
    $calls = [];
    uamswp_auditor_role_g2_die($calls);

    expect(fn () => RequestGuard::check())->toThrow(RuntimeException::class)
        ->and($calls[0][0])->toBe('This screen is hidden from Auditor accounts because it can show secrets or personal data.');
});

it('keeps the read-only message for a reason that only ends in hidden:', function (): void {
    uamswp_auditor_role_g2_request('admin-ajax.php', 'POST');
    $_POST['action'] = 'x_hidden:';
    $calls = [];
    uamswp_auditor_role_g2_die($calls);

    expect(fn () => RequestGuard::check())->toThrow(RuntimeException::class)
        ->and(RequestGuard::refusal())->toBe('ajax:x_hidden:')
        ->and($calls[0][0])->toBe('This account is in read-only audit mode. Changes are refused and recorded.');
});

it('reads body parameters as well as the query', function (): void {
    uamswp_auditor_role_g2_request('admin.php', 'GET');
    $_GET['page'] = 'x';
    $_POST['action'] = 'delete';
    $calls = [];
    uamswp_auditor_role_g2_die($calls);

    expect(fn () => RequestGuard::check())->toThrow(RuntimeException::class)
        ->and(RequestGuard::refusal())->toBe('action:delete');
});

it('reads a method-override header, unless the query already names a method', function (?string $query, string $header, ?string $reason): void {
    uamswp_auditor_role_g2_request('index.php', 'GET', false);
    $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = $header;

    if ($query !== null) {
        $_GET['_method'] = $query;
    }

    $calls = [];
    uamswp_auditor_role_g2_die($calls);

    if ($reason === null) {
        RequestGuard::check();
    } else {
        expect(fn () => RequestGuard::check())->toThrow(RuntimeException::class);
    }

    expect(RequestGuard::refusal())->toBe($reason);
})->with([
    'header alone' => [null, 'DELETE', 'method-override'],
    'query wins' => ['GET', 'DELETE', null],
]);

it('answers an AJAX refusal as JSON, with a 403', function (): void {
    uamswp_auditor_role_g2_request('admin-ajax.php', 'POST');
    $_POST['action'] = 'gf_save_form';
    Functions\when('wp_doing_ajax')->justReturn(true);
    Functions\expect('wp_send_json_error')->once()
        ->with(['code' => 'uamswp_auditor_role_read_only', 'message' => 'This account is in read-only audit mode. Changes are refused and recorded.'], 403)
        ->andReturnUsing(static function (): never {
            throw new RuntimeException('json');
        });

    expect(fn () => RequestGuard::check())->toThrow(RuntimeException::class, 'json');
});

it('answers a REST refusal as JSON, with a 403', function (array $get, ?string $uri): void {
    uamswp_auditor_role_g2_request('index.php', 'POST', false);
    $_GET = $get;

    if ($uri !== null) {
        $_SERVER['REQUEST_URI'] = $uri;
    }

    Functions\expect('wp_send_json')->once()
        ->with(['code' => 'uamswp_auditor_role_read_only', 'message' => 'This account is in read-only audit mode. Changes are refused and recorded.', 'data' => ['status' => 403]], 403)
        ->andReturnUsing(static function (): never {
            throw new RuntimeException('json');
        });

    expect(fn () => RequestGuard::check())->toThrow(RuntimeException::class, 'json');
})->with([
    'pretty permalink' => [[], '/wp-json/wp/v2/posts'],
    'subsite permalink' => [[], '/site-two/wp-json/wp/v2/posts'],
    'plain permalink' => [['rest_route' => '/wp/v2/posts'], '/index.php'],
]);

it('answers a front-end refusal as a page, not JSON', function (?string $uri): void {
    uamswp_auditor_role_g2_request('index.php', 'POST', false);

    if ($uri !== null) {
        $_SERVER['REQUEST_URI'] = $uri;
    }

    Functions\expect('wp_send_json')->never();
    $calls = [];
    uamswp_auditor_role_g2_die($calls);

    expect(fn () => RequestGuard::check())->toThrow(RuntimeException::class, 'wp_die');
})->with([
    'no URI' => [null],
    'a page' => ['/about/'],
    'a slug that starts like the prefix' => ['/blog/wp-json-guide/'],
    'a path that ends like the prefix' => ['/notwp-json/x'],
    'the prefix without its slashes' => ['/wp-jsonx'],
]);

it('sends an Auditor away from the admin email confirmation', function (): void {
    uamswp_auditor_role_g2_request('wp-login.php', 'GET', false);
    $_REQUEST['action'] = 'confirm_admin_email';
    $_GET['action'] = 'confirm_admin_email';
    Functions\when('admin_url')->justReturn('https://example.org/wp-admin/');
    Functions\expect('wp_safe_redirect')->once()->with('https://example.org/wp-admin/')->andReturnUsing(static function (): never {
        throw new RuntimeException('redirected');
    });

    expect(fn () => RequestGuard::check())->toThrow(RuntimeException::class, 'redirected');
});
