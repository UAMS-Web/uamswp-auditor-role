<?php

/**
 * UAMSWeb\AuditorRole\Secrets.
 *
 * @see includes/Secrets.php
 */

declare(strict_types=1);

use UAMSWeb\AuditorRole\Secrets;

it('recognizes secret-bearing names', function (string $name, bool $secret): void {
    expect(Secrets::is_secret_name($name))->toBe($secret);
})->with([
    ['wp_mail_smtp', true],
    ['gf_recaptcha_private_key', true],
    ['facetwp_license', true],
    ['api_key', true],
    ['wppusher_token', true],
    ['key', true],
    ['blogname', false],
    ['siteurl', false],
    ['active_sitewide_plugins', false],
    ['monkey', false],
]);

it('masks by name, recursing into arrays and objects', function (): void {
    $value = ['host' => 'smtp.example.org', 'password' => 'hunter2', 'nested' => (object) ['client_id' => 'abc', 'label' => 'x']];

    expect(Secrets::mask('mail_settings', $value))->toBe([
        'host' => 'smtp.example.org',
        'password' => Secrets::MASK,
        'nested' => ['client_id' => Secrets::MASK, 'label' => 'x'],
    ])
        ->and(Secrets::mask('smtp', ['a' => 1]))->toBe(Secrets::MASK)
        ->and(Secrets::mask('api_key', ''))->toBe('');
});

it('masks a value that looks like a token whatever its name', function (): void {
    expect(Secrets::mask('site_name', 'sk_live_abc'))->toBe(Secrets::MASK)
        ->and(Secrets::mask('x', 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8'))->toBe(Secrets::MASK)
        ->and(Secrets::mask('x', 'https://example.org/a/very/long/path/that/is/fine'))->toBe('https://example.org/a/very/long/path/that/is/fine')
        ->and(Secrets::mask('blogname', 'UAMS Health'))->toBe('UAMS Health')
        ->and(Secrets::mask('form_data', 'action=savefile&connection_info=https%3A%2F%2Fx%0Aabc&create_backup=1'))->toBe(Secrets::MASK)
        ->and(Secrets::mask('form_data', 'action=savefile&create_backup=1'))->toBe('action=savefile&create_backup=1')
        ->and(Secrets::mask('sig', 'abc'))->toBe(Secrets::MASK);
});

it('reads each parameter name of a query string, not its separator', function (string $value): void {
    // `key` matches only as a whole name, so a run that read `&key=` instead of `key` would let it through.
    expect(Secrets::looks_like_secret($value))->toBeTrue();
})->with([
    'first parameter' => ['key=abc&x=1'],
    'later parameter' => ['x=1&key=abc'],
    'semicolon separated' => ['x=1;sig=abc'],
]);

// The dataset spells the mask out: a class constant here would load the class while
// Pest collects the tests, before any test runs, and leave its guard line uncovered.
it('keeps an empty secret value as it is and masks any other', function (mixed $value, mixed $expected): void {
    expect(Secrets::mask('api_key', $value))->toBe($expected);
})->with([
    'empty string' => ['', ''],
    'null' => [null, null],
    'false' => [false, false],
    'true' => [true, '[masked]'],
    'zero' => [0, '[masked]'],
    'text' => ['x', '[masked]'],
]);

it('recurses into a list by position', function (): void {
    expect(Secrets::mask('servers', ['smtp.example.org', ['password' => 'p']]))->toBe(['smtp.example.org', ['password' => Secrets::MASK]]);
});
