<?php

/**
 * WP-CLI: read the Auditor activity log with the same filters as the viewer.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Cli;

use UAMSWeb\AuditorRole\Log;
use UAMSWeb\AuditorRole\LogQuery;
use UAMSWeb\AuditorRole\Retention;
use WP_CLI;
use WP_User;

use function WP_CLI\Utils\format_items;

defined('ABSPATH') || exit;

final class LogCommand
{
    /**
     * List log rows on the current site, newest first.
     *
     * ## OPTIONS
     *
     * [--user=<user>]
     * : An auditor's login or ID.
     *
     * [--from=<date>]
     * : First day, Y-m-d, UTC.
     *
     * [--to=<date>]
     * : Last day, Y-m-d, UTC.
     *
     * [--event=<event>]
     * : view, refused, db_refused, login, logout or login_refused.
     *
     * [--limit=<number>]
     * : The most rows to print.
     * ---
     * default: 100
     * ---
     *
     * [--format=<format>]
     * : table, csv, json or yaml.
     * ---
     * default: table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp auditor log list --user=jdoe --from=2026-11-01 --event=refused
     *     wp auditor log list --url=example.org/site-two/ --format=json
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $assoc
     */
    public function list(array $args, array $assoc): void
    {
        $this->require_table();

        $rows = LogQuery::rows($this->filters($assoc), max(1, (int) ($assoc['limit'] ?? 100)));

        format_items($assoc['format'] ?? 'table', $rows, LogQuery::COLUMNS);
    }

    /**
     * Export every matching row on the current site as CSV.
     *
     * ## OPTIONS
     *
     * [--user=<user>]
     * : An auditor's login or ID.
     *
     * [--from=<date>]
     * : First day, Y-m-d, UTC.
     *
     * [--to=<date>]
     * : Last day, Y-m-d, UTC.
     *
     * [--event=<event>]
     * : An event type.
     *
     * [--file=<path>]
     * : Write to this file instead of standard output.
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $assoc
     */
    public function export(array $args, array $assoc): void
    {
        $this->require_table();

        $stream = fopen($assoc['file'] ?? 'php://stdout', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        if ($stream === false) {
            WP_CLI::error('Could not open the output.');

            return;
        }

        LogQuery::write_csv($stream, $this->filters($assoc));
        fclose($stream); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        if (isset($assoc['file'])) {
            WP_CLI::success('Wrote '.$assoc['file']);
        }
    }

    /**
     * Delete rows older than the retention setting on the current site now.
     */
    public function prune(): void
    {
        $this->require_table();
        WP_CLI::success(sprintf('Deleted %d row(s) older than %d days.', Retention::prune(), Retention::days()));
    }

    /**
     * @param  array<string, string>  $assoc
     * @return array{user_id: int, from: string, to: string, event: string}
     */
    private function filters(array $assoc): array
    {
        $user_id = 0;

        if (isset($assoc['user'])) {
            $user = is_numeric($assoc['user']) ? get_userdata((int) $assoc['user']) : get_user_by('login', $assoc['user']);

            if (! $user instanceof WP_User) {
                WP_CLI::error('No such user: '.$assoc['user']);

                return LogQuery::filters([]);
            }

            $user_id = $user->ID;
        }

        foreach (['from', 'to'] as $key) {
            if (isset($assoc[$key]) && LogQuery::filters([$key => $assoc[$key]])[$key] === '') {
                WP_CLI::error(sprintf('--%s must be a date in Y-m-d form.', $key));
            }
        }

        if (isset($assoc['event']) && ! in_array($assoc['event'], Log::EVENTS, true)) {
            WP_CLI::error('--event must be one of: '.implode(', ', Log::EVENTS));
        }

        return LogQuery::filters([
            'user_id' => $user_id,
            'from' => $assoc['from'] ?? '',
            'to' => $assoc['to'] ?? '',
            'event' => $assoc['event'] ?? '',
        ]);
    }

    private function require_table(): void
    {
        if (get_option(Log::OPTION) !== Log::SCHEMA) {
            WP_CLI::error('The log table is not installed on this site yet. It is created on the first web request.');
        }
    }
}
