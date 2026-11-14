<?php

/**
 * The screen walk (#19): sign in as an Auditor, visit every admin menu slug and
 * hidden script screen of every site and every admin-ajax action once, and fail on any database write
 * other than the plugin's own activity log.
 *
 * Writes are read from MySQL's binary log, outside WordPress, so a write that
 * slips past the plugin's guard (a plugin's own connection, a request a page
 * sets off) still shows. Disable WP-Cron on the install while it runs: a cron
 * run started by a visit writes as an anonymous request and would read as that
 * visit's write.
 *
 *   php tests/Walk/walk.php --wp-root=<path> --admin=<super admin login> \
 *     --auditor=<login> --password-env=WALK_PASSWORD [--login-path=/wp-login.php] \
 *     --db-host=127.0.0.1 --db-user=root [--db-password-env=WALK_DB_PASSWORD] --db-name=<name> \
 *     [--report=walk.md] [--settle-ms=200] [--limit=<n>] [--insecure] [--wp=wp]
 *
 * Exit: 0 passed, 1 an unexpected write (or nothing visited), 2 the walk could not run.
 */

declare(strict_types=1);

use UAMSWeb\AuditorRole\Walk\BinlogWindow;
use UAMSWeb\AuditorRole\Walk\Command;
use UAMSWeb\AuditorRole\Walk\Report;
use UAMSWeb\AuditorRole\Walk\Targets;
use UAMSWeb\AuditorRole\Walk\Walker;

require __DIR__.'/../../vendor/autoload.php';

$options = getopt('', ['wp-root:', 'admin:', 'auditor:', 'password-env:', 'login-path::', 'db-host:', 'db-user:', 'db-password-env::', 'db-name:', 'report::', 'settle-ms::', 'limit::', 'insecure', 'wp::']);

$need = static function (string $key) use ($options): string {
    $value = $options[$key] ?? null;

    if (! is_string($value) || $value === '') {
        fwrite(STDERR, "Missing --{$key}.\n");
        exit(2);
    }

    return $value;
};

$wp = is_string($options['wp'] ?? null) ? $options['wp'] : 'wp';
$root = $need('wp-root');
$password = (string) getenv($need('password-env'));
$db_password = is_string($options['db-password-env'] ?? null) ? (string) getenv($options['db-password-env']) : '';
$settle = max(0, (int) ($options['settle-ms'] ?? 200));
$insecure = isset($options['insecure']);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($need('db-host'), $need('db-user'), $db_password);

$query = static function (string $sql) use ($db): array {
    try {
        $result = $db->query($sql);
    } catch (mysqli_sql_exception $e) {
        throw new RuntimeException($e->getMessage(), 0, $e);
    }

    $rows = [];

    foreach ($result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [] as $row) {
        $keyed = [];

        foreach ($row as $column => $value) {
            $keyed[(string) $column] = $value;
        }

        $rows[] = $keyed;
    }

    return $rows;
};

// Prove the instrument sees a write before trusting an empty window.
$probe = new BinlogWindow($query, 'uamswp_walk_probe');

try {
    $probe->verify_settings();
    $query('CREATE DATABASE IF NOT EXISTS uamswp_walk_probe');
    $query('CREATE TABLE IF NOT EXISTS uamswp_walk_probe.probe (id int AUTO_INCREMENT PRIMARY KEY, at datetime)');
    $since = $probe->position();
    $query('INSERT INTO uamswp_walk_probe.probe (at) VALUES (NOW())');

    if ($probe->writes_since($since) === []) {
        throw new RuntimeException('A probe write did not show in the binary log, so the instrument cannot see writes.');
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Instrument check failed: '.$e->getMessage()."\n");
    exit(2);
}

fwrite(STDERR, "Instrument check passed: the binary log shows a probe write.\n");

// Inventory every site, as a super admin in an admin context.
$run = static function (string ...$command) use ($root): string {
    $result = Command::run(array_values($command), $root);

    if ($result === null) {
        fwrite(STDERR, 'Could not run: '.implode(' ', $command)."\n");
        exit(2);
    }

    return $result['stdout'];
};

$sites = array_values(array_filter(array_map(trim(...), explode("\n", $run($wp, 'site', 'list', '--field=url')))));

if ($sites === []) {
    $sites = [trim($run($wp, 'option', 'get', 'home')).'/'];
}

$inventories = [];

foreach ($sites as $site) {
    $json = $run($wp, '--exec=define("WP_ADMIN", true);', 'eval-file', __DIR__.'/enumerate.php', '--use-include', '--user='.$need('admin'), '--url='.$site);
    $inventory = json_decode(trim($json), true);

    if (! is_array($inventory)) {
        fwrite(STDERR, "Could not inventory {$site}.\n");
        exit(2);
    }

    $inventories[] = $inventory;
}

$targets = Targets::from_inventories($inventories);

if (isset($options['limit'])) {
    $targets = array_slice($targets, 0, max(1, (int) $options['limit']));
}

fwrite(STDERR, sprintf("Inventoried %d site(s): %d target(s).\n", count($sites), count($targets)));

// Sign in once; the logged-in cookie covers every site of a subdirectory network.
$jar = tempnam(sys_get_temp_dir(), 'uamswp-walk-');
$inventoryUrl = $inventories[0]['url'] ?? '';
$home = rtrim(is_string($inventoryUrl) ? $inventoryUrl : '', '/');

$curl = static function (string $url, string $method, array $body) use ($jar, $insecure): array {
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => ! $insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
        CURLOPT_COOKIE => 'wordpress_test_cookie=WP%20Cookie%20check',
    ]);

    if ($method === 'POST') {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($body));
    }

    $response = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $location = curl_getinfo($handle, CURLINFO_REDIRECT_URL);
    curl_close($handle);

    return ['status' => $status, 'body' => is_string($response) ? $response : '', 'location' => is_string($location) ? $location : ''];
};

$login_path = is_string($options['login-path'] ?? null) ? $options['login-path'] : '/wp-login.php';
$curl($home.$login_path, 'POST', ['log' => $need('auditor'), 'pwd' => $password, 'testcookie' => '1', 'wp-submit' => 'Log In']);

if (! str_contains((string) file_get_contents((string) $jar), 'wordpress_logged_in_')) {
    fwrite(STDERR, "Sign-in as the Auditor failed: no logged-in cookie.\n");
    exit(2);
}

$site_window = new BinlogWindow($query, $need('db-name'));

$walker = new Walker(
    $site_window,
    static fn (array $target): array => $curl($target['url'], $target['method'], $target['body']),
    static function () use ($settle): void {
        usleep($settle * 1000);
    },
);

$results = $walker->walk($targets, static function (int $done, int $total, array $result): void {
    if ($result['unexpected'] !== [] || $done % 100 === 0 || $done === $total) {
        fwrite(STDERR, sprintf("[%d/%d] %s %s\n", $done, $total, $result['unexpected'] === [] ? 'ok' : 'WRITE', $result['target']['label']));
    }
});

$markdown = Report::markdown($results);
$report = $options['report'] ?? null;

// The file also lists every target; the console keeps the summary only.
if (is_string($report) && $report !== '') {
    file_put_contents($report, $markdown."\n".Report::targets($results));
}

echo $markdown;
unlink((string) $jar);

exit(Report::passed($results) ? 0 : 1);
