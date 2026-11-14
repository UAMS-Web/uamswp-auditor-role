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
    /**
     * The columns a row has, in export order. A method, not a constant, so
     * each entry is a line a test is seen to cover.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        return ['id', 'logged_at', 'user_id', 'user_login', 'blog_id', 'event', 'method', 'screen', 'url', 'detail'];
    }

    /**
     * Clean a set of filters: a user ID, a date range and an event type.
     *
     * @param  array<array-key, mixed>  $raw
     * @return array{user_id: int, from: string, to: string, event: string}
     */
    public static function filters(array $raw): array
    {
        $event = $raw['event'] ?? null;

        return [
            'user_id' => isset($raw['user_id']) && is_numeric($raw['user_id']) ? max(0, (int) $raw['user_id']) : 0,
            'from' => self::date($raw['from'] ?? null),
            'to' => self::date($raw['to'] ?? null),
            'event' => is_string($event) && in_array($event, Log::events(), true) ? $event : '',
        ];
    }

    /**
     * A Y-m-d date that exists on the calendar, surrounding space dropped, or
     * an empty string.
     */
    public static function date(mixed $value): string
    {
        if (! is_scalar($value) || preg_match('/^\s*(?<y>\d{4})-(?<m>\d{2})-(?<d>\d{2})\s*$/', (string) $value, $m) !== 1) {
            return '';
        }

        return checkdate((int) $m['m'], (int) $m['d'], (int) $m['y']) ? $m['y'].'-'.$m['m'].'-'.$m['d'] : '';
    }

    /**
     * The WHERE clause and its values for a set of clean filters.
     *
     * Dates are whole days in UTC, the time the log stores.
     *
     * @param  array{user_id: int, from: string, to: string, event: string}  $filters
     * @return array{0: literal-string, 1: list<int|string>}
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
        $wpdb = Database::wpdb();

        [$where, $values] = self::where($filters);

        // The WHERE clause is built from fixed fragments; every value is a placeholder.
        $sql = "SELECT * FROM %i WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, Log::table(), ...array_merge($values, [$limit, $offset])), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

        $result = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $columns = [];

            foreach ($row as $name => $value) {
                if (is_string($name)) {
                    $columns[$name] = is_scalar($value) ? (string) $value : '';
                }
            }

            $result[] = $columns;
        }

        return $result;
    }

    /**
     * How many rows match the filters.
     *
     * @param  array{user_id: int, from: string, to: string, event: string}  $filters
     */
    public static function count(array $filters): int
    {
        $wpdb = Database::wpdb();

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
        $wpdb = Database::wpdb();

        $rows = $wpdb->get_results($wpdb->prepare('SELECT DISTINCT user_id, user_login FROM %i ORDER BY user_login', Log::table()), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $users = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $id = $row['user_id'] ?? null;
            $login = $row['user_login'] ?? null;

            if (is_numeric($id) && is_scalar($login)) {
                $users[(int) $id] = (string) $login;
            }
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
     * Write rows matching the filters to a stream as CSV, in batches, after a
     * header row unless $header is false (a later site of a multi-site export).
     *
     * @param  resource  $stream
     * @param  array{user_id: int, from: string, to: string, event: string}  $filters
     */
    public static function write_csv($stream, array $filters, bool $header = true): void
    {
        if ($header) {
            fputcsv($stream, self::columns(), ',', '"', '');
        }

        $offset = 0;

        do {
            $rows = self::rows($filters, 1000, $offset);

            foreach ($rows as $row) {
                $line = [];

                foreach (self::columns() as $column) {
                    $line[] = self::csv_cell($row[$column] ?? '');
                }

                fputcsv($stream, $line, ',', '"', '');
            }

            $offset += 1000;
        } while (count($rows) === 1000);
    }
}
