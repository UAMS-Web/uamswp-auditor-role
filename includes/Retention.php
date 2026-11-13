<?php

/**
 * How long the activity log keeps rows, and the scheduled job that prunes older ones.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class Retention
{
    /** The option holding the retention in days. */
    public const OPTION = 'uamswp_auditor_role_retention_days';

    /** The option holding the last prune's time and row count. */
    public const LAST_PRUNE = 'uamswp_auditor_role_last_prune';

    /** The scheduled hook. */
    public const HOOK = 'uamswp_auditor_role_prune';

    public static function boot(): void
    {
        add_action('init', [self::class, 'schedule'], 20);
        add_action(self::HOOK, [self::class, 'run']);
    }

    /**
     * The retention when none is set: a year. The three limits are methods,
     * not constants, so each value is a line a test is seen to cover.
     */
    public static function default_days(): int
    {
        return 365;
    }

    /**
     * The shortest retention: a day.
     */
    public static function min_days(): int
    {
        return 1;
    }

    /**
     * The longest retention: ten years.
     */
    public static function max_days(): int
    {
        return 3650;
    }

    /**
     * The retention in days on the current site.
     */
    public static function days(): int
    {
        return self::clamp(get_option(self::OPTION, self::default_days()));
    }

    /**
     * A submitted number of days, held to the allowed range.
     */
    public static function clamp(mixed $days): int
    {
        $days = is_numeric($days) ? (int) $days : self::default_days();

        return max(self::min_days(), min(self::max_days(), $days));
    }

    /**
     * Schedule the daily prune once for the install: on the main site of a
     * multisite network, where one run prunes every site. A subsite clears an
     * event left from an earlier version that scheduled one per site. Never
     * from an Auditor session, whose writes are refused.
     */
    public static function schedule(): void
    {
        if (Session::is_auditor()) {
            return;
        }

        $scheduled = wp_next_scheduled(self::HOOK) !== false;

        if (! is_main_site()) {
            if ($scheduled) {
                wp_clear_scheduled_hook(self::HOOK);
            }

            return;
        }

        if (! $scheduled) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }

    /**
     * The scheduled job.
     */
    public static function run(): void
    {
        self::prune_all();
    }

    /**
     * Prune each site's log by that site's retention.
     *
     * @return array<int, int> Site ID to the rows deleted there.
     */
    public static function prune_all(): array
    {
        $deleted = [];
        $multisite = is_multisite();

        foreach (Report::site_ids() as $blog_id) {
            if ($multisite) {
                switch_to_blog($blog_id);
            }

            try {
                $deleted[$blog_id] = self::prune();
            } finally {
                if ($multisite) {
                    restore_current_blog();
                }
            }
        }

        return $deleted;
    }

    /**
     * Delete rows older than the retention, in batches, and record how many.
     *
     * @return int The rows deleted.
     */
    public static function prune(): int
    {
        $wpdb = Database::wpdb();

        if (Session::is_auditor() || get_option(Log::OPTION) !== Log::SCHEMA) {
            return 0;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - self::days() * DAY_IN_SECONDS);
        $deleted = 0;

        // At most 100 statements of 1,000 rows each per run; the next run takes the rest.
        for ($batch = 0; $batch < 100; $batch++) {
            $statement = $wpdb->prepare('DELETE FROM %i WHERE logged_at < %s LIMIT %d', Log::table(), $cutoff, 1000);
            $removed = is_string($statement) ? $wpdb->query($statement) : 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery

            $removed = is_int($removed) ? $removed : 0;
            $deleted += $removed;

            if ($removed < 1000) {
                break;
            }
        }

        update_option(self::LAST_PRUNE, ['time' => time(), 'deleted' => $deleted], false);

        return $deleted;
    }
}
