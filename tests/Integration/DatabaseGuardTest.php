<?php

/**
 * The database write block (#9), against WordPress and a database: each
 * statement type as an Auditor and as an Administrator, and the writes the
 * guard allows. Runs on a single site and, with WP_MULTISITE=1, on a network.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Tests\Integration;

use UAMSWeb\AuditorRole\DatabaseGuard;
use UAMSWeb\AuditorRole\Log;
use UAMSWeb\AuditorRole\LogQuery;
use UAMSWeb\AuditorRole\Network;
use UAMSWeb\AuditorRole\Role;
use UAMSWeb\AuditorRole\Session;
use UAMSWeb\AuditorRole\Tests\Support\IntegrationTestCase;
use WP_Session_Tokens;

final class DatabaseGuardTest extends IntegrationTestCase
{
    private const TABLE = 'uamswp_g1_probe';

    private int $auditor;

    private int $admin;

    public function set_up(): void
    {
        parent::set_up();

        Role::maybe_register();
        Log::maybe_install();

        $this->auditor = $this->id(self::factory()->user->create(['role' => Role::NAME]));
        $this->admin = $this->id(self::factory()->user->create(['role' => 'administrator']));

        Session::reset();
        DatabaseGuard::reset();
    }

    /**
     * Each statement type, in an order that leaves the probe table usable for
     * the next: the test suite turns CREATE and DROP into temporary-table
     * statements, so nothing outlives the test.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function statements(): array
    {
        $t = self::TABLE;

        return [
            ['CREATE', "CREATE TABLE {$t} (id int NOT NULL, v varchar(10) NOT NULL DEFAULT '', PRIMARY KEY (id))"],
            ['ALTER', "ALTER TABLE {$t} ADD COLUMN w int NOT NULL DEFAULT 0"],
            ['INSERT', "INSERT INTO {$t} (id, v) VALUES (1, 'a')"],
            ['UPDATE', "UPDATE {$t} SET v = 'b' WHERE id = 1"],
            ['REPLACE', "REPLACE INTO {$t} (id, v) VALUES (2, 'c')"],
            ['DELETE', "DELETE FROM {$t} WHERE id = 1"],
            ['TRUNCATE', "TRUNCATE TABLE {$t}"],
            ['DROP', "DROP TABLE {$t}"],
        ];
    }

    public function test_an_administrator_runs_every_statement_type(): void
    {
        $wpdb = self::database();

        wp_set_current_user($this->admin);

        foreach ($this->statements() as [$verb, $sql]) {
            $this->assertNotFalse($wpdb->query($sql), $verb.': '.$wpdb->last_error); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $this->assertSame(0, DatabaseGuard::refused_count());
    }

    public function test_an_auditor_is_refused_every_statement_type_and_nothing_changes(): void
    {
        $wpdb = self::database();

        // The table exists before the session, so each refused write has something to change.
        wp_set_current_user($this->admin);
        $wpdb->query('CREATE TABLE '.self::TABLE." (id int NOT NULL, v varchar(10) NOT NULL DEFAULT '', PRIMARY KEY (id))"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query('INSERT INTO '.self::TABLE." (id, v) VALUES (1, 'a')"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        wp_set_current_user($this->auditor);
        $wpdb->suppress_errors(true);

        foreach ($this->statements() as [$verb, $sql]) {
            if ($verb === 'CREATE') {
                $sql = str_replace(self::TABLE, self::TABLE.'_new', $sql);
            }

            $this->assertFalse($wpdb->query($sql), $verb); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        $this->assertSame(8, DatabaseGuard::refused_count());
        $this->assertSame('a', $wpdb->get_var('SELECT v FROM '.self::TABLE.' WHERE id = 1')); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertSame('1', $wpdb->get_var('SELECT COUNT(*) FROM '.self::TABLE)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->assertNull($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::TABLE.'_new')));
        $this->assertSame(['CREATE '.self::TABLE.'_new', 'ALTER '.self::TABLE, 'INSERT '.self::TABLE, 'UPDATE '.self::TABLE], array_slice(DatabaseGuard::refused(), 0, 4));
    }

    public function test_the_activity_log_is_written_inside_an_auditor_session(): void
    {
        wp_set_current_user($this->auditor);
        $filters = LogQuery::filters(['user_id' => $this->auditor, 'event' => 'login']);
        $before = LogQuery::count($filters);

        Log::write('login', $this->auditor, 'auditor');

        $this->assertSame($before + 1, LogQuery::count($filters));
        $this->assertSame(0, DatabaseGuard::refused_count());
    }

    public function test_the_session_token_is_written_and_destroyed_inside_an_auditor_session(): void
    {
        wp_set_current_user($this->auditor);
        $this->assertTrue(Session::is_auditor());

        $manager = WP_Session_Tokens::get_instance($this->auditor);
        $token = $manager->create(time() + HOUR_IN_SECONDS);

        $this->assertTrue($manager->verify($token));

        $manager->destroy($token);

        $this->assertFalse($manager->verify($token));
        $this->assertSame(0, DatabaseGuard::refused_count());
    }

    public function test_a_configured_login_is_added_to_a_site_inside_an_auditor_session(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only.');
        }

        $blog = $this->id(self::factory()->blog->create());
        wp_set_current_user($this->auditor);
        $this->assertTrue(Session::is_auditor());

        $this->assertTrue(Network::add_to_site($blog, $this->auditor));
        $this->assertTrue(is_user_member_of_blog($this->auditor, $blog));
        $this->assertSame(0, DatabaseGuard::refused_count());
    }

    private function id(mixed $id): int
    {
        if (! is_int($id)) {
            $this->fail('The factory did not create the object.');
        }

        return $id;
    }
}
