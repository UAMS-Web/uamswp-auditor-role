<?php

/**
 * What the WP-CLI stand-in in tests/Support/wp-cli-double.php was asked to do.
 * A class of its own, because the static analyzer reads WP-CLI's real stubs
 * for `WP_CLI` and would not know a test-only method there.
 */

declare(strict_types=1);

final class UamswpAuditorRoleG4CliLog
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    /**
     * @param  list<mixed>  $args
     */
    public static function record(string $method, array $args): void
    {
        self::$calls[] = [$method, $args];
    }

    /**
     * The calls of one kind, by their arguments.
     *
     * @return list<list<mixed>>
     */
    public static function calls(string $method): array
    {
        $calls = [];

        foreach (self::$calls as [$name, $args]) {
            if ($name === $method) {
                $calls[] = $args;
            }
        }

        return $calls;
    }
}
