<?php

/**
 * Reads the activity log with the filters the viewer, the CSV export and the
 * WP-CLI command share.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class LogQuery
{
    /** The columns a row has, in export order. */
    public const COLUMNS = ['id', 'logged_at', 'user_id', 'user_login', 'blog_id', 'event', 'method', 'screen', 'url', 'detail'];

    /**
     * Clean a set of filters: a user ID, a date range and an event type.
     *
     * @param  array<string, mixed>  $raw
     * @return array{user_id: int, from: string, to: string, event: string}
     */
    public static function filters(array $raw): array
    {
        $date = static function (mixed $value): string {
            $value = is_scalar($value) ? trim((string) $value) : '';

            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
                return '';
            }

            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : '';
        };

        $event = isset($raw['event']) && is_scalar($raw['event']) ? (string) $raw['event'] : '';

        return [
            'user_id' => isset($raw['user_id']) && is_numeric($raw['user_id']) ? max(0, (int) $raw['user_id']) : 0,
            'from' => $date($raw['from'] ?? ''),
            'to' => $date($raw['to'] ?? ''),
            'event' => in_array($event, Log::EVENTS, true) ? $event : '',
        ];
    }

    /**
     * The WHERE clause and its values for a set of clean filters.
     *
     * Dates are whole days in UTC, the time the log stores.
     *
     * @param  array{user_id: int, from: string, to: string, event: string}  $filters
     * @return array{0: string, 1: list<int|string>}
     */
    public static function where(array $filters): array
    {
        $clauses = ['1=1'];
        $values = [];

        if ($filters['user_id'] > 0) {
            $clauses[] = 'user_id = %d';
            $values[] = $filters['user_id'];
        }

        if ($filters['from'] !== '') {
            $clauses[] = 'logged_at >= %s';
            $values[] = $filters['from'].' 00:00:00';
        }

        if ($filters['to'] !== '') {
            $clauses[] = 'logged_at <= %s';
            $values[] = $filters['to'].' 23:59:59';
        }

        if ($filters['event'] !== '') {
            $clauses[] = 'event = %s';
            $values[] = $filters['event'];
        }

        return [implode(' AND ', $clauses), $values];
    }

    /**
     * Rows matching the filters, newest first.
     *
     * @param  array{user_id: int, from: string, to: string, event: string}  $filters
     * @return list<array<string, string>>
     */
    public static function rows(array $filters, int $limit, int $offset = 0): array
    {
        global $wpdb;

        [$where, $values] = self::where($filters);

        // The WHERE clause is built from fixed fragments; every value is a placeholder.
        $sql = "SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, Log::table(), ...array_merge($values, [$limit, $offset])), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * How many rows match the filters.
     *
     * @param  array{user_id: int, from: string, to: string, event: string}  $filters
     */
    public static function count(array $filters): int
    {
        global $wpdb;

        [$where, $values] = self::where($filters);

        $sql = "SELECT COUNT(*) FROM %i WHERE {$where}";

        return (int) $wpdb->get_var($wpdb->prepare($sql, Log::table(), ...$values)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    }

    /**
     * The users who appear in the log, for the viewer's filter.
     *
     * @return array<int, string>
     */
    public static function users(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare('SELECT DISTINCT user_id, user_login FROM %i ORDER BY user_login', Log::table()), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $users = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $users[(int) $row['user_id']] = (string) $row['user_login'];
        }

        return $users;
    }

    /**
     * One CSV cell, with a leading formula character neutralized so a
     * spreadsheet never runs a logged value.
     */
    public static function csv_cell(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * Write rows matching the filters to a stream as CSV, in batches.
     *
     * @param  resource  $stream
     * @param  array{user_id: int, from: string, to: string, event: string}  $filters
     */
    public static function write_csv($stream, array $filters): void
    {
        fputcsv($stream, self::COLUMNS, ',', '"', '');

        $offset = 0;

        do {
            $rows = self::rows($filters, 1000, $offset);

            foreach ($rows as $row) {
                $line = [];

                foreach (self::COLUMNS as $column) {
                    $line[] = self::csv_cell($row[$column] ?? '');
                }

                fputcsv($stream, $line, ',', '"', '');
            }

            $offset += 1000;
        } while (count($rows) === 1000);
    }
}
