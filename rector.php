<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\If_\UnwrapFutureCompatibleIfPhpVersionRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/includes',
        __DIR__.'/tests',
        __DIR__.'/scripts',
        __DIR__.'/uamswp-auditor-role.php',
        __DIR__.'/uninstall.php',
    ])
    ->withSkip([
        __DIR__.'/tests/Integration/vendor',
        // The floor guard in the main file is deliberate: it protects a site
        // downgraded underneath an active plugin.
        UnwrapFutureCompatibleIfPhpVersionRector::class => [__DIR__.'/uamswp-auditor-role.php'],
        // WordPress hooks take `[Class::class, 'method']` callables: remove_action()
        // finds a hook by the callable it was added with, and a first-class
        // callable is a new Closure on every call, so it could never be removed.
        ArrayToFirstClassCallableRector::class,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    // The supported floor, not the development PHP.
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPhpSets(php82: true);
