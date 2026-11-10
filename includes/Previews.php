<?php

/**
 * Lets an Auditor view another user's draft, pending and scheduled posts on
 * the front end, of every viewable post type, without opening the editor.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

use WP_Admin_Bar;

defined('ABSPATH') || exit;

final class Previews
{
    /** The front-end admin bar node that links to the editor. */
    public const EDIT_NODE = 'edit';

    public static function boot(): void
    {
        // Before MetaCaps::map(), which runs last and passes `read` through.
        add_filter('map_meta_cap', [self::class, 'map'], PHP_INT_MAX - 1, 4);
        add_action('admin_bar_menu', [self::class, 'remove_edit_link'], PHP_INT_MAX);
        add_filter('edit_post_link', [self::class, 'edit_post_link'], PHP_INT_MAX);
    }

    /**
     * Answer `edit_post` and `edit_page` with `read` for an Auditor, on a
     * front-end read, for a post of a viewable type in a protected status.
     *
     * WordPress shows a draft, pending or scheduled post, by preview link or by
     * `?p=<id>`, only to a user who passes `edit_post` for it, and every post
     * type's own edit capability resolves through `edit_post` or `edit_page`.
     * Everywhere else the check is left alone, so the editor stays closed: in
     * the admin, over AJAX and REST, in cron, and on any request that is not a
     * GET or HEAD. XML-RPC sign-in is refused for the role
     * (RequestGuard::refuse_xmlrpc()), and a WP-CLI run is server access with
     * the database guard still in place, so neither is checked here.
     *
     * @return list<string>
     */
    public static function map(mixed $caps, mixed $cap, mixed $user_id, mixed $args): array
    {
        $caps = is_array($caps) ? array_values($caps) : [];

        if (($cap !== 'edit_post' && $cap !== 'edit_page') || ! is_int($user_id)) {
            return $caps;
        }

        if (! is_array($args) || ! isset($args[0]) || ! is_numeric($args[0])) {
            return $caps;
        }

        if (! Role::user_is_auditor(get_userdata($user_id)) || ! self::is_front_end_read()) {
            return $caps;
        }

        return self::is_unpublished_view((int) $args[0]) ? ['read'] : $caps;
    }

    /**
     * Whether the request is a front-end page view: not the admin, AJAX, REST
     * or cron, and a GET or HEAD.
     */
    public static function is_front_end_read(): bool
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || wp_is_serving_rest_request()) {
            return false;
        }

        if (! isset($_SERVER['REQUEST_METHOD'])) {
            return false;
        }

        $method = strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])));

        return $method === 'GET' || $method === 'HEAD';
    }

    /**
     * Whether a post is one this allowance is for: a viewable post type, in a
     * status WordPress protects (draft, pending, scheduled, and any status a
     * plugin registers as protected). A published post is left to its own
     * capabilities, and a private one to `read_private_posts`.
     */
    public static function is_unpublished_view(int $post_id): bool
    {
        $type = get_post_type($post_id);
        $status = get_post_status($post_id);

        if (! is_string($type) || ! is_string($status) || ! is_post_type_viewable($type)) {
            return false;
        }

        $status_object = get_post_status_object($status);

        return $status_object !== null && $status_object->protected === true;
    }

    /**
     * Take the front-end admin bar "Edit" link out for an Auditor: the
     * allowance above makes it show on a draft, and it leads to the editor,
     * which is refused.
     */
    public static function remove_edit_link(mixed $bar): void
    {
        if (! $bar instanceof WP_Admin_Bar || is_admin() || ! Session::is_auditor()) {
            return;
        }

        $bar->remove_node(self::EDIT_NODE);
    }

    /**
     * Print no theme "Edit" link for an Auditor, for the same reason.
     */
    public static function edit_post_link(mixed $link): mixed
    {
        return Session::is_auditor() ? '' : $link;
    }
}
