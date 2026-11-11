<?php

/**
 * UAMSWeb\AuditorRole\Acf, with WordPress mocked: ACF's admin is off and its
 * capability is `do_not_allow` for an Auditor, and ACF's own answers stand
 * for another role.
 *
 * @see includes/Acf.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use UAMSWeb\AuditorRole\Acf;

beforeEach(function (): void {
    uamswp_auditor_role_reset();
});

it('hooks both ACF settings filters last', function (): void {
    Filters\expectAdded('acf/settings/show_admin')->once()->with([Acf::class, 'show_admin'], PHP_INT_MAX);
    Filters\expectAdded('acf/settings/capability')->once()->with([Acf::class, 'capability'], PHP_INT_MAX);

    Acf::boot();
});

it('turns the admin off and denies the capability for an Auditor, whatever the answer so far', function (mixed $value): void {
    uamswp_auditor_role_as(true);

    expect(Acf::show_admin($value))->toBeFalse()
        ->and(Acf::capability($value))->toBe('do_not_allow');
})->with([
    'the defaults' => [true],
    'a filtered capability' => ['edit_posts'],
    'not set' => [null],
]);

it('leaves both answers alone for another role', function (mixed $value): void {
    uamswp_auditor_role_as(false);

    expect(Acf::show_admin($value))->toBe($value)
        ->and(Acf::capability($value))->toBe($value);
})->with([
    'the defaults' => [true],
    'a filtered capability' => ['manage_options'],
    'not set' => [null],
]);
