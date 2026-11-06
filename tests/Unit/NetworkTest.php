<?php

/**
 * UAMSWeb\AuditorRole\Network, with WordPress mocked.
 *
 * @see includes/Network.php
 */

declare(strict_types=1);

use UAMSWeb\AuditorRole\Network;

it('reads logins from an array or a comma-separated string', function (mixed $value, array $logins): void {
    expect(Network::parse_logins($value))->toBe($logins);
})->with([
    'array' => [['audit-one', 'audit-two'], ['audit-one', 'audit-two']],
    'string' => ['audit-one, audit-two ,', ['audit-one', 'audit-two']],
    'duplicates' => [['a', 'a', ' a '], ['a']],
    'mixed types' => [['a', 5, null], ['a']],
    'nothing' => [null, []],
    'empty' => ['', []],
]);
