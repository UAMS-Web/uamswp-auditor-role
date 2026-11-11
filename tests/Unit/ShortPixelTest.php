<?php

/**
 * UAMSWeb\AuditorRole\ShortPixel, with WordPress mocked: the processor is off
 * for an Auditor and ShortPixel's own answer stands for another role.
 *
 * @see includes/ShortPixel.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use UAMSWeb\AuditorRole\ShortPixel;

beforeEach(function (): void {
    uamswp_auditor_role_reset();
});

it('hooks ShortPixel\'s processor filter last', function (): void {
    Filters\expectAdded('shortpixel/processorjs/disable')->once()->with([ShortPixel::class, 'disable_processor'], PHP_INT_MAX);

    ShortPixel::boot();
});

it('turns the processor off for an Auditor, whatever the answer so far', function (mixed $disabled): void {
    uamswp_auditor_role_as(true);

    expect(ShortPixel::disable_processor($disabled))->toBeTrue();
})->with([
    'processor on' => [false],
    'processor off' => [true],
    'not a boolean' => ['0'],
]);

it('leaves the answer alone for another role', function (mixed $disabled): void {
    uamswp_auditor_role_as(false);

    expect(ShortPixel::disable_processor($disabled))->toBe($disabled);
})->with([
    'processor on' => [false],
    'processor off' => [true],
    'not a boolean' => ['0'],
]);
