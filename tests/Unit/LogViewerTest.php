<?php

/**
 * UAMSWeb\AuditorRole\LogViewer, with WordPress and the database mocked.
 *
 * The CSV export's headers and the end of each handler go through
 * LogViewer::$send_header and LogViewer::$terminate, which these tests set:
 * PHP's CLI records no header, and exit would end the test run.
 * Mutation-clean under `composer mutate -- --path=includes/LogViewer.php`,
 * with no equivalent mutants.
 *
 * @see includes/LogViewer.php
 */

declare(strict_types=1);

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use UAMSWeb\AuditorRole\LogViewer;
use UAMSWeb\AuditorRole\MetaCaps;
use UAMSWeb\AuditorRole\Retention;

use function Brain\Monkey\Functions\stubEscapeFunctions;
use function Brain\Monkey\Functions\stubTranslationFunctions;

require_once __DIR__.'/LogSupport.php';

beforeEach(function (): void {
    uamswp_auditor_role_reset();
    uamswp_auditor_role_g3_wpdb();
    $GLOBALS['uamswp_auditor_role_g3_headers'] = [];
    $GLOBALS['uamswp_auditor_role_g3_ended'] = 0;

    stubEscapeFunctions();
    stubTranslationFunctions();
    Functions\when('wp_unslash')->returnArg();
    Functions\when('sanitize_text_field')->alias('trim');
    Functions\when('wp_kses_post')->returnArg();
    Functions\when('get_admin_page_title')->justReturn('Auditor activity log');
    Functions\when('absint')->alias(static fn (mixed $value): int => abs((int) $value));
    Functions\when('admin_url')->alias(static fn (string $path = ''): string => 'https://example.test/wp-admin/'.$path);
    // Patchwork calls these without strict types, so each checks it was handed an integer.
    Functions\when('number_format_i18n')->alias(static function (mixed $n): string {
        if (! is_int($n)) {
            throw new TypeError('number_format_i18n() was given '.get_debug_type($n));
        }

        return number_format($n);
    });
    Functions\when('wp_date')->alias(static function (string $format, mixed $time): string {
        if (! is_int($time)) {
            throw new TypeError('wp_date() was given '.get_debug_type($time));
        }

        return gmdate($format, $time);
    });
    Functions\when('selected')->alias(static function (mixed $selected, mixed $current): void {
        echo (string) $selected === (string) $current ? ' selected="selected"' : '';
    });
    Functions\when('submit_button')->alias(static function (string $text, string $type = 'primary', string $name = 'submit', bool $wrap = true): void {
        echo '<button class="'.$type.'" name="'.$name.'" data-wrap="'.($wrap ? 'p' : 'none').'">'.$text.'</button>';
    });
    Functions\when('wp_nonce_field')->alias(static function (string $action, string $name): void {
        echo '<input type="hidden" name="'.$name.'" value="nonce-for-'.$action.'">';
    });
    Functions\when('add_query_arg')->alias(static fn (mixed $key, mixed $value = null, mixed $url = null): string => is_array($key) ? $value.'?'.http_build_query($key) : '?'.$key.'='.$value);
    Functions\when('paginate_links')->alias(static fn (array $args): string => '<nav>'.var_export($args, true).'</nav>');

    LogViewer::$send_header = static function (string $header): void {
        $GLOBALS['uamswp_auditor_role_g3_headers'][] = $header;
    };
    LogViewer::$terminate = static function (): never {
        $GLOBALS['uamswp_auditor_role_g3_ended']++;

        throw new RuntimeException('terminated');
    };
});

afterEach(function (): void {
    LogViewer::$send_header = null;
    LogViewer::$terminate = null;
    $_GET = [];
    $_POST = [];
});

/**
 * The headers the export sent.
 *
 * @return list<string>
 */
function uamswp_auditor_role_g3_headers(): array
{
    $headers = $GLOBALS['uamswp_auditor_role_g3_headers'] ?? [];

    return is_array($headers) ? array_values(array_filter($headers, is_string(...))) : [];
}

/**
 * How many times a handler ended the request.
 */
function uamswp_auditor_role_g3_ended(): int
{
    $ended = $GLOBALS['uamswp_auditor_role_g3_ended'] ?? 0;

    return is_int($ended) ? $ended : 0;
}

/**
 * Answer get_option() for the last prune and the retention.
 */
function uamswp_auditor_role_g3_viewer_options(mixed $last_prune = false, mixed $days = 365): void
{
    Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => match ($name) {
        Retention::LAST_PRUNE => $last_prune,
        Retention::OPTION => $days,
        default => $default,
    });
}

/**
 * The page's HTML, for an administrator.
 */
function uamswp_auditor_role_g3_render(): string
{
    Functions\when('current_user_can')->justReturn(true);

    ob_start();
    LogViewer::render_page();

    return (string) ob_get_clean();
}

it('adds its page, its export and its retention save', function (): void {
    Actions\expectAdded('admin_menu')->once()->with([LogViewer::class, 'register_page'], 10, 1);
    Actions\expectAdded('admin_post_uamswp_auditor_role_export_log')->once()->with([LogViewer::class, 'handle_export'], 10, 1);
    Actions\expectAdded('admin_post_uamswp_auditor_role_save_retention')->once()->with([LogViewer::class, 'handle_retention'], 10, 1);

    LogViewer::boot();
});

it('puts the page under Tools for the log capability', function (): void {
    Functions\expect('add_management_page')->once()->with('Auditor activity log', 'Auditor log', MetaCaps::VIEW_LOG, 'uamswp-auditor-log', [LogViewer::class, 'render_page']);

    LogViewer::register_page();
});

it('names its page, actions and nonce, and shows 50 rows a page', function (): void {
    expect(LogViewer::PAGE)->toBe('uamswp-auditor-log')
        ->and(LogViewer::EXPORT)->toBe('uamswp_auditor_role_export_log')
        ->and(LogViewer::RETENTION)->toBe('uamswp_auditor_role_save_retention')
        ->and(LogViewer::NONCE)->toBe('uamswp_auditor_role_nonce')
        ->and(LogViewer::per_page())->toBe(50);
});

it('refuses the page to anyone without the log capability, before reading the log', function (): void {
    Functions\expect('current_user_can')->once()->with(MetaCaps::VIEW_LOG)->andReturn(false);
    Functions\expect('wp_die')->once()->with('You do not have permission to view this page.', 403)->andReturnUsing(static function (): never {
        throw new RuntimeException('wp_die');
    });

    expect(fn () => LogViewer::render_page())->toThrow(RuntimeException::class, 'wp_die')
        ->and(uamswp_auditor_role_g3_db()->prepared)->toBe([]);
});

it('lists the filtered rows with every filter and column, escaped', function (): void {
    // Stored values can come back as strings.
    uamswp_auditor_role_g3_viewer_options(['time' => '1793920000', 'deleted' => '1234'], '30');
    $_GET = ['page' => 'uamswp-auditor-log', 'user_id' => '7', 'from' => '2026-11-01', 'to' => '2026-11-05', 'event' => 'refused'];
    $row = ['id' => '9', 'logged_at' => '2026-11-05 10:00:00', 'user_id' => '7', 'user_login' => 'audit<b>', 'blog_id' => '1', 'event' => 'refused', 'method' => 'POST', 'screen' => 'options.php', 'url' => '/wp-admin/options.php?x=<y>', 'detail' => 'method:POST'];
    uamswp_auditor_role_g3_db()->vars = ['1'];
    uamswp_auditor_role_g3_db()->results = [[$row], [['user_id' => '7', 'user_login' => 'audit<b>'], ['user_id' => '8', 'user_login' => 'other']]];

    $html = uamswp_auditor_role_g3_render();

    foreach ([
        '<h1>Auditor activity log</h1>',
        'Each page an Auditor account opened on this site, each request the read-only guard refused, and each sign-in and sign-out. Times are UTC.',
        '<input type="hidden" name="page" value="uamswp-auditor-log">',
        '<label for="uamswp-auditor-log-user">Auditor</label>',
        '<option value="0">All</option>',
        '<option value="7"  selected="selected">audit&lt;b&gt;</option>',
        '<option value="8" >other</option>',
        '<label for="uamswp-auditor-log-from">From</label>',
        'name="from" value="2026-11-01"',
        '<label for="uamswp-auditor-log-to">To</label>',
        'name="to" value="2026-11-05"',
        '<label for="uamswp-auditor-log-event">Event</label>',
        '<option value="">All</option>',
        '<option value="refused"  selected="selected">refused</option>',
        '<option value="view" >view</option>',
        '<option value="login_refused" >login_refused</option>',
        '<button class="secondary" name="" data-wrap="none">Filter</button>',
        '<form method="post" action="https://example.test/wp-admin/admin-post.php" style="margin-top:8px">',
        '<input type="hidden" name="action" value="uamswp_auditor_role_export_log">',
        '<input type="hidden" name="uamswp_auditor_role_nonce" value="nonce-for-uamswp_auditor_role_export_log">',
        '<input type="hidden" name="user_id" value="7">',
        '<input type="hidden" name="from" value="2026-11-01">',
        '<input type="hidden" name="to" value="2026-11-05">',
        '<input type="hidden" name="event" value="refused">',
        '<button class="secondary" name="" data-wrap="none">Export these rows as CSV</button>',
        '1 row',
        '<th>Time (UTC)</th>', '<th>Auditor</th>', '<th>Event</th>', '<th>Method</th>', '<th>Screen</th>', '<th>URL</th>', '<th>Detail</th>',
        '<td>2026-11-05 10:00:00</td>',
        '<td>audit&lt;b&gt;</td>',
        '<td>refused</td>',
        '<td>POST</td>',
        '<td>options.php</td>',
        '<td style="word-break:break-all">/wp-admin/options.php?x=&lt;y&gt;</td>',
        '<td style="word-break:break-word">method:POST</td>',
        '<h2>Retention</h2>',
        '<input type="hidden" name="action" value="uamswp_auditor_role_save_retention">',
        '<input type="hidden" name="uamswp_auditor_role_nonce" value="nonce-for-uamswp_auditor_role_save_retention">',
        '<label for="uamswp-auditor-log-retention">Keep rows for this many days</label>',
        'name="retention_days" min="1" max="3650" value="30" class="small-text"',
        'A daily job deletes older rows. Last run: '.gmdate('Y-m-d H:i', 1_793_920_000).', 1,234 rows deleted.',
        '<button class="primary" name="submit" data-wrap="p">Save retention</button>',
    ] as $fragment) {
        expect($html)->toContain($fragment);
    }

    foreach (['Retention saved.', 'No rows match.', '<nav>', '<b>'] as $absent) {
        expect($html)->not->toContain($absent);
    }

    expect(uamswp_auditor_role_g3_db()->prepared[0])->toBe("SELECT COUNT(*) FROM `wp_uamswp_auditor_log` WHERE 1=1 AND user_id = 7 AND logged_at >= '2026-11-01 00:00:00' AND logged_at <= '2026-11-05 23:59:59' AND event = 'refused'")
        ->and(uamswp_auditor_role_g3_db()->prepared[1])->toEndWith('ORDER BY id DESC LIMIT 50 OFFSET 0');
});

it('says so when no row matches, and when the prune has not run', function (mixed $last_prune): void {
    uamswp_auditor_role_g3_viewer_options($last_prune);
    uamswp_auditor_role_g3_db()->vars = ['0'];
    uamswp_auditor_role_g3_db()->results = [[], []];

    $html = uamswp_auditor_role_g3_render();

    expect($html)->toContain('<tr><td colspan="7">No rows match.</td></tr>')
        ->toContain('0 rows')
        ->toContain('A daily job deletes older rows. It has not run yet.')
        ->toContain('value="365" class="small-text"');
    expect($html)->not->toContain('Last run:');
})->with([
    'never' => [false],
    'a record without a count' => [['time' => 1_793_920_000]],
    'a record without a time' => [['deleted' => 3]],
]);

it('confirms a saved retention', function (): void {
    uamswp_auditor_role_g3_viewer_options();
    $_GET = ['retention-saved' => '1'];
    uamswp_auditor_role_g3_db()->vars = ['0'];
    uamswp_auditor_role_g3_db()->results = [[], []];

    expect(uamswp_auditor_role_g3_render())->toContain('<div class="notice notice-success is-dismissible"><p>Retention saved.</p></div>');
});

it('pages through the rows 50 at a time', function (mixed $paged, int $offset, int $current): void {
    uamswp_auditor_role_g3_viewer_options();
    $_GET = ['paged' => $paged];
    uamswp_auditor_role_g3_db()->vars = ['120'];
    uamswp_auditor_role_g3_db()->results = [[uamswp_auditor_role_g3_row(1)], []];

    $html = uamswp_auditor_role_g3_render();

    expect(uamswp_auditor_role_g3_db()->prepared[1])->toEndWith('LIMIT 50 OFFSET '.$offset)
        ->and($html)->toContain('120 rows')
        ->toContain('<div class="tablenav"><div class="tablenav-pages">')
        ->toContain('<nav>'.var_export(['base' => '?paged=%#%', 'format' => '', 'current' => $current, 'total' => 3], true).'</nav>');
})->with([
    'the third page' => ['3', 100, 3],
    'page zero' => ['0', 0, 1],
    'a negative page' => ['-2', 50, 2],
    'no page' => [null, 0, 1],
]);

it('prints nothing in the page links when WordPress returns none', function (): void {
    uamswp_auditor_role_g3_viewer_options();
    uamswp_auditor_role_g3_db()->vars = ['120'];
    uamswp_auditor_role_g3_db()->results = [[uamswp_auditor_role_g3_row(1)], []];
    Functions\when('paginate_links')->justReturn();

    expect(uamswp_auditor_role_g3_render())->toMatch('#<div class="tablenav-pages">\s*</div></div>#');
});

it('shows no page links for a single page', function (string $total, bool $links): void {
    uamswp_auditor_role_g3_viewer_options();
    uamswp_auditor_role_g3_db()->vars = [$total];
    uamswp_auditor_role_g3_db()->results = [[uamswp_auditor_role_g3_row(1)], []];

    expect(str_contains(uamswp_auditor_role_g3_render(), '<nav>'))->toBe($links);
})->with([
    'exactly one page' => ['50', false],
    'one row over' => ['51', true],
]);

it('refuses the export without the capability, before the nonce', function (): void {
    Functions\expect('current_user_can')->once()->with(MetaCaps::VIEW_LOG)->andReturn(false);
    Functions\expect('wp_die')->once()->with('You do not have permission to do that.', 403)->andReturnUsing(static function (): never {
        throw new RuntimeException('wp_die');
    });
    Functions\expect('check_admin_referer')->never();

    expect(fn () => LogViewer::handle_export())->toThrow(RuntimeException::class, 'wp_die')
        ->and(uamswp_auditor_role_g3_headers())->toBe([]);
});

it('refuses the export with a bad nonce, before reading the log', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    Functions\expect('check_admin_referer')->once()->with(LogViewer::EXPORT, LogViewer::NONCE)->andReturnUsing(static function (): never {
        throw new RuntimeException('bad nonce');
    });

    expect(fn () => LogViewer::handle_export())->toThrow(RuntimeException::class, 'bad nonce')
        ->and(uamswp_auditor_role_g3_db()->prepared)->toBe([]);
});

it('streams the filtered rows as a CSV download, then ends', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('check_admin_referer')->justReturn(1);
    Functions\expect('nocache_headers')->once();
    $_POST = ['user_id' => '7', 'event' => 'view', 'from' => 'nonsense'];
    uamswp_auditor_role_g3_db()->results = [[uamswp_auditor_role_g3_row(4)]];

    ob_start();

    try {
        LogViewer::handle_export();
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('terminated');
    }

    $csv = (string) ob_get_clean();

    expect($csv)->toBe("id,logged_at,user_id,user_login,blog_id,event,method,screen,url,detail\n4,\"2026-11-05 10:00:00\",7,audit,1,view,GET,index.php,/wp-admin/,\n")
        ->and(uamswp_auditor_role_g3_headers())->toBe(['Content-Type: text/csv; charset=utf-8', 'Content-Disposition: attachment; filename="auditor-log-'.gmdate('Y-m-d').'.csv"'])
        ->and(uamswp_auditor_role_g3_ended())->toBe(1)
        ->and(uamswp_auditor_role_g3_db()->prepared[0])->toBe("SELECT * FROM `wp_uamswp_auditor_log` WHERE 1=1 AND user_id = 7 AND event = 'view' ORDER BY id DESC LIMIT 1000 OFFSET 0");
});

it('refuses the retention save without the capability, before the nonce', function (): void {
    Functions\expect('current_user_can')->once()->with(MetaCaps::VIEW_LOG)->andReturn(false);
    Functions\expect('wp_die')->once()->with('You do not have permission to do that.', 403)->andReturnUsing(static function (): never {
        throw new RuntimeException('wp_die');
    });
    Functions\expect('check_admin_referer')->never();
    Functions\expect('update_option')->never();

    expect(fn () => LogViewer::handle_retention())->toThrow(RuntimeException::class, 'wp_die');
});

it('refuses the retention save with a bad nonce', function (): void {
    Functions\when('current_user_can')->justReturn(true);
    Functions\expect('check_admin_referer')->once()->with(LogViewer::RETENTION, LogViewer::NONCE)->andReturnUsing(static function (): never {
        throw new RuntimeException('bad nonce');
    });
    Functions\expect('update_option')->never();

    expect(fn () => LogViewer::handle_retention())->toThrow(RuntimeException::class, 'bad nonce');
});

it('saves the period held to its range and returns to the screen', function (mixed $submitted, int $saved): void {
    Functions\when('current_user_can')->justReturn(true);
    Functions\when('check_admin_referer')->justReturn(1);
    $_POST = $submitted === null ? [] : ['retention_days' => $submitted];
    Functions\expect('update_option')->once()->with(Retention::OPTION, $saved, false)->ordered();
    Functions\expect('wp_safe_redirect')->once()->with('https://example.test/wp-admin/tools.php?page=uamswp-auditor-log&retention-saved=1')->ordered();

    expect(fn () => LogViewer::handle_retention())->toThrow(RuntimeException::class, 'terminated')
        ->and(uamswp_auditor_role_g3_ended())->toBe(1);
})->with([
    'a month' => [' 30 ', 30],
    'too long' => ['99999', 3650],
    'nothing submitted' => [null, 365],
]);
