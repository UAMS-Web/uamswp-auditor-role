<?php

/**
 * Reads MySQL's binary log to find every table written in a span of time, by
 * any connection: WordPress's own, a plugin's own, or another request a page
 * set off. The instrument sits outside WordPress on purpose, so a write that
 * slips past the plugin's guard still shows.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Walk;

use RuntimeException;

final readonly class BinlogWindow
{
    /**
     * @param  \Closure(string): list<array<string, mixed>>  $query  Runs one statement and returns its rows.
     * @param  string  $schema  The database whose writes count.
     */
    public function __construct(private \Closure $query, private string $schema) {}

    /**
     * Fail unless the binary log records every row a statement changes, the
     * only setting under which an empty window means nothing was written.
     */
    public function verify_settings(): void
    {
        $row = $this->rows('SELECT @@log_bin AS log_bin, @@binlog_format AS format')[0] ?? [];

        if ($this->field($row, 'log_bin') !== '1') {
            throw new RuntimeException('The binary log is off (log_bin=0), so a write cannot be seen.');
        }

        if (strtoupper($this->field($row, 'format')) !== 'ROW') {
            throw new RuntimeException('binlog_format is not ROW, so a row write cannot be told from a statement that wrote nothing.');
        }
    }

    /**
     * The log's current end: a file and a position.
     *
     * @return array{file: string, position: int}
     */
    public function position(): array
    {
        $row = $this->first_of(['SHOW BINARY LOG STATUS', 'SHOW MASTER STATUS']);
        $file = $this->field($row, 'File');

        if ($file === '') {
            throw new RuntimeException('The server reported no binary log position.');
        }

        return ['file' => $file, 'position' => (int) $this->field($row, 'Position')];
    }

    /**
     * Every write to the schema logged since a position, following the log
     * into newer files if it rotated.
     *
     * @param  array{file: string, position: int}  $since
     * @return list<array{table: string, kind: string, info: string}>
     */
    public function writes_since(array $since): array
    {
        $writes = [];
        $from = $since['position'];

        foreach ($this->files_from($since['file']) as $file) {
            foreach ($this->rows(sprintf("SHOW BINLOG EVENTS IN '%s' FROM %d", addslashes($file), $from)) as $event) {
                $write = $this->write_of($event);

                if ($write !== null) {
                    $writes[] = $write;
                }
            }

            $from = 4;
        }

        return $writes;
    }

    /**
     * The write one binlog event records in the schema, or null for none.
     *
     * A `Table_map` event names each table a row event changes. A `Query`
     * event in row format carries DDL and transaction markers only.
     *
     * @param  array<string, mixed>  $event
     * @return array{table: string, kind: string, info: string}|null
     */
    public function write_of(array $event): ?array
    {
        $type = $this->field($event, 'Event_type');
        $info = $this->field($event, 'Info');

        if ($type === 'Table_map' && preg_match('/\(`?([^`.]+)`?\.`?([^`)]+)`?\)/', $info, $m) === 1) {
            return $m[1] === $this->schema ? ['table' => $m[2], 'kind' => 'row', 'info' => $info] : null;
        }

        // A transaction marker (`BEGIN`, `XA START`) names no database, so it
        // fails the schema check below like any statement outside the schema.
        if ($type !== 'Query' || preg_match('/^use `([^`]+)`/i', $info, $db) !== 1 || $db[1] !== $this->schema) {
            return null;
        }

        $table = preg_match('/\b(?:TABLE|INTO|UPDATE|FROM)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([\w$]+)`?/i', $info, $t) === 1 ? $t[1] : '';

        return ['table' => $table, 'kind' => 'statement', 'info' => $info];
    }

    /**
     * The log files from one file on, oldest first.
     *
     * @return list<string>
     */
    private function files_from(string $file): array
    {
        $names = [];

        foreach ($this->rows('SHOW BINARY LOGS') as $row) {
            $names[] = $this->field($row, 'Log_name');
        }

        $start = array_search($file, $names, true);

        return $start === false ? [$file] : array_slice($names, $start);
    }

    /**
     * The first row of the first statement the server accepts, for a
     * statement that was renamed between MySQL versions.
     *
     * @param  list<string>  $statements
     * @return array<string, mixed>
     */
    private function first_of(array $statements): array
    {
        foreach ($statements as $statement) {
            try {
                return $this->rows($statement)[0] ?? [];
            } catch (RuntimeException) {
                continue;
            }
        }

        return [];
    }

    /**
     * One field of a row as text: the server returns every value as a string,
     * and a missing or non-scalar one reads as empty.
     *
     * @param  array<string, mixed>  $row
     */
    private function field(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql): array
    {
        return ($this->query)($sql);
    }
}
