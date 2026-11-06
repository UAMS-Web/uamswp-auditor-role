<?php

/**
 * The Auditor role and its guard, against WordPress and a database.
 *
 * Runs on a single site and, with WP_MULTISITE=1, on a multisite network.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Tests\Integration;

use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\Log;
use UAMSWeb\AuditorRole\LogQuery;
use UAMSWeb\AuditorRole\MetaCaps;
use UAMSWeb\AuditorRole\RequestGuard;
use UAMSWeb\AuditorRole\Role;
use UAMSWeb\AuditorRole\Session;
use UAMSWeb\AuditorRole\Tests\Support\IntegrationTestCase;

final class AuditorRoleTest extends IntegrationTestCase
{
    private int $auditor;

    private int $admin;

    public function set_up(): void
    {
        parent::set_up();

        Role::maybe_register();
        Log::maybe_install();

        $this->auditor = $this->user_id(self::factory()->user->create(['role' => Role::NAME, 'user_login' => 'auditor'.wp_rand()]));
        $this->admin = $this->user_id(self::factory()->user->create(['role' => 'administrator']));

        if (is_multisite()) {
            grant_super_admin($this->admin);
        }

        Session::reset();
        DatabaseGuard::reset();
        RequestGuard::reset();
        MetaCaps::reset();
    }

    /**
     * The ID a factory returned, or a failed test.
     */
    private function user_id(mixed $id): int
    {
        if (! is_int($id)) {
            self::fail('The factory did not create the object.');
        }

        return $id;
    }

    public function test_the_role_holds_the_granted_capabilities_and_none_of_the_never_granted(): void
    {
        $role = get_role(Role::NAME);

        $this->assertNotNull($role);
        $this->assertSame(Role::granted(), array_keys(array_filter($role->capabilities)));
        $this->assertSame([], array_intersect(array_keys($role->capabilities), Role::never_granted()));
    }

    public function test_raising_the_stamp_rewrites_the_role_once(): void
    {
        update_option(Role::OPTION, '0:stale');
        add_filter(Role::FILTER, $add = static fn (array $caps): array => array_merge($caps, ['view_reports', 'promote_users']));

        Role::maybe_register();

        $role = get_role(Role::NAME);
        $this->assertNotNull($role);
        $this->assertArrayHasKey('view_reports', $role->capabilities);
        $this->assertArrayNotHasKey('promote_users', $role->capabilities);
        $this->assertSame(Role::stamp(), get_option(Role::OPTION));
        $this->assertSame(1, count(array_keys(wp_roles()->get_names(), 'Auditor', true)));

        remove_filter(Role::FILTER, $add);
    }

    public function test_an_auditor_is_denied_every_acting_capability(): void
    {
        $post = self::factory()->post->create();

        foreach (['activate_plugin', 'promote_users', 'delete_post', 'publish_post', 'edit_plugins', 'create_app_password', 'list_users', 'export', 'unfiltered_html'] as $cap) {
            $this->assertFalse(user_can($this->auditor, $cap, $post), $cap);
        }

        $this->assertTrue(user_can($this->auditor, 'manage_options'));
        $this->assertTrue(user_can($this->auditor, 'edit_posts'));
        $this->assertTrue(user_can($this->auditor, 'edit_user', $this->auditor));
        $this->assertFalse(user_can($this->auditor, 'edit_user', $this->admin));
        $this->assertFalse(user_can($this->auditor, MetaCaps::VIEW_LOG));
        $this->assertTrue(user_can($this->admin, MetaCaps::VIEW_LOG));
        $this->assertTrue(user_can($this->admin, 'delete_post', $post));
    }

    public function test_the_database_refuses_an_auditor_write_and_passes_an_admin_write(): void
    {
        update_option('uamswp_auditor_role_probe', 'before');

        wp_set_current_user($this->auditor);
        $this->assertTrue(Session::is_auditor());
        $this->assertFalse(update_option('uamswp_auditor_role_probe', 'auditor'));
        wp_cache_delete('uamswp_auditor_role_probe', 'options');
        wp_cache_delete('alloptions', 'options');
        $this->assertSame('before', get_option('uamswp_auditor_role_probe'));
        $this->assertGreaterThan(0, DatabaseGuard::refused_count());

        wp_set_current_user($this->admin);
        $this->assertTrue(update_option('uamswp_auditor_role_probe', 'admin'));
        $this->assertSame('admin', get_option('uamswp_auditor_role_probe'));
    }

    public function test_each_statement_type_is_refused_for_an_auditor(): void
    {
        global $wpdb;

        wp_set_current_user($this->auditor);
        $wpdb->suppress_errors(true);

        foreach ([
            $wpdb->prepare('INSERT INTO %i (option_name, option_value) VALUES (%s, %s)', $wpdb->options, 'uamswp_x', 'x'),
            $wpdb->prepare('UPDATE %i SET option_value = %s WHERE option_name = %s', $wpdb->options, 'x', 'blogname'),
            $wpdb->prepare('DELETE FROM %i WHERE option_name = %s', $wpdb->options, 'blogname'),
            $wpdb->prepare('REPLACE INTO %i (option_name, option_value) VALUES (%s, %s)', $wpdb->options, 'uamswp_x', 'x'),
            'CREATE TABLE uamswp_probe (id int)',
        ] as $query) {
            $this->assertFalse($wpdb->query($query), $query); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $this->assertNotFalse($wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'blogname')));
    }

    public function test_the_log_records_a_refusal_and_never_a_body(): void
    {
        wp_set_current_user($this->auditor);
        $_SERVER['REQUEST_URI'] = '/wp-admin/options.php?_wpnonce=secret123&page=x';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['blogname'] = 'never stored';

        $this->assertFalse(update_option('blogname', 'never stored'));
        Log::reset();
        Log::record_request();

        $rows = LogQuery::rows(LogQuery::filters(['user_id' => $this->auditor]), 10);
        $this->assertNotSame([], $rows);

        $events = array_column($rows, 'event');
        $this->assertContains('db_refused', $events);

        foreach ($rows as $row) {
            $this->assertStringNotContainsString('secret123', $row['url']);
            $this->assertStringNotContainsString('never stored', $row['url'].$row['detail']);
        }

        unset($_POST['blogname']);
    }

    public function test_the_plugin_writes_its_own_log_inside_an_auditor_session(): void
    {
        wp_set_current_user($this->auditor);
        $filters = LogQuery::filters(['user_id' => $this->auditor, 'event' => 'login']);
        $before = LogQuery::count($filters);

        Log::write('login', $this->auditor, 'auditor');

        $this->assertSame($before + 1, LogQuery::count($filters));
    }

    public function test_a_rest_write_is_refused_and_a_read_passes(): void
    {
        wp_set_current_user($this->auditor);

        $write = rest_do_request(new \WP_REST_Request('POST', '/wp/v2/posts'));
        $read = rest_do_request(new \WP_REST_Request('GET', '/wp/v2/posts'));

        $this->assertSame(403, $write->get_status());
        $this->assertSame('uamswp_auditor_role_read_only', $write->get_data()['code']);
        $this->assertSame(200, $read->get_status());
    }

    public function test_application_passwords_are_off_for_an_auditor(): void
    {
        // A test install is not served over HTTPS, where core turns them off for everyone.
        add_filter('wp_is_application_passwords_available', '__return_true');

        $this->assertFalse(wp_is_application_passwords_available_for_user($this->auditor));
        $this->assertTrue(wp_is_application_passwords_available_for_user($this->admin));
    }

    public function test_a_new_site_gets_the_role_and_the_log_table(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only.');
        }

        $blog_id = $this->user_id(self::factory()->blog->create());

        switch_to_blog($blog_id);
        $this->assertNotNull(get_role(Role::NAME));
        $this->assertSame(Log::SCHEMA, get_option(Log::OPTION));
        restore_current_blog();
    }
}
