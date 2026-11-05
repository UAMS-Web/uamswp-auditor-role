<?php

/**
 * The discovery report from files alone, for an install whose bootstrap fails
 * under WP-CLI. Reads plugin and theme headers, the core version and the
 * multisite constants. Never loads WordPress, never connects to the database,
 * and never prints a value from wp-config.php other than those constants.
 *
 * Usage: php scripts/discover-files.php <path-to-wordpress> > discovery.json
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = rtrim($argv[1] ?? '', '/');

if ($root === '' || ! is_file($root.'/wp-includes/version.php')) {
    fwrite(STDERR, "Usage: php scripts/discover-files.php <path-to-wordpress>\n");
    exit(2);
}

/**
 * The header fields of a plugin or theme file, read like get_file_data().
 *
 * @param  array<string, string>  $fields
 * @return array<string, string>
 */
function uamswp_auditor_role_headers(string $file, array $fields): array
{
    $source = (string) file_get_contents($file, false, null, 0, 8192);
    $found = [];

    foreach ($fields as $key => $label) {
        $found[$key] = preg_match('/^[ \t\/*#@]*'.preg_quote($label, '/').':(.*)$/mi', $source, $m) === 1 ? trim($m[1]) : '';
    }

    return $found;
}

$version = (string) file_get_contents($root.'/wp-includes/version.php');
preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $version, $wp);

$config = is_file($root.'/wp-config.php') ? (string) file_get_contents($root.'/wp-config.php') : '';
$constant = (static fn (string $name): ?string => preg_match('/define\(\s*[\'"]'.$name.'[\'"]\s*,\s*([^)]+)\)/', $config, $m) === 1 ? trim($m[1], " '\"") : null);

$content = $root.'/wp-content';
$plugins = [];

foreach (glob($content.'/plugins/*', GLOB_ONLYDIR) ?: [] as $dir) {
    foreach (glob($dir.'/*.php') ?: [] as $file) {
        $headers = uamswp_auditor_role_headers($file, ['name' => 'Plugin Name', 'version' => 'Version']);

        if ($headers['name'] !== '') {
            $plugins[basename($dir).'/'.basename($file)] = $headers['version'];
        }
    }
}

$mu = [];

foreach (glob($content.'/mu-plugins/*.php') ?: [] as $file) {
    $mu[] = basename($file);
}

$themes = [];

foreach (glob($content.'/themes/*/style.css') ?: [] as $file) {
    $headers = uamswp_auditor_role_headers($file, ['name' => 'Theme Name', 'version' => 'Version', 'template' => 'Template']);
    $themes[basename(dirname($file))] = $headers;
}

ksort($plugins);
ksort($themes);

echo json_encode([
    'generated_at' => gmdate('c'),
    'source' => 'files',
    'wordpress' => $wp[1] ?? '',
    'multisite' => $constant('MULTISITE'),
    'subdomain_install' => $constant('SUBDOMAIN_INSTALL'),
    'installed_plugins' => $plugins,
    'must_use_plugins' => $mu,
    'dropins' => array_values(array_map(basename(...), array_filter([$content.'/object-cache.php', $content.'/advanced-cache.php', $content.'/db.php', $content.'/sunrise.php'], is_file(...)))),
    'themes' => $themes,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
