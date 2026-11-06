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

    public const DEFAULT_DAYS = 365;

    public const MIN_DAYS = 1;

    public const MAX_DAYS = 3650;

    /** Rows deleted per statement, and the most statements one run issues. */
    private const BATCH = 1000;

    private const MAX_BATCHES = 100;

    public static function boot(): void
    {
        add_action('init', [self::class, 'schedule'], 20);
        add_action(self::HOOK, [self::class, 'run']);
    }

    /**
     * The retention in days on the current site.
     */
    public static function days(): int
    {
        return self::clamp(get_option(self::OPTION, self::DEFAULT_DAYS));
    }

    /**
     * A submitted number of days, held to the allowed range.
     */
    public static function clamp(mixed $days): int
    {
        $days = is_numeric($days) ? (int) $days : self::DEFAULT_DAYS;

        return max(self::MIN_DAYS, min(self::MAX_DAYS, $days));
    }

    /**
     * Schedule the daily prune. Never from an Auditor session, whose writes are refused.
     */
    public static function schedule(): void
    {
        if (Session::is_auditor() || wp_next_scheduled(self::HOOK) !== false) {
            return;
        }

        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
    }

    /**
     * The scheduled job.
     */
    public static function run(): void
    {
        self::prune();
    }

    /**
     * Delete rows older than the retention, in batches, and record how many.
     *
     * @return int The rows deleted.
     */
    public static function prune(): int
    {
        global $wpdb;

        if (Session::is_auditor() || get_option(Log::OPTION) !== Log::SCHEMA) {
            return 0;
        }

        $cutoff = gmdate('Y-m-d H:i:s', time() - self::days() * DAY_IN_SECONDS);
        $deleted = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $removed = $wpdb->query($wpdb->prepare('DELETE FROM %i WHERE logged_at < %s LIMIT %d', Log::table(), $cutoff, self::BATCH)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

            $removed = is_int($removed) ? $removed : 0;
            $deleted += $removed;

            if ($removed < self::BATCH) {
                break;
            }
        }

        update_option(self::LAST_PRUNE, ['time' => time(), 'deleted' => $deleted], false);

        return $deleted;
    }
}
