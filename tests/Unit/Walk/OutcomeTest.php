<?php

/**
 * UAMSWeb\AuditorRole\Walk\Outcome: a walk response sorted by what the plugin
 * did with the request, read from the text RequestGuard sends.
 *
 * @see tests/Walk/src/Outcome.php
 */

declare(strict_types=1);

use UAMSWeb\AuditorRole\Walk\Outcome;

it('sorts a response by what the plugin did with it', function (string $body, string $outcome): void {
    expect(Outcome::of($body))->toBe($outcome);
})->with([
    'a hidden screen' => ['<div class="wp-die-message">This screen is hidden from Auditor accounts because it can show secrets or personal data.</div>', 'hidden'],
    'a refused screen' => ['<div class="wp-die-message">This account is in read-only audit mode. Changes are refused and recorded.</div>', 'refused'],
    'a refused AJAX action' => ['{"success":false,"data":{"code":"uamswp_auditor_role_read_only","message":"x"}}', 'refused'],
    'core refusing a screen' => ['<div class="wp-die-message">Sorry, you are not allowed to access this page.</div>', 'none'],
    'a served screen' => ['<h1>Dashboard</h1>', 'none'],
    'an empty body' => ['', 'none'],
    'the message in another case' => ['this screen is hidden from auditor accounts', 'none'],
]);

it('reads the hidden message before the refusal text', function (): void {
    expect(Outcome::of(Outcome::REFUSED_MESSAGE.' '.Outcome::HIDDEN_MESSAGE))->toBe('hidden');
});

it('matches the text RequestGuard sends', function (string $text): void {
    expect((string) file_get_contents(dirname(__DIR__, 3).'/includes/RequestGuard.php'))->toContain($text);
})->with([
    'hidden message' => [Outcome::HIDDEN_MESSAGE],
    'refused message' => [Outcome::REFUSED_MESSAGE],
    'refused code' => [Outcome::REFUSED_CODE],
]);
