<?php

/**
 * The read-only audit mode badge (#17), against WordPress's own admin bar, for
 * an Auditor and for an Administrator.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Tests\Integration;

use UAMSWeb\AuditorRole\AdminBar;
use UAMSWeb\AuditorRole\Role;
use UAMSWeb\AuditorRole\Session;
use UAMSWeb\AuditorRole\Tests\Support\IntegrationTestCase;
use WP_Admin_Bar;

final class AdminBarTest extends IntegrationTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        require_once ABSPATH.'wp-includes/class-wp-admin-bar.php';
        Role::maybe_register();
        Session::reset();
    }

    private function bar_for(string $role): WP_Admin_Bar
    {
        $user = self::factory()->user->create(['role' => $role]);
        wp_set_current_user(is_int($user) ? $user : 0);
        Session::reset();

        $bar = new WP_Admin_Bar;
        AdminBar::add_badge($bar);

        return $bar;
    }

    public function test_an_auditor_sees_the_badge(): void
    {
        $node = $this->bar_for(Role::NAME)->get_node(AdminBar::NODE);

        $this->assertNotNull($node);

        $data = (array) $node;
        $meta = (array) ($data['meta'] ?? []);

        $this->assertSame('Read-only audit mode', $data['title'] ?? null);
        $this->assertIsString($meta['title'] ?? null);
        $this->assertStringContainsString('Changes are refused and recorded', $meta['title']);
    }

    public function test_an_administrator_does_not(): void
    {
        $this->assertNull($this->bar_for('administrator')->get_node(AdminBar::NODE));
    }
}
