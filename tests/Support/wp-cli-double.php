<?php

/**
 * A stand-in for WP-CLI's `WP_CLI` class, for the Unit suite: each call is
 * recorded in UamswpAuditorRoleG4CliLog, and error() throws, as the real one
 * stops the command. Excluded from static analysis, which reads WP-CLI's own stubs.
 */

declare(strict_types=1);

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
