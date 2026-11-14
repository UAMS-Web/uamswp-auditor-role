<?php

/**
 * UAMSWeb\AuditorRole\Walk\Command.
 *
 * @see tests/Walk/src/Command.php
 */

declare(strict_types=1);

use UAMSWeb\AuditorRole\Walk\Command;

it('returns the stdout of the command', function (): void {
    expect(Command::run([PHP_BINARY, '-r', 'echo "one\ntwo";'], __DIR__))->toBe(['stdout' => "one\ntwo", 'status' => 0]);
});

it('returns the stdout of a child that writes far more to stderr than a pipe holds', function (): void {
    // 1 MB is well past a pipe buffer (64 KB on Linux, 16 KB on macOS).
    $code = 'fwrite(STDERR, str_repeat("x", 1048576)); echo "done";';

    expect(Command::run([PHP_BINARY, '-r', $code], __DIR__))->toBe(['stdout' => 'done', 'status' => 0]);
});

it('does not return the stderr of the command', function (): void {
    expect(Command::run([PHP_BINARY, '-r', 'fwrite(STDERR, "noise"); echo "out";'], __DIR__))->toBe(['stdout' => 'out', 'status' => 0]);
});

it('runs the command in the directory it is given', function (): void {
    expect(Command::run([PHP_BINARY, '-r', 'echo getcwd();'], sys_get_temp_dir()))->toBe(['stdout' => realpath(sys_get_temp_dir()), 'status' => 0]);
});

it('gives the child its own stderr, not the stderr it was started with', function (): void {
    $identity = static fn (): string => (string) json_encode(array_intersect_key((array) fstat(STDERR), ['dev' => 0, 'ino' => 0]));
    $child = Command::run([PHP_BINARY, '-r', 'echo json_encode(array_intersect_key(fstat(STDERR), ["dev" => 0, "ino" => 0]));'], __DIR__);

    expect($child)->not->toBeNull()
        ->and($child['stdout'] ?? '')->not->toBe($identity())
        ->and($child['stdout'] ?? '')->not->toBe('');
});

it('returns the exit status of the command', function (): void {
    expect(Command::run([PHP_BINARY, '-r', 'echo "x"; exit(3);'], __DIR__))->toBe(['stdout' => 'x', 'status' => 3]);
});

it('returns null when the command cannot start', function (): void {
    // PHP warns that the spawn failed; the walk reports it in its own words.
    set_error_handler(static fn (): bool => true);

    try {
        expect(Command::run([PHP_BINARY, '-r', 'echo 1;'], '/nonexistent-directory-for-the-walk'))->toBeNull();
    } finally {
        restore_error_handler();
    }
});
