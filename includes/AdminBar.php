<?php

/**
 * The "Read-only audit mode" badge in the admin bar, so a refused save is never a surprise.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

use WP_Admin_Bar;

defined('ABSPATH') || exit;

final class AdminBar
{
    /** The node ID. */
    public const NODE = 'uamswp-auditor-role-badge';

    public static function boot(): void
    {
        add_action('admin_bar_menu', [self::class, 'add_badge'], 0);
        add_action('admin_enqueue_scripts', [self::class, 'add_style']);
        add_action('wp_enqueue_scripts', [self::class, 'add_style']);
    }

    /**
     * Add the badge for an Auditor.
     */
    public static function add_badge(mixed $bar): void
    {
        if (! $bar instanceof WP_Admin_Bar || ! Session::is_auditor()) {
            return;
        }

        $bar->add_node([
            'id' => self::NODE,
            'title' => esc_html__('Read-only audit mode', 'uamswp-auditor-role'),
            'meta' => [
                'title' => __('This account can view but not change anything. Changes are refused and recorded.', 'uamswp-auditor-role'),
            ],
        ]);
    }

    /**
     * Color the badge so it stands out from the menu items around it.
     */
    public static function add_style(): void
    {
        if (! Session::is_auditor() || ! is_admin_bar_showing()) {
            return;
        }

        wp_add_inline_style(
            'admin-bar',
            '#wpadminbar #wp-admin-bar-'.self::NODE.' > .ab-item{background:#9a3412;color:#fff;font-weight:600}'
        );
    }
}
