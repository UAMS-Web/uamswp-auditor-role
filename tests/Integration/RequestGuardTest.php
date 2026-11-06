<?php

/**
 * The request and capability layers of the guard, against WordPress and a
 * database: each refused path of #6 and #7 and an allowed one, for an Auditor
 * and for an Administrator.
 *
 * Runs on a single site and, with WP_MULTISITE=1, on a multisite network.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Tests\Integration;

use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\MetaCaps;
use UAMSWeb\AuditorRole\RequestGuard;
use UAMSWeb\AuditorRole\Role;
use UAMSWeb\AuditorRole\Session;
use UAMSWeb\AuditorRole\Tests\Support\IntegrationTestCase;
use WP_REST_Request;
use WPDieException;

final class RequestGuardTest extends IntegrationTestCase
{
    private const PASSWORD = 'auditor-role-integration-password';

    private int $auditor;

    private int $admin;

    private int $post;

    public function set_up(): void
    {
        parent::set_up();

        Role::maybe_register();

        $this->auditor = $this->id(self::factory()->user->create(['role' => Role::NAME, 'user_pass' => self::PASSWORD]));
        $this->admin = $this->id(self::factory()->user->create(['role' => 'administrator', 'user_pass' => self::PASSWORD]));
        $this->post = $this->id(self::factory()->post->create(['post_title' => 'Before', 'post_author' => $this->admin]));

        if (is_multisite()) {
            grant_super_admin($this->admin);
        }

        $this->forget();
    }

    public function tear_down(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
        unset($_SERVER['REQUEST_METHOD'], $GLOBALS['pagenow'], $GLOBALS['current_screen']);
        $this->forget();

        parent::tear_down();
    }

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function refused_admin_requests(): array
    {
        return [
            'settings save' => ['options-general.php', 'POST', ['blogname' => 'Changed']],
            'options.php save' => ['options.php', 'POST', ['option_page' => 'general', 'action' => 'update', 'blogname' => 'Changed']],
            'options.php read' => ['options.php', 'GET', []],
            'post save' => ['post.php', 'POST', ['action' => 'editpost', 'post_title' => 'Changed']],
            'admin-post' => ['admin-post.php', 'GET', ['action' => 'anything']],
            'plugin activate' => ['plugins.php', 'GET', ['action' => 'activate', 'plugin' => 'hello.php', '_wpnonce' => 'n']],
            'plugin deactivate' => ['plugins.php', 'GET', ['action' => 'deactivate', 'plugin' => 'hello.php', '_wpnonce' => 'n']],
            'post trash' => ['post.php', 'GET', ['action' => 'trash', 'post' => '1', '_wpnonce' => 'n']],
            'post untrash' => ['post.php', 'GET', ['action' => 'untrash', 'post' => '1', '_wpnonce' => 'n']],
            'theme switch' => ['themes.php', 'GET', ['action' => 'activate', 'stylesheet' => 'twentytwentyfive', '_wpnonce' => 'n']],
            'nonce-bearing GET not on the list' => ['tools.php', 'GET', ['page' => 'crontrol_admin_manage_page', 'crontrol_action' => 'run-cron', '_wpnonce' => 'n']],
        ];
    }

    /**
     * @dataProvider refused_admin_requests
     *
     * @param  array<string, string>  $params
     */
    public function test_an_auditor_admin_request_is_refused_and_changes_nothing(string $script, string $method, array $params): void
    {
        wp_set_current_user($this->auditor);

        try {
            $this->request($script, $method, $params);
            $this->fail('The request was not refused.');
        } catch (WPDieException) {
            $this->assertNotNull(RequestGuard::refusal());
        }

        $this->assertSame('Before', get_the_title($this->post));
        $this->assertNotSame('Changed', get_option('blogname'));
    }

    /**
     * @dataProvider refused_admin_requests
     *
     * @param  array<string, string>  $params
     */
    public function test_the_same_request_from_an_administrator_is_unaffected(string $script, string $method, array $params): void
    {
        wp_set_current_user($this->admin);

        $this->request($script, $method, $params);

        $this->assertNull(RequestGuard::refusal());
    }

    public function test_an_auditor_read_of_an_admin_screen_is_allowed(): void
    {
        wp_set_current_user($this->auditor);

        $this->request('edit.php', 'GET', ['post_type' => 'page']);

        $this->assertNull(RequestGuard::refusal());
    }

    public function test_an_auditor_rest_post_save_and_autosave_are_refused(): void
    {
        wp_set_current_user($this->auditor);

        foreach (["/wp/v2/posts/{$this->post}", "/wp/v2/posts/{$this->post}/autosaves"] as $route) {
            $request = new WP_REST_Request('POST', $route);
            $request->set_body_params(['title' => 'Changed', 'content' => 'Changed']);
            $response = rest_do_request($request);

            $this->assertSame(403, $response->get_status(), $route);
            $this->assertSame('uamswp_auditor_role_read_only', $response->get_data()['code'], $route);
        }

        $this->assertSame('Before', get_the_title($this->post));
        $this->assertFalse(wp_get_post_autosave($this->post, $this->auditor));
    }

    public function test_an_administrator_rest_post_save_is_unaffected(): void
    {
        wp_set_current_user($this->admin);

        $request = new WP_REST_Request('POST', "/wp/v2/posts/{$this->post}");
        $request->set_body_params(['title' => 'Changed']);

        $this->assertSame(200, rest_do_request($request)->get_status());
        $this->assertSame('Changed', get_the_title($this->post));
    }

    public function test_an_auditor_rest_read_is_allowed(): void
    {
        wp_set_current_user($this->auditor);

        $this->assertSame(200, rest_do_request(new WP_REST_Request('GET', '/wp/v2/posts'))->get_status());
    }

    /**
     * XML-RPC is detected by the XMLRPC_REQUEST constant, and defining it here
     * would switch every later test's wp_die() to the XML-RPC handler: it
     * cannot be undefined, and a separate process deadlocks on the test
     * transaction's table locks. So core's XML-RPC login runs with the
     * plugin's decision bound to its XML-RPC answer, the plugin's own hook is
     * checked to be registered, and the constant's read is pinned in the Unit
     * suite (RequestGuardTest, refuse_xmlrpc()).
     */
    public function test_xmlrpc_authentication_fails_for_an_auditor_and_works_for_an_administrator(): void
    {
        $this->assertSame(PHP_INT_MAX, has_filter('authenticate', [RequestGuard::class, 'refuse_xmlrpc']));

        require_once ABSPATH.'wp-includes/class-IXR.php';
        require_once ABSPATH.'wp-includes/class-wp-xmlrpc-server.php';

        $as_xmlrpc = static fn (mixed $user): mixed => RequestGuard::xmlrpc_refusal($user, true);
        add_filter('authenticate', $as_xmlrpc, PHP_INT_MAX);

        $auditor = get_userdata($this->auditor);
        $admin = get_userdata($this->admin);
        $this->assertNotFalse($auditor);
        $this->assertNotFalse($admin);

        // One server per sign-in, as one per request: a server refuses every
        // sign-in after a failed one.
        $this->assertFalse((new \wp_xmlrpc_server)->login($auditor->user_login, self::PASSWORD));
        $this->assertInstanceOf(\WP_User::class, (new \wp_xmlrpc_server)->login($admin->user_login, self::PASSWORD));

        remove_filter('authenticate', $as_xmlrpc, PHP_INT_MAX);
    }

    public function test_each_listed_meta_capability_and_never_granted_capability_is_denied_to_an_auditor(): void
    {
        $args = [
            'activate_plugin' => ['hello.php'],
            'deactivate_plugin' => ['hello.php'],
            'resume_plugin' => ['hello.php'],
            'resume_theme' => ['twentytwentyfive'],
            'publish_post' => [$this->post],
            'remove_user' => [$this->admin],
            'promote_user' => [$this->admin],
            'create_app_password' => [$this->auditor],
            'edit_app_password' => [$this->auditor, 'uuid'],
        ];

        foreach ([...MetaCaps::defaults(), ...Role::never_granted(), 'delete_post', 'delete_user'] as $cap) {
            $cap_args = $args[$cap] ?? ($cap === 'delete_post' ? [$this->post] : ($cap === 'delete_user' ? [$this->admin] : []));

            $this->assertFalse(user_can($this->auditor, $cap, ...$cap_args), $cap);
        }

        $this->assertTrue(user_can($this->admin, 'publish_post', $this->post));
        $this->assertTrue(user_can($this->admin, 'promote_user', $this->auditor));
    }

    /**
     * Run the request guard as WordPress would for a request.
     *
     * @param  array<string, string>  $params
     */
    private function request(string $script, string $method, array $params): void
    {
        $GLOBALS['pagenow'] = $script;
        $_SERVER['REQUEST_METHOD'] = $method;

        if ($method === 'GET') {
            $_GET = $params;
        } else {
            $_POST = $params;
        }

        $_REQUEST = $params;
        set_current_screen('dashboard');

        RequestGuard::check();
    }

    private function forget(): void
    {
        Session::reset();
        RequestGuard::reset();
        DatabaseGuard::reset();
        MetaCaps::reset();
    }

    /**
     * The ID a factory returned, or a failed test.
     */
    private function id(mixed $id): int
    {
        if (! is_int($id)) {
            $this->fail('The factory did not create the object.');
        }

        return $id;
    }
}
