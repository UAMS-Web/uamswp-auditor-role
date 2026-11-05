<?php

declare(strict_types=1);

/**
 * Plugin Name:       UAMS Auditor Role (loader)
 * Plugin URI:        https://github.com/UAMS-Web/uamswp-auditor-role
 * Description:       Loads the UAMS Auditor Role must-use plugin from its folder. Copy this file to the top level of mu-plugins/, beside the uamswp-auditor-role/ folder.
 * Author:            University of Arkansas for Medical Sciences
 * License:           GPL-3.0-or-later
 *
 * WordPress loads only the top level of mu-plugins/, so this stub loads the
 * plugin's folder. It fails closed: when the folder is missing, an Auditor
 * account holds no capability at all, because the role it still has would
 * otherwise open settings screens with no guard behind them.
 */
defined('ABSPATH') || exit;

$uamswp_auditor_role_main = __DIR__.'/uamswp-auditor-role/uamswp-auditor-role.php';

if (is_readable($uamswp_auditor_role_main)) {
    require_once $uamswp_auditor_role_main;
} else {
    add_filter('user_has_cap', static function ($allcaps, $caps, $args, $user) {
        return $user instanceof WP_User && in_array('auditor', (array) $user->roles, true) ? [] : $allcaps;
    }, PHP_INT_MAX, 4);

    $uamswp_auditor_role_notice = static function (): void {
        if (! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-error"><p>'
            .esc_html('UAMS Auditor Role: the loader in mu-plugins/ cannot find mu-plugins/uamswp-auditor-role/. Auditor accounts hold no capabilities until the folder is restored.')
            .'</p></div>';
    };

    add_action('admin_notices', $uamswp_auditor_role_notice);
    add_action('network_admin_notices', $uamswp_auditor_role_notice);
}

unset($uamswp_auditor_role_main);
