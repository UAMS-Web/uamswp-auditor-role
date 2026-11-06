<?php

/**
 * The activity log, its viewer, its WP-CLI command and its retention, against
 * WordPress and a database (#11, #12, #13, #14).
 *
 * Runs on a single site and, with WP_MULTISITE=1, on a multisite network.
 * WP-CLI is not loaded here, so the command runs against the double in
 * tests/Support/wp-cli-double.php, which records what reaches WP-CLI's formatter.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Tests\Integration;

use RuntimeException;
use UAMSWeb\AuditorRole\Cli\LogCommand;
use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\Log;
use UAMSWeb\AuditorRole\LogQuery;
use UAMSWeb\AuditorRole\LogViewer;
use UAMSWeb\AuditorRole\RequestGuard;
use UAMSWeb\AuditorRole\Retention;
use UAMSWeb\AuditorRole\Role;
use UAMSWeb\AuditorRole\Session;
use UAMSWeb\AuditorRole\Tests\Support\IntegrationTestCase;
use WP_REST_Request;
use WPDieException;

require_once __DIR__.'/../Unit/LogSupport.php';

final class ActivityLogTest extends IntegrationTestCase
{
    private int $auditor;

    private int $admin;

    public function set_up(): void
    {
        global $wpdb;

        parent::set_up();

        Role::maybe_register();
        Log::maybe_install();
        $wpdb->query($wpdb->prepare('DELETE FROM %i', Log::table())); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        $this->auditor = $this->id(self::factory()->user->create(['role' => Role::NAME, 'user_login' => 'auditor'.wp_rand()]));
        $this->admin = $this->id(self::factory()->user->create(['role' => 'administrator']));

        if (is_multisite()) {
            grant_super_admin($this->admin);
        }

        Session::reset();
        DatabaseGuard::reset();
        RequestGuard::reset();
        Log::reset();
        uamswp_auditor_role_g3_cli_reset();
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }

    public function tear_down(): void
    {
        LogViewer::$send_header = null;
        LogViewer::$terminate = null;
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        parent::tear_down();
    }

    /**
     * The ID a factory returned, or a failed test.
     */
    private function id(mixed $id): int
    {
        if (! is_int($id)) {
            self::fail('The factory did not create the object.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, string>>
     */
    private function rows(array $filters = []): array
    {
        return LogQuery::rows(LogQuery::filters($filters), 100);
    }

    /**
     * Insert a row directly, at a given age in days.
     */
    private function insert_row(int $days_old, string $event = 'view', int $user_id = 0): void
    {
        global $wpdb;

        $wpdb->insert(Log::table(), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            'logged_at' => gmdate('Y-m-d H:i:s', time() - $days_old * DAY_IN_SECONDS),
            'user_id' => $user_id === 0 ? $this->auditor : $user_id,
            'user_login' => 'audit',
            'blog_id' => get_current_blog_id(),
            'event' => $event,
            'method' => 'GET',
            'screen' => 'index.php',
            'url' => '/',
            'detail' => '',
        ]);
    }

    /* #11 ------------------------------------------------------------------ */

    public function test_an_allowed_page_load_writes_one_view_row(): void
    {
        wp_set_current_user($this->auditor);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/wp-admin/edit.php?post_type=page';

        Log::record_request();

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('view', $rows[0]['event']);
        $this->assertSame((string) $this->auditor, $rows[0]['user_id']);
        $this->assertSame('/wp-admin/edit.php?post_type=page', $rows[0]['url']);
        $this->assertSame((string) get_current_blog_id(), $rows[0]['blog_id']);
    }

    public function test_a_refused_request_writes_one_refused_row_with_its_refused_statements(): void
    {
        wp_set_current_user($this->auditor);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';

        $response = rest_do_request(new WP_REST_Request('POST', '/wp/v2/posts'));
        $this->assertSame(403, $response->get_status());
        $this->assertFalse(update_option('blogname', 'changed'));

        Log::record_request();

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('refused', $rows[0]['event']);
        $this->assertStringStartsWith('rest:POST /wp/v2/posts; 1 refused: UPDATE ', $rows[0]['detail']);
        $this->assertStringContainsString('(blogname)', $rows[0]['detail']);
        $this->assertStringNotContainsString('changed', $rows[0]['detail']);
    }

    public function test_another_role_writes_no_row(): void
    {
        wp_set_current_user($this->admin);

        Log::record_request();

        $this->assertSame([], $this->rows());
    }

    public function test_each_subsite_has_its_own_table(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only.');
        }

        $blog_id = $this->id(self::factory()->blog->create());

        switch_to_blog($blog_id);
        $this->assertSame(Log::SCHEMA, get_option(Log::OPTION));
        $this->assertStringEndsWith($blog_id.'_uamswp_auditor_log', Log::table());
        $this->assertSame([], $this->rows());
        restore_current_blog();
    }

    /* #12 ------------------------------------------------------------------ */

    public function test_the_viewer_filters_by_auditor_dates_and_event(): void
    {
        $other = $this->id(self::factory()->user->create(['role' => Role::NAME]));
        $this->insert_row(1, 'view');
        $this->insert_row(1, 'refused');
        $this->insert_row(40, 'refused');
        $this->insert_row(1, 'refused', $other);

        wp_set_current_user($this->admin);
        $_GET = ['user_id' => (string) $this->auditor, 'event' => 'refused', 'from' => gmdate('Y-m-d', time() - 3 * DAY_IN_SECONDS)];

        ob_start();
        LogViewer::render_page();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('1 row', $html);
        $this->assertSame(1, substr_count($html, '<td>refused</td>'));
        $this->assertStringNotContainsString('<td>view</td>', $html);
    }

    public function test_the_export_holds_the_filtered_rows_and_needs_the_nonce(): void
    {
        $this->insert_row(1, 'view');
        $this->insert_row(1, 'login');
        $headers = [];
        LogViewer::$send_header = static function (string $header) use (&$headers): void {
            $headers[] = $header;
        };
        LogViewer::$terminate = static function (): never {
            throw new RuntimeException('terminated');
        };

        wp_set_current_user($this->admin);
        $_POST = ['event' => 'login'];
        $_REQUEST = [LogViewer::NONCE => wp_create_nonce(LogViewer::EXPORT)];

        ob_start();

        try {
            LogViewer::handle_export();
        } catch (RuntimeException) {
        }

        $csv = (string) ob_get_clean();

        $this->assertSame(2, substr_count($csv, "\n"));
        $this->assertStringContainsString(',login,', $csv);
        $this->assertStringNotContainsString(',view,', $csv);
        $this->assertSame('Content-Type: text/csv; charset=utf-8', $headers[0]);

        $_REQUEST = [LogViewer::NONCE => 'forged'];
        $this->expectException(WPDieException::class);
        LogViewer::handle_export();
    }

    public function test_an_auditor_cannot_open_the_viewer(): void
    {
        wp_set_current_user($this->auditor);

        $this->assertFalse(current_user_can('uamswp_auditor_role_view_log'));
        $this->expectException(WPDieException::class);

        LogViewer::render_page();
    }

    public function test_an_auditor_cannot_export(): void
    {
        wp_set_current_user($this->auditor);
        $_REQUEST = [LogViewer::NONCE => wp_create_nonce(LogViewer::EXPORT)];

        $this->expectException(WPDieException::class);

        LogViewer::handle_export();
    }

    /* #13 ------------------------------------------------------------------ */

    public function test_the_command_lists_each_format_with_a_filter(): void
    {
        $this->insert_row(1, 'view');
        $this->insert_row(1, 'refused');

        foreach (['table', 'csv', 'json'] as $format) {
            uamswp_auditor_role_g3_cli_reset();

            (new LogCommand)->list([], ['format' => $format, 'event' => 'refused']);

            [$used, $rows, $fields] = uamswp_auditor_role_g3_formatted_calls()[0];

            $this->assertSame($format, $used);
            $this->assertSame(['refused'], array_column($rows, 'event'));
            $this->assertSame(LogQuery::columns(), $fields);
        }
    }

    public function test_the_command_exports_csv(): void
    {
        $this->insert_row(1, 'logout');
        $file = get_temp_dir().'uamswp-auditor-role-g3-'.wp_rand().'.csv';

        (new LogCommand)->export([], ['file' => $file, 'event' => 'logout']);

        $csv = (string) file_get_contents($file);
        unlink($file);

        $this->assertStringStartsWith(implode(',', LogQuery::columns())."\n", $csv);
        $this->assertSame(2, substr_count($csv, "\n"));
    }

    public function test_the_command_reads_one_site_or_every_site_of_a_network(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only.');
        }

        $this->insert_row(2, 'view');
        $blog_id = $this->id(self::factory()->blog->create());
        switch_to_blog($blog_id);
        $this->insert_row(1, 'view');
        restore_current_blog();

        (new LogCommand)->list([], ['format' => 'json']);
        $this->assertSame([(string) get_current_blog_id()], array_column(uamswp_auditor_role_g3_formatted_calls()[0][1], 'blog_id'));

        uamswp_auditor_role_g3_cli_reset();
        (new LogCommand)->list([], ['format' => 'json', 'all-sites' => '1']);
        $this->assertSame([(string) $blog_id, (string) get_current_blog_id()], array_column(uamswp_auditor_role_g3_formatted_calls()[0][1], 'blog_id'));
    }

    public function test_the_command_writes_nothing_to_the_site(): void
    {
        global $wpdb;

        $this->insert_row(400, 'view');
        $before = $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', Log::table())); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

        (new LogCommand)->list([], []);

        $this->assertSame($before, $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', Log::table()))); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $public = array_map(static fn (\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(LogCommand::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        $this->assertSame(['list', 'export'], $public);
    }

    /* #14 ------------------------------------------------------------------ */

    public function test_the_default_is_a_year_and_an_administrator_can_change_it(): void
    {
        delete_option(Retention::OPTION);
        $this->assertSame(365, Retention::days());

        LogViewer::$terminate = static function (): never {
            throw new RuntimeException('terminated');
        };
        add_filter('wp_redirect', '__return_false');
        wp_set_current_user($this->admin);
        $_POST = ['retention_days' => '30'];
        $_REQUEST = [LogViewer::NONCE => wp_create_nonce(LogViewer::RETENTION)];

        try {
            LogViewer::handle_retention();
        } catch (RuntimeException) {
        }

        $this->assertSame(30, Retention::days());
    }

    public function test_an_auditor_cannot_change_the_period(): void
    {
        wp_set_current_user($this->auditor);
        $_POST = ['retention_days' => '1'];
        $_REQUEST = [LogViewer::NONCE => wp_create_nonce(LogViewer::RETENTION)];

        try {
            LogViewer::handle_retention();
            $this->fail('The save went through.');
        } catch (WPDieException) {
        }

        $this->assertSame(365, Retention::days());
    }

    public function test_the_prune_deletes_rows_older_than_the_period_and_keeps_newer_ones(): void
    {
        update_option(Retention::OPTION, 30);
        $this->insert_row(31, 'view');
        $this->insert_row(29, 'refused');
        $this->insert_row(1, 'login');
        wp_set_current_user(0);

        $this->assertSame(1, Retention::prune());
        $this->assertSame(['login', 'refused'], array_column($this->rows(), 'event'));
        $this->assertSame(1, get_option(Retention::LAST_PRUNE)['deleted']);
    }

    public function test_the_prune_does_nothing_in_an_auditor_session(): void
    {
        $this->insert_row(400, 'view');
        wp_set_current_user($this->auditor);

        $this->assertSame(0, Retention::prune());
        $this->assertCount(1, $this->rows());
    }

    public function test_the_job_is_registered_once_and_prunes_every_site(): void
    {
        wp_clear_scheduled_hook(Retention::HOOK);
        wp_set_current_user(0);

        Retention::schedule();
        Retention::schedule();

        $this->assertNotFalse(wp_next_scheduled(Retention::HOOK));
        $this->assertSame(1, $this->count_events());

        if (! is_multisite()) {
            return;
        }

        $blog_id = $this->id(self::factory()->blog->create());
        switch_to_blog($blog_id);
        // A per-site event left by an earlier version is cleared, and none is added.
        wp_schedule_event(time() + 60, 'daily', Retention::HOOK);
        Retention::schedule();
        $this->assertFalse(wp_next_scheduled(Retention::HOOK));
        update_option(Retention::OPTION, 10);
        $this->insert_row(11, 'view');
        restore_current_blog();

        update_option(Retention::OPTION, 365);
        $this->insert_row(11, 'view');

        $deleted = Retention::prune_all();

        $this->assertSame(1, $deleted[$blog_id]);
        $this->assertSame(0, $deleted[get_current_blog_id()]);
    }

    /**
     * How many prune events the current site's cron holds.
     */
    private function count_events(): int
    {
        $count = 0;

        foreach ((array) _get_cron_array() as $hooks) {
            $count += isset($hooks[Retention::HOOK]) ? count($hooks[Retention::HOOK]) : 0;
        }

        return $count;
    }
}
