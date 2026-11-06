<?php

/**
 * #18: the must-use loader stub, against WordPress.
 *
 * The suite's bootstrap loads the plugin's main file directly, so these tests
 * load the stub as WordPress would, from a mu-plugins/ layout in a temporary
 * directory: with the plugin folder beside it, and without.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Tests\Integration;

use UAMSWeb\AuditorRole\Role;
use UAMSWeb\AuditorRole\Tests\Support\IntegrationTestCase;

final class LoaderTest extends IntegrationTestCase
{
    private string $mu;

    public function set_up(): void
    {
        parent::set_up();

        $this->mu = sys_get_temp_dir().'/uamswp-auditor-role-mu-'.getmypid().'-'.wp_rand();
        mkdir($this->mu);
        copy(dirname(__DIR__, 2).'/loader/uamswp-auditor-role-loader.php', $this->mu.'/uamswp-auditor-role-loader.php');
    }

    public function tear_down(): void
    {
        if (is_link($this->mu.'/uamswp-auditor-role')) {
            unlink($this->mu.'/uamswp-auditor-role');
        }

        unlink($this->mu.'/uamswp-auditor-role-loader.php');
        rmdir($this->mu);

        parent::tear_down();
    }

    public function test_the_stub_loads_the_plugin_folder_beside_it(): void
    {
        symlink(dirname(__DIR__, 2), $this->mu.'/uamswp-auditor-role');

        // The main file is already loaded once, and stops at its own guard.
        $notices_before = has_action('admin_notices');
        require $this->mu.'/uamswp-auditor-role-loader.php';

        $this->assertSame($notices_before, has_action('admin_notices'));
        $this->assertNotNull(get_role(Role::NAME));
    }

    public function test_a_missing_plugin_folder_shows_a_notice_and_strips_every_auditor_capability(): void
    {
        $auditor = self::factory()->user->create(['role' => Role::NAME]);
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $this->assertIsInt($auditor);
        $this->assertIsInt($admin);

        $this->assertTrue(user_can($auditor, 'read'));

        require $this->mu.'/uamswp-auditor-role-loader.php';

        // Fails closed: no capability at all, and nothing fatal.
        $this->assertFalse(user_can($auditor, 'read'));
        $this->assertFalse(user_can($auditor, 'manage_options'));

        if (is_multisite()) {
            grant_super_admin($admin);
        }

        wp_set_current_user($admin);
        ob_start();
        do_action('admin_notices');
        $notice = (string) ob_get_clean();

        $this->assertStringContainsString('cannot find mu-plugins/uamswp-auditor-role/', $notice);
        $this->assertTrue(user_can($admin, 'manage_options'));
    }

    public function test_the_missing_folder_notice_is_for_administrators_only(): void
    {
        $editor = self::factory()->user->create(['role' => 'editor']);
        $this->assertIsInt($editor);

        require $this->mu.'/uamswp-auditor-role-loader.php';

        wp_set_current_user($editor);
        ob_start();
        do_action('admin_notices');
        do_action('network_admin_notices');

        $this->assertStringNotContainsString('uamswp-auditor-role', (string) ob_get_clean());
    }
}
