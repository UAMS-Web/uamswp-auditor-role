<?php

/**
 * #15: the Auditor's own account, against WordPress and a database.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Tests\Integration;

use UAMSWeb\AuditorRole\Account;
use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\Log;
use UAMSWeb\AuditorRole\RequestGuard;
use UAMSWeb\AuditorRole\Role;
use UAMSWeb\AuditorRole\Session;
use UAMSWeb\AuditorRole\Tests\Support\IntegrationTestCase;
use WP_Error;
use WP_User;
use WPDieException;

final class AccountTest extends IntegrationTestCase
{
    private int $auditor;

    public function set_up(): void
    {
        parent::set_up();

        Role::maybe_register();
        Log::maybe_install();

        $id = self::factory()->user->create(['role' => Role::NAME, 'user_login' => 'audit'.wp_rand(), 'user_pass' => 'old-password']);
        $this->auditor = is_int($id) ? $id : 0;

        Session::reset();
        DatabaseGuard::reset();
        RequestGuard::reset();
        $GLOBALS['pagenow'] = 'index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_REQUEST = [];
        $_GET = [];
        $_POST = [];
    }

    public function tear_down(): void
    {
        $GLOBALS['pagenow'] = 'index.php';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_REQUEST = [];

        parent::tear_down();
    }

    private function user(): WP_User
    {
        $user = get_userdata($this->auditor);
        $this->assertInstanceOf(WP_User::class, $user);

        return $user;
    }

    private function sign_in(): mixed
    {
        return wp_authenticate($this->user()->user_login, 'old-password');
    }

    public function test_an_account_signs_in_through_its_end_date_and_is_refused_after_it(): void
    {
        update_user_meta($this->auditor, Account::END_DATE, wp_date('Y-m-d'));
        $this->assertInstanceOf(WP_User::class, $this->sign_in());

        update_user_meta($this->auditor, Account::END_DATE, wp_date('Y-m-d', time() - DAY_IN_SECONDS));
        $refused = $this->sign_in();

        $this->assertInstanceOf(WP_Error::class, $refused);
        $this->assertSame('uamswp_auditor_role_expired', $refused->get_error_code());
    }

    public function test_an_account_with_no_end_date_never_expires(): void
    {
        delete_user_meta($this->auditor, Account::END_DATE);

        $this->assertInstanceOf(WP_User::class, $this->sign_in());
        $this->assertFalse(Account::is_expired($this->auditor));
    }

    public function test_an_auditor_cannot_create_an_application_password(): void
    {
        add_filter('wp_is_application_passwords_available', '__return_true');

        $this->assertFalse(wp_is_application_passwords_available_for_user($this->auditor));
        $this->assertFalse(user_can($this->auditor, 'create_app_password', $this->auditor));
    }

    public function test_the_profile_screen_offers_no_password_fields_and_refuses_a_save(): void
    {
        $this->assertFalse(apply_filters('show_password_fields', true, $this->user()));

        wp_set_current_user($this->auditor);
        $GLOBALS['pagenow'] = 'profile.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['email' => 'changed@example.org', 'pass1' => 'new-password', 'pass2' => 'new-password'];

        try {
            RequestGuard::check();
            $this->fail('The profile save was not refused.');
        } catch (WPDieException) {
            // Refused before the profile handler runs.
        }

        $this->assertSame('method:POST', RequestGuard::refusal());

        // And underneath, the database refuses the change even if a handler ran.
        $this->assertTrue(Session::is_auditor());
        $result = wp_update_user(['ID' => $this->auditor, 'user_email' => 'changed@example.org']);
        clean_user_cache($this->auditor);

        $this->assertNotSame('changed@example.org', $this->user()->user_email, is_int($result) ? 'update refused' : 'error');
    }

    public function test_a_password_reset_by_email_still_works(): void
    {
        wp_set_current_user($this->auditor);
        $GLOBALS['pagenow'] = 'wp-login.php';
        $_REQUEST['action'] = 'rp';

        $this->assertFalse(Session::is_auditor(), 'A reset on wp-login.php is not an Auditor session.');

        reset_password($this->user(), 'reset-password');
        clean_user_cache($this->auditor);

        $this->assertTrue(wp_check_password('reset-password', $this->user()->user_pass, $this->auditor));
    }

    public function test_an_administrator_saves_an_end_date_from_the_profile_form(): void
    {
        $admin = self::factory()->user->create(['role' => 'administrator']);
        $this->assertIsInt($admin);

        if (is_multisite()) {
            grant_super_admin($admin);
        }

        wp_set_current_user($admin);

        ob_start();
        Account::render_end_date_field($this->user());
        $html = (string) ob_get_clean();

        // The form carries the nonce and the date under different names.
        $this->assertStringContainsString('name="'.Account::NONCE.'"', $html);
        $this->assertStringContainsString('name="'.Account::END_DATE.'"', $html);
        $nonce = preg_match('/name="'.Account::NONCE.'" value="(?<nonce>[a-f0-9]+)"/', $html, $found) === 1 ? $found['nonce'] : '';
        $this->assertNotSame('', $nonce);

        $_POST = [Account::NONCE => $nonce, Account::END_DATE => '2027-01-31'];
        $_REQUEST = $_POST;
        Account::save_end_date($this->auditor);

        $this->assertSame('2027-01-31', Account::end_date($this->auditor));
    }
}
