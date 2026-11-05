<?php

/**
 * Plugin Name:       UAMS Auditor Role
 * Plugin URI:        https://github.com/UAMS-Web/uamswp-auditor-role
 * Description:       Adds a read-only Auditor role for reviewing a WordPress install without Administrator rights. Deploy as a must-use plugin.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            University of Arkansas for Medical Sciences
 * Author URI:        https://uams.edu/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       uamswp-auditor-role
 * Domain Path:       /languages
 * Update URI:        false
 *
 * UAMS Auditor Role
 * Copyright (C) 2026 University of Arkansas for Medical Sciences
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 3 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);
use UAMSWeb\AuditorRole\Plugin;

defined('ABSPATH') || exit;

/*
 * The floor guard. `Requires PHP` in the header stops an install on an older
 * PHP, but not a site that is downgraded underneath an active plugin, where the
 * alternative to this notice is a fatal error.
 */
if (version_compare(PHP_VERSION, '8.2', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            .esc_html__('UAMS Auditor Role requires PHP 8.2 or newer and has not loaded.', 'uamswp-auditor-role')
            .'</p></div>';
    });

    return;
}

define('UAMSWP_AUDITOR_ROLE_VERSION', '0.1.0');
define('UAMSWP_AUDITOR_ROLE_FILE', __FILE__);

require_once __DIR__.'/includes/autoload.php';

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

Plugin::boot();
