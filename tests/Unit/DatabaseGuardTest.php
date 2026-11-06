<?php

/**
 * UAMSWeb\AuditorRole\DatabaseGuard, with WordPress mocked.
 *
 * Mutation-clean under `composer mutate -- --path=includes/DatabaseGuard.php`
 * and `--path=includes/SessionTokens.php`, with no equivalent mutants.
 *
 * @see includes/DatabaseGuard.php
 * @see includes/SessionTokens.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\SessionTokens;

/*
 * A stand-in for core's session manager, so SessionTokens can be loaded with no
 * WordPress. Its write records what the database guard does with a statement
 * issued from inside it.
 */
if (! class_exists('WP_User_Meta_Session_Tokens')) {
    class WP_User_Meta_Session_Tokens
    {
        /** @var list<mixed> */
        public array $written = [];

        /**
         * @param  array<string, mixed>  $sessions
         */
        protected function update_sessions($sessions): void
        {
            $this->written[] = [$sessions, DatabaseGuard::filter("UPDATE wp_usermeta SET meta_value = 'x' WHERE meta_key = 'session_tokens'")];
        }
    }
}

beforeEach(function (): void {
    uamswp_auditor_role_reset();
});

it('treats reads as reads', function (string $query): void {
    expect(DatabaseGuard::is_read($query))->toBeTrue();
})->with([
    'select' => ['SELECT * FROM wp_posts'],
    'lower case' => ['select 1'],
    'leading space and comment' => ['  /* x */ SELECT option_value FROM wp_options'],
    'line comment' => ["-- note\nSELECT 1"],
    'parenthesized union' => ['(SELECT 1) UNION (SELECT 2)'],
    'show' => ['SHOW TABLES LIKE "wp_%"'],
    'describe' => ['DESCRIBE wp_posts'],
    'desc' => ['DESC wp_posts'],
    'explain' => ['EXPLAIN SELECT 1'],
    'session variable' => ["SET SESSION sql_mode=''"],
    'names' => ['SET NAMES utf8mb4'],
    'user variable' => ['SET @a = 1'],
]);

it('treats everything else as a write', function (string $query): void {
    expect(DatabaseGuard::is_read($query))->toBeFalse();
})->with([
    'insert' => ["INSERT INTO wp_options (option_name) VALUES ('x')"],
    'update' => ["UPDATE wp_options SET option_value = 'x'"],
    'delete' => ['DELETE FROM wp_posts WHERE ID = 1'],
    'replace' => ['REPLACE INTO wp_options VALUES (1)'],
    'drop' => ['DROP TABLE wp_posts'],
    'alter' => ['ALTER TABLE wp_posts ADD x int'],
    'create' => ['CREATE TABLE x (id int)'],
    'truncate' => ['TRUNCATE wp_posts'],
    'comment hiding a write' => ['/* SELECT */ DELETE FROM wp_posts'],
    'select into a file' => ["SELECT * FROM wp_users INTO OUTFILE '/tmp/x'"],
    'select into a dump file' => ["SELECT 1 INTO DUMPFILE '/tmp/x'"],
    'global variable' => ['SET GLOBAL general_log = 1'],
    'common table expression' => ['WITH x AS (SELECT 1) DELETE FROM wp_posts'],
    'empty' => [''],
    'call' => ['CALL proc()'],
]);

it('describes a statement by verb and table, never values', function (string $query, string $described): void {
    expect(DatabaseGuard::describe($query))->toBe($described);
})->with([
    ["INSERT INTO `wp_options` (`option_name`) VALUES ('blogname')", 'INSERT wp_options (blogname)'],
    ["INSERT INTO `wp_options` (`option_value`, `option_name`) VALUES ('secret', 'x')", 'INSERT wp_options'],
    ["UPDATE `wp_usermeta` SET `meta_value` = 'x'", 'UPDATE wp_usermeta'],
    ['DELETE FROM wp_posts WHERE ID = 1', 'DELETE wp_posts'],
    ['REPLACE INTO wp_options VALUES (1)', 'REPLACE wp_options'],
    ['INSERT IGNORE INTO wp_x VALUES (1)', 'INSERT wp_x'],
    ['DROP TABLE IF EXISTS wp_x', 'DROP wp_x'],
    ['TRUNCATE TABLE wp_x', 'TRUNCATE wp_x'],
    ["INSERT INTO `wp_options` (`option_name`, `option_value`, `autoload`) VALUES ('_transient_x', 'secret', 'off') ON DUPLICATE KEY UPDATE x", 'INSERT wp_options (_transient_x)'],
    ["UPDATE `wp_2_options` SET `option_value` = 'secret' WHERE `option_name` = 'gf_db_version'", 'UPDATE wp_2_options (gf_db_version)'],
    ["DELETE FROM `wp_sitemeta` WHERE `meta_key` = '_site_transient_x'", 'DELETE wp_sitemeta (_site_transient_x)'],
    ["UPDATE `wp_postmeta` SET `meta_value` = 'x' WHERE `meta_key` = '_edit_lock'", 'UPDATE wp_postmeta'],
    ['CALL proc()', 'CALL'],
    'lower case write' => ['insert into wp_x values (1)', 'INSERT wp_x'],
    'lower case other' => ['call proc()', 'CALL'],
    'leading comment and space' => ["  /* note */ -- line\n DELETE FROM wp_posts", 'DELETE wp_posts'],
    ['', 'UNKNOWN'],
]);

it('refuses a write in an Auditor session and records it', function (): void {
    uamswp_auditor_role_as(true);

    expect(DatabaseGuard::filter("UPDATE wp_options SET option_value = 'x'"))->toBe('')
        ->and(DatabaseGuard::refused())->toBe(['UPDATE wp_options'])
        ->and(DatabaseGuard::refused_count())->toBe(1);
});

it('passes a read in an Auditor session', function (): void {
    uamswp_auditor_role_as(true);

    expect(DatabaseGuard::filter('SELECT 1'))->toBe('SELECT 1')
        ->and(DatabaseGuard::refused_count())->toBe(0);
});

it('passes a write by any other role', function (): void {
    uamswp_auditor_role_as(false);

    expect(DatabaseGuard::filter('DELETE FROM wp_posts'))->toBe('DELETE FROM wp_posts');
});

it('passes the plugin\'s own writes inside bypass() and restores the guard after', function (): void {
    uamswp_auditor_role_as(true);

    $inside = DatabaseGuard::bypass(static fn (): mixed => DatabaseGuard::filter('INSERT INTO wp_uamswp_auditor_log VALUES (1)'));

    expect($inside)->toBe('INSERT INTO wp_uamswp_auditor_log VALUES (1)')
        ->and(DatabaseGuard::filter('INSERT INTO wp_x VALUES (1)'))->toBe('');
});

it('restores the guard when the bypassed callback throws', function (): void {
    uamswp_auditor_role_as(true);

    try {
        DatabaseGuard::bypass(static function (): never {
            throw new RuntimeException('x');
        });
    } catch (RuntimeException) {
    }

    expect(DatabaseGuard::filter('INSERT INTO wp_x VALUES (1)'))->toBe('');
});

it('lets a deployment allow one statement through the filter', function (): void {
    uamswp_auditor_role_as(true);
    Brain\Monkey\Filters\expectApplied('uamswp_auditor_role_allow_query')->once()->andReturn(true);

    expect(DatabaseGuard::filter('INSERT INTO wp_x VALUES (1)'))->toBe('INSERT INTO wp_x VALUES (1)');
});

it('keeps ten descriptions but counts every refusal', function (): void {
    uamswp_auditor_role_as(true);

    for ($i = 0; $i < 12; $i++) {
        DatabaseGuard::filter('INSERT INTO wp_x VALUES (1)');
    }

    expect(DatabaseGuard::refused())->toHaveCount(10)
        ->and(DatabaseGuard::refused_count())->toBe(12);
});

it('passes a value that is not a string untouched', function (): void {
    expect(DatabaseGuard::filter(null))->toBeNull();
});

it('swaps in the session token manager only for core\'s', function (): void {
    expect(DatabaseGuard::session_token_manager('WP_User_Meta_Session_Tokens'))->toBe(SessionTokens::class)
        ->and(DatabaseGuard::session_token_manager('Other_Tokens'))->toBe('Other_Tokens');
});

it('hooks the query filter and the session token manager last', function (): void {
    Filters\expectAdded('query')->once()->with([DatabaseGuard::class, 'filter'], PHP_INT_MAX);
    Filters\expectAdded('session_token_manager')->once()->with([DatabaseGuard::class, 'session_token_manager'], PHP_INT_MAX);

    DatabaseGuard::boot();
});

it('starts with nothing refused and the guard armed', function (): void {
    uamswp_auditor_role_as(true);

    expect(DatabaseGuard::refused())->toBe([])
        ->and(DatabaseGuard::refused_count())->toBe(0)
        ->and(DatabaseGuard::filter('INSERT INTO wp_x VALUES (1)'))->toBe('');
});

it('keeps the guard suspended through nested bypasses until the outer one ends', function (): void {
    uamswp_auditor_role_as(true);

    $inner = DatabaseGuard::bypass(static function (): mixed {
        DatabaseGuard::bypass(static fn (): null => null);

        return DatabaseGuard::filter('INSERT INTO wp_x VALUES (1)');
    });

    expect($inner)->toBe('INSERT INTO wp_x VALUES (1)')
        ->and(DatabaseGuard::filter('INSERT INTO wp_x VALUES (1)'))->toBe('');
});

it('returns what the bypassed callback returns', function (): void {
    expect(DatabaseGuard::bypass(static fn (): string => 'done'))->toBe('done');
});

it('strips one leading comment at a time without eating the statement', function (): void {
    expect(DatabaseGuard::is_read('/* a */SELECT 1'))->toBeTrue()
        ->and(DatabaseGuard::is_read("# a\nSELECT 1"))->toBeTrue()
        ->and(DatabaseGuard::is_read('((SELECT 1))'))->toBeTrue()
        ->and(DatabaseGuard::is_read('/* a */ /* b */INSERT INTO x VALUES (1)'))->toBeFalse();
});

it('writes session tokens past the guard, inside an Auditor session', function (): void {
    uamswp_auditor_role_as(true);

    $tokens = (new ReflectionClass(SessionTokens::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod(SessionTokens::class, 'update_sessions');
    $method->invoke($tokens, ['verifier' => ['expiration' => 1]]);

    expect($tokens->written)->toBe([[['verifier' => ['expiration' => 1]], "UPDATE wp_usermeta SET meta_value = 'x' WHERE meta_key = 'session_tokens'"]])
        ->and(DatabaseGuard::filter('INSERT INTO wp_x VALUES (1)'))->toBe('');
});
