<?php

/**
 * Print one site's walk inventory as JSON: its admin menu slugs, the hidden
 * screens on the install (`Screens::hidden()`, after a deployment's filter; empty
 * when the plugin is not loaded), and its admin-ajax actions. Read-only.
 *
 * Run as an administrator, in an admin context, once per site:
 *   wp --exec="define('WP_ADMIN', true);" eval-file tests/Walk/enumerate.php --use-include --user=<admin> --url=<site>
 *
 * Menus are built the way wp-admin/menu.php builds them, so a plugin that
 * registers its screens only in the admin is seen. AJAX actions are every
 * `wp_ajax_` hook registered by then, plus core's own lists in
 * wp-admin/admin-ajax.php, which core hooks only while serving an AJAX request.
 */

declare(strict_types=1);
use UAMSWeb\AuditorRole\Screens;

if (! defined('ABSPATH') || ! defined('WP_ADMIN')) {
    fwrite(STDERR, "Run through wp eval-file with --exec=\"define('WP_ADMIN', true);\".\n");
    exit(2);
}

require_once ABSPATH.'wp-admin/includes/admin.php';

global $menu, $submenu, $pagenow, $wp_filter;

$pagenow = 'index.php';
require ABSPATH.'wp-admin/menu.php';

$slugs = [];

foreach ((array) $menu as $item) {
    if (is_array($item) && isset($item[2]) && is_string($item[2]) && ! str_starts_with($item[2], 'separator')) {
        $slugs[] = $item[2];
    }
}

foreach ((array) $submenu as $items) {
    foreach ((array) $items as $item) {
        if (is_array($item) && isset($item[2]) && is_string($item[2])) {
            $slugs[] = $item[2];
        }
    }
}

$ajax = [];

foreach (array_keys((array) $wp_filter) as $hook) {
    if (str_starts_with((string) $hook, 'wp_ajax_') && ! str_starts_with((string) $hook, 'wp_ajax_nopriv_')) {
        $ajax[] = substr((string) $hook, 8);
    }
}

$source = (string) file_get_contents(ABSPATH.'wp-admin/admin-ajax.php');

if (preg_match_all('/\$core_actions_(get|post)\s*=\s*array\((.*?)\);/s', $source, $lists, PREG_SET_ORDER) > 0) {
    foreach ($lists as $list) {
        preg_match_all("/'([a-z0-9_\-]+)'/i", $list[2], $names);

        foreach ($names[1] as $name) {
            $ajax[] = $name;
        }
    }
}

$hidden = class_exists(Screens::class) ? Screens::hidden() : [];

echo json_encode([
    'url' => home_url('/'),
    'admin_url' => admin_url(),
    'menu' => array_values(array_unique($slugs)),
    'hidden' => $hidden,
    'ajax' => array_values(array_unique($ajax)),
], JSON_UNESCAPED_SLASHES), "\n";
