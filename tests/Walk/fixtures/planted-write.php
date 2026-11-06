<?php

/**
 * Plugin Name: Screen walk planted write (control)
 * Description: For CI only. Writes an option on every admin request by an Auditor, through its own database connection, past the plugin's guard. The screen walk must fail while this is installed.
 */

declare(strict_types=1);

add_action('admin_init', static function (): void {
    $user = wp_get_current_user();

    if (! in_array('auditor', (array) $user->roles, true)) {
        return;
    }

    global $wpdb;

    // Its own connection, so the guard's `query` filter never sees the statement.
    $db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $table = $wpdb->options;
    $db->query("INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES ('uamswp_walk_planted', '".time()."', 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
    $db->close();
});
