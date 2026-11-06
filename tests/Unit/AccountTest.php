<?php

/**
 * UAMSWeb\AuditorRole\Account, with WordPress mocked.
 *
 * @see includes/Account.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Account;

beforeEach(function (): void {
    uamswp_auditor_role_reset();
});

it('keeps only real calendar dates', function (string $date, string $clean): void {
    expect(Account::clean_date($date))->toBe($clean);
})->with([
    ['2026-12-31', '2026-12-31'],
    [' 2026-12-31 ', '2026-12-31'],
    ['2026-02-30', ''],
    ['12/31/2026', ''],
    ['', ''],
]);

it('ends access after the end date, not on it', function (string $end, string $today, bool $ended): void {
    expect(Account::has_ended($end, $today))->toBe($ended);
})->with([
    'before' => ['2026-12-31', '2026-12-30', false],
    'on the day' => ['2026-12-31', '2026-12-31', false],
    'after' => ['2026-12-31', '2027-01-01', true],
    'no end date' => ['', '2099-01-01', false],
]);

it('refuses a sign-in after the end date', function (): void {
    Functions\when('get_user_meta')->justReturn('2026-01-01');
    Functions\when('wp_date')->justReturn('2026-11-05');
    Functions\when('get_option')->justReturn('');
    Functions\when('__')->returnArg();

    $user = uamswp_auditor_role_user_with(3, 'audit', ['auditor']);

    expect(Account::refuse_expired_login($user))->toBeInstanceOf(WP_Error::class);
});

it('lets a sign-in through before the end date, with none, and for other roles', function (): void {
    Functions\when('wp_date')->justReturn('2026-11-05');
    $auditor = uamswp_auditor_role_user_with(3, 'audit', ['auditor']);
    $admin = uamswp_auditor_role_user_with(4, 'admin', ['administrator']);

    Functions\when('get_user_meta')->justReturn('2026-12-31');
    expect(Account::refuse_expired_login($auditor))->toBe($auditor);

    Functions\when('get_user_meta')->justReturn('');
    expect(Account::refuse_expired_login($auditor))->toBe($auditor);

    Functions\when('get_user_meta')->justReturn('2020-01-01');
    expect(Account::refuse_expired_login($admin))->toBe($admin);
});

it('turns off application passwords and password fields for an Auditor only', function (): void {
    $auditor = uamswp_auditor_role_user_with(3, 'audit', ['auditor']);
    $admin = uamswp_auditor_role_user_with(4, 'admin', ['administrator']);

    expect(Account::application_passwords(true, $auditor))->toBeFalse()
        ->and(Account::application_passwords(true, $admin))->toBeTrue()
        ->and(Account::show_password_fields(true, $auditor))->toBeFalse()
        ->and(Account::show_password_fields(true, $admin))->toBeTrue();
});
