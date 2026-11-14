<?php

/**
 * Runs one WP-CLI call for the walk and returns what it printed to stdout.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Walk;

final class Command
{
    /**
     * Run a command and return its stdout and exit status, or null when it
     * could not start.
     *
     * The child's stderr goes to a scratch file, not a pipe: a pipe holds
     * only so much, and a child that writes more than that (WP-CLI's
     * deprecation notices on a newer PHP) blocks on the write while a caller
     * reading stdout to its end blocks too, so neither returns. The file is
     * deleted when the handle is released.
     *
     * @param  list<string>  $command
     * @return array{stdout: string, status: int}|null
     */
    public static function run(array $command, string $directory): ?array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => tmpfile()], $pipes, $directory);

        if (! is_resource($process)) {
            return null;
        }

        // A failed read (false) and an empty one ('') both leave an empty stdout, so no test can tell the cast from its absence.
        // @pest-mutate-ignore:RemoveStringCast
        $stdout = (string) stream_get_contents($pipes[1]);

        return ['stdout' => $stdout, 'status' => proc_close($process)];
    }
}
