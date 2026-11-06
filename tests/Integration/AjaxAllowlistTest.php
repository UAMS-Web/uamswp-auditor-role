<?php

/**
 * The AJAX allowlist of #8, against WordPress and a database: each allowlisted
 * action runs its core handler as an Auditor and attempts no write at all, and
 * an action off the list is refused.
 *
 * "Attempts no write" is read from the database guard, which records every
 * statement that is not a read during an Auditor session whether or not it
 * would have succeeded. Outbound HTTP is short-circuited so no handler waits
 * on the network.
 *
 * Runs on a single site and, with WP_MULTISITE=1, on a multisite network.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Tests\Integration;

use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\RequestGuard;
use UAMSWeb\AuditorRole\Role;
use UAMSWeb\AuditorRole\Session;
use WP_Ajax_UnitTestCase;
use WP_Error;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

final class AjaxAllowlistTest extends WP_Ajax_UnitTestCase
{
    private int $auditor;

    private int $post;

    public function set_up(): void
    {
        parent::set_up();

        Role::maybe_register();

        $admin = self::factory()->user->create(['role' => 'administrator']);
        $auditor = self::factory()->user->create(['role' => Role::NAME]);
        $post = self::factory()->post->create(['post_author' => $admin, 'post_title' => 'A post']);
        $this->assertIsInt($auditor);
        $this->assertIsInt($post);
        $this->auditor = $auditor;
        $this->post = $post;

        add_filter('pre_http_request', static fn (): WP_Error => new WP_Error('offline', 'No network in the test suite.'));

        // The test install has no salt constants, so core stores each salt on
        // first use. Store them now, as the site would have long before.
        foreach (['auth', 'secure_auth', 'logged_in', 'nonce'] as $scheme) {
            wp_salt($scheme);
        }

        wp_set_current_user($this->auditor);
        Session::reset();
        RequestGuard::reset();
        DatabaseGuard::reset();
    }

    public function tear_down(): void
    {
        unset($GLOBALS['pagenow'], $_SERVER['REQUEST_METHOD']);
        Session::reset();
        RequestGuard::reset();
        DatabaseGuard::reset();

        parent::tear_down();
    }

    /**
     * Each allowlisted action, with the parameters its handler reads.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function allowed_actions(): array
    {
        return [
            'heartbeat' => ['heartbeat', ['screen_id' => 'dashboard', 'interval' => '60', 'data' => ['wp-auth-check' => true]]],
            'fetch-list' => ['fetch-list', ['list_args' => ['class' => 'WP_Posts_List_Table', 'screen' => ['id' => 'edit-post', 'base' => 'edit']]]],
            'ajax-tag-search' => ['ajax-tag-search', ['tax' => 'post_tag', 'q' => 'a']],
            'query-attachments' => ['query-attachments', ['query' => []]],
            'get-attachment' => ['get-attachment', ['id' => '0']],
            'find_posts' => ['find_posts', ['ps' => 'post']],
            'menu-get-metabox' => ['menu-get-metabox', ['item-type' => 'post_type', 'item-object' => 'page']],
            'menu-quick-search' => ['menu-quick-search', ['q' => 'post', 'type' => 'quick-search-posttype-post']],
            'get-revision-diffs' => ['get-revision-diffs', ['post_id' => '0', 'compare' => ['0:0']]],
            'dashboard-widgets' => ['dashboard-widgets', ['widget' => 'dashboard_primary', 'pagenow' => 'dashboard']],
            'wp-link-ajax' => ['wp-link-ajax', ['search' => 'post']],
        ];
    }

    public function test_the_data_set_names_every_allowlisted_action(): void
    {
        $this->assertSame(RequestGuard::ajax_defaults(), array_keys(self::allowed_actions()));
    }

    /**
     * @dataProvider allowed_actions
     *
     * @param  array<string, mixed>  $params
     */
    public function test_an_allowlisted_action_passes_the_guard_and_attempts_no_write(string $action, array $params): void
    {
        $nonces = [
            'heartbeat' => ['_nonce', 'heartbeat-nonce'],
            'fetch-list' => ['_ajax_fetch_list_nonce', 'fetch-list-WP_Posts_List_Table'],
            'find_posts' => ['_ajax_nonce', 'find-posts'],
            'wp-link-ajax' => ['_ajax_linking_nonce', 'internal-linking'],
            'get-revision-diffs' => ['_ajax_nonce', 'revisions-ajax-nonce'],
        ];

        if (isset($nonces[$action])) {
            $params[$nonces[$action][0]] = wp_create_nonce($nonces[$action][1]);
        }

        if ($action === 'get-revision-diffs') {
            $params['post_id'] = (string) $this->post;
        }

        $_POST = $params + ['action' => $action];
        $_GET = [];
        $_REQUEST = $_POST;
        $GLOBALS['pagenow'] = 'admin-ajax.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        RequestGuard::check();
        $this->assertNull(RequestGuard::refusal(), $action);

        // admin-ajax.php fires admin_init before the action, and core's
        // admin_init upkeep (cron, for one) writes on any admin request. That
        // is not the action, so only the handler is measured.
        do_action('admin_init');
        DatabaseGuard::reset();

        ob_start();

        try {
            do_action('wp_ajax_'.$action);
        } catch (WPAjaxDieContinueException|WPAjaxDieStopException) {
            // The handler finished, as admin-ajax.php handlers do, by dying.
        } finally {
            ob_end_clean();
        }

        $this->assertSame([], DatabaseGuard::refused(), $action.' attempted a write');
        $this->assertSame(0, DatabaseGuard::refused_count(), $action);
    }

    public function test_an_action_off_the_list_is_refused_with_json(): void
    {
        $_POST = ['action' => 'gf_save_form'];
        $_REQUEST = $_POST;
        $GLOBALS['pagenow'] = 'admin-ajax.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        add_filter('wp_doing_ajax', '__return_true');

        $this->_last_response = '';
        ob_start();

        try {
            RequestGuard::check();
            $this->fail('The action was not refused.');
        } catch (WPAjaxDieContinueException|WPAjaxDieStopException) {
            // wp_send_json_error() ends the request through wp_die(), whose
            // test handler moves the buffered output to _last_response.
        }

        $response = json_decode($this->last_response(), true);

        $this->assertIsArray($response);
        $this->assertFalse($response['success']);
        $this->assertSame('uamswp_auditor_role_read_only', $response['data']['code']);
        $this->assertSame('ajax:gf_save_form', RequestGuard::refusal());
    }

    public function test_an_action_added_through_the_filter_is_allowed(): void
    {
        $_POST = ['action' => 'example_read'];
        $_REQUEST = $_POST;
        $GLOBALS['pagenow'] = 'admin-ajax.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        add_filter('uamswp_auditor_role_ajax_actions', static fn (array $actions): array => [...$actions, 'example_read']);

        RequestGuard::check();

        $this->assertNull(RequestGuard::refusal());
    }

    /**
     * What the test die handler collected. Read through a call, because the
     * handler fills it from inside wp_die(), which static analysis cannot see.
     */
    private function last_response(): string
    {
        return (string) $this->_last_response;
    }
}
