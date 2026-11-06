<?php

/**
 * Read a `composer mutate` log and fail unless the run proved something.
 *
 * Pest's exit status alone is not trusted: a run that created no mutants, or
 * whose mutants never ran, can still exit 0 depending on the Pest version and
 * its settings. This reads the summary Pest prints and fails on:
 *
 * - no summary at all (the run did not reach mutation testing),
 * - `0 Mutations for 0 Files created` (a setup failure: --path, driver, or
 *   no covering test), unless --allow-zero is passed,
 * - any untested (survived), uncovered, pending or timed-out mutant.
 *
 * Usage: php scripts/check-mutation-run.php <log> [--allow-zero]
 * Exit:  0 = every mutant was killed; 1 = it was not; 2 = the log is unreadable.
 */

declare(strict_types=1);

$file = $argv[1] ?? '';
$allow_zero = in_array('--allow-zero', $argv, true);

if ($file === '' || ! is_readable($file)) {
    fwrite(STDERR, "Usage: php scripts/check-mutation-run.php <log> [--allow-zero]\n");
    exit(2);
}

// Strip color codes, so a log captured with or without ANSI reads the same.
$log = (string) preg_replace('/\e\[[0-9;]*m/', '', (string) file_get_contents($file));

if (preg_match('/(\d+) Mutations for (\d+) Files created/', $log, $created) !== 1) {
    fwrite(STDERR, "FAIL: no mutation summary in the log; the run did not reach mutation testing.\n");
    exit(1);
}

if ((int) $created[1] === 0) {
    if ($allow_zero) {
        echo "PASS: no mutants were created, which --allow-zero accepts.\n";
        exit(0);
    }

    fwrite(STDERR, "FAIL: 0 mutants created. That is a setup failure (--path, the coverage driver, or no covering test), not a pass.\n");
    exit(1);
}

if (preg_match('/Mutations:\s*([^\n]+)/', $log, $line) !== 1) {
    fwrite(STDERR, "FAIL: mutants were created but no result line was printed.\n");
    exit(1);
}

$bad = [];

foreach (['untested', 'uncovered', 'pending', 'timeout', 'timed out'] as $kind) {
    if (preg_match('/(\d+)\s+'.$kind.'/', $line[1], $m) === 1 && (int) $m[1] > 0) {
        $bad[] = $m[1].' '.$kind;
    }
}

if (preg_match('/(\d+)\s+tested/', $line[1], $tested) !== 1 || (int) $tested[1] === 0) {
    $bad[] = 'none killed';
}

if ($bad !== []) {
    fwrite(STDERR, 'FAIL: '.$created[1].' mutants, '.implode(', ', $bad).". Kill each with a test, delete dead code, or annotate a provably equivalent one with @pest-mutate-ignore and the reason.\n");
    exit(1);
}

echo 'PASS: '.$created[1].' mutants created in '.$created[2]." file(s), every one killed.\n";
