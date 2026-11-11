<?php

/**
 * UAMSWeb\AuditorRole\ListLinks, with WordPress mocked: the "Preview" row
 * action is added for an Auditor on an unpublished post only, and the
 * title-linking script prints for an Auditor only.
 *
 * @see includes/ListLinks.php
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\ListLinks;

/**
 * A post, built the way the stand-in allows.
 */
function uamswp_auditor_role_g7_post(int $id = 12): WP_Post
{
    return new WP_Post((object) ['ID' => $id]);
}

/**
 * The lookups row_actions() reaches: the post's type and status, and its
 * preview URL.
 */
function uamswp_auditor_role_g7_lookups(string $status = 'draft', bool $protected = true, mixed $url = 'https://example.test/?p=12&preview=true'): void
{
    Functions\when('get_post_type')->justReturn('post');
    Functions\when('get_post_status')->justReturn($status);
    Functions\when('is_post_type_viewable')->justReturn(true);
    Functions\when('get_post_status_object')->justReturn((object) ['protected' => $protected]);
    Functions\when('get_preview_post_link')->justReturn($url);
    Functions\when('get_the_title')->justReturn('A <b>draft</b>');
    Functions\when('__')->returnArg();
    Functions\when('esc_url')->alias(static fn (string $url): string => 'url:'.$url);
    Functions\when('esc_attr')->alias(static fn (string $text): string => 'attr:'.$text);
    Functions\when('esc_html__')->alias(static fn (string $text): string => 'html:'.$text);
}

beforeEach(function (): void {
    uamswp_auditor_role_reset();
});

it('hooks both row action filters last and the script on the list screen', function (): void {
    Filters\expectAdded('post_row_actions')->once()->with([ListLinks::class, 'row_actions'], PHP_INT_MAX, 2);
    Filters\expectAdded('page_row_actions')->once()->with([ListLinks::class, 'row_actions'], PHP_INT_MAX, 2);
    Actions\expectAdded('admin_footer-edit.php')->once()->with([ListLinks::class, 'print_title_script']);

    ListLinks::boot();
});

it('adds an escaped Preview action for an Auditor on an unpublished post', function (): void {
    uamswp_auditor_role_as(true, 'edit.php');
    uamswp_auditor_role_g7_lookups();

    expect(ListLinks::row_actions(['trash' => 't'], uamswp_auditor_role_g7_post()))->toBe([
        'trash' => 't',
        'view' => '<a href="url:https://example.test/?p=12&preview=true" rel="bookmark" aria-label="attr:Preview &#8220;A <b>draft</b>&#8221;">html:Preview</a>',
    ]);
});

it('leaves the actions alone where the Preview action does not belong', function (bool $auditor, string $status, bool $protected, mixed $url, array $actions): void {
    uamswp_auditor_role_as($auditor, 'edit.php');
    uamswp_auditor_role_g7_lookups($status, $protected, $url);

    expect(ListLinks::row_actions($actions, uamswp_auditor_role_g7_post()))->toBe($actions);
})->with([
    'another role' => [false, 'draft', true, 'https://example.test/', []],
    'a published post' => [true, 'publish', false, 'https://example.test/', []],
    'an existing View action' => [true, 'draft', true, 'https://example.test/', ['view' => 'core']],
    'no preview URL' => [true, 'draft', true, null, []],
    'an empty preview URL' => [true, 'draft', true, '', []],
]);

it('passes values that are not actions or a post through unchanged', function (mixed $actions, mixed $post): void {
    uamswp_auditor_role_as(true, 'edit.php');
    uamswp_auditor_role_g7_lookups();

    expect(ListLinks::row_actions($actions, $post))->toBe($actions);
})->with([
    'actions that are not a list' => [null, null],
    'a post that is not a WP_Post' => [[], 12],
]);

it('prints the title script for an Auditor only', function (bool $auditor, int $times): void {
    uamswp_auditor_role_as($auditor, 'edit.php');
    Functions\expect('wp_print_inline_script_tag')->times($times)->with(ListLinks::SCRIPT);

    ListLinks::print_title_script();
})->with([
    'an Auditor' => [true, 1],
    'another role' => [false, 0],
]);

it('links the title from the row action the screen already printed', function (): void {
    expect(ListLinks::SCRIPT)
        ->toContain("row.querySelector('.row-actions .view a')")
        ->toContain('link.href = action.href;')
        ->toContain("title.querySelector('a')")
        ->and(str_contains(ListLinks::SCRIPT, 'innerHTML'))->toBeFalse();
});
