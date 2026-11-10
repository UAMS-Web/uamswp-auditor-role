<?php

/**
 * Takes Better Image Sizes' links to its Tools screen off the Media screens in
 * an Auditor session. The screen itself is a hidden screen (see Screens).
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class BetterImageSizes
{
    /** The Media list row action Better Image Sizes adds. */
    public const ROW_ACTION = 'bis-image-delete';

    /** The attachment field Better Image Sizes adds on the attachment screens. */
    public const FIELD = 'regenerate_bis_images';

    public static function boot(): void
    {
        add_filter('media_row_actions', [self::class, 'remove_row_action'], PHP_INT_MAX);
        add_filter('attachment_fields_to_edit', [self::class, 'remove_field'], PHP_INT_MAX);
    }

    /**
     * Drop the "Regenerate BIS images" row action for an Auditor. Better Image
     * Sizes adds it for every user, and it links to the hidden Tools screen.
     */
    public static function remove_row_action(mixed $actions): mixed
    {
        if (is_array($actions) && Session::is_auditor()) {
            unset($actions[self::ROW_ACTION]);
        }

        return $actions;
    }

    /**
     * Drop the matching link from the attachment screens for an Auditor.
     */
    public static function remove_field(mixed $fields): mixed
    {
        if (is_array($fields) && Session::is_auditor()) {
            unset($fields[self::FIELD]);
        }

        return $fields;
    }
}
