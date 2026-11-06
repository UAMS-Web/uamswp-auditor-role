<?php

/**
 * A router for PHP's built-in server that serves a subdirectory multisite the
 * way WordPress's own rewrite rules do: `/<site>/wp-admin/...` and
 * `/<site>/wp-<name>.php` reach the shared core files, and anything else that
 * is not a file reaches index.php. Used by CI's screen walk:
 *
 *   php -S 127.0.0.1:80 -t <wordpress> tests/Walk/router.php
 */

declare(strict_types=1);

$root = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/');
$request = '/'.ltrim((string) parse_url(urldecode((string) $_SERVER['REQUEST_URI']), PHP_URL_PATH), '/');
$path = $request;

if (! file_exists($root.$path) && preg_match('#^/[\w\-]+(/wp-(?:admin|includes|content)(?:/.*)?|/wp-[a-z\-]+\.php)$#', $path, $match) === 1) {
    $path = $match[1];
}

if (is_dir($root.$path)) {
    if (! str_ends_with($path, '/')) {
        header('Location: '.$request.'/');

        return true;
    }

    $path .= 'index.php';
    $request .= str_ends_with($request, '/') ? 'index.php' : '/index.php';
}

$file = $root.$path;

if (! is_file($file)) {
    $file = $root.'/index.php';
    $request = '/index.php';
}

if (! str_ends_with($file, '.php')) {
    if ($path === $request) {
        return false;
    }

    readfile($file);

    return true;
}

// WordPress reads the requested script from these, as it would behind a web server.
$_SERVER['SCRIPT_NAME'] = $request;
$_SERVER['PHP_SELF'] = $request;
$_SERVER['SCRIPT_FILENAME'] = $file;

chdir(dirname($file));

require $file;
