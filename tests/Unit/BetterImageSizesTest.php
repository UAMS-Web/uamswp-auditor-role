<?php

/**
 * UAMSWeb\AuditorRole\BetterImageSizes, with WordPress mocked: the row action
 * and the attachment field are dropped for an Auditor and kept for another role.
 *
 * @see includes/BetterImageSizes.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use UAMSWeb\AuditorRole\BetterImageSizes;

beforeEach(function (): void {
    uamswp_auditor_role_reset();
});

it('hooks both Media filters last', function (): void {
    Filters\expectAdded('media_row_actions')->once()->with([BetterImageSizes::class, 'remove_row_action'], PHP_INT_MAX);
    Filters\expectAdded('attachment_fields_to_edit')->once()->with([BetterImageSizes::class, 'remove_field'], PHP_INT_MAX);

    BetterImageSizes::boot();
});

it('drops only the Better Image Sizes row action for an Auditor', function (): void {
    uamswp_auditor_role_as(true, 'upload.php');

    expect(BetterImageSizes::remove_row_action(['edit' => 'e', 'bis-image-delete' => 'b', 'view' => 'v']))
        ->toBe(['edit' => 'e', 'view' => 'v']);
});

it('drops only the Better Image Sizes field for an Auditor', function (): void {
    uamswp_auditor_role_as(true, 'post.php');

    expect(BetterImageSizes::remove_field(['image_alt' => ['a'], 'regenerate_bis_images' => ['r']]))
        ->toBe(['image_alt' => ['a']]);
});

it('keeps both for another role', function (): void {
    uamswp_auditor_role_as(false, 'upload.php');

    expect(BetterImageSizes::remove_row_action(['bis-image-delete' => 'b']))->toBe(['bis-image-delete' => 'b'])
        ->and(BetterImageSizes::remove_field(['regenerate_bis_images' => ['r']]))->toBe(['regenerate_bis_images' => ['r']]);
});

it('passes a value that is not a list through unchanged', function (mixed $value): void {
    uamswp_auditor_role_as(true, 'upload.php');

    expect(BetterImageSizes::remove_row_action($value))->toBe($value)
        ->and(BetterImageSizes::remove_field($value))->toBe($value);
})->with([
    'null' => [null],
    'string' => ['bis-image-delete'],
]);
