<?php

/**
 * A PSR-4 autoloader for the plugin's own classes.
 *
 * Sites deploy this plugin from a branch with no build step, so nothing here
 * depends on Composer's `vendor/` directory. A plugin that ships a runtime
 * library commits it and requires its autoloader from here.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

spl_autoload_register(static function (string $class): void {
    $prefix = 'UAMSWeb\\PluginTemplate\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_readable($file)) {
        require_once $file;
    }
});
