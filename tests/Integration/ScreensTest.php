<?php

/**
 * The hidden screens (#10), against WordPress: a menu entry, a direct URL and a
 * filter removal, for an Auditor and an Administrator.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Tests\Integration;

use UAMSWeb\AuditorRole\RequestGuard;
use UAMSWeb\AuditorRole\Role;
use UAMSWeb\AuditorRole\Screens;
use UAMSWeb\AuditorRole\Session;
use UAMSWeb\AuditorRole\Tests\Support\IntegrationTestCase;
use WPDieException;

final class ScreensTest extends IntegrationTestCase
{
    private int $auditor;

    private int $admin;

    /** @var array<string, mixed> */
    private array $saved = [];

    public function set_up(): void
    {
        parent::set_up();

        Role::maybe_register();

        $this->auditor = $this->id(self::factory()->user->create(['role' => Role::NAME]));
        $this->admin = $this->id(self::factory()->user->create(['role' => 'administrator']));

        if (is_multisite()) {
            grant_super_admin($this->admin);
        }

        foreach (['menu', 'submenu', 'pagenow'] as $global) {
            $this->saved[$global] = $GLOBALS[$global] ?? null;
        }

        $_SERVER['REQUEST_METHOD'] = 'GET';
        set_current_screen('dashboard');
        Session::reset();
        RequestGuard::reset();
    }

    public function tear_down(): void
    {
        foreach ($this->saved as $global => $value) {
            $GLOBALS[$global] = $value;
        }

        $_GET = [];
        $_REQUEST = [];
        set_current_screen('front');

        parent::tear_down();
    }

    private function build_menu(): void
    {
        $GLOBALS['menu'] = [
            10 => ['WP Pusher', 'manage_options', 'wppusher'],
            20 => ['Tools', 'edit_posts', 'tools.php'],
        ];
        $GLOBALS['submenu'] = [
            'tools.php' => [
                5 => ['Export', 'export', 'export.php'],
                10 => ['Cron Events', 'manage_options', 'wp-crontrol'],
            ],
            'admin.php' => [
                5 => ['Entries', 'manage_options', 'gf_entries'],
            ],
        ];
    }

    public function test_hidden_entries_leave_the_menu_for_an_auditor_only(): void
    {
        wp_set_current_user($this->auditor);
        $this->build_menu();
        Screens::remove_menu_entries();

        $this->assertSame(['tools.php'], array_column(self::as_array($GLOBALS['menu']), 2));
        $this->assertSame(['wp-crontrol'], array_column(self::as_array(self::value_at($GLOBALS, 'submenu', 'tools.php')), 2));
        $this->assertSame([], self::value_at($GLOBALS, 'submenu', 'admin.php'));

        Session::reset();
        wp_set_current_user($this->admin);
        $this->build_menu();
        Screens::remove_menu_entries();

        $this->assertSame(['wppusher', 'tools.php'], array_column(self::as_array($GLOBALS['menu']), 2));
        $this->assertCount(2, self::as_array(self::value_at($GLOBALS, 'submenu', 'tools.php')));
    }

    public function test_a_hidden_screen_is_refused_by_direct_url_for_an_auditor(): void
    {
        wp_set_current_user($this->auditor);
        $GLOBALS['pagenow'] = 'admin.php';
        $_GET = ['page' => 'gf_entries'];

        try {
            RequestGuard::check();
            $this->fail('The hidden screen was not refused.');
        } catch (WPDieException $refused) {
            $this->assertStringContainsString('hidden from Auditor accounts', $refused->getMessage());
        }

        $this->assertSame('hidden:admin.php?page=gf_entries', RequestGuard::refusal());
    }

    public function test_an_administrator_opens_a_hidden_screen(): void
    {
        wp_set_current_user($this->admin);
        $GLOBALS['pagenow'] = 'admin.php';
        $_GET = ['page' => 'gf_entries'];

        RequestGuard::check();

        $this->assertNull(RequestGuard::refusal());
    }

    public function test_removing_an_entry_through_the_filter_opens_that_screen(): void
    {
        $open = static fn (array $hidden): array => array_values(array_filter($hidden, static fn (mixed $entry): bool => ! in_array($entry, ['page:gf_entries', 'export.php'], true)));
        add_filter(Screens::FILTER, $open);

        wp_set_current_user($this->auditor);
        $GLOBALS['pagenow'] = 'admin.php';
        $_GET = ['page' => 'gf_entries'];

        RequestGuard::check();
        $this->assertNull(RequestGuard::refusal());

        $this->build_menu();
        Screens::remove_menu_entries();
        $this->assertSame(['export.php', 'wp-crontrol'], array_column(self::as_array(self::value_at($GLOBALS, 'submenu', 'tools.php')), 2));
        $this->assertSame(['gf_entries'], array_column(self::as_array(self::value_at($GLOBALS, 'submenu', 'admin.php')), 2));

        remove_filter(Screens::FILTER, $open);
    }

    private function id(mixed $id): int
    {
        if (! is_int($id)) {
            $this->fail('The factory did not create the object.');
        }

        return $id;
    }
}
