<?php

/**
 * UAMSWeb\AuditorRole\RegenerateThumbnails, with WordPress mocked: the
 * plugin's capability is `do_not_allow` for an Auditor and its own answer
 * stands for another role.
 *
 * @see includes/RegenerateThumbnails.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use UAMSWeb\AuditorRole\RegenerateThumbnails;

beforeEach(function (): void {
    uamswp_auditor_role_reset();
});

it('hooks the plugin\'s capability filter last', function (): void {
    Filters\expectAdded('regenerate_thumbs_cap')->once()->with([RegenerateThumbnails::class, 'capability'], PHP_INT_MAX);

    RegenerateThumbnails::boot();
});

it('answers do_not_allow for an Auditor, whatever the answer so far', function (mixed $capability): void {
    uamswp_auditor_role_as(true);

    expect(RegenerateThumbnails::capability($capability))->toBe('do_not_allow');
})->with([
    'the default' => ['manage_options'],
    'a filtered capability' => ['upload_files'],
    'not a string' => [null],
]);

it('leaves the answer alone for another role', function (mixed $capability): void {
    uamswp_auditor_role_as(false);

    expect(RegenerateThumbnails::capability($capability))->toBe($capability);
})->with([
    'the default' => ['manage_options'],
    'a filtered capability' => ['upload_files'],
    'not a string' => [null],
]);
