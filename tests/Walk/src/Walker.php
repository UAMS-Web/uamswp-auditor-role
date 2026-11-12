<?php

/**
 * Makes each request as the Auditor inside its own binlog window, sorts what
 * the window saw into allowed and unexpected writes, and records what the
 * plugin did with the request (see Outcome).
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Walk;

final readonly class Walker
{
    /**
     * @param  \Closure(array{kind: string, label: string, url: string, method: string, body: array<string, string>}): array{status: int, body: string, location: string}  $fetch  Makes the request, returns its HTTP status, response body and redirect target (empty when none).
     * @param  \Closure(): void  $settle  Waits for writes a response leaves behind.
     * @param  list<string>|null  $allowed  Patterns for tables a request may write; null for the default.
     */
    public function __construct(
        private BinlogWindow $window,
        private \Closure $fetch,
        private \Closure $settle,
        private ?array $allowed = null,
    ) {}

    /**
     * The tables a request may write by default: the plugin's own activity log, on any site.
     *
     * @return list<string>
     */
    public static function default_allowed(): array
    {
        return ['/(^|_)uamswp_auditor_log$/'];
    }

    /**
     * Whether a table is one a request may write.
     */
    public function is_allowed(string $table): bool
    {
        foreach ($this->allowed ?? self::default_allowed() as $pattern) {
            if (preg_match($pattern, $table) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Visit each target and record what it wrote.
     *
     * @param  list<array{kind: string, label: string, url: string, method: string, body: array<string, string>}>  $targets
     * @param  (\Closure(int, int, array{target: array{kind: string, label: string, url: string, method: string, body: array<string, string>}, status: int, outcome: string, location: string, unexpected: list<array{table: string, kind: string, info: string}>}): void)|null  $progress
     * @return list<array{target: array{kind: string, label: string, url: string, method: string, body: array<string, string>}, status: int, outcome: string, location: string, unexpected: list<array{table: string, kind: string, info: string}>}>
     */
    public function walk(array $targets, ?\Closure $progress = null): array
    {
        $results = [];
        $total = count($targets);

        foreach ($targets as $index => $target) {
            $since = $this->window->position();
            $response = ($this->fetch)($target);
            ($this->settle)();

            $unexpected = [];

            foreach ($this->window->writes_since($since) as $write) {
                if (! $this->is_allowed($write['table'])) {
                    $unexpected[] = $write;
                }
            }

            $result = ['target' => $target, 'status' => $response['status'], 'outcome' => Outcome::of($response['body']), 'location' => $response['location'], 'unexpected' => $unexpected];
            $results[] = $result;

            if ($progress instanceof \Closure) {
                $progress($index + 1, $total, $result);
            }
        }

        return $results;
    }
}
