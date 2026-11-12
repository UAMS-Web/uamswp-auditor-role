<?php

/**
 * Renders the walk's results as Markdown and decides whether the run passed.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Walk;

final class Report
{
    /**
     * Whether the run passed: at least one target was visited, and none wrote
     * outside the allowlist. A run that visited nothing proved nothing.
     *
     * @param  list<array{target: array{kind: string, label: string, url: string, method: string, body: array<string, string>}, status: int, outcome: string, location: string, unexpected: list<array{table: string, kind: string, info: string}>}>  $results
     */
    public static function passed(array $results): bool
    {
        if ($results === []) {
            return false;
        }

        foreach ($results as $result) {
            if ($result['unexpected'] !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The report: a summary, every unexpected write with the target that made
     * it, and the count of each HTTP status.
     *
     * @param  list<array{target: array{kind: string, label: string, url: string, method: string, body: array<string, string>}, status: int, outcome: string, location: string, unexpected: list<array{table: string, kind: string, info: string}>}>  $results
     */
    public static function markdown(array $results): string
    {
        $screens = 0;
        $ajax = 0;
        $statuses = [];
        $failures = [];

        foreach ($results as $result) {
            if ($result['target']['kind'] === 'screen') {
                $screens++;
            } else {
                $ajax++;
            }

            $statuses[$result['status']] = ($statuses[$result['status']] ?? 0) + 1;

            foreach ($result['unexpected'] as $write) {
                $failures[] = '| `'.$result['target']['label'].'` | `'.$write['table'].'` | '.$write['kind'].' |';
            }
        }

        ksort($statuses);

        $lines = [
            '# Screen walk',
            '',
            sprintf('**%s.** %d screens and %d AJAX actions visited as an Auditor; %d unexpected writes.', self::passed($results) ? 'Passed' : 'Failed', $screens, $ajax, count($failures)),
            '',
        ];

        if ($results === []) {
            $lines[] = 'No target was visited, so the run proves nothing.';
            $lines[] = '';
        }

        if ($failures !== []) {
            $lines[] = '## Unexpected writes';
            $lines[] = '';
            $lines[] = '| Target | Table | Logged as |';
            $lines[] = '| --- | --- | --- |';
            array_push($lines, ...$failures);
            $lines[] = '';
        }

        $lines[] = '## HTTP statuses';
        $lines[] = '';
        $lines[] = '| Status | Requests |';
        $lines[] = '| ---: | ---: |';

        foreach ($statuses as $status => $count) {
            $lines[] = '| '.$status.' | '.$count.' |';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Every target the run visited, in the order visited, with its status,
     * what the plugin did with it (see Outcome), where a redirect sent it, and
     * its unexpected writes. The count matches the run's `Inventoried` line unless `--limit` cut it.
     *
     * @param  list<array{target: array{kind: string, label: string, url: string, method: string, body: array<string, string>}, status: int, outcome: string, location: string, unexpected: list<array{table: string, kind: string, info: string}>}>  $results
     */
    public static function targets(array $results): string
    {
        $outcomes = [];
        $rows = [];

        foreach ($results as $index => $result) {
            $outcomes[$result['outcome']] = ($outcomes[$result['outcome']] ?? 0) + 1;
            $rows[] = sprintf(
                '| %d | `%s` | %d | %s | %s | %d |',
                $index + 1,
                str_replace('|', '\|', $result['target']['label']),
                $result['status'],
                $result['outcome'],
                $result['location'] === '' ? '' : '`'.str_replace('|', '\|', $result['location']).'`',
                count($result['unexpected']),
            );
        }

        ksort($outcomes);
        $counts = [];

        foreach ($outcomes as $outcome => $count) {
            $counts[] = $outcome.' '.$count;
        }

        $lines = [
            '## Every target',
            '',
            sprintf('%d targets, in the order visited%s.', count($results), $counts === [] ? '' : ' ('.implode(', ', $counts).')'),
        ];

        if ($rows !== []) {
            $lines[] = '';
            $lines[] = '| # | Target | Status | Plugin | Redirects to | Unexpected writes |';
            $lines[] = '| ---: | --- | ---: | --- | --- | ---: |';
            array_push($lines, ...$rows);
        }

        return implode("\n", $lines)."\n";
    }
}
