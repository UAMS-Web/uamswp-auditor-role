<?php

/**
 * A PSR-4 autoloader for the plugin's own classes.
 *
 * Sites deploy this plugin from a branch with no build step, so nothing here
 * depends on Composer's `vendor/` directory. A plugin that ships a runtime
 * library commits it and requires its autoloader from here.
 */

declare(strict_types=1);

/*
 * No direct-access guard: the file only registers an autoloader, so a direct
 * request to it prints nothing and loads nothing. A guard here would run while
 * the test bootstrap loads the file, before any test, where no test can cover it.
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'UAMSWeb\\AuditorRole\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_readable($file)) {
        require_once $file;
    }
});
