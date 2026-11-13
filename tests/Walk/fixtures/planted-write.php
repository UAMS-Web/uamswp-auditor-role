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

    if (! $wpdb instanceof wpdb) {
        throw new RuntimeException('The planted write needs WordPress\' database object.');
    }

    // The connection settings are constants of unknown type to static analysis.
    $setting = static fn (mixed $value): string => is_string($value) ? $value : throw new RuntimeException('A database setting is not a string.');

    // Its own connection, so the guard's `query` filter never sees the statement.
    $db = new mysqli($setting(DB_HOST), $setting(DB_USER), $setting(DB_PASSWORD), $setting(DB_NAME));
    $table = $wpdb->options;
    $db->query("INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES ('uamswp_walk_planted', '".time()."', 'off') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)");
    $db->close();
});
