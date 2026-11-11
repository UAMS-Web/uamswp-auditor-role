<?php

/**
 * UAMSWeb\AuditorRole\AdminBar, with WordPress mocked: the badge for an Auditor
 * and for another role (#17), and the "+ New" (#50) and FacetWP Cache (#76) menus
 * removed for an Auditor only.
 *
 * Mutation-clean under `composer mutate -- --path=includes/AdminBar.php`, with
 * no equivalent mutants.
 *
 * @see includes/AdminBar.php
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\MockInterface;
use UAMSWeb\AuditorRole\AdminBar;

/**
 * A double of core's admin bar. Mockery declares the class when WordPress is
 * not loaded, so the badge's instanceof check sees a real WP_Admin_Bar.
 */
function uamswp_auditor_role_g1_bar(): MockInterface
{
    return Mockery::mock(WP_Admin_Bar::class);
}

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    Functions\when('__')->returnArg();
    Functions\when('esc_html__')->alias(static fn (string $text): string => 'escaped:'.$text);
});

it('hooks the badge first and the "+ New" removal last in the admin bar, and the style on both admin and front end', function (): void {
    Actions\expectAdded('admin_bar_menu')->once()->with([AdminBar::class, 'add_badge'], 0);
    Actions\expectAdded('admin_bar_menu')->once()->with([AdminBar::class, 'remove_nodes'], PHP_INT_MAX);
    Actions\expectAdded('admin_enqueue_scripts')->once()->with([AdminBar::class, 'add_style']);
    Actions\expectAdded('wp_enqueue_scripts')->once()->with([AdminBar::class, 'add_style']);

    AdminBar::boot();
});

it('adds the escaped badge for an Auditor', function (): void {
    uamswp_auditor_role_as(true);
    $bar = uamswp_auditor_role_g1_bar();
    $bar->shouldReceive('add_node')->once()->with([
        'id' => 'uamswp-auditor-role-badge',
        'title' => 'escaped:Read-only audit mode',
        'meta' => [
            'title' => 'This account can view but not change anything. Changes are refused and recorded.',
        ],
    ]);

    AdminBar::add_badge($bar);
});

it('adds no badge for another role', function (): void {
    uamswp_auditor_role_as(false);
    $bar = uamswp_auditor_role_g1_bar();
    $bar->shouldReceive('add_node')->never();

    AdminBar::add_badge($bar);
});

it('adds nothing when it is not given an admin bar', function (): void {
    uamswp_auditor_role_as(true);
    Functions\expect('esc_html__')->never();

    AdminBar::add_badge(new stdClass);
});

it('colors the badge for an Auditor when the admin bar shows', function (): void {
    uamswp_auditor_role_as(true);
    Functions\when('is_admin_bar_showing')->justReturn(true);
    Functions\expect('wp_add_inline_style')->once()->with(
        'admin-bar',
        '#wpadminbar #wp-admin-bar-uamswp-auditor-role-badge > .ab-item{background:#9a3412;color:#fff;font-weight:600}'
    );

    AdminBar::add_style();
});

it('adds no style when the admin bar is hidden or the user is another role', function (bool $auditor, bool $showing): void {
    uamswp_auditor_role_as($auditor);
    Functions\when('is_admin_bar_showing')->justReturn($showing);
    Functions\expect('wp_add_inline_style')->never();

    AdminBar::add_style();
})->with([
    'auditor, bar hidden' => [true, false],
    'another role, bar showing' => [false, true],
]);

it('removes the "+ New" and FacetWP Cache menus for an Auditor', function (): void {
    uamswp_auditor_role_as(true);
    $bar = uamswp_auditor_role_g1_bar();
    $bar->shouldReceive('remove_node')->once()->ordered()->with('new-content');
    $bar->shouldReceive('remove_node')->once()->ordered()->with('fwp-cache');

    AdminBar::remove_nodes($bar);
});

it('leaves both menus for another role', function (): void {
    uamswp_auditor_role_as(false);
    $bar = uamswp_auditor_role_g1_bar();
    $bar->shouldReceive('remove_node')->never();

    AdminBar::remove_nodes($bar);
});

it('removes nothing when it is not given an admin bar', function (): void {
    uamswp_auditor_role_as(true);
    $bar = Mockery::mock();
    $bar->shouldReceive('remove_node')->never();

    AdminBar::remove_nodes($bar);
});

it('lists the hidden admin bar nodes', function (): void {
    expect(AdminBar::hidden_nodes())->toBe(['new-content', 'fwp-cache']);
});
