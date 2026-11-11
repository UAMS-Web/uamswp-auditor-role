<?php

/**
 * Keeps ShortPixel Image Optimizer's background processor from running in an
 * Auditor session.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class ShortPixel
{
    /** ShortPixel's filter that turns its processor script off. */
    public const FILTER = 'shortpixel/processorjs/disable';

    public static function boot(): void
    {
        add_filter(self::FILTER, [self::class, 'disable_processor'], PHP_INT_MAX);
    }

    /**
     * Turn the processor off for an Auditor, and leave any other answer alone.
     *
     * ShortPixel loads its processor on Media and editor screens, where it asks
     * `admin-ajax.php` to optimize the next image in the site's shared queue
     * every few seconds. The request guard refuses that action for an Auditor,
     * and ShortPixel reports the refusal as a fatal error. With the processor
     * off, the screens still show each image's ShortPixel status.
     */
    public static function disable_processor(mixed $disabled): mixed
    {
        return Session::is_auditor() ? true : $disabled;
    }
}
