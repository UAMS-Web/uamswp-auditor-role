<?php

/**
 * Keeps Regenerate Thumbnails out of an Auditor session. The Tools screen is
 * also a hidden screen (see Screens).
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class RegenerateThumbnails
{
    /** Regenerate Thumbnails' filter for the capability every part of it checks. */
    public const FILTER = 'regenerate_thumbs_cap';

    public static function boot(): void
    {
        add_filter(self::FILTER, [self::class, 'capability'], PHP_INT_MAX);
    }

    /**
     * Answer `do_not_allow` for an Auditor, and leave any other answer alone.
     *
     * Regenerate Thumbnails reads this once, on `init`, after the current user
     * is set, and checks the result for its Tools screen, its Media row action,
     * its attachment-screen buttons, its bulk action and its REST routes. The
     * role holds `manage_options`, the plugin's default, so every one of them
     * would otherwise show.
     */
    public static function capability(mixed $capability): mixed
    {
        return Session::is_auditor() ? 'do_not_allow' : $capability;
    }
}
