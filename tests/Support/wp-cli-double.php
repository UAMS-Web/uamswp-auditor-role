<?php

/**
 * A stand-in for WP-CLI's `WP_CLI` class, for the Unit suite: each call is
 * recorded in UamswpAuditorRoleG4CliLog, and error() throws, as the real one
 * stops the command. WP_CLI\Utils\format_items() is recorded as `format_items`
 * with its format, items and fields. Excluded from static analysis, which
 * reads WP-CLI's own stubs.
 *
 * Bracketed namespaces, because the file declares both a global class and a
 * function in WP_CLI\Utils.
 */

declare(strict_types=1);

namespace {
    require_once __DIR__.'/cli-log.php';

    if (! class_exists('WP_CLI')) {
        final class WP_CLI
        {
            public static function add_command(string $name, mixed $callable): void
            {
                UamswpAuditorRoleG4CliLog::record('add_command', [$name, $callable]);
            }

            public static function error(string $message): never
            {
                UamswpAuditorRoleG4CliLog::record('error', [$message]);

                throw new RuntimeException('WP_CLI::error: '.$message);
            }

            public static function success(string $message): void
            {
                UamswpAuditorRoleG4CliLog::record('success', [$message]);
            }

            public static function log(string $message): void
            {
                UamswpAuditorRoleG4CliLog::record('log', [$message]);
            }

            public static function line(string $message = ''): void
            {
                UamswpAuditorRoleG4CliLog::record('line', [$message]);
            }

            /**
             * @param  array<string, mixed>  $assoc
             */
            public static function print_value(mixed $value, array $assoc = []): void
            {
                UamswpAuditorRoleG4CliLog::record('print_value', [$value, $assoc]);
            }
        }
    }
}

namespace WP_CLI\Utils {
    use UamswpAuditorRoleG4CliLog;

    if (! function_exists('WP_CLI\Utils\format_items')) {
        /**
         * @param  array<mixed>  $items
         * @param  array<string>|string  $fields
         */
        function format_items(string $format, array $items, array|string $fields): void
        {
            UamswpAuditorRoleG4CliLog::record('format_items', [$format, $items, $fields]);
        }
    }
}
