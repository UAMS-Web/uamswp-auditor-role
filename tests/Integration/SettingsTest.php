<?php

/**
 * The settings page inside a real WordPress: what the Unit suite's mocks cannot see.
 *
 * A class-based PHPUnit 9.6 test, run from tests/Integration's own Composer
 * setup, because WordPress' test suite supports PHPUnit 9 only.
 *
 * @see includes/Settings.php
 */

declare(strict_types=1);

namespace UAMSWeb\PluginTemplate\Tests\Integration;

use UAMSWeb\PluginTemplate\Plugin;
use UAMSWeb\PluginTemplate\Settings;
use UAMSWeb\PluginTemplate\Tests\Support\IntegrationTestCase;
use WPDieException;

final class SettingsTest extends IntegrationTestCase
{
    public function test_registers_its_hooks(): void
    {
        $this->assertNotFalse(has_action('admin_menu', [Settings::class, 'register_page']));
        $this->assertNotFalse(has_action('admin_post_'.Settings::ACTION, [Settings::class, 'handle_save']));
    }

    public function test_sanitizes_the_option_on_update(): void
    {
        Settings::register_setting();

        update_option(Settings::OPTION, '<b>Hi</b>');

        $this->assertSame('Hi', get_option(Settings::OPTION));
    }

    public function test_the_page_escapes_the_stored_greeting(): void
    {
        $this->actingAs('administrator');
        update_option(Settings::OPTION, 'a"b');

        ob_start();
        Settings::render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('value="a&quot;b"', $html);
        $this->assertStringContainsString(Settings::NONCE, $html);
    }

    public function test_a_subscriber_cannot_view_the_page(): void
    {
        $this->actingAs('subscriber');
        add_filter('wp_die_handler', static fn (): callable => static function (): never {
            throw new WPDieException('denied');
        });

        $this->expectException(WPDieException::class);

        Settings::render_page();
    }

    public function test_a_save_without_a_valid_nonce_is_refused(): void
    {
        $this->actingAs('administrator');
        add_filter('wp_die_handler', static fn (): callable => static function (): never {
            throw new WPDieException('bad nonce');
        });
        $_POST['greeting'] = 'x';

        $this->expectException(WPDieException::class);

        try {
            Settings::handle_save();
        } finally {
            unset($_POST['greeting']);
        }
    }

    public function test_activation_records_the_version_and_uninstall_is_the_only_remover(): void
    {
        delete_option('uams_plugin_template_version');

        Plugin::activate();
        Plugin::deactivate();

        $this->assertSame(UAMS_PLUGIN_TEMPLATE_VERSION, get_option('uams_plugin_template_version'));
    }

    public function test_uninstall_removes_the_options_from_every_site(): void
    {
        $siteIds = [get_current_blog_id()];

        if (is_multisite()) {
            $created = self::factory()->blog->create();
            $this->assertIsInt($created);
            $siteIds[] = $created;
        }

        foreach ($siteIds as $siteId) {
            $this->onSite($siteId, static function (): void {
                update_option(Settings::OPTION, 'kept until uninstall');
                update_option('uams_plugin_template_version', '0.1.0');
            });
        }

        define('WP_UNINSTALL_PLUGIN', 'uams-plugin-template/uams-plugin-template.php');
        require dirname(__DIR__, 2).'/uninstall.php';

        foreach ($siteIds as $siteId) {
            $this->onSite($siteId, function (): void {
                $this->assertFalse(get_option(Settings::OPTION));
                $this->assertFalse(get_option('uams_plugin_template_version'));
            });
        }
    }

    /**
     * Run a callback on a site: switch_to_blog() exists only on a multisite.
     */
    private function onSite(int $siteId, callable $callback): void
    {
        if (! is_multisite()) {
            $callback();

            return;
        }

        switch_to_blog($siteId);

        try {
            $callback();
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Make a new user with the role the current user.
     */
    private function actingAs(string $role): void
    {
        $id = self::factory()->user->create(['role' => $role]);

        if (! is_int($id)) {
            $this->fail('Could not create a '.$role.' user.');
        }

        wp_set_current_user($id);
    }
}
