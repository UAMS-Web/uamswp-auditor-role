<?php

/**
 * Unit bootstrap.
 *
 * The Unit suite runs with no WordPress: WordPress functions are mocked per
 * test with Brain Monkey. The Integration suite does not come through here; it
 * has its own Composer setup and bootstrap under tests/Integration.
 */

declare(strict_types=1);
use Pest\Mutate\Support\StreamWrapper;

require_once __DIR__.'/../vendor/autoload.php';

/*
 * A mutation run (`composer mutate`) starts one child process per mutant, with
 * PEST_MUTATION_TESTING naming the original file and PEST_MUTATION_FILE the
 * mutated copy. Pest swaps the copy in through a `file://` stream wrapper, and
 * Patchwork, loaded next, wraps the same protocol: its directory calls then
 * reach Pest's wrapper, which does not implement them, and the child fatals
 * while PHPUnit is still finding the tests. Pest scores a failed child as a
 * killed mutant, so every mutant read as killed whatever the tests asserted.
 *
 * So in a child, Pest's wrapper is turned off before Patchwork loads, and the
 * mutated copy is loaded in place of the original by the plugin's own class
 * path, through Patchwork's wrapper like any other plugin file.
 */
$uamswp_auditor_role_original = getenv('PEST_MUTATION_TESTING');
$uamswp_auditor_role_mutant = getenv('PEST_MUTATION_FILE');
$uamswp_auditor_role_is_mutant = is_string($uamswp_auditor_role_original) && $uamswp_auditor_role_original !== ''
    && is_string($uamswp_auditor_role_mutant) && $uamswp_auditor_role_mutant !== '';

if ($uamswp_auditor_role_is_mutant && class_exists(StreamWrapper::class)) {
    StreamWrapper::disable();
}

/*
 * Patchwork first: it can only redefine functions declared in files loaded
 * after it, so loading it before the plugin is what lets a test mock one of the
 * plugin's own functions, not only WordPress'.
 */
require_once __DIR__.'/../vendor/antecedent/patchwork/Patchwork.php';

/*
 * Plugin files open with `defined('ABSPATH') || exit;`. Define it so they load,
 * pointing at a directory that is not a WordPress install: code that requires a
 * core file through ABSPATH fails loudly here, which is the sign it belongs in
 * the Integration suite.
 */
if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir().'/uamswp-auditor-role-tests-no-wordpress/');
}

/*
 * Load the autoloader, never the main plugin file: it registers hooks at file
 * scope, and Brain Monkey's add_action() and add_filter() exist only inside a
 * test. Hook registration is tested in the
 * Integration suite, or in a unit test that calls a registering function.
 * Composer-autoloaded classes need no line here.
 */
$uamswp_auditor_role_includes = (string) realpath(__DIR__.'/../includes');

if ($uamswp_auditor_role_is_mutant) {
    $uamswp_auditor_role_original = (string) realpath((string) $uamswp_auditor_role_original);

    // Prepended, so the plugin's class whose file is mutated comes from the copy.
    spl_autoload_register(static function (string $class) use ($uamswp_auditor_role_includes, $uamswp_auditor_role_original, $uamswp_auditor_role_mutant): void {
        $prefix = 'UAMSWeb\\AuditorRole\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $file = realpath($uamswp_auditor_role_includes.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php');

        if ($file === $uamswp_auditor_role_original) {
            require_once $uamswp_auditor_role_mutant;
        }
    }, true, true);
}

if ($uamswp_auditor_role_is_mutant && $uamswp_auditor_role_original === realpath($uamswp_auditor_role_includes.'/autoload.php')) {
    require_once $uamswp_auditor_role_mutant;
} else {
    require_once __DIR__.'/../includes/autoload.php';
}

require_once __DIR__.'/Support/wordpress-classes.php';

// cspell:ignore ABSPATH autoloaded
