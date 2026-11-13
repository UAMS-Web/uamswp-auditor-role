<?php

/**
 * The administrator-only screen under Tools: the activity log with filters,
 * a CSV export and the retention setting.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class LogViewer
{
    /** The menu and page slug. */
    public const PAGE = 'uamswp-auditor-log';

    /** The admin-post action and nonce action for the CSV export. */
    public const EXPORT = 'uamswp_auditor_role_export_log';

    /** The admin-post action and nonce action for the retention setting. */
    public const RETENTION = 'uamswp_auditor_role_save_retention';

    /** The nonce field name. */
    public const NONCE = 'uamswp_auditor_role_nonce';

    /**
     * Sends one response header. Null in production, which calls header(); the
     * tests set a recorder, because PHP's CLI records no header.
     */
    public static ?\Closure $send_header = null;

    /**
     * Ends the request after the CSV stream or the redirect. Null in
     * production, which exits; the tests set a callable that throws.
     */
    public static ?\Closure $terminate = null;

    /**
     * Rows per page. A method, not a constant, so the value is a line a test is
     * seen to cover.
     */
    public static function per_page(): int
    {
        return 50;
    }

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'register_page']);
        add_action('admin_post_'.self::EXPORT, [self::class, 'handle_export']);
        add_action('admin_post_'.self::RETENTION, [self::class, 'handle_retention']);
    }

    /**
     * Add the page under Tools, for the log capability that an Auditor never holds.
     */
    public static function register_page(): void
    {
        add_management_page(
            __('Auditor activity log', 'uamswp-auditor-role'),
            __('Auditor log', 'uamswp-auditor-role'),
            MetaCaps::VIEW_LOG,
            self::PAGE,
            [self::class, 'render_page']
        );
    }

    /**
     * Print the screen. Every dynamic value is escaped where it is printed.
     */
    public static function render_page(): void
    {
        if (! current_user_can(MetaCaps::VIEW_LOG)) {
            wp_die(esc_html__('You do not have permission to view this page.', 'uamswp-auditor-role'), 403);
        }

        // Filters for a read-only list; each is validated against a fixed shape in LogQuery::filters().
        $filters = LogQuery::filters(wp_unslash($_GET)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $paged = isset($_GET['paged']) && is_scalar($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $total = LogQuery::count($filters);
        $rows = LogQuery::rows($filters, self::per_page(), ($paged - 1) * self::per_page());
        $pages = (int) ceil($total / self::per_page());
        $last_prune = get_option(Retention::LAST_PRUNE);
        $saved = isset($_GET['retention-saved']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <?php if ($saved) { ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Retention saved.', 'uamswp-auditor-role'); ?></p></div>
            <?php } ?>
            <p><?php esc_html_e('Each page an Auditor account opened on this site, each request the read-only guard refused, and each sign-in and sign-out. Times are UTC.', 'uamswp-auditor-role'); ?></p>

            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
                <label for="uamswp-auditor-log-user"><?php esc_html_e('Auditor', 'uamswp-auditor-role'); ?></label>
                <select id="uamswp-auditor-log-user" name="user_id">
                    <option value="0"><?php esc_html_e('All', 'uamswp-auditor-role'); ?></option>
                    <?php foreach (LogQuery::users() as $id => $login) { ?>
                        <option value="<?php echo absint($id); ?>" <?php selected($filters['user_id'], $id); ?>><?php echo esc_html($login); ?></option>
                    <?php } ?>
                </select>
                <label for="uamswp-auditor-log-from"><?php esc_html_e('From', 'uamswp-auditor-role'); ?></label>
                <input type="date" id="uamswp-auditor-log-from" name="from" value="<?php echo esc_attr($filters['from']); ?>">
                <label for="uamswp-auditor-log-to"><?php esc_html_e('To', 'uamswp-auditor-role'); ?></label>
                <input type="date" id="uamswp-auditor-log-to" name="to" value="<?php echo esc_attr($filters['to']); ?>">
                <label for="uamswp-auditor-log-event"><?php esc_html_e('Event', 'uamswp-auditor-role'); ?></label>
                <select id="uamswp-auditor-log-event" name="event">
                    <option value=""><?php esc_html_e('All', 'uamswp-auditor-role'); ?></option>
                    <?php foreach (Log::events() as $event) { ?>
                        <option value="<?php echo esc_attr($event); ?>" <?php selected($filters['event'], $event); ?>><?php echo esc_html($event); ?></option>
                    <?php } ?>
                </select>
                <?php submit_button(__('Filter', 'uamswp-auditor-role'), 'secondary', '', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::EXPORT); ?>">
                <?php wp_nonce_field(self::EXPORT, self::NONCE); ?>
                <input type="hidden" name="user_id" value="<?php echo absint($filters['user_id']); ?>">
                <input type="hidden" name="from" value="<?php echo esc_attr($filters['from']); ?>">
                <input type="hidden" name="to" value="<?php echo esc_attr($filters['to']); ?>">
                <input type="hidden" name="event" value="<?php echo esc_attr($filters['event']); ?>">
                <?php submit_button(__('Export these rows as CSV', 'uamswp-auditor-role'), 'secondary', '', false); ?>
            </form>

            <p>
                <?php
                /* translators: %s: number of rows */
                echo esc_html(sprintf(_n('%s row', '%s rows', $total, 'uamswp-auditor-role'), number_format_i18n($total)));
        ?>
            </p>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Time (UTC)', 'uamswp-auditor-role'); ?></th>
                        <th><?php esc_html_e('Auditor', 'uamswp-auditor-role'); ?></th>
                        <th><?php esc_html_e('Event', 'uamswp-auditor-role'); ?></th>
                        <th><?php esc_html_e('Method', 'uamswp-auditor-role'); ?></th>
                        <th><?php esc_html_e('Screen', 'uamswp-auditor-role'); ?></th>
                        <th><?php esc_html_e('URL', 'uamswp-auditor-role'); ?></th>
                        <th><?php esc_html_e('Detail', 'uamswp-auditor-role'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($rows === []) { ?>
                        <tr><td colspan="7"><?php esc_html_e('No rows match.', 'uamswp-auditor-role'); ?></td></tr>
                    <?php } ?>
                    <?php foreach ($rows as $row) { ?>
                        <tr>
                            <td><?php echo esc_html($row['logged_at']); ?></td>
                            <td><?php echo esc_html($row['user_login']); ?></td>
                            <td><?php echo esc_html($row['event']); ?></td>
                            <td><?php echo esc_html($row['method']); ?></td>
                            <td><?php echo esc_html($row['screen']); ?></td>
                            <td style="word-break:break-all"><?php echo esc_html($row['url']); ?></td>
                            <td style="word-break:break-word"><?php echo esc_html($row['detail']); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>

            <?php if ($pages > 1) { ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                echo wp_kses_post(paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => $paged,
                    'total' => $pages,
                ]));
                ?>
                </div></div>
            <?php } ?>

            <h2><?php esc_html_e('Retention', 'uamswp-auditor-role'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::RETENTION); ?>">
                <?php wp_nonce_field(self::RETENTION, self::NONCE); ?>
                <p>
                    <label for="uamswp-auditor-log-retention"><?php esc_html_e('Keep rows for this many days', 'uamswp-auditor-role'); ?></label>
                    <input type="number" id="uamswp-auditor-log-retention" name="retention_days" min="<?php echo absint(Retention::min_days()); ?>" max="<?php echo absint(Retention::max_days()); ?>" value="<?php echo absint(Retention::days()); ?>" class="small-text">
                </p>
                <p class="description">
                    <?php
                if (is_array($last_prune) && isset($last_prune['time'], $last_prune['deleted']) && is_numeric($last_prune['time']) && is_numeric($last_prune['deleted'])) {
                    echo esc_html(sprintf(
                        /* translators: 1: date and time, 2: number of rows */
                        __('A daily job deletes older rows. Last run: %1$s, %2$s rows deleted.', 'uamswp-auditor-role'),
                        wp_date('Y-m-d H:i', (int) $last_prune['time']),
                        number_format_i18n((int) $last_prune['deleted'])
                    ));
                } else {
                    esc_html_e('A daily job deletes older rows. It has not run yet.', 'uamswp-auditor-role');
                }
        ?>
                </p>
                <?php submit_button(__('Save retention', 'uamswp-auditor-role')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Stream the filtered rows as CSV: capability first, then the nonce.
     */
    public static function handle_export(): void
    {
        if (! current_user_can(MetaCaps::VIEW_LOG)) {
            wp_die(esc_html__('You do not have permission to do that.', 'uamswp-auditor-role'), 403);
        }

        check_admin_referer(self::EXPORT, self::NONCE);

        // The nonce was verified above; each filter is validated in LogQuery::filters().
        $filters = LogQuery::filters(wp_unslash($_POST)); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing

        nocache_headers();
        self::send_header('Content-Type: text/csv; charset=utf-8');
        self::send_header('Content-Disposition: attachment; filename="auditor-log-'.gmdate('Y-m-d').'.csv"');

        $out = fopen('php://output', 'w');

        // Left open: the request ends right after the stream, which closes it.
        if (is_resource($out)) {
            LogQuery::write_csv($out, $filters);
        }

        self::terminate();
    }

    /**
     * Save the retention: capability first, then the nonce, then the clamped value.
     */
    public static function handle_retention(): void
    {
        if (! current_user_can(MetaCaps::VIEW_LOG)) {
            wp_die(esc_html__('You do not have permission to do that.', 'uamswp-auditor-role'), 403);
        }

        check_admin_referer(self::RETENTION, self::NONCE);

        // The nonce was verified above.
        $days = isset($_POST['retention_days']) && is_string($_POST['retention_days']) ? sanitize_text_field(wp_unslash($_POST['retention_days'])) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        update_option(Retention::OPTION, Retention::clamp($days), false);

        wp_safe_redirect(add_query_arg(['page' => self::PAGE, 'retention-saved' => '1'], admin_url('tools.php')));
        self::terminate();
    }

    /**
     * Send one response header.
     */
    private static function send_header(string $header): void
    {
        (self::$send_header ?? header(...))($header);
    }

    /**
     * End the request.
     */
    private static function terminate(): never
    {
        (self::$terminate ?? static function (): never {
            exit;
        })();

        exit;
    }
}
