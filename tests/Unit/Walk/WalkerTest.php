<?php

/**
 * UAMSWeb\AuditorRole\Walk\Walker and Report, with a scripted log and fetch.
 *
 * @see tests/Walk/src/Walker.php
 * @see tests/Walk/src/Report.php
 */

declare(strict_types=1);

use UAMSWeb\AuditorRole\Walk\BinlogWindow;
use UAMSWeb\AuditorRole\Walk\Report;
use UAMSWeb\AuditorRole\Walk\Walker;

/**
 * A target, as Targets builds them.
 *
 * @return array{kind: string, label: string, url: string, method: string, body: array<string, string>}
 */
function uamswp_auditor_role_walk_target(string $label, string $kind = 'screen'): array
{
    return ['kind' => $kind, 'label' => $label, 'url' => 'https://example.org/'.$label, 'method' => $kind === 'ajax' ? 'POST' : 'GET', 'body' => []];
}

/**
 * A window over a log that grows by the events each fetched target writes.
 * A fetch answers with the body named for its label in $bodies, or an empty one,
 * and redirects the label `moved` to `https://example.org/elsewhere`.
 *
 * @param  array<string, list<array<string, string>>>  $writes  Label to the binlog events its request adds.
 * @param  list<string>  $calls
 * @param  list<string>|null  $allowed
 * @param  array<string, string>  $bodies  Label to the response body its request returns.
 */
function uamswp_auditor_role_walk_walker(array $writes, array &$calls, ?array $allowed = null, array $bodies = []): Walker
{
    $log = [];

    $query = static function (string $sql) use (&$log): array {
        if ($sql === 'SHOW BINARY LOG STATUS') {
            return [['File' => 'binlog.000001', 'Position' => count($log)]];
        }

        if ($sql === 'SHOW BINARY LOGS') {
            return [['Log_name' => 'binlog.000001']];
        }

        if (preg_match('/FROM (\d+)$/', $sql, $m) === 1) {
            return array_slice($log, (int) $m[1]);
        }

        throw new LogicException('Unscripted statement: '.$sql);
    };

    $fetch = static function (array $target) use (&$log, $writes, &$calls, $bodies): array {
        $label = uamswp_auditor_role_string($target['label']);
        $calls[] = 'fetch '.$label;

        foreach ($writes[$label] ?? [] as $event) {
            $log[] = $event;
        }

        return ['status' => $target['kind'] === 'ajax' ? 403 : 200, 'body' => $bodies[$label] ?? '', 'location' => $label === 'moved' ? 'https://example.org/elsewhere' : ''];
    };

    $settle = static function () use (&$calls): void {
        $calls[] = 'settle';
    };

    return new Walker(new BinlogWindow($query, 'site'), $fetch, $settle, $allowed);
}

it('allows only the activity log by default', function (): void {
    expect(Walker::default_allowed())->toBe(['/(^|_)uamswp_auditor_log$/']);
});

it('allows writes to the activity log on any site, and nothing else', function (string $table, bool $allowed): void {
    $calls = [];

    expect(uamswp_auditor_role_walk_walker([], $calls)->is_allowed($table))->toBe($allowed);
})->with([
    ['wp_uamswp_auditor_log', true],
    ['wp_57rzdn91pe_8_uamswp_auditor_log', true],
    ['uamswp_auditor_log', true],
    ['wp_options', false],
    ['wp_uamswp_auditor_log_old', false],
    ['wp_xuamswp_auditor_log', false],
    ['', false],
]);

it('takes a deployment\'s own allowlist', function (): void {
    $calls = [];
    $walker = uamswp_auditor_role_walk_walker([], $calls, ['/^wp_cache$/', '/_sessions$/']);

    expect($walker->is_allowed('wp_cache'))->toBeTrue()
        ->and($walker->is_allowed('wp_2_sessions'))->toBeTrue()
        ->and($walker->is_allowed('wp_uamswp_auditor_log'))->toBeFalse();
});

it('charges each write to the target whose window it fell in, after the response settles', function (): void {
    $calls = [];
    $walker = uamswp_auditor_role_walk_walker([
        'a' => [['Event_type' => 'Table_map', 'Info' => 'table_id: 1 (site.wp_uamswp_auditor_log)']],
        'b' => [
            ['Event_type' => 'Table_map', 'Info' => 'table_id: 2 (site.wp_options)'],
            ['Event_type' => 'Table_map', 'Info' => 'table_id: 1 (site.wp_uamswp_auditor_log)'],
            ['Event_type' => 'Table_map', 'Info' => 'table_id: 3 (site.wp_postmeta)'],
        ],
        'c' => [['Event_type' => 'Table_map', 'Info' => 'table_id: 9 (elsewhere.wp_options)']],
    ], $calls);

    $results = $walker->walk([uamswp_auditor_role_walk_target('a'), uamswp_auditor_role_walk_target('b'), uamswp_auditor_role_walk_target('c', 'ajax')]);

    expect($calls)->toBe(['fetch a', 'settle', 'fetch b', 'settle', 'fetch c', 'settle'])
        ->and(array_column($results, 'status'))->toBe([200, 200, 403])
        ->and($results[0]['unexpected'])->toBe([])
        ->and(array_column($results[1]['unexpected'], 'table'))->toBe(['wp_options', 'wp_postmeta'])
        ->and($results[2]['unexpected'])->toBe([])
        ->and($results[1]['target']['label'])->toBe('b');
});

it('reports progress after each target, with the running count', function (): void {
    $calls = [];
    $seen = [];
    $walker = uamswp_auditor_role_walk_walker(['b' => [['Event_type' => 'Table_map', 'Info' => 'table_id: 2 (site.wp_options)']]], $calls);

    $walker->walk([uamswp_auditor_role_walk_target('a'), uamswp_auditor_role_walk_target('b')], static function (int $done, int $total, array $result) use (&$seen): void {
        $seen[] = [$done, $total, $result['target']['label'], count($result['unexpected'])];
    });

    expect($seen)->toBe([[1, 2, 'a', 0], [2, 2, 'b', 1]]);
});

it('walks nothing without targets', function (): void {
    $calls = [];

    expect(uamswp_auditor_role_walk_walker([], $calls)->walk([]))->toBe([])
        ->and($calls)->toBe([]);
});

it('passes only a run that visited something and found no unexpected write', function (): void {
    $clean = ['target' => uamswp_auditor_role_walk_target('a'), 'status' => 200, 'outcome' => 'none', 'location' => '', 'unexpected' => []];
    $dirty = ['target' => uamswp_auditor_role_walk_target('b'), 'status' => 200, 'outcome' => 'none', 'location' => '', 'unexpected' => [['table' => 'wp_options', 'kind' => 'row', 'info' => 'x']]];

    expect(Report::passed([$clean]))->toBeTrue()
        ->and(Report::passed([$clean, $dirty]))->toBeFalse()
        ->and(Report::passed([$dirty, $clean]))->toBeFalse()
        ->and(Report::passed([]))->toBeFalse();
});

it('reports a passing run with its counts and statuses', function (): void {
    expect(Report::markdown([
        ['target' => uamswp_auditor_role_walk_target('a'), 'status' => 200, 'outcome' => 'none', 'location' => '', 'unexpected' => []],
        ['target' => uamswp_auditor_role_walk_target('ajax:x', 'ajax'), 'status' => 403, 'outcome' => 'none', 'location' => '', 'unexpected' => []],
        ['target' => uamswp_auditor_role_walk_target('b'), 'status' => 200, 'outcome' => 'none', 'location' => '', 'unexpected' => []],
    ]))->toBe(implode("\n", [
        '# Screen walk',
        '',
        '**Passed.** 2 screens and 1 AJAX actions visited as an Auditor; 0 unexpected writes.',
        '',
        '## HTTP statuses',
        '',
        '| Status | Requests |',
        '| ---: | ---: |',
        '| 200 | 2 |',
        '| 403 | 1 |',
    ])."\n");
});

it('names each unexpected write with the target that made it', function (): void {
    expect(Report::markdown([
        ['target' => uamswp_auditor_role_walk_target('b'), 'status' => 500, 'outcome' => 'none', 'location' => '', 'unexpected' => [
            ['table' => 'wp_options', 'kind' => 'row', 'info' => 'x'],
            ['table' => 'wp_x', 'kind' => 'statement', 'info' => 'y'],
        ]],
        ['target' => uamswp_auditor_role_walk_target('a'), 'status' => 200, 'outcome' => 'none', 'location' => '', 'unexpected' => []],
    ]))->toBe(implode("\n", [
        '# Screen walk',
        '',
        '**Failed.** 2 screens and 0 AJAX actions visited as an Auditor; 2 unexpected writes.',
        '',
        '## Unexpected writes',
        '',
        '| Target | Table | Logged as |',
        '| --- | --- | --- |',
        '| `b` | `wp_options` | row |',
        '| `b` | `wp_x` | statement |',
        '',
        '## HTTP statuses',
        '',
        '| Status | Requests |',
        '| ---: | ---: |',
        '| 200 | 1 |',
        '| 500 | 1 |',
    ])."\n");
});

it('says a run that visited nothing proves nothing', function (): void {
    expect(Report::markdown([]))->toBe(implode("\n", [
        '# Screen walk',
        '',
        '**Failed.** 0 screens and 0 AJAX actions visited as an Auditor; 0 unexpected writes.',
        '',
        'No target was visited, so the run proves nothing.',
        '',
        '## HTTP statuses',
        '',
        '| Status | Requests |',
        '| ---: | ---: |',
    ])."\n");
});

it('records what the plugin did with each request, from its response body', function (): void {
    $calls = [];
    $walker = uamswp_auditor_role_walk_walker([], $calls, null, [
        'hidden' => 'x This screen is hidden from Auditor accounts y',
        'ajax:refused' => '{"code":"uamswp_auditor_role_read_only"}',
    ]);

    $results = $walker->walk([
        uamswp_auditor_role_walk_target('hidden'),
        uamswp_auditor_role_walk_target('ajax:refused', 'ajax'),
        uamswp_auditor_role_walk_target('served'),
        uamswp_auditor_role_walk_target('moved'),
    ]);

    expect(array_column($results, 'outcome'))->toBe(['hidden', 'refused', 'none', 'none'])
        ->and(array_column($results, 'location'))->toBe(['', '', '', 'https://example.org/elsewhere'])
        ->and(array_keys($results[0]))->toBe(['target', 'status', 'outcome', 'location', 'unexpected']);
});

it('lists every target in the order visited, with its status, outcome and writes', function (): void {
    expect(Report::targets([
        ['target' => uamswp_auditor_role_walk_target('b'), 'status' => 403, 'outcome' => 'hidden', 'location' => '', 'unexpected' => []],
        ['target' => uamswp_auditor_role_walk_target('ajax:x | y', 'ajax'), 'status' => 403, 'outcome' => 'refused', 'location' => '', 'unexpected' => []],
        ['target' => uamswp_auditor_role_walk_target('a'), 'status' => 200, 'outcome' => 'none', 'location' => '', 'unexpected' => [
            ['table' => 'wp_options', 'kind' => 'row', 'info' => 'x'],
            ['table' => 'wp_x', 'kind' => 'row', 'info' => 'y'],
        ]],
        ['target' => uamswp_auditor_role_walk_target('c'), 'status' => 302, 'outcome' => 'hidden', 'location' => 'https://example.org/a|b', 'unexpected' => []],
    ]))->toBe(implode("\n", [
        '## Every target',
        '',
        '4 targets, in the order visited (hidden 2, none 1, refused 1).',
        '',
        '| # | Target | Status | Plugin | Redirects to | Unexpected writes |',
        '| ---: | --- | ---: | --- | --- | ---: |',
        '| 1 | `b` | 403 | hidden |  | 0 |',
        '| 2 | `ajax:x \| y` | 403 | refused |  | 0 |',
        '| 3 | `a` | 200 | none |  | 2 |',
        '| 4 | `c` | 302 | hidden | `https://example.org/a\|b` | 0 |',
    ])."\n");
});

it('lists no rows for a run that visited nothing', function (): void {
    expect(Report::targets([]))->toBe("## Every target\n\n0 targets, in the order visited.\n");
});
