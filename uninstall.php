<?php

/**
 * Runs when the plugin is deleted from the Plugins screen: the one place the
 * plugin removes its data. Activation and deactivation never do.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Delete the plugin's options from the current site.
 */
function uams_plugin_template_remove_site_data(): void
{
    delete_option('uams_plugin_template_greeting');
    delete_option('uams_plugin_template_version');
}

if (is_multisite()) {
    foreach (get_sites(['number' => 0]) as $uams_plugin_template_site) {
        switch_to_blog((int) $uams_plugin_template_site->blog_id);
        uams_plugin_template_remove_site_data();
        restore_current_blog();
    }
} else {
    uams_plugin_template_remove_site_data();
}
