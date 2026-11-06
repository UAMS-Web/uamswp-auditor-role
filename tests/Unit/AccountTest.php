<?php

/**
 * UAMSWeb\AuditorRole\Account, with WordPress mocked.
 *
 * Mutation-clean under `composer mutate -- --path=includes/Account.php`, with
 * no equivalent mutants. The log rows are read through a mocked `$wpdb`, so a
 * test sees the row Log::write() would insert.
 *
 * @see includes/Account.php
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Account;
use UAMSWeb\AuditorRole\Log;

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    Functions\when('wp_date')->justReturn('2026-11-05');
});

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
    $_POST = [];
});

/**
 * Capture the rows Log::write() inserts.
 *
 * @return ArrayObject<int, array<string, mixed>>
 */
function uamswp_auditor_role_g4_log_rows(): ArrayObject
{
    $rows = new ArrayObject;

    Functions\when('get_option')->alias(static fn (string $name): mixed => $name === Log::OPTION ? Log::SCHEMA : false);
    Functions\when('get_current_blog_id')->justReturn(1);
    Functions\when('sanitize_key')->returnArg();
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('wp_unslash')->returnArg();
    Functions\when('esc_url_raw')->returnArg();

    $GLOBALS['wpdb'] = new class($rows)
    {
        public string $prefix = 'wp_';

        /**
         * @param  ArrayObject<int, array<string, mixed>>  $rows
         */
        public function __construct(private readonly ArrayObject $rows) {}

        /**
         * @param  array<string, mixed>  $row
         */
        public function insert(string $table, array $row): int
        {
            $this->rows->append($row);

            return 1;
        }
    };

    return $rows;
}

function uamswp_auditor_role_g4_stub_output(): void
{
    Functions\when('esc_html_e')->echoArg();
    Functions\when('esc_html__')->returnArg();
    Functions\when('esc_html')->returnArg();
    Functions\when('esc_attr')->returnArg();
    Functions\when('__')->returnArg();
}

it('hooks each rule where WordPress applies it', function (): void {
    Filters\expectAdded('authenticate')->once()->with([Account::class, 'refuse_expired_login'], 99);
    Actions\expectAdded('init')->once()->with([Account::class, 'end_expired_session'], 0);
    Filters\expectAdded('wp_is_application_passwords_available_for_user')->once()->with([Account::class, 'application_passwords'], 10, 2);
    Filters\expectAdded('show_password_fields')->once()->with([Account::class, 'show_password_fields'], 10, 2);
    Actions\expectAdded('wp_login')->once()->with([Account::class, 'skip_admin_email_check'], 0, 2);
    Actions\expectAdded('show_user_profile')->once()->with([Account::class, 'render_own_notice']);
    Actions\expectAdded('edit_user_profile')->once()->with([Account::class, 'render_end_date_field']);
    Actions\expectAdded('edit_user_profile_update')->once()->with([Account::class, 'save_end_date']);

    Account::boot();
});

it('keeps only real calendar dates', function (string $date, string $clean): void {
    expect(Account::clean_date($date))->toBe($clean);
})->with([
    ['2026-12-31', '2026-12-31'],
    [' 2026-12-31 ', '2026-12-31'],
    'leap day in a leap year' => ['2024-02-29', '2024-02-29'],
    'leap day in another year' => ['2026-02-29', ''],
    'month 13' => ['2026-13-01', ''],
    'day 31 of a 30-day month' => ['2026-04-31', ''],
    ['12/31/2026', ''],
    ['', ''],
]);

it('names the nonce field apart from the date field', function (): void {
    expect(Account::NONCE)->toBe('uamswp_auditor_role_end_date_nonce')
        ->and(Account::END_DATE)->toBe('uamswp_auditor_role_end_date');
});

it('reads the single end date from user meta', function (mixed $stored, string $date): void {
    Functions\expect('get_user_meta')->once()->with(3, Account::END_DATE, true)->andReturn($stored);

    expect(Account::end_date(3))->toBe($date);
})->with([
    'a date' => ['2026-12-31', '2026-12-31'],
    'not a date' => ['soon', ''],
    'none' => ['', ''],
    'not a string' => [['2026-12-31'], ''],
]);

it('ends access after the end date, not on it', function (string $end, string $today, bool $ended): void {
    expect(Account::has_ended($end, $today))->toBe($ended);
})->with([
    'before' => ['2026-12-31', '2026-12-30', false],
    'on the day' => ['2026-12-31', '2026-12-31', false],
    'after' => ['2026-12-31', '2027-01-01', true],
    'no end date' => ['', '2099-01-01', false],
]);

it('treats an unreadable date as not yet expired', function (): void {
    Functions\when('get_user_meta')->justReturn('2026-01-01');
    Functions\when('wp_date')->justReturn(false);

    expect(Account::is_expired(3))->toBeFalse();
});

it('refuses a sign-in after the end date and records it', function (): void {
    $rows = uamswp_auditor_role_g4_log_rows();
    Functions\when('get_user_meta')->justReturn('2026-01-01');
    Functions\when('__')->returnArg();

    $result = Account::refuse_expired_login(uamswp_auditor_role_user_with(3, 'audit', ['auditor']));

    expect($result)->toBeInstanceOf(WP_Error::class)
        ->and($result->code)->toBe('uamswp_auditor_role_expired')
        ->and($rows->getArrayCopy())->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['event' => 'login_refused', 'user_id' => 3, 'user_login' => 'audit', 'detail' => 'end date 2026-01-01']);
});

it('lets a sign-in through before the end date, with none, for other roles, and for errors', function (): void {
    $rows = uamswp_auditor_role_g4_log_rows();
    $auditor = uamswp_auditor_role_user_with(3, 'audit', ['auditor']);
    $admin = uamswp_auditor_role_user_with(4, 'admin', ['administrator']);
    $error = new WP_Error('incorrect_password');

    Functions\when('get_user_meta')->justReturn('2026-12-31');
    expect(Account::refuse_expired_login($auditor))->toBe($auditor);

    Functions\when('get_user_meta')->justReturn('');
    expect(Account::refuse_expired_login($auditor))->toBe($auditor);

    Functions\when('get_user_meta')->justReturn('2020-01-01');
    expect(Account::refuse_expired_login($admin))->toBe($admin)
        ->and(Account::refuse_expired_login($error))->toBe($error)
        ->and(Account::refuse_expired_login(null))->toBeNull()
        ->and($rows->getArrayCopy())->toBe([]);
});

it('ends an Auditor session past its end date: records it, revokes every session and stops', function (): void {
    $rows = uamswp_auditor_role_g4_log_rows();
    uamswp_auditor_role_as(true);
    uamswp_auditor_role_g4_stub_output();
    Functions\when('get_user_meta')->justReturn('2026-01-01');
    Functions\expect('wp_destroy_all_sessions')->once()->ordered();
    Functions\expect('wp_clear_auth_cookie')->once()->ordered();
    Functions\expect('wp_die')->once()->with('This Auditor account has reached its end date.', 'Read-only audit mode', ['response' => 403])->ordered()
        ->andReturnUsing(static function (): never {
            throw new RuntimeException('wp_die');
        });

    expect(fn () => Account::end_expired_session())->toThrow(RuntimeException::class, 'wp_die')
        ->and($rows[0])->toMatchArray(['event' => 'login_refused', 'user_id' => 7, 'user_login' => 'audit', 'detail' => 'session ended at end date']);
});

it('leaves a session alone before the end date and for other roles', function (bool $auditor, string $end): void {
    uamswp_auditor_role_as($auditor);
    Functions\when('get_user_meta')->justReturn($end);
    Functions\expect('wp_destroy_all_sessions')->never();
    Functions\expect('wp_die')->never();

    Account::end_expired_session();
})->with([
    'Auditor before the end date' => [true, '2026-12-31'],
    'Auditor with no end date' => [true, ''],
    'administrator past the date' => [false, '2026-01-01'],
]);

it('turns off application passwords and password fields for an Auditor only', function (): void {
    $auditor = uamswp_auditor_role_user_with(3, 'audit', ['auditor']);
    $admin = uamswp_auditor_role_user_with(4, 'admin', ['administrator']);

    // A filter may pass a value that is not a boolean; the answer always is one.
    expect(Account::application_passwords(true, $auditor))->toBeFalse()
        ->and(Account::application_passwords(1, $admin))->toBeTrue()
        ->and(Account::application_passwords(0, $admin))->toBeFalse()
        ->and(Account::show_password_fields(true, $auditor))->toBeFalse()
        ->and(Account::show_password_fields(1, $admin))->toBeTrue()
        ->and(Account::show_password_fields(0, $admin))->toBeFalse();
});

it('skips the admin email confirmation after an Auditor signs in', function (): void {
    Filters\expectAdded('admin_email_check_interval')->once()->with('__return_zero');

    Account::skip_admin_email_check('audit', uamswp_auditor_role_user_with(3, 'audit', ['auditor']));
});

it('keeps the admin email confirmation after another role signs in', function (): void {
    Filters\expectAdded('admin_email_check_interval')->never();

    Account::skip_admin_email_check('admin', uamswp_auditor_role_user_with(4, 'admin', ['administrator']));
});

it('tells an Auditor on its own profile that it is read-only, with its end date', function (string $end, string $line): void {
    uamswp_auditor_role_g4_stub_output();
    Functions\expect('get_user_meta')->once()->with(3, Account::END_DATE, true)->andReturn($end);

    ob_start();
    Account::render_own_notice(uamswp_auditor_role_user_with(3, 'audit', ['auditor']));
    $html = (string) ob_get_clean();

    expect($html)->toContain('<h2>Read-only audit mode</h2>')
        ->toContain('This profile cannot be changed. To set a new password, use "Lost your password?" on the sign-in screen.')
        ->toContain($line);
})->with([
    'no end date' => ['', 'This account has no end date.'],
    'an end date' => ['2026-12-31', 'This account works through 2026-12-31.'],
]);

it('prints nothing on another role\'s own profile', function (): void {
    uamswp_auditor_role_g4_stub_output();

    ob_start();
    Account::render_own_notice(uamswp_auditor_role_user_with(4, 'admin', ['administrator']));

    expect((string) ob_get_clean())->toBe('');
});

it('shows the end date field on an Auditor\'s profile to someone who can manage it', function (): void {
    uamswp_auditor_role_g4_stub_output();
    Functions\expect('current_user_can')->once()->with('promote_user', 3)->andReturn(true);
    Functions\expect('get_user_meta')->once()->with(3, Account::END_DATE, true)->andReturn('2026-12-31');
    Functions\expect('wp_nonce_field')->once()->with(Account::NONCE, Account::NONCE)->andReturnUsing(static function (): void {
        echo '[nonce]';
    });

    ob_start();
    Account::render_end_date_field(uamswp_auditor_role_user_with(3, 'audit', ['auditor']));
    $html = (string) ob_get_clean();

    expect($html)->toStartWith('[nonce]')
        ->toContain('<h2>Auditor access</h2>')
        ->toContain('<label for="uamswp-auditor-role-end-date">End date</label>')
        ->toContain('name="'.Account::END_DATE.'" value="2026-12-31"')
        ->toContain('Optional. The account can sign in through this date, in the site time zone, and is refused after it. Leave empty for no end date.');
});

it('shows no end date field without the capability, or on another role\'s profile', function (): void {
    uamswp_auditor_role_g4_stub_output();
    Functions\expect('wp_nonce_field')->never();

    Functions\when('current_user_can')->justReturn(false);
    ob_start();
    Account::render_end_date_field(uamswp_auditor_role_user_with(3, 'audit', ['auditor']));
    expect((string) ob_get_clean())->toBe('');

    Functions\when('current_user_can')->justReturn(true);
    ob_start();
    Account::render_end_date_field(uamswp_auditor_role_user_with(4, 'admin', ['administrator']));
    expect((string) ob_get_clean())->toBe('');
});

/**
 * Arrange a save of the end date field for an Auditor account with ID 3.
 */
function uamswp_auditor_role_g4_save(bool $can = true, bool $auditor = true): void
{
    Functions\when('get_userdata')->alias(static fn (int $id): mixed => $id === 3 ? uamswp_auditor_role_user_with(3, 'audit', [$auditor ? 'auditor' : 'editor']) : false);
    Functions\when('current_user_can')->alias(static fn (string $cap, int $id): bool => $can && $cap === 'promote_user' && $id === 3);
    Functions\when('sanitize_text_field')->alias('trim');
    Functions\when('wp_unslash')->returnArg();
}

it('saves a clean end date after checking the capability and the nonce', function (mixed $user_id): void {
    uamswp_auditor_role_g4_save();
    $_POST = [Account::NONCE => 'n', Account::END_DATE => ' 2026-12-31 '];
    Functions\expect('check_admin_referer')->once()->with(Account::NONCE, Account::NONCE)->ordered();
    Functions\expect('update_user_meta')->once()->with(3, Account::END_DATE, '2026-12-31')->ordered();
    Functions\expect('delete_user_meta')->never();

    Account::save_end_date($user_id);
})->with(['an integer' => [3], 'a numeric string' => ['3']]);

it('deletes the end date when the field is empty, missing or not a date', function (array $post): void {
    uamswp_auditor_role_g4_save();
    $_POST = [Account::NONCE => 'n', ...$post];
    Functions\when('check_admin_referer')->justReturn(1);
    Functions\expect('delete_user_meta')->once()->with(3, Account::END_DATE);
    Functions\expect('update_user_meta')->never();

    Account::save_end_date(3);
})->with([
    'empty' => [[Account::END_DATE => '']],
    'missing' => [[]],
    'not a date' => [[Account::END_DATE => '2026-02-30']],
]);

it('saves nothing without the role, the capability or the nonce field', function (mixed $user_id, bool $can, bool $auditor, bool $nonce): void {
    uamswp_auditor_role_g4_save($can, $auditor);
    $_POST = $nonce ? [Account::NONCE => 'n', Account::END_DATE => '2026-12-31'] : [Account::END_DATE => '2026-12-31'];
    Functions\expect('check_admin_referer')->never();
    Functions\expect('update_user_meta')->never();
    Functions\expect('delete_user_meta')->never();

    Account::save_end_date($user_id);
})->with([
    'not an Auditor' => [3, true, false, true],
    'no capability' => [3, false, true, true],
    'no nonce field' => [3, true, true, false],
]);

it('reads a user ID that is not a number as no user', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('sanitize_text_field')->alias('trim');
    Functions\when('wp_unslash')->returnArg();
    $_POST = [Account::NONCE => 'n', Account::END_DATE => '2026-12-31'];
    Functions\expect('get_userdata')->once()->with(Mockery::on(static fn (mixed $id): bool => $id === 0))->andReturn(false);
    Functions\expect('update_user_meta')->never();

    Account::save_end_date('x');
});

it('passes a numeric user ID on as an integer', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('sanitize_text_field')->alias('trim');
    Functions\when('wp_unslash')->returnArg();
    $_POST = [Account::NONCE => 'n', Account::END_DATE => '2026-12-31'];
    Functions\expect('get_userdata')->once()->with(Mockery::on(static fn (mixed $id): bool => $id === 3))->andReturn(uamswp_auditor_role_user_with(3, 'audit', ['auditor']));
    Functions\when('check_admin_referer')->justReturn(1);
    Functions\expect('update_user_meta')->once()->with(3, Account::END_DATE, '2026-12-31');

    Account::save_end_date('3');
});
