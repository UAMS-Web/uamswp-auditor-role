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
function uamswp_auditor_role_remove_site_data(): void
{
    delete_option('uamswp_auditor_role_greeting');
    delete_option('uamswp_auditor_role_version');
}

if (is_multisite()) {
    foreach (get_sites(['number' => 0]) as $uamswp_auditor_role_site) {
        switch_to_blog((int) $uamswp_auditor_role_site->blog_id);
        uamswp_auditor_role_remove_site_data();
        restore_current_blog();
    }
} else {
    uamswp_auditor_role_remove_site_data();
}
