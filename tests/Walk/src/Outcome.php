<?php

/**
 * Sorts a walk response by what the plugin did with the request: hid the
 * screen, refused the request, or let WordPress answer it.
 *
 * The plugin's refusals are read from the response body, by the English text
 * RequestGuard sends and the error code its AJAX and REST refusals carry. On a
 * site that translates the plugin, a hidden or refused screen reads as `none`,
 * while an AJAX or REST refusal still reads as `refused` by its error code; the
 * status column still shows the 403.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Walk;

final class Outcome
{
    /** The message RequestGuard shows on a hidden screen. */
    public const HIDDEN_MESSAGE = 'This screen is hidden from Auditor accounts';

    /** The message RequestGuard shows on any other refused screen. */
    public const REFUSED_MESSAGE = 'This account is in read-only audit mode';

    /** The error code RequestGuard's AJAX and REST refusals carry. */
    public const REFUSED_CODE = 'uamswp_auditor_role_read_only';

    /**
     * `hidden` for a hidden screen, `refused` for a request the plugin refused
     * otherwise, and `none` for a response the plugin did not stop.
     */
    public static function of(string $body): string
    {
        if (str_contains($body, self::HIDDEN_MESSAGE)) {
            return 'hidden';
        }

        if (str_contains($body, self::REFUSED_MESSAGE) || str_contains($body, self::REFUSED_CODE)) {
            return 'refused';
        }

        return 'none';
    }
}
