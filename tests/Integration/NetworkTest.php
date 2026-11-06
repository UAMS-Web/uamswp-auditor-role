<?php

/**
 * #16: configured logins on a multisite network, against WordPress and a database.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Tests\Integration;

use UAMSWeb\AuditorRole\Network;
use UAMSWeb\AuditorRole\RequestGuard;
use UAMSWeb\AuditorRole\Role;
use UAMSWeb\AuditorRole\Session;
use UAMSWeb\AuditorRole\Tests\Support\IntegrationTestCase;

final class NetworkTest extends IntegrationTestCase
{
    private int $configured;

    private int $other;

    private string $login;

    public function set_up(): void
    {
        parent::set_up();

        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only.');
        }

        $this->login = 'audit'.wp_rand();
        $configured = self::factory()->user->create(['user_login' => $this->login, 'role' => '']);
        $other = self::factory()->user->create(['role' => '']);
        $this->configured = is_int($configured) ? $configured : 0;
        $this->other = is_int($other) ? $other : 0;

        add_filter('uamswp_auditor_role_logins', fn (): array => [$this->login]);

        Session::reset();
        RequestGuard::reset();

        // A request to an ordinary screen, whatever an earlier test left behind.
        $GLOBALS['pagenow'] = 'index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }

    public function test_a_configured_login_is_added_to_an_existing_subsite_it_visits(): void
    {
        $blog = self::factory()->blog->create();
        $this->assertIsInt($blog);
        remove_user_from_blog($this->configured, $blog);

        switch_to_blog($blog);
        wp_set_current_user($this->configured);
        Network::on_visit();

        $this->assertTrue(is_user_member_of_blog($this->configured, $blog));
        $this->assertTrue(Role::user_is_auditor(new \WP_User($this->configured, '', $blog)));
        restore_current_blog();
    }

    public function test_a_configured_login_is_added_to_the_site_it_signs_in_on(): void
    {
        $blog = self::factory()->blog->create();
        $this->assertIsInt($blog);
        remove_user_from_blog($this->configured, $blog);

        switch_to_blog($blog);
        do_action('wp_login', $this->login, get_userdata($this->configured));
        $this->assertTrue(is_user_member_of_blog($this->configured, $blog));
        restore_current_blog();
    }

    public function test_a_new_site_gets_each_configured_login_as_an_auditor(): void
    {
        $blog = self::factory()->blog->create();
        $this->assertIsInt($blog);

        $this->assertTrue(is_user_member_of_blog($this->configured, $blog));

        switch_to_blog($blog);
        $this->assertTrue(Role::user_is_auditor(new \WP_User($this->configured)));
        restore_current_blog();
    }

    public function test_a_user_not_in_the_list_is_never_added(): void
    {
        $blog = self::factory()->blog->create();
        $this->assertIsInt($blog);

        switch_to_blog($blog);
        wp_set_current_user($this->other);
        Network::on_visit();
        $other = get_userdata($this->other);
        $this->assertInstanceOf(\WP_User::class, $other);
        do_action('wp_login', $other->user_login, $other);
        restore_current_blog();

        $this->assertFalse(is_user_member_of_blog($this->other, $blog));
    }

    public function test_sync_adds_the_login_to_every_existing_site(): void
    {
        $blogs = [];

        foreach ([self::factory()->blog->create(), self::factory()->blog->create()] as $blog) {
            $this->assertIsInt($blog);
            remove_user_from_blog($this->configured, $blog);
            $blogs[] = $blog;
        }

        $added = Network::sync_all();

        foreach ($blogs as $blog) {
            $this->assertTrue(is_user_member_of_blog($this->configured, $blog));
        }

        $this->assertGreaterThanOrEqual(2, $added[$this->login]);
    }

    public function test_the_network_admin_stays_unreachable_for_an_auditor(): void
    {
        $blog = self::factory()->blog->create();
        $this->assertIsInt($blog);
        switch_to_blog($blog);
        wp_set_current_user($this->configured);
        Network::on_visit();

        $this->assertFalse(current_user_can('manage_network'));
        $this->assertSame('network-admin', $this->network_admin_reason());
        restore_current_blog();
    }

    /**
     * The guard's answer for a network admin screen.
     */
    private function network_admin_reason(): ?string
    {
        set_current_screen('dashboard-network');

        try {
            return RequestGuard::reason('index.php', 'GET', [], true);
        } finally {
            set_current_screen('front');
        }
    }
}
