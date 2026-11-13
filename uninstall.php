<?php

/**
 * Runs when the plugin is deleted from the Plugins screen: the one place the
 * plugin removes its data. Deactivation never does.
 *
 * A must-use plugin is never deleted this way; removing it is the procedure in
 * docs/DEPLOY.md, which ends with `wp role delete auditor` on each site.
 */

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Delete the plugin's role, options, scheduled job and log table from the current site.
 */
function uamswp_auditor_role_remove_site_data(): void
{
    global $wpdb;

    // WordPress creates $wpdb before this file runs; static analysis types a global as mixed.
    /** @var wpdb $wpdb */
    remove_role('auditor');
    wp_clear_scheduled_hook('uamswp_auditor_role_prune');

    foreach (['uamswp_auditor_role_role_version', 'uamswp_auditor_role_log_schema', 'uamswp_auditor_role_retention_days', 'uamswp_auditor_role_last_prune', 'uamswp_auditor_role_version'] as $option) {
        delete_option($option);
    }

    $statement = $wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix.'uamswp_auditor_log');

    if (is_string($statement)) {
        $wpdb->query($statement); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
    }
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

delete_metadata('user', 0, 'uamswp_auditor_role_end_date', '', true);
