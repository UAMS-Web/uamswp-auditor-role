<?php

/**
 * WP-CLI: read the Auditor activity log with the same filters as the viewer.
 * Every subcommand reads; pruning is the scheduled job's alone.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Cli;

use UAMSWeb\AuditorRole\Log;
use UAMSWeb\AuditorRole\LogQuery;
use UAMSWeb\AuditorRole\Report;
use WP_CLI;
use WP_User;

use function WP_CLI\Utils\format_items;

defined('ABSPATH') || exit;

final class LogCommand
{
    /**
     * List log rows, newest first.
     *
     * ## OPTIONS
     *
     * [--auditor=<auditor>]
     * : An auditor's login or ID. Not `--user`, which is WP-CLI's own global
     * option (the user the command runs as) and never reaches the command.
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
     * [--all-sites]
     * : Every site of a multisite network, not only the one --url names.
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
     *     wp auditor log list --auditor=jdoe --from=2026-11-01 --event=refused
     *     wp auditor log list --url=example.org/site-two/ --format=json
     *     wp auditor log list --all-sites --format=csv
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $assoc
     */
    public function list(array $args, array $assoc): void
    {
        $filters = $this->filters($assoc);
        $limit = max(1, (int) ($assoc['limit'] ?? 100));
        $rows = [];

        $this->each_site($assoc, static function () use (&$rows, $filters, $limit): void {
            array_push($rows, ...LogQuery::rows($filters, $limit));
        });

        // Newest first across sites; within a second, the later site and row
        // first. The IDs are numeric strings, which <=> compares as numbers.
        usort($rows, static fn (array $a, array $b): int => [$b['logged_at'], $b['blog_id'], $b['id']] <=> [$a['logged_at'], $a['blog_id'], $a['id']]);

        format_items($assoc['format'] ?? 'table', array_slice($rows, 0, $limit), LogQuery::columns());
    }

    /**
     * Export every matching row as CSV.
     *
     * ## OPTIONS
     *
     * [--auditor=<auditor>]
     * : An auditor's login or ID. Not `--user`, which is WP-CLI's own global
     * option (the user the command runs as) and never reaches the command.
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
     * [--all-sites]
     * : Every site of a multisite network, one header row for all.
     *
     * [--file=<path>]
     * : Write to this file instead of standard output.
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $assoc
     */
    public function export(array $args, array $assoc): void
    {
        $filters = $this->filters($assoc);
        $stream = fopen($assoc['file'] ?? 'php://stdout', 'w'); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        if ($stream === false) {
            WP_CLI::error('Could not open the output.');

            return;
        }

        $header = true;

        // The stream closes when this method returns and its last reference goes.
        $this->each_site($assoc, static function () use ($stream, $filters, &$header): void {
            LogQuery::write_csv($stream, $filters, $header);
            $header = false;
        });

        if (isset($assoc['file'])) {
            WP_CLI::success('Wrote '.$assoc['file']);
        }
    }

    /**
     * Run a read on the current site, or with --all-sites on each site of the
     * network whose log table is installed.
     *
     * @param  array<string, string>  $assoc
     */
    private function each_site(array $assoc, callable $read): void
    {
        if (! isset($assoc['all-sites'])) {
            $this->require_table();
            $read();

            return;
        }

        if (! is_multisite()) {
            WP_CLI::error('--all-sites needs a multisite network.');

            return;
        }

        foreach (Report::site_ids() as $blog_id) {
            switch_to_blog($blog_id);

            try {
                if (get_option(Log::OPTION) === Log::SCHEMA) {
                    $read();
                }
            } finally {
                restore_current_blog();
            }
        }
    }

    /**
     * @param  array<string, string>  $assoc
     * @return array{user_id: int, from: string, to: string, event: string}
     */
    private function filters(array $assoc): array
    {
        $user_id = null;

        if (isset($assoc['auditor'])) {
            $user = is_numeric($assoc['auditor']) ? get_userdata((int) $assoc['auditor']) : get_user_by('login', $assoc['auditor']);

            if ($user instanceof WP_User) {
                $user_id = $user->ID;
            } else {
                WP_CLI::error('No such auditor: '.$assoc['auditor']);
            }
        }

        foreach (['from', 'to'] as $key) {
            if (isset($assoc[$key]) && LogQuery::filters([$key => $assoc[$key]])[$key] === '') {
                WP_CLI::error(sprintf('--%s must be a date in Y-m-d form.', $key));
            }
        }

        if (isset($assoc['event']) && ! in_array($assoc['event'], Log::events(), true)) {
            WP_CLI::error('--event must be one of: '.implode(', ', Log::events()));
        }

        return LogQuery::filters([
            'user_id' => $user_id,
            'from' => $assoc['from'] ?? null,
            'to' => $assoc['to'] ?? null,
            'event' => $assoc['event'] ?? null,
        ]);
    }

    private function require_table(): void
    {
        if (get_option(Log::OPTION) !== Log::SCHEMA) {
            WP_CLI::error('The log table is not installed on this site yet. It is created on the first web request.');
        }
    }
}
