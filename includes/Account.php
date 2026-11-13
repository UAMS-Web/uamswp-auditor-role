<?php

/**
 * The Auditor's own account: an optional end date, no application passwords,
 * and no change to its own credentials from the profile screen.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

use WP_Error;
use WP_User;

defined('ABSPATH') || exit;

final class Account
{
    /** The user meta holding the optional end date, as Y-m-d in the site's time zone. */
    public const END_DATE = 'uamswp_auditor_role_end_date';

    /**
     * The nonce action and field for the end date on another user's profile.
     * Not the date field's own name: the two shared one, the date overwrote the
     * nonce in the submitted form, and the profile save failed its nonce check.
     */
    public const NONCE = 'uamswp_auditor_role_end_date_nonce';

    public static function boot(): void
    {
        add_filter('authenticate', [self::class, 'refuse_expired_login'], 99);
        add_action('init', [self::class, 'end_expired_session'], 0);
        add_filter('wp_is_application_passwords_available_for_user', [self::class, 'application_passwords'], 10, 2);
        add_filter('show_password_fields', [self::class, 'show_password_fields'], 10, 2);
        add_action('wp_login', [self::class, 'skip_admin_email_check'], 0, 2);
        add_action('show_user_profile', [self::class, 'render_own_notice']);
        add_action('edit_user_profile', [self::class, 'render_end_date_field']);
        add_action('edit_user_profile_update', [self::class, 'save_end_date']);
    }

    /**
     * The end date on an account, or an empty string for none.
     */
    public static function end_date(int $user_id): string
    {
        $date = get_user_meta($user_id, self::END_DATE, true);

        return is_string($date) ? self::clean_date($date) : '';
    }

    /**
     * A date in Y-m-d form that exists on the calendar, or an empty string.
     */
    public static function clean_date(string $date): string
    {
        $date = trim($date);

        if (preg_match('/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/', $date, $m) !== 1) {
            return '';
        }

        return checkdate((int) $m['month'], (int) $m['day'], (int) $m['year']) ? $date : '';
    }

    /**
     * Whether an end date has passed. The account works through the whole of
     * the end date, in the site's time zone, and an empty date never passes.
     */
    public static function has_ended(string $end_date, string $today): bool
    {
        return $end_date !== '' && $today > $end_date;
    }

    /**
     * Whether an account's access has ended.
     */
    public static function is_expired(int $user_id): bool
    {
        return self::has_ended(self::end_date($user_id), (string) wp_date('Y-m-d'));
    }

    /**
     * Refuse a sign-in by an Auditor account whose end date has passed.
     */
    public static function refuse_expired_login(mixed $user): mixed
    {
        // user_is_auditor() is true only for a WP_User.
        if (! Role::user_is_auditor($user)) {
            return $user;
        }

        /** @var WP_User $user */
        if (! self::is_expired($user->ID)) {
            return $user;
        }

        Log::write('login_refused', $user->ID, $user->user_login, 'end date '.self::end_date($user->ID));

        return new WP_Error('uamswp_auditor_role_expired', __('This Auditor account has reached its end date.', 'uamswp-auditor-role'));
    }

    /**
     * End a session that outlived its account's end date.
     */
    public static function end_expired_session(): void
    {
        if (! Session::is_auditor() || ! self::is_expired(get_current_user_id())) {
            return;
        }

        $user = wp_get_current_user();
        Log::write('login_refused', $user->ID, $user->user_login, 'session ended at end date');

        wp_destroy_all_sessions();
        wp_clear_auth_cookie();

        wp_die(
            esc_html__('This Auditor account has reached its end date.', 'uamswp-auditor-role'),
            esc_html__('Read-only audit mode', 'uamswp-auditor-role'),
            ['response' => 403]
        );
    }

    /**
     * No application passwords for an Auditor.
     */
    public static function application_passwords(mixed $available, mixed $user): bool
    {
        return Role::user_is_auditor($user) ? false : (bool) $available;
    }

    /**
     * No password fields on an Auditor's profile: a reset by email is the only path.
     */
    public static function show_password_fields(mixed $show, mixed $user): bool
    {
        return Role::user_is_auditor($user) ? false : (bool) $show;
    }

    /**
     * Skip the admin email confirmation screen after an Auditor signs in. The
     * role holds `manage_options`, which would otherwise show it.
     */
    public static function skip_admin_email_check(mixed $login, mixed $user): void
    {
        if (Role::user_is_auditor($user)) {
            add_filter('admin_email_check_interval', '__return_zero');
        }
    }

    /**
     * On the Auditor's own profile, say why it cannot be changed.
     */
    public static function render_own_notice(mixed $user): void
    {
        if (! Role::user_is_auditor($user)) {
            return;
        }

        /** @var WP_User $user */
        $end = self::end_date($user->ID);
        ?>
        <h2><?php esc_html_e('Read-only audit mode', 'uamswp-auditor-role'); ?></h2>
        <p><?php esc_html_e('This profile cannot be changed. To set a new password, use "Lost your password?" on the sign-in screen.', 'uamswp-auditor-role'); ?></p>
        <p>
            <?php
            echo $end === ''
                ? esc_html__('This account has no end date.', 'uamswp-auditor-role')
                /* translators: %s: date */
                : esc_html(sprintf(__('This account works through %s.', 'uamswp-auditor-role'), $end));
        ?>
        </p>
        <?php
    }

    /**
     * On an Auditor's profile seen by someone who can manage the account, the end date field.
     */
    public static function render_end_date_field(mixed $user): void
    {
        if (! Role::user_is_auditor($user)) {
            return;
        }

        /** @var WP_User $user */
        if (! current_user_can('promote_user', $user->ID)) {
            return;
        }

        wp_nonce_field(self::NONCE, self::NONCE);
        ?>
        <h2><?php esc_html_e('Auditor access', 'uamswp-auditor-role'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="uamswp-auditor-role-end-date"><?php esc_html_e('End date', 'uamswp-auditor-role'); ?></label></th>
                <td>
                    <input type="date" id="uamswp-auditor-role-end-date" name="<?php echo esc_attr(self::END_DATE); ?>" value="<?php echo esc_attr(self::end_date($user->ID)); ?>">
                    <p class="description"><?php esc_html_e('Optional. The account can sign in through this date, in the site time zone, and is refused after it. Leave empty for no end date.', 'uamswp-auditor-role'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save the end date: capability first, then the nonce, then the clean date.
     */
    public static function save_end_date(mixed $user_id): void
    {
        $user_id = is_numeric($user_id) ? (int) $user_id : 0;

        if (! Role::user_is_auditor(get_userdata($user_id)) || ! current_user_can('promote_user', $user_id) || ! isset($_POST[self::NONCE])) {
            return;
        }

        check_admin_referer(self::NONCE, self::NONCE);

        $date = isset($_POST[self::END_DATE]) && is_string($_POST[self::END_DATE]) ? self::clean_date(sanitize_text_field(wp_unslash($_POST[self::END_DATE]))) : '';

        if ($date === '') {
            delete_user_meta($user_id, self::END_DATE);
        } else {
            update_user_meta($user_id, self::END_DATE, $date);
        }
    }
}
