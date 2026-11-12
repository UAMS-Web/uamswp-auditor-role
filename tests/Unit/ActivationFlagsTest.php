<?php

/**
 * UAMSWeb\AuditorRole\ActivationFlags, with WordPress mocked: each listed
 * activation flag reads as unset for an Auditor and is left alone otherwise.
 *
 * @see includes/ActivationFlags.php
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use UAMSWeb\AuditorRole\ActivationFlags;

beforeEach(function (): void {
    uamswp_auditor_role_reset();
});

it('lists SEOPress\'s activation option and WP Mail SMTP\'s activation transient by default', function (): void {
    expect(ActivationFlags::defaults())->toBe(['option:seopress_activated', 'transient:wp_mail_smtp_activation_redirect']);
});

it('registers late on init', function (): void {
    Actions\expectAdded('init')->once()->with([ActivationFlags::class, 'register'], PHP_INT_MAX);

    ActivationFlags::boot();
});

it('filters the read of each flag, an option or a transient, a colon in its name kept', function (): void {
    Filters\expectApplied(ActivationFlags::FILTER)->once()->with(ActivationFlags::defaults())->andReturn(['transient:_x_welcome_redirect', 'option:vendor:welcome']);
    Filters\expectAdded('option_seopress_activated')->once()->with([ActivationFlags::class, 'read'], PHP_INT_MAX);
    Filters\expectAdded('transient_wp_mail_smtp_activation_redirect')->once()->with([ActivationFlags::class, 'read'], PHP_INT_MAX);
    Filters\expectAdded('transient__x_welcome_redirect')->once()->with([ActivationFlags::class, 'read'], PHP_INT_MAX);
    Filters\expectAdded('option_vendor:welcome')->once()->with([ActivationFlags::class, 'read'], PHP_INT_MAX);

    ActivationFlags::register();
});

it('keeps the defaults and adds a deployment\'s valid entries, each once', function (mixed $added, array $flags): void {
    Filters\expectApplied(ActivationFlags::FILTER)->once()->andReturn($added);

    expect(ActivationFlags::flags())->toBe($flags);
})->with([
    'an option and a transient' => [['option:a', 'transient:b'], ['option:seopress_activated', 'transient:wp_mail_smtp_activation_redirect', 'option:a', 'transient:b']],
    'the defaults dropped' => [[], ['option:seopress_activated', 'transient:wp_mail_smtp_activation_redirect']],
    'a repeat' => [['option:seopress_activated', 'option:a', 'option:a'], ['option:seopress_activated', 'transient:wp_mail_smtp_activation_redirect', 'option:a']],
    'not a list' => ['option:a', ['option:seopress_activated', 'transient:wp_mail_smtp_activation_redirect']],
    'malformed entries' => [[7, null, 'a', 'option:', 'option:a b', 'site_option:a', 'xoption:a', 'option:c'], ['option:seopress_activated', 'transient:wp_mail_smtp_activation_redirect', 'option:c']],
]);

it('reads a flag as unset for an Auditor', function (mixed $value): void {
    uamswp_auditor_role_as(true);

    expect(ActivationFlags::read($value))->toBeFalse();
})->with([
    'yes' => ['yes'],
    'true' => [true],
    'a timestamp' => [1789000000],
]);

it('leaves a flag alone for another role', function (): void {
    uamswp_auditor_role_as(false);

    expect(ActivationFlags::read('yes'))->toBe('yes');
});
