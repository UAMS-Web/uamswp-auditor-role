<?php

/**
 * UAMSWeb\AuditorRole\Walk\BinlogWindow, against a scripted server.
 *
 * @see tests/Walk/src/BinlogWindow.php
 */

declare(strict_types=1);

use UAMSWeb\AuditorRole\Walk\BinlogWindow;

/**
 * A dataset's answers, typed for the server: every statement a string, every answer a list of keyed rows or an exception.
 *
 * @param  array<array-key, mixed>  $answers
 * @return array<string, list<array<string, mixed>>|RuntimeException>
 */
function uamswp_auditor_role_walk_answers(array $answers): array
{
    $typed = [];

    foreach ($answers as $sql => $answer) {
        $typed[uamswp_auditor_role_string($sql)] = $answer instanceof RuntimeException
            ? $answer
            : array_map(uamswp_auditor_role_string_keyed(...), array_values(uamswp_auditor_role_array($answer)));
    }

    return $typed;
}

/**
 * A server that answers each statement from a map, and records what it was asked.
 *
 * @param  array<string, list<array<string, mixed>>|RuntimeException>  $answers
 * @param  list<string>  $asked
 */
function uamswp_auditor_role_walk_server(array $answers, array &$asked): Closure
{
    return static function (string $sql) use ($answers, &$asked): array {
        $asked[] = $sql;

        if (! array_key_exists($sql, $answers)) {
            throw new LogicException('Unscripted statement: '.$sql);
        }

        $answer = $answers[$sql];

        if ($answer instanceof RuntimeException) {
            throw $answer;
        }

        return $answer;
    };
}

it('accepts a binary log in row format', function (): void {
    $asked = [];
    $window = new BinlogWindow(uamswp_auditor_role_walk_server(['SELECT @@log_bin AS log_bin, @@binlog_format AS format' => [['log_bin' => '1', 'format' => 'row']]], $asked), 'site');

    $window->verify_settings();

    expect($asked)->toBe(['SELECT @@log_bin AS log_bin, @@binlog_format AS format']);
});

it('refuses a server whose log cannot show a write', function (array $row, string $message): void {
    $asked = [];
    $window = new BinlogWindow(uamswp_auditor_role_walk_server(['SELECT @@log_bin AS log_bin, @@binlog_format AS format' => [uamswp_auditor_role_string_keyed($row)]], $asked), 'site');

    if ($message === '') {
        $window->verify_settings();
        expect($asked)->toHaveCount(1);

        return;
    }

    expect(fn () => $window->verify_settings())->toThrow(RuntimeException::class, $message);
})->with([
    'log off' => [['log_bin' => '0', 'format' => 'ROW'], 'The binary log is off'],
    'no answer' => [[], 'The binary log is off'],
    'statement format' => [['log_bin' => '1', 'format' => 'STATEMENT'], 'binlog_format is not ROW'],
    'mixed format' => [['log_bin' => '1', 'format' => 'MIXED'], 'binlog_format is not ROW'],
    'no format' => [['log_bin' => '1'], 'binlog_format is not ROW'],
    'a number for on' => [['log_bin' => 1, 'format' => 'ROW'], ''],
    'a list for on' => [['log_bin' => ['1'], 'format' => 'ROW'], 'The binary log is off'],
]);

it('reads the position from the current statement, or the one older servers know', function (): void {
    $asked = [];
    $new = new BinlogWindow(uamswp_auditor_role_walk_server(['SHOW BINARY LOG STATUS' => [['File' => 'binlog.000007', 'Position' => '1234']]], $asked), 'site');

    expect($new->position())->toBe(['file' => 'binlog.000007', 'position' => 1234])
        ->and($asked)->toBe(['SHOW BINARY LOG STATUS']);

    $asked = [];
    $old = new BinlogWindow(uamswp_auditor_role_walk_server([
        'SHOW BINARY LOG STATUS' => new RuntimeException('syntax error'),
        'SHOW MASTER STATUS' => [['File' => 'mysql-bin.000002', 'Position' => 99]],
    ], $asked), 'site');

    expect($old->position())->toBe(['file' => 'mysql-bin.000002', 'position' => 99])
        ->and($asked)->toBe(['SHOW BINARY LOG STATUS', 'SHOW MASTER STATUS']);
});

it('reads a position the server returns as a number, and none as zero', function (): void {
    $asked = [];
    $numeric = new BinlogWindow(uamswp_auditor_role_walk_server(['SHOW BINARY LOG STATUS' => [['File' => 7, 'Position' => 15]]], $asked), 'site');
    $missing = new BinlogWindow(uamswp_auditor_role_walk_server(['SHOW BINARY LOG STATUS' => [['File' => 'binlog.1']]], $asked), 'site');

    expect($numeric->position())->toBe(['file' => '7', 'position' => 15])
        ->and($missing->position())->toBe(['file' => 'binlog.1', 'position' => 0]);
});

it('fails when the server reports no position', function (array $answers): void {
    $asked = [];
    $window = new BinlogWindow(uamswp_auditor_role_walk_server(uamswp_auditor_role_walk_answers($answers), $asked), 'site');

    expect(fn (): array => $window->position())->toThrow(RuntimeException::class, 'no binary log position');
})->with([
    'empty result' => [['SHOW BINARY LOG STATUS' => []]],
    'no file' => [['SHOW BINARY LOG STATUS' => [['Position' => 5]]]],
    'both refused' => [['SHOW BINARY LOG STATUS' => new RuntimeException('a'), 'SHOW MASTER STATUS' => new RuntimeException('b')]],
]);

it('reads the writes to its schema from a position, across a rotation, in order', function (): void {
    $asked = [];
    $window = new BinlogWindow(uamswp_auditor_role_walk_server([
        'SHOW BINARY LOGS' => [['Log_name' => 'binlog.000006'], ['Log_name' => 'binlog.000007'], ['Log_name' => 'binlog.000008']],
        "SHOW BINLOG EVENTS IN 'binlog.000007' FROM 500" => [
            ['Event_type' => 'Query', 'Info' => 'BEGIN'],
            ['Event_type' => 'Table_map', 'Info' => 'table_id: 92 (site.wp_options)'],
            ['Event_type' => 'Update_rows', 'Info' => 'table_id: 92 flags: STMT_END_F'],
            ['Event_type' => 'Table_map', 'Info' => 'table_id: 93 (other.wp_options)'],
            ['Event_type' => 'Xid', 'Info' => 'COMMIT /* xid=12 */'],
        ],
        "SHOW BINLOG EVENTS IN 'binlog.000008' FROM 4" => [
            ['Event_type' => 'Query', 'Info' => 'use `site`; CREATE TABLE `wp_probe` (id int)'],
        ],
    ], $asked), 'site');

    expect($window->writes_since(['file' => 'binlog.000007', 'position' => 500]))->toBe([
        ['table' => 'wp_options', 'kind' => 'row', 'info' => 'table_id: 92 (site.wp_options)'],
        ['table' => 'wp_probe', 'kind' => 'statement', 'info' => 'use `site`; CREATE TABLE `wp_probe` (id int)'],
    ])->and($asked)->toBe([
        'SHOW BINARY LOGS',
        "SHOW BINLOG EVENTS IN 'binlog.000007' FROM 500",
        "SHOW BINLOG EVENTS IN 'binlog.000008' FROM 4",
    ]);
});

it('reads only the named file when the server no longer lists it', function (): void {
    $asked = [];
    $window = new BinlogWindow(uamswp_auditor_role_walk_server([
        'SHOW BINARY LOGS' => [['Log_name' => 'binlog.000009']],
        "SHOW BINLOG EVENTS IN 'bin\\'log.1' FROM 4" => [['Event_type' => 'Table_map', 'Info' => 'table_id: 1 (site.wp_posts)']],
    ], $asked), 'site');

    expect($window->writes_since(['file' => "bin'log.1", 'position' => 4]))->toHaveCount(1);
});

it('names the table a row event changes, in its schema only', function (string $info, ?array $write): void {
    $asked = [];
    $window = new BinlogWindow(uamswp_auditor_role_walk_server([], $asked), 'site');

    expect($window->write_of(['Event_type' => 'Table_map', 'Info' => $info]))->toBe($write);
})->with([
    'plain' => ['table_id: 92 (site.wp_options)', ['table' => 'wp_options', 'kind' => 'row', 'info' => 'table_id: 92 (site.wp_options)']],
    'quoted' => ['table_id: 92 (`site`.`wp_2_options`)', ['table' => 'wp_2_options', 'kind' => 'row', 'info' => 'table_id: 92 (`site`.`wp_2_options`)']],
    'another schema' => ['table_id: 92 (site_copy.wp_options)', null],
    'a schema that starts the same' => ['table_id: 92 (sites.wp_options)', null],
    'no table' => ['table_id: 92', null],
]);

it('reads DDL in its schema from a query event, and skips transaction markers and other schemas', function (string $info, ?array $write): void {
    $asked = [];
    $window = new BinlogWindow(uamswp_auditor_role_walk_server([], $asked), 'site');

    expect($window->write_of(['Event_type' => 'Query', 'Info' => $info]))->toBe($write);
})->with([
    'create' => ['use `site`; CREATE TABLE IF NOT EXISTS `wp_x` (id int)', ['table' => 'wp_x', 'kind' => 'statement', 'info' => 'use `site`; CREATE TABLE IF NOT EXISTS `wp_x` (id int)']],
    'drop' => ['use `site`; DROP TABLE wp_y /* generated by server */', ['table' => 'wp_y', 'kind' => 'statement', 'info' => 'use `site`; DROP TABLE wp_y /* generated by server */']],
    'no table named' => ['use `site`; FLUSH PRIVILEGES', ['table' => '', 'kind' => 'statement', 'info' => 'use `site`; FLUSH PRIVILEGES']],
    'begin' => ['BEGIN', null],
    'commit' => ['  COMMIT', null],
    'xa' => ['XA START X\'1\'', null],
    'another schema' => ['use `other`; CREATE TABLE t (id int)', null],
    'no schema' => ['CREATE DATABASE x', null],
]);

it('ignores events that change nothing', function (string $type): void {
    $asked = [];
    $window = new BinlogWindow(uamswp_auditor_role_walk_server([], $asked), 'site');

    expect($window->write_of(['Event_type' => $type, 'Info' => 'use `site`; CREATE TABLE t (id int)']))->toBeNull();
})->with(['Format_desc', 'Gtid', 'Xid', 'Rotate', 'Write_rows', '']);

it('reads an event with no info as nothing', function (): void {
    $asked = [];
    $window = new BinlogWindow(uamswp_auditor_role_walk_server([], $asked), 'site');

    expect($window->write_of(['Event_type' => 'Table_map']))->toBeNull()
        ->and($window->write_of([]))->toBeNull()
        ->and($window->write_of(['Event_type' => ['Table_map'], 'Info' => 'table_id: 1 (site.wp_x)']))->toBeNull()
        ->and($window->write_of(['Event_type' => 'Table_map', 'Info' => ['table_id: 1 (site.wp_x)']]))->toBeNull();
});
