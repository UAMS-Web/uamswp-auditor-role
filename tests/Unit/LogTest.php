<?php

/**
 * UAMSWeb\AuditorRole\Log and LogQuery, with WordPress mocked.
 *
 * @see includes/Log.php
 * @see includes/LogQuery.php
 */

declare(strict_types=1);

use UAMSWeb\AuditorRole\Log;
use UAMSWeb\AuditorRole\LogQuery;

it('drops secret-bearing values from a logged URL', function (string $url, string $logged): void {
    expect(Log::redact_url($url))->toBe($logged);
})->with([
    'no query' => ['/wp-admin/edit.php', '/wp-admin/edit.php'],
    'empty query' => ['/wp-admin/edit.php?', '/wp-admin/edit.php'],
    'plain query' => ['/wp-admin/edit.php?post_type=page&paged=2', '/wp-admin/edit.php?post_type=page&paged=2'],
    'nonce' => ['/wp-login.php?action=logout&_wpnonce=abc123', '/wp-login.php?action=logout&_wpnonce=REDACTED'],
    'reset key' => ['/wp-login.php?action=rp&key=XYZ&login=a', '/wp-login.php?action=rp&key=REDACTED&login=a'],
    'token and password' => ['/x?token=1&pwd=2&api_key=3', '/x?token=REDACTED&pwd=REDACTED&api_key=REDACTED'],
    'encoded name' => ['/x?_wp%6Eonce=1', '/x?_wpnonce=REDACTED'],
]);

it('cleans filters to a fixed shape', function (): void {
    expect(LogQuery::filters(['user_id' => '5', 'from' => '2026-11-01', 'to' => '2026-02-30', 'event' => 'refused']))
        ->toBe(['user_id' => 5, 'from' => '2026-11-01', 'to' => '', 'event' => 'refused'])
        ->and(LogQuery::filters(['user_id' => '-3', 'from' => "2026-11-01' OR 1=1", 'event' => 'DROP']))
        ->toBe(['user_id' => 0, 'from' => '', 'to' => '', 'event' => '']);
});

it('builds the WHERE clause from placeholders only', function (): void {
    [$where, $values] = LogQuery::where(['user_id' => 5, 'from' => '2026-11-01', 'to' => '2026-11-05', 'event' => 'view']);

    expect($where)->toBe('1=1 AND user_id = %d AND logged_at >= %s AND logged_at <= %s AND event = %s')
        ->and($values)->toBe([5, '2026-11-01 00:00:00', '2026-11-05 23:59:59', 'view'])
        ->and(LogQuery::where(LogQuery::filters([])))->toBe(['1=1', []]);
});

it('neutralizes a formula in a CSV cell', function (mixed $value, string $cell): void {
    expect(LogQuery::csv_cell($value))->toBe($cell);
})->with([
    ['=HYPERLINK("x")', "'=HYPERLINK(\"x\")"],
    ['+1', "'+1"],
    ['-1', "'-1"],
    ['@SUM(A1)', "'@SUM(A1)"],
    ['/wp-admin/', '/wp-admin/'],
    [42, '42'],
    [null, ''],
]);
