<?php

/**
 * UAMSWeb\AuditorRole\LogQuery, with the database mocked.
 *
 * Mutation-clean under `composer mutate -- --path=includes/LogQuery.php`, with
 * no equivalent mutants.
 *
 * @see includes/LogQuery.php
 */

declare(strict_types=1);

use UAMSWeb\AuditorRole\LogQuery;

require_once __DIR__.'/LogSupport.php';

beforeEach(function (): void {
    uamswp_auditor_role_g3_wpdb();
});

/**
 * The CSV a write_csv() call produced.
 *
 * @param  array{user_id: int, from: string, to: string, event: string}  $filters
 */
function uamswp_auditor_role_g3_csv(array $filters, bool $header = true): string
{
    $stream = fopen('php://memory', 'w+');
    assert($stream !== false);

    LogQuery::write_csv($stream, $filters, $header);
    rewind($stream);

    return (string) stream_get_contents($stream);
}

it('lists the columns in export order', function (): void {
    expect(LogQuery::columns())->toBe(['id', 'logged_at', 'user_id', 'user_login', 'blog_id', 'event', 'method', 'screen', 'url', 'detail']);
});

it('cleans filters to a fixed shape', function (array $raw, array $clean): void {
    expect(LogQuery::filters($raw))->toBe($clean);
})->with([
    'every filter' => [['user_id' => '5', 'from' => '2026-11-01', 'to' => '2026-01-31', 'event' => 'refused'], ['user_id' => 5, 'from' => '2026-11-01', 'to' => '2026-01-31', 'event' => 'refused']],
    'none' => [[], ['user_id' => 0, 'from' => '', 'to' => '', 'event' => '']],
    'dates with spaces' => [['from' => ' 2026-11-01 ', 'to' => "2026-11-05\n"], ['user_id' => 0, 'from' => '2026-11-01', 'to' => '2026-11-05', 'event' => '']],
    'a day that does not exist' => [['from' => '2026-02-30', 'to' => '2026-13-01'], ['user_id' => 0, 'from' => '', 'to' => '', 'event' => '']],
    'a date with something around it' => [['from' => "2026-11-01' OR 1=1", 'to' => 'x2026-11-01'], ['user_id' => 0, 'from' => '', 'to' => '', 'event' => '']],
    'a date that is not text' => [['from' => ['2026-11-01'], 'to' => 20261101], ['user_id' => 0, 'from' => '', 'to' => '', 'event' => '']],
    'a negative user' => [['user_id' => '-3'], ['user_id' => 0, 'from' => '', 'to' => '', 'event' => '']],
    'a user that is not a number' => [['user_id' => 'audit'], ['user_id' => 0, 'from' => '', 'to' => '', 'event' => '']],
    'an unknown event' => [['event' => 'DROP'], ['user_id' => 0, 'from' => '', 'to' => '', 'event' => '']],
    'an event that is not text' => [['event' => ['view']], ['user_id' => 0, 'from' => '', 'to' => '', 'event' => '']],
    'a numeric event' => [['event' => 1], ['user_id' => 0, 'from' => '', 'to' => '', 'event' => '']],
]);

it('reads a date as year, month and day, in that order', function (mixed $value, string $date): void {
    expect(LogQuery::date($value))->toBe($date);
})->with([
    'the last day of January' => ['2026-01-31', '2026-01-31'],
    'a leap day' => ['2028-02-29', '2028-02-29'],
    'not a leap year' => ['2027-02-29', ''],
    'year 0000' => ['0000-01-01', ''],
    'with tabs' => ["\t2026-11-01\t", '2026-11-01'],
    'nothing' => [null, ''],
    'a float' => [2026.11, ''],
    'an empty string' => ['', ''],
]);

it('accepts each event the log records', function (string $event): void {
    expect(LogQuery::filters(['event' => $event])['event'])->toBe($event);
})->with(['view', 'refused', 'db_refused', 'login', 'logout', 'login_refused']);

it('builds the WHERE clause from placeholders only', function (array $filters, string $where, array $values): void {
    // Each dataset's filters are already clean, so filters() hands them back unchanged.
    expect(LogQuery::filters($filters))->toBe($filters)
        ->and(LogQuery::where(LogQuery::filters($filters)))->toBe([$where, $values]);
})->with([
    'every filter' => [['user_id' => 5, 'from' => '2026-11-01', 'to' => '2026-11-05', 'event' => 'view'], '1=1 AND user_id = %d AND logged_at >= %s AND logged_at <= %s AND event = %s', [5, '2026-11-01 00:00:00', '2026-11-05 23:59:59', 'view']],
    'user 1' => [['user_id' => 1, 'from' => '', 'to' => '', 'event' => ''], '1=1 AND user_id = %d', [1]],
    'none' => [['user_id' => 0, 'from' => '', 'to' => '', 'event' => ''], '1=1', []],
]);

it('reads matching rows newest first, a page at a time', function (): void {
    uamswp_auditor_role_g3_db()->results = [['k' => uamswp_auditor_role_g3_row(2), 'j' => uamswp_auditor_role_g3_row(1)]];

    $rows = LogQuery::rows(['user_id' => 5, 'from' => '', 'to' => '', 'event' => 'view'], 50, 100);

    expect($rows)->toBe([uamswp_auditor_role_g3_row(2), uamswp_auditor_role_g3_row(1)])
        ->and(uamswp_auditor_role_g3_db()->prepared)->toBe(["SELECT * FROM `wp_uamswp_auditor_log` WHERE 1=1 AND user_id = 5 AND event = 'view' ORDER BY id DESC LIMIT 50 OFFSET 100"]);
});

it('reads from the first row by default', function (): void {
    uamswp_auditor_role_g3_db()->results = [[]];

    LogQuery::rows(LogQuery::filters([]), 10);

    expect(uamswp_auditor_role_g3_db()->prepared[0])->toEndWith('LIMIT 10 OFFSET 0');
});

it('reads no rows when the query fails', function (): void {
    uamswp_auditor_role_g3_db()->results = [null];

    expect(LogQuery::rows(LogQuery::filters([]), 10))->toBe([]);
});

it('counts matching rows', function (): void {
    uamswp_auditor_role_g3_db()->vars = ['42'];

    expect(LogQuery::count(['user_id' => 0, 'from' => '2026-11-01', 'to' => '', 'event' => '']))->toBe(42)
        ->and(uamswp_auditor_role_g3_db()->prepared)->toBe(["SELECT COUNT(*) FROM `wp_uamswp_auditor_log` WHERE 1=1 AND logged_at >= '2026-11-01 00:00:00'"]);
});

it('lists the users in the log, by ID', function (): void {
    uamswp_auditor_role_g3_db()->results = [[['user_id' => '7', 'user_login' => 'audit'], ['user_id' => '012', 'user_login' => 5]]];

    expect(LogQuery::users())->toBe([7 => 'audit', 12 => '5'])
        ->and(uamswp_auditor_role_g3_db()->prepared)->toBe(['SELECT DISTINCT user_id, user_login FROM `wp_uamswp_auditor_log` ORDER BY user_login']);
});

it('lists no users when the query fails', function (): void {
    uamswp_auditor_role_g3_db()->results = [null];

    expect(LogQuery::users())->toBe([]);
});

it('neutralizes a formula in a CSV cell', function (mixed $value, string $cell): void {
    expect(LogQuery::csv_cell($value))->toBe($cell);
})->with([
    ['=HYPERLINK("x")', "'=HYPERLINK(\"x\")"],
    ['+1', "'+1"],
    ['-1', "'-1"],
    ['@SUM(A1)', "'@SUM(A1)"],
    ["\tx", "'\tx"],
    ["\rx", "'\rx"],
    ['a=1', 'a=1'],
    ['/wp-admin/', '/wp-admin/'],
    [42, '42'],
    [null, ''],
    [['x'], ''],
]);

it('writes a header and each row as CSV', function (): void {
    $row = uamswp_auditor_role_g3_row(3, '2026-11-05 10:00:00', 1, '=cmd');
    unset($row['detail']);
    uamswp_auditor_role_g3_db()->results = [[$row]];

    expect(uamswp_auditor_role_g3_csv(LogQuery::filters([])))
        ->toBe("id,logged_at,user_id,user_login,blog_id,event,method,screen,url,detail\n3,\"2026-11-05 10:00:00\",7,audit,1,view,GET,index.php,'=cmd,\n");
});

it('writes the header unless told not to', function (): void {
    uamswp_auditor_role_g3_db()->results = [[]];
    $stream = fopen('php://memory', 'w+');
    assert($stream !== false);

    LogQuery::write_csv($stream, LogQuery::filters([]));
    rewind($stream);

    expect(stream_get_contents($stream))->toBe("id,logged_at,user_id,user_login,blog_id,event,method,screen,url,detail\n");
});

it('leaves out the header when asked', function (): void {
    uamswp_auditor_role_g3_db()->results = [[uamswp_auditor_role_g3_row(3)]];

    expect(uamswp_auditor_role_g3_csv(LogQuery::filters([]), false))->toBe("3,\"2026-11-05 10:00:00\",7,audit,1,view,GET,index.php,/wp-admin/,\n");
});

it('reads in batches of 1000 until a batch comes back short', function (): void {
    uamswp_auditor_role_g3_db()->results = [
        array_map(uamswp_auditor_role_g3_row(...), range(1, 1000)),
        [uamswp_auditor_role_g3_row(1001)],
        [uamswp_auditor_role_g3_row(9999)],
    ];

    $csv = uamswp_auditor_role_g3_csv(LogQuery::filters([]));

    expect(substr_count($csv, "\n"))->toBe(1002)
        ->and($csv)->not->toContain('9999,')
        ->and(uamswp_auditor_role_g3_db()->prepared)->toHaveCount(2)
        ->and(uamswp_auditor_role_g3_db()->prepared[0])->toEndWith('LIMIT 1000 OFFSET 0')
        ->and(uamswp_auditor_role_g3_db()->prepared[1])->toEndWith('LIMIT 1000 OFFSET 1000');
});

it('stops after an empty first batch', function (): void {
    uamswp_auditor_role_g3_db()->results = [[], [uamswp_auditor_role_g3_row(5)]];

    expect(uamswp_auditor_role_g3_csv(LogQuery::filters([])))->toBe("id,logged_at,user_id,user_login,blog_id,event,method,screen,url,detail\n")
        ->and(uamswp_auditor_role_g3_db()->prepared)->toHaveCount(1);
});
