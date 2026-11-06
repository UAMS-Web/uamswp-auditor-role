<?php

/**
 * UAMSWeb\AuditorRole\Network, with WordPress mocked.
 *
 * The configured logins come from the `uamswp_auditor_role_logins` filter in
 * every test, never from the constant, which no test defines: once defined, a
 * constant cannot be undefined for the tests that follow. Mutation-clean under
 * `composer mutate -- --path=includes/Network.php`, with no equivalent mutants.
 *
 * @see includes/Network.php
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\Log;
use UAMSWeb\AuditorRole\Network;
use UAMSWeb\AuditorRole\Role;

require_once __DIR__.'/../Support/wordpress-site.php';

beforeEach(function (): void {
    uamswp_auditor_role_reset();
});

/**
 * Configure the logins through the filter.
 *
 * @param  array<int, mixed>|string  $logins
 */
function uamswp_auditor_role_g4_logins(array|string $logins): void
{
    Filters\expectApplied('uamswp_auditor_role_logins')->andReturn($logins);
}

/**
 * A current user with exists() and for_site(), which the stand-in lacks.
 * for_site() checks the site it is given and counts its calls.
 */
final class UamswpAuditorRoleG4CurrentUser extends WP_User
{
    public bool $signed_in = false;

    public int $site_calls = 0;

    public function exists(): bool
    {
        return $this->signed_in;
    }

    /**
     * @param  int  $site_id
     */
    public function for_site($site_id = 0): void
    {
        expect($site_id)->toBe(5);
        $this->site_calls++;
    }
}

function uamswp_auditor_role_g4_current_user(bool $exists, string $login): UamswpAuditorRoleG4CurrentUser
{
    $user = new UamswpAuditorRoleG4CurrentUser;
    $user->ID = 9;
    $user->user_login = $login;
    $user->roles = ['auditor'];
    $user->signed_in = $exists;

    return $user;
}

/**
 * A WP_Site with an ID, as core stores it: a string. Built without the
 * constructor, which the stand-in and core's class do not share.
 */
function uamswp_auditor_role_g4_site(string $id): WP_Site
{
    $site = (new ReflectionClass(WP_Site::class))->newInstanceWithoutConstructor();
    $site->blog_id = $id;

    return $site;
}

/**
 * Let Role::maybe_register() and Log::maybe_install() find their stamps current.
 */
function uamswp_auditor_role_g4_current_stamps(): void
{
    Functions\when('get_option')->alias(static fn (string $name): mixed => match ($name) {
        Role::OPTION => Role::stamp(),
        Log::OPTION => Log::SCHEMA,
        default => false,
    });
    Functions\when('get_role')->justReturn(new stdClass);
}

it('hooks sign-in, visits and new sites on a network', function (): void {
    Functions\when('is_multisite')->justReturn(true);
    Actions\expectAdded('wp_login')->once()->with([Network::class, 'on_login'], 20, 2);
    Actions\expectAdded('init')->once()->with([Network::class, 'on_visit'], 2);
    Actions\expectAdded('wp_initialize_site')->once()->with([Network::class, 'on_new_site'], 100);

    Network::boot();
});

it('hooks nothing on a single site', function (): void {
    Functions\when('is_multisite')->justReturn(false);
    Actions\expectAdded('wp_login')->never();
    Actions\expectAdded('init')->never();
    Actions\expectAdded('wp_initialize_site')->never();

    Network::boot();
});

it('reads no logins when the constant is not defined', function (): void {
    expect(defined(Network::CONSTANT))->toBeFalse();
    Filters\expectApplied('uamswp_auditor_role_logins')->once()->with([])->andReturnFirstArg();

    expect(Network::logins())->toBe([]);
});

it('reads the logins through the filter', function (): void {
    uamswp_auditor_role_g4_logins('audit-one, audit-two');

    expect(Network::logins())->toBe(['audit-one', 'audit-two']);
});

it('reads logins from an array or a comma-separated string', function (mixed $value, array $logins): void {
    expect(Network::parse_logins($value))->toBe($logins);
})->with([
    'array' => [['audit-one', 'audit-two'], ['audit-one', 'audit-two']],
    'string' => ['audit-one, audit-two ,', ['audit-one', 'audit-two']],
    'duplicates, trimmed' => [['a', ' a ', 'b', 'a'], ['a', 'b']],
    'mixed types' => [['a', 5, null, ' '], ['a']],
    'nothing' => [null, []],
    'empty' => ['', []],
]);

it('recognizes only a WP_User whose login is configured', function (string $kind, bool $configured): void {
    uamswp_auditor_role_g4_logins(['audit-one']);

    $user = match ($kind) {
        'configured' => uamswp_auditor_role_user_with(1, 'audit-one', []),
        'other' => uamswp_auditor_role_user_with(1, 'editor', []),
        'object' => (object) ['user_login' => 'audit-one'],
        default => null,
    };

    expect(Network::is_configured($user))->toBe($configured);
})->with([
    'configured' => ['configured', true],
    'not configured' => ['other', false],
    'not a user, with a configured login' => ['object', false],
    'nothing' => ['none', false],
]);

it('adds a user to a site as an Auditor, switched to that site, inside the guard bypass', function (bool $succeeds, bool $added): void {
    $result = $succeeds ? true : new WP_Error('user_does_not_exist');
    uamswp_auditor_role_g4_current_stamps();
    uamswp_auditor_role_as(true);
    Functions\expect('is_user_member_of_blog')->once()->with(9, 5)->andReturn(false);
    Functions\expect('switch_to_blog')->once()->with(5)->ordered();
    Functions\expect('add_user_to_blog')->once()->with(5, 9, Role::NAME)->ordered()->andReturnUsing(static function () use ($result): mixed {
        // The plugin's own write passes the database guard.
        expect(DatabaseGuard::filter('INSERT INTO wp_usermeta VALUES (1)'))->toBe('INSERT INTO wp_usermeta VALUES (1)');

        return $result;
    });
    Functions\expect('restore_current_blog')->once()->ordered();

    expect(Network::add_to_site(5, 9))->toBe($added)
        ->and(DatabaseGuard::filter('INSERT INTO wp_usermeta VALUES (1)'))->toBe('');
})->with([
    'added' => [true, true],
    'refused' => [false, false],
]);

it('registers the role on the site before adding the user', function (): void {
    Functions\when('get_option')->justReturn('0:stale');
    Functions\when('get_role')->justReturn();
    Functions\when('__')->returnArg();
    Functions\when('remove_role')->justReturn();
    Functions\when('update_option')->justReturn(true);
    Functions\when('is_user_member_of_blog')->justReturn(false);
    Functions\when('switch_to_blog')->justReturn(true);
    Functions\when('restore_current_blog')->justReturn(true);
    Functions\expect('add_role')->once()->ordered();
    Functions\expect('add_user_to_blog')->once()->ordered()->andReturn(true);

    Network::add_to_site(5, 9);
});

it('restores the site even when adding the user fails', function (): void {
    uamswp_auditor_role_g4_current_stamps();
    Functions\when('is_user_member_of_blog')->justReturn(false);
    Functions\when('switch_to_blog')->justReturn(true);
    Functions\when('add_user_to_blog')->alias(static function (): never {
        throw new RuntimeException('database gone');
    });
    Functions\expect('restore_current_blog')->once();

    expect(fn (): bool => Network::add_to_site(5, 9))->toThrow(RuntimeException::class);
});

it('leaves a member of the site alone', function (): void {
    Functions\expect('is_user_member_of_blog')->once()->with(9, 5)->andReturn(true);
    Functions\expect('switch_to_blog')->never();
    Functions\expect('add_user_to_blog')->never();

    expect(Network::add_to_site(5, 9))->toBeFalse();
});

it('adds a configured login to the site it signs in on, and no other login', function (): void {
    uamswp_auditor_role_g4_logins(['audit-one']);
    uamswp_auditor_role_g4_current_stamps();
    Functions\when('get_current_blog_id')->justReturn(5);
    Functions\when('switch_to_blog')->justReturn(true);
    Functions\when('restore_current_blog')->justReturn(true);
    Functions\when('is_user_member_of_blog')->justReturn(false);
    Functions\expect('add_user_to_blog')->once()->with(5, 9, Role::NAME)->andReturn(true);

    Network::on_login('audit-one', uamswp_auditor_role_user_with(9, 'audit-one', []));
    Network::on_login('editor', uamswp_auditor_role_user_with(10, 'editor', []));
});

it('adds a configured login on a visit, then rereads its roles and rechecks the request', function (): void {
    $GLOBALS['pagenow'] = 'index.php';
    uamswp_auditor_role_g4_logins(['audit-one']);
    uamswp_auditor_role_g4_current_stamps();
    $user = uamswp_auditor_role_g4_current_user(true, 'audit-one');
    Functions\when('wp_get_current_user')->justReturn($user);
    Functions\when('get_current_blog_id')->justReturn(5);
    Functions\when('switch_to_blog')->justReturn(true);
    Functions\when('restore_current_blog')->justReturn(true);
    Functions\when('is_user_member_of_blog')->justReturn(false);
    Functions\when('add_user_to_blog')->justReturn(true);
    // RequestGuard::check() asks the session first, so a recheck is seen here.
    Functions\expect('did_action')->atLeast()->once()->with('set_current_user')->andReturn(0);
    Functions\when('wp_validate_auth_cookie')->justReturn(false);

    Network::on_visit();

    expect($user->site_calls)->toBe(1);
});

it('does nothing on a visit by a user who is signed out, not configured, or already a member', function (bool $exists, string $login, bool $member): void {
    uamswp_auditor_role_g4_logins(['audit-one']);
    $user = uamswp_auditor_role_g4_current_user($exists, $login);
    Functions\when('wp_get_current_user')->justReturn($user);
    Functions\when('get_current_blog_id')->justReturn(5);
    Functions\when('is_user_member_of_blog')->justReturn($member);
    Functions\expect('add_user_to_blog')->never();
    Functions\expect('did_action')->never();

    Network::on_visit();

    expect($user->site_calls)->toBe(0);
})->with([
    'signed out' => [false, 'audit-one', false],
    'not configured' => [true, 'editor', false],
    'already a member' => [true, 'audit-one', true],
]);

it('prepares a new site and adds each configured login that exists', function (): void {
    uamswp_auditor_role_g4_logins(['audit-one', 'gone']);
    $site = uamswp_auditor_role_g4_site('6');
    Functions\expect('get_option')->atLeast()->once()->with(Role::OPTION)->andReturn(Role::stamp());
    Functions\expect('get_option')->atLeast()->once()->with(Log::OPTION)->andReturn(Log::SCHEMA);
    Functions\when('get_role')->justReturn(new stdClass);
    Functions\expect('switch_to_blog')->atLeast()->once()->with(6);
    Functions\when('restore_current_blog')->justReturn(true);
    Functions\when('get_user_by')->alias(static fn (string $field, string $login): mixed => $login === 'audit-one' ? uamswp_auditor_role_user_with(9, 'audit-one', []) : false);
    Functions\when('is_user_member_of_blog')->justReturn(false);
    Functions\expect('add_user_to_blog')->once()->with(6, 9, Role::NAME)->andReturn(true);

    Network::on_new_site($site);
});

it('switches to a new site by its integer ID to register the role and the log table there', function (): void {
    uamswp_auditor_role_g4_logins([]);
    $site = uamswp_auditor_role_g4_site('6');
    Functions\expect('switch_to_blog')->once()->with(Mockery::on(static fn (mixed $id): bool => $id === 6))->ordered();
    Functions\expect('get_option')->once()->with(Role::OPTION)->ordered()->andReturn(Role::stamp());
    Functions\when('get_role')->justReturn(new stdClass);
    Functions\expect('get_option')->once()->with(Log::OPTION)->ordered()->andReturn(Log::SCHEMA);
    Functions\expect('restore_current_blog')->once()->ordered();

    Network::on_new_site($site);
});

it('restores the site even when preparing it fails', function (): void {
    uamswp_auditor_role_g4_logins([]);
    $site = uamswp_auditor_role_g4_site('6');
    Functions\when('switch_to_blog')->justReturn(true);
    Functions\when('get_option')->alias(static function (): never {
        throw new RuntimeException('database gone');
    });
    Functions\expect('restore_current_blog')->once();

    expect(fn () => Network::on_new_site($site))->toThrow(RuntimeException::class);
});

it('ignores a new site that is not a WP_Site', function (): void {
    Functions\expect('switch_to_blog')->never();

    Network::on_new_site((object) ['blog_id' => '6']);
});

it('adds every configured login to every site and counts the sites it was added to', function (): void {
    uamswp_auditor_role_g4_logins(['audit-one', 'gone', 'audit-two']);
    uamswp_auditor_role_g4_current_stamps();
    Functions\when('is_multisite')->justReturn(true);
    Functions\when('get_sites')->justReturn([(object) ['blog_id' => '1'], (object) ['blog_id' => '2']]);
    Functions\when('get_user_by')->alias(static fn (string $field, string $login): mixed => match ($login) {
        'audit-one' => uamswp_auditor_role_user_with(9, 'audit-one', []),
        'audit-two' => uamswp_auditor_role_user_with(10, 'audit-two', []),
        default => false,
    });
    // audit-one is already a member of site 1; audit-two of neither.
    Functions\when('is_user_member_of_blog')->alias(static fn (int $user, int $blog): bool => $user === 9 && $blog === 1);
    Functions\when('switch_to_blog')->justReturn(true);
    Functions\when('restore_current_blog')->justReturn(true);
    Functions\when('add_user_to_blog')->justReturn(true);

    expect(Network::sync_all())->toBe(['audit-one' => 1, 'gone' => 0, 'audit-two' => 2]);
});
