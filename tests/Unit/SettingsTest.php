<?php

/**
 * UAMSWeb\PluginTemplate\Settings, with WordPress mocked.
 *
 * @see includes/Settings.php
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use UAMSWeb\PluginTemplate\Settings;

use function Brain\Monkey\Functions\stubEscapeFunctions;
use function Brain\Monkey\Functions\stubTranslationFunctions;
use function Brain\Monkey\Functions\when;

beforeEach(function (): void {
    stubEscapeFunctions();
    stubTranslationFunctions();
});

it('sanitizes input to plain text', function (): void {
    when('wp_unslash')->returnArg();
    Functions\expect('sanitize_text_field')
        ->once()
        ->with('<b>Hello</b>  there')
        ->andReturn('Hello there');

    expect(Settings::sanitize('<b>Hello</b>  there'))->toBe('Hello there');
});

it('turns a value that is not a string into an empty string', function (mixed $value): void {
    expect(Settings::sanitize($value))->toBe('');
})->with([
    'array' => [['a']],
    'null' => [null],
    'int' => [5],
]);

it('refuses to render the page without the capability', function (): void {
    Functions\expect('current_user_can')->once()->with(Settings::CAPABILITY)->andReturn(false);
    Functions\expect('wp_die')->once()->andReturnUsing(static function (): never {
        throw new RuntimeException('wp_die');
    });

    expect(fn () => Settings::render_page())->toThrow(RuntimeException::class, 'wp_die');
});

it('escapes the stored greeting where it prints it', function (): void {
    when('current_user_can')->justReturn(true);
    when('get_admin_page_title')->justReturn('Title');
    when('admin_url')->returnArg();
    when('wp_nonce_field')->justReturn('');
    when('submit_button')->justReturn('');
    when('get_option')->justReturn('"><script>alert(1)</script>');

    ob_start();
    Settings::render_page();
    $html = (string) ob_get_clean();

    expect($html)
        ->not->toContain('<script>')
        ->toContain('value="&quot;&gt;&lt;script&gt;');
});

it('refuses to save without the capability and never reaches the nonce', function (): void {
    Functions\expect('current_user_can')->once()->andReturn(false);
    Functions\expect('wp_die')->once()->andReturnUsing(static function (): never {
        throw new RuntimeException('wp_die');
    });
    Functions\expect('check_admin_referer')->never();
    Functions\expect('update_option')->never();

    expect(fn () => Settings::handle_save())->toThrow(RuntimeException::class);
});

it('checks the nonce before it saves', function (): void {
    when('current_user_can')->justReturn(true);
    Functions\expect('check_admin_referer')
        ->once()
        ->with(Settings::ACTION, Settings::NONCE)
        ->andReturnUsing(static function (): never {
            throw new RuntimeException('bad nonce');
        });
    Functions\expect('update_option')->never();

    expect(fn () => Settings::handle_save())->toThrow(RuntimeException::class, 'bad nonce');
});

it('saves the sanitized value and redirects safely', function (): void {
    $_POST['greeting'] = '  hi  ';

    when('current_user_can')->justReturn(true);
    when('check_admin_referer')->justReturn(1);
    when('wp_unslash')->returnArg();
    when('sanitize_text_field')->alias('trim');
    when('admin_url')->returnArg();
    when('add_query_arg')->justReturn('https://example.org/wp-admin/options-general.php?page=x');
    Functions\expect('update_option')->once()->with(Settings::OPTION, 'hi');
    Functions\expect('wp_safe_redirect')->once()->andReturnUsing(static function (): never {
        throw new RuntimeException('redirected');
    });

    try {
        expect(fn () => Settings::handle_save())->toThrow(RuntimeException::class, 'redirected');
    } finally {
        unset($_POST['greeting']);
    }
});

it('registers the page under Settings with the capability', function (): void {
    Functions\expect('add_options_page')
        ->once()
        ->with('UAMS Plugin Template', 'UAMS Plugin Template', Settings::CAPABILITY, Settings::PAGE, [Settings::class, 'render_page']);

    Settings::register_page();
});

it('registers the option with its sanitize callback', function (): void {
    Functions\expect('register_setting')
        ->once()
        ->with('uams_plugin_template', Settings::OPTION, Mockery::on(
            static fn (array $args): bool => $args['sanitize_callback'] === [Settings::class, 'sanitize']
        ));

    Settings::register_setting();
});
