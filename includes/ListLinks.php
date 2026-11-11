<?php

/**
 * Gives an Auditor a way into each post from the list screens: a "Preview"
 * row action on an unpublished post, and a title linked to the post's view or
 * preview. Core offers both only to a user who passes `edit_post`, which the
 * role does not in the admin.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

use WP_Post;

defined('ABSPATH') || exit;

final class ListLinks
{
    /** The row action key core uses for both "View" and "Preview". */
    public const ACTION = 'view';

    /**
     * The script that links each title to its row's view or preview action.
     * It reads the link core or row_actions() already printed, so it builds
     * no URL of its own, and moves the title's own nodes into the link.
     */
    public const SCRIPT = <<<'JS'
(function () {
    document.querySelectorAll('#the-list tr').forEach(function (row) {
        var action = row.querySelector('.row-actions .view a');
        var title = row.querySelector('.column-primary strong > span');
        if (!action || !title || title.querySelector('a')) {
            return;
        }
        var link = document.createElement('a');
        link.className = 'row-title';
        link.href = action.href;
        while (title.firstChild) {
            link.appendChild(title.firstChild);
        }
        title.appendChild(link);
    });
})();
JS;

    public static function boot(): void
    {
        add_filter('post_row_actions', [self::class, 'row_actions'], PHP_INT_MAX, 2);
        add_filter('page_row_actions', [self::class, 'row_actions'], PHP_INT_MAX, 2);
        add_action('admin_footer-edit.php', [self::class, 'print_title_script']);
    }

    /**
     * Add the "Preview" action for an Auditor to a post Previews lets the role
     * view: a viewable type in a protected status. Core already adds "View" to
     * a published post, and an existing action is left as it is.
     */
    public static function row_actions(mixed $actions, mixed $post): mixed
    {
        if (! is_array($actions) || ! $post instanceof WP_Post || isset($actions[self::ACTION]) || ! Session::is_auditor()) {
            return $actions;
        }

        if (! Previews::is_unpublished_view($post->ID)) {
            return $actions;
        }

        $url = get_preview_post_link($post);

        if (! is_string($url) || $url === '') {
            return $actions;
        }

        $actions[self::ACTION] = sprintf(
            '<a href="%s" rel="bookmark" aria-label="%s">%s</a>',
            esc_url($url),
            /* translators: %s: Post title. */
            esc_attr(sprintf(__('Preview &#8220;%s&#8221;', 'uamswp-auditor-role'), get_the_title($post))),
            esc_html__('Preview', 'uamswp-auditor-role')
        );

        return $actions;
    }

    /**
     * Print the title-linking script on a list screen for an Auditor.
     */
    public static function print_title_script(): void
    {
        if (! Session::is_auditor()) {
            return;
        }

        wp_print_inline_script_tag(self::SCRIPT);
    }
}
