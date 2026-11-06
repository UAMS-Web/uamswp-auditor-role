<?php

/**
 * UAMSWeb\AuditorRole\Session, with WordPress mocked.
 *
 * Mutation-clean under `composer mutate -- --path=includes/Session.php`, with
 * no equivalent mutants. The exempt list is pinned against the class's
 * documented actions, written out here.
 *
 * @see includes/Session.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Session;

/**
 * did_action() answering per hook: whether WordPress has set the current user,
 * and whether the pluggable functions are loaded.
 */
function uamswp_auditor_role_g2_actions(bool $user_set, bool $pluggable = true): void
{
    Functions\when('did_action')->alias(static fn (string $hook): int => match ($hook) {
        'set_current_user' => $user_set ? 1 : 0,
        'plugins_loaded' => $pluggable ? 1 : 0,
        default => 0,
    });
}

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    unset($_SERVER['SCRIPT_NAME']);
    Functions\when('wp_unslash')->returnArg();
    Functions\when('sanitize_key')->alias('strtolower');
    Functions\when('sanitize_text_field')->returnArg();
});

it('lists exactly the exempt sign-in actions', function (): void {
    expect(Session::exempt_login_actions())->toBe(['login', 'lostpassword', 'retrievepassword', 'rp', 'resetpass', 'postpass', 'switch_to_olduser']);
});

it('follows the current user once WordPress has set it', function (): void {
    uamswp_auditor_role_as(true);
    expect(Session::is_auditor())->toBeTrue();

    uamswp_auditor_role_as(false);
    expect(Session::is_auditor())->toBeFalse();
});

it('reads the auth cookie first, then the logged-in cookie, and holds the answer', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false);
    Functions\expect('wp_validate_auth_cookie')->once()->with()->ordered()->andReturn(false);
    Functions\expect('wp_validate_auth_cookie')->once()->with('', 'logged_in')->ordered()->andReturn(7);
    Functions\expect('get_userdata')->once()->with(7)->andReturn(uamswp_auditor_role_user_with(7, 'audit', ['auditor']));

    expect(Session::is_auditor())->toBeTrue()
        ->and(Session::is_auditor())->toBeTrue();
});

it('takes the auth cookie alone when it is valid', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false);
    // Once for is_auditor(), which holds its answer, and once for user_id(), which does not.
    Functions\expect('wp_validate_auth_cookie')->twice()->with()->andReturn(5);
    Functions\when('get_userdata')->justReturn(uamswp_auditor_role_user_with(5, 'admin', ['administrator']));

    expect(Session::is_auditor())->toBeFalse()
        ->and(Session::user_id())->toBe(5);
});

it('is no session without a valid cookie, and never looks the user up', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false);
    Functions\when('wp_validate_auth_cookie')->justReturn(false);
    Functions\expect('get_userdata')->never();

    expect(Session::is_auditor())->toBeFalse()
        ->and(Session::user_id())->toBe(0);
});

it('reads no cookie before the pluggable functions load', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false, false);
    Functions\expect('wp_validate_auth_cookie')->never();

    expect(Session::user_id())->toBe(0)
        ->and(Session::is_auditor())->toBeFalse();
});

it('treats a cookie answer that is not an ID as none', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false);
    Functions\when('wp_validate_auth_cookie')->justReturn('7');

    expect(Session::user_id())->toBe(0);
});

it('answers no while the cookie is read and while its user is looked up, and yes after', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false);
    $during = [];
    // An object, so the count changed inside the closure is read back as such.
    $depth = new ArrayObject(['now' => 0]);

    // A re-entry fails at once, so a mutant that drops the guard is killed by
    // this assertion instead of recursing until the mutation run times out.
    $ask = static function () use (&$during, $depth): void {
        if ($depth['now'] > 0) {
            throw new RuntimeException('is_auditor() re-entered the cookie read');
        }

        $depth['now']++;

        try {
            $during[] = Session::is_auditor();
        } finally {
            $depth['now']--;
        }
    };

    Functions\when('wp_validate_auth_cookie')->alias(static function () use ($ask): int {
        $ask();

        return 7;
    });
    Functions\when('get_userdata')->alias(static function () use ($ask): WP_User {
        $ask();

        return uamswp_auditor_role_user_with(7, 'audit', ['auditor']);
    });

    expect(Session::is_auditor())->toBeTrue()
        ->and($during)->toBe([false, false]);
});

it('clears the flag after a cookie read on its own', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false);
    Functions\when('wp_validate_auth_cookie')->justReturn(7);
    Functions\when('get_userdata')->justReturn(uamswp_auditor_role_user_with(7, 'audit', ['auditor']));

    expect(Session::user_id())->toBe(7)
        ->and(Session::is_auditor())->toBeTrue();
});

it('names the current user once WordPress has set it, and the cookie before', function (): void {
    uamswp_auditor_role_g2_actions(true);
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('wp_validate_auth_cookie')->justReturn(9);

    expect(Session::user_id())->toBe(3);

    uamswp_auditor_role_g2_actions(false);

    expect(Session::user_id())->toBe(9);
});

it('names the script from $pagenow, or from SCRIPT_NAME when $pagenow is unset or empty', function (mixed $pagenow, ?string $script_name, string $script): void {
    $GLOBALS['pagenow'] = $pagenow;

    if ($script_name !== null) {
        $_SERVER['SCRIPT_NAME'] = $script_name;
    }

    expect(Session::script())->toBe($script);
})->with([
    'pagenow' => ['edit.php', '/wp-admin/upload.php', 'edit.php'],
    'empty pagenow' => ['', '/wp-admin/upload.php', 'upload.php'],
    'no pagenow' => [null, '/wp-admin/upload.php', 'upload.php'],
    'neither' => [null, null, ''],
]);

it('exempts sign-in and password-reset actions on wp-login.php', function (mixed $action, bool $exempt): void {
    uamswp_auditor_role_as(true, 'wp-login.php');

    if ($action !== null) {
        $_REQUEST['action'] = $action;
    }

    expect(Session::is_exempt_login())->toBe($exempt)
        ->and(Session::is_auditor())->toBe(! $exempt);
})->with([
    'no action' => [null, true],
    'empty action' => ['', true],
    'login' => ['login', true],
    'lostpassword' => ['lostpassword', true],
    'retrievepassword' => ['retrievepassword', true],
    'rp' => ['rp', true],
    'resetpass' => ['resetpass', true],
    'postpass' => ['postpass', true],
    'switch back' => ['switch_to_olduser', true],
    'logout' => ['logout', false],
    'confirm_admin_email' => ['confirm_admin_email', false],
    'register' => ['register', false],
]);

it('exempts nothing outside wp-login.php', function (): void {
    uamswp_auditor_role_as(true, 'admin.php');
    $_REQUEST['action'] = 'login';

    expect(Session::is_exempt_login())->toBeFalse();
});

it('takes the exempt list from the filter, and falls back when the filter returns no list', function (): void {
    uamswp_auditor_role_as(true, 'wp-login.php');
    $_REQUEST['action'] = 'custom_sso';

    Filters\expectApplied('uamswp_auditor_role_exempt_login_actions')->once()->with(Session::exempt_login_actions())->andReturn(['custom_sso']);
    expect(Session::is_exempt_login())->toBeTrue();

    $_REQUEST['action'] = 'rp';
    Filters\expectApplied('uamswp_auditor_role_exempt_login_actions')->once()->andReturn('custom_sso');
    expect(Session::is_exempt_login())->toBeTrue();
});

/*
 * Before the pluggable layer: an auth cookie that names an Auditor fails closed.
 */

/**
 * A database stand-in for the role lookup: answers get_var() from a queue and
 * records each prepared statement.
 */
final class Uamswp_Auditor_Role_G2_Wpdb
{
    public string $users = 'wp_users';

    public string $usermeta = 'wp_usermeta';

    /** @var list<string> */
    public array $prepared = [];

    /**
     * @param  array<mixed>  $answers
     */
    public function __construct(public array $answers, public ?Closure $on_query) {}

    public function prepare(string $sql, mixed ...$args): string
    {
        $sql = str_replace(['%i', '%s'], ['`%s`', "'%s'"], $sql);
        $sql = str_replace("`'%s'`", '`%s`', $sql);
        $this->prepared[] = vsprintf($sql, $args);

        return $this->prepared[count($this->prepared) - 1];
    }

    public function get_var(string $sql): mixed
    {
        if ($this->on_query instanceof Closure) {
            ($this->on_query)();
        }

        if ($this->answers === []) {
            throw new LogicException('Unqueued read: '.$sql);
        }

        return array_shift($this->answers);
    }

    public function get_blog_prefix(): string
    {
        return 'wp_2_';
    }
}

/** How deep a lookup has re-entered, for the test that guards against recursion. */
final class Uamswp_Auditor_Role_G2_Depth
{
    public static int $depth = 0;
}

/**
 * Install the stand-in as $wpdb.
 *
 * @param  array<mixed>  $answers
 */
function uamswp_auditor_role_g2_wpdb(array $answers, ?Closure $on_query = null): Uamswp_Auditor_Role_G2_Wpdb
{
    $wpdb = new Uamswp_Auditor_Role_G2_Wpdb($answers, $on_query);
    $GLOBALS['wpdb'] = $wpdb;

    return $wpdb;
}

afterEach(function (): void {
    unset($GLOBALS['wpdb']);
    $_COOKIE = [];
});

it('reads the logins auth cookies name, by constant or by the default name', function (): void {
    $hash = str_repeat('a1', 16);

    expect(Session::cookie_logins([
        'other' => 'other|1|t|h',
        'wordpress_'.str_repeat('d', 32) => ['not', 'a', 'string'],
        'custom_auth' => 'audit|1|t|h',
        'wordpress_logged_in_'.$hash => 'second|1|t|h',
        'wordpress_sec_'.$hash => 'third|1|t|h',
        'wordpress_'.$hash => 'audit|1|t|h',
        'wordpress_test_cookie' => 'WP Cookie check',
        'wordpress_logged_in_short' => 'short|1|t|h',
        'wordpress_logged_in_'.$hash.'x' => 'long|1|t|h',
        'wp-settings-7' => 'a|b|c|d',
        'wordpress_'.strtoupper($hash) => 'upper|1|t|h',
        8 => 'numeric|1|t|h',
    ], ['custom_auth']))->toBe(['audit', 'second', 'third']);
});

it('skips a cookie value that is not an auth cookie', function (mixed $value): void {
    expect(Session::cookie_logins(['wordpress_'.str_repeat('0', 32) => $value], []))->toBe([]);
})->with([
    'three parts' => ['audit|1|t'],
    'five parts' => ['audit|1|t|h|x'],
    'empty login' => ['|1|t|h'],
    'not a string' => [['audit', '1', 't', 'h']],
]);

it('reads a numeric cookie key as its name', function (): void {
    expect(Session::cookie_logins([7 => 'audit|1|t|h'], ['7']))->toBe(['audit']);
});

it('takes the cookie names from the three auth constants only', function (): void {
    expect(Session::cookie_names([
        'LOGGED_IN_COOKIE' => 'li',
        'AUTH_COOKIE' => 'a',
        'SECURE_AUTH_COOKIE' => 'sa',
        'COOKIEHASH' => 'h',
    ]))->toBe(['a', 'sa', 'li'])
        ->and(Session::cookie_names(['AUTH_COOKIE' => 5, 'LOGGED_IN_COOKIE' => 'li']))->toBe(['li'])
        ->and(Session::cookie_names([]))->toBe([]);
});

it('reads the role from the current site\'s capabilities, in one read joined by login', function (): void {
    $wpdb = uamswp_auditor_role_g2_wpdb([serialize(['auditor' => true])]);

    expect(Session::login_is_auditor('audit'))->toBeTrue()
        ->and($wpdb->prepared)->toBe([
            "SELECT m.meta_value FROM `wp_users` AS u INNER JOIN `wp_usermeta` AS m ON m.user_id = u.ID WHERE u.user_login = 'audit' AND m.meta_key = 'wp_2_capabilities'",
        ]);
});

/** Records that an object was instantiated from stored capabilities. */
final class Uamswp_Auditor_Role_G2_Wakeup
{
    public static bool $woken = false;

    public function __wakeup(): void
    {
        self::$woken = true;
    }
}

it('never instantiates an object stored in the capabilities', function (): void {
    Uamswp_Auditor_Role_G2_Wakeup::$woken = false;
    uamswp_auditor_role_g2_wpdb([serialize(['auditor' => true, 'x' => new Uamswp_Auditor_Role_G2_Wakeup])]);

    expect(Session::login_is_auditor('audit'))->toBeTrue()
        ->and(Uamswp_Auditor_Role_G2_Wakeup::$woken)->toBeFalse();
});

it('is no Auditor for an unknown login, other roles, or capabilities it cannot read', function (array $answers): void {
    uamswp_auditor_role_g2_wpdb($answers);

    expect(Session::login_is_auditor('audit'))->toBeFalse();
})->with([
    'unknown login, or no capabilities on this site' => [[null]],
    'another role' => [[serialize(['editor' => true])]],
    'role switched off' => [[serialize(['auditor' => false])]],
    'not a list' => [[serialize('auditor')]],
    'an object' => [[serialize(new ArrayObject(['auditor' => true]))]],
]);

it('fails closed before the pluggable layer when a cookie names an Auditor, and holds the answer', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false, false);
    Functions\expect('wp_validate_auth_cookie')->never();
    $_COOKIE = ['wordpress_logged_in_'.str_repeat('b', 32) => 'other|1|t|h', 'wordpress_'.str_repeat('c', 32) => 'audit|1|t|h'];
    $wpdb = uamswp_auditor_role_g2_wpdb([serialize(['editor' => true]), serialize(['auditor' => true])]);

    expect(Session::is_auditor())->toBeTrue()
        ->and(Session::is_auditor())->toBeTrue()
        ->and($wpdb->prepared)->toHaveCount(2);
});

it('is no session before the pluggable layer when no cookie names an Auditor', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false, false);
    $_COOKIE = ['wordpress_'.str_repeat('c', 32) => 'editor|1|t|h'];
    uamswp_auditor_role_g2_wpdb([serialize(['editor' => true])]);

    expect(Session::is_auditor())->toBeFalse();
});

it('never lets the before-pluggable answer stand for the validated one', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false, false);
    $_COOKIE = ['wordpress_'.str_repeat('c', 32) => 'audit|1|t|h'];
    uamswp_auditor_role_g2_wpdb([serialize(['auditor' => true])]);

    expect(Session::is_auditor())->toBeTrue();

    // The cookie did not validate: once the pluggable layer is in, that decides.
    uamswp_auditor_role_g2_actions(false, true);
    Functions\expect('wp_validate_auth_cookie')->twice()->andReturn(false);

    expect(Session::is_auditor())->toBeFalse();
});

it('answers no while it looks the cookie\'s login up, and clears the flag after', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false, false);
    $_COOKIE = ['wordpress_'.str_repeat('c', 32) => 'audit|1|t|h'];
    $inner = [];
    Uamswp_Auditor_Role_G2_Depth::$depth = 0;
    uamswp_auditor_role_g2_wpdb([serialize(['auditor' => true])], static function () use (&$inner): void {
        // A mutant that drops the flag would recurse; fail on the first re-entry instead.
        if (Uamswp_Auditor_Role_G2_Depth::$depth > 0) {
            throw new LogicException('Re-entered the lookup.');
        }

        Uamswp_Auditor_Role_G2_Depth::$depth++;
        $inner[] = Session::is_auditor();
        Uamswp_Auditor_Role_G2_Depth::$depth--;
    });

    expect(Session::is_auditor())->toBeTrue()
        ->and($inner)->toBe([false]);

    Session::reset();
    uamswp_auditor_role_g2_wpdb([]);
    $_COOKIE = [];

    expect(Session::is_auditor())->toBeFalse();
});

it('clears the flag when the lookup fails', function (): void {
    $GLOBALS['pagenow'] = 'admin.php';
    uamswp_auditor_role_g2_actions(false, false);
    $_COOKIE = ['wordpress_'.str_repeat('c', 32) => 'audit|1|t|h'];
    uamswp_auditor_role_g2_wpdb([]);

    expect(fn (): bool => Session::is_auditor())->toThrow(LogicException::class);

    uamswp_auditor_role_as(true);

    expect(Session::is_auditor())->toBeTrue();
});
