<?php

/**
 * UAMSWeb\AuditorRole\Role, with WordPress mocked.
 *
 * @see includes/Role.php
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\Role;

it('grants exactly the listed capabilities by default', function (): void {
    expect(Role::capabilities())->toBe(Role::GRANTED);
});

it('grants none of the never-granted capabilities', function (): void {
    expect(array_intersect(Role::capabilities(), Role::NEVER_GRANTED))->toBe([]);
});

it('adds a capability through the filter', function (): void {
    Filters\expectApplied(Role::FILTER)->once()->andReturn(array_merge(Role::GRANTED, ['view_reports']));

    expect(Role::capabilities())->toContain('view_reports');
});

it('never lets the filter add a never-granted capability', function (string $capability): void {
    Filters\expectApplied(Role::FILTER)->once()->andReturn([$capability, 'view_reports']);

    expect(Role::capabilities())
        ->not->toContain($capability)
        ->toContain('view_reports')
        ->toContain('read');
})->with(['promote_users', 'edit_plugins', 'unfiltered_html', 'install_anything', 'update_foo', 'delete_bar', 'upload_plugins']);

it('ignores a filter that returns something other than a list of names', function (): void {
    Filters\expectApplied(Role::FILTER)->once()->andReturn('read');

    expect(Role::capabilities())->toBe(Role::GRANTED);
});

it('changes the stamp when the capability list changes', function (): void {
    Functions\when('wp_json_encode')->alias('json_encode');

    $before = Role::stamp();
    Filters\expectApplied(Role::FILTER)->andReturn(['view_reports']);

    expect(Role::stamp())->not->toBe($before)->toStartWith(Role::VERSION.':');
});

it('rewrites the role when the stamp is stale, without duplicating it', function (): void {
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\when('get_option')->justReturn('0:old');
    Functions\when('__')->returnArg();
    Functions\expect('remove_role')->once()->with(Role::NAME);
    Functions\expect('add_role')->once()->with(Role::NAME, 'Auditor', Mockery::on(
        static fn (array $caps): bool => array_keys($caps) === Role::GRANTED
    ));
    Functions\expect('update_option')->once()->with(Role::OPTION, Mockery::type('string'), true);

    Role::maybe_register();
});

it('leaves the role alone when the stamp is current', function (): void {
    Functions\when('wp_json_encode')->alias('json_encode');
    Functions\when('get_option')->justReturn(Role::stamp());
    Functions\when('get_role')->justReturn(new stdClass);
    Functions\expect('add_role')->never();

    Role::maybe_register();
});

it('recognizes an Auditor by role', function (): void {
    expect(Role::user_is_auditor(uamswp_auditor_role_user_with(1, 'a', ['auditor'])))->toBeTrue()
        ->and(Role::user_is_auditor(uamswp_auditor_role_user_with(1, 'a', ['administrator'])))->toBeFalse()
        ->and(Role::user_is_auditor(false))->toBeFalse();
});
