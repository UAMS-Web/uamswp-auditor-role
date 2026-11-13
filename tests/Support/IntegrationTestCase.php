<?php

/**
 * Base case for every Integration test.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Tests\Support;

use WP_UnitTest_Factory;
use WP_UnitTestCase;

/**
 * WordPress' test case, unchanged, on the PHPUnit 9.6 its test suite supports.
 *
 * Loaded by tests/Integration/bootstrap.php once WordPress is up; it cannot be
 * autoloaded earlier, because its parent does not exist until then.
 *
 * The Integration suite runs from its own Composer setup on PHPUnit 9.6, so it
 * needs no adapter: core's expectDeprecated() and checkRequirements() work as
 * shipped. A suite moved onto PHPUnit 10 or later would need one, and that move
 * waits on WordPress core's test suite supporting it.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase
{
    /**
     * The fixture factory, with a named return type for static analysis.
     */
    protected static function factory(): WP_UnitTest_Factory
    {
        return parent::factory();
    }

    /**
     * The value at a path of keys through nested arrays, or null where the path ends early.
     */
    protected static function value_at(mixed $value, int|string ...$path): mixed
    {
        foreach ($path as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * A value that must be an array: anything else fails the test with its type.
     *
     * @return array<array-key, mixed>
     */
    protected static function as_array(mixed $value): array
    {
        if (! is_array($value)) {
            throw new \UnexpectedValueException('Expected an array, got '.get_debug_type($value).'.');
        }

        return $value;
    }
}

// cspell:ignore autoloaded
