<?php

/**
 * Plugin Name:       UAMS Plugin Template
 * Plugin URI:        https://github.com/UAMS-Web/wordpress-plugin-template
 * Description:       The starting point for a UAMS-Web WordPress plugin. Replace this description with the plugin's own.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            University of Arkansas for Medical Sciences
 * Author URI:        https://uams.edu/
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       uams-plugin-template
 * Domain Path:       /languages
 * Update URI:        false
 *
 * UAMS Plugin Template
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
use UAMSWeb\PluginTemplate\Plugin;

defined('ABSPATH') || exit;

/*
 * The floor guard. `Requires PHP` in the header stops an install on an older
 * PHP, but not a site that is downgraded underneath an active plugin, where the
 * alternative to this notice is a fatal error.
 */
if (version_compare(PHP_VERSION, '8.2', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            .esc_html__('UAMS Plugin Template requires PHP 8.2 or newer and has not loaded.', 'uams-plugin-template')
            .'</p></div>';
    });

    return;
}

define('UAMS_PLUGIN_TEMPLATE_VERSION', '0.1.0');
define('UAMS_PLUGIN_TEMPLATE_FILE', __FILE__);

require_once __DIR__.'/includes/autoload.php';

register_activation_hook(__FILE__, [Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'deactivate']);

Plugin::boot();
