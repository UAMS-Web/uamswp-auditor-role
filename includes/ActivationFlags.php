<?php

/**
 * Keeps a plugin's post-activation redirect from catching an Auditor.
 *
 * A plugin that sends the next administrator to a welcome or setup screen after
 * activation keeps a flag (an option or a transient), checks a capability the
 * role holds, and clears the flag before it redirects. An Auditor's clear is
 * refused, so without this the redirect would repeat on every admin screen until
 * an administrator loaded one. Each listed flag reads as unset in an Auditor
 * session; nothing is written, so the next administrator is still redirected.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

defined('ABSPATH') || exit;

final class ActivationFlags
{
    /** The filter a deployment adds flags through, as `option:<name>` or `transient:<name>`. */
    public const FILTER = 'uamswp_auditor_role_activation_flags';

    /**
     * The flags read as unset by default. A method, not a constant, so each
     * entry is a line a test is seen to cover.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        return [
            // SEOPress: redirects a user who can `manage_options` to its setup
            // wizard while this option is `yes` (seopress_redirect_after_activation()).
            'option:seopress_activated',
            // WP Mail SMTP: redirects a user who can `activate_plugins` (an Auditor on
            // the Plugins screen) to its setup wizard while this transient is set.
            'transient:wp_mail_smtp_activation_redirect',
        ];
    }

    public static function boot(): void
    {
        add_action('init', [self::class, 'register'], PHP_INT_MAX);
    }

    /**
     * Filter each flag's read. Run late on `init`, once every plugin has had
     * the chance to add to the list, and before `admin_init`, where activation
     * redirects run.
     */
    public static function register(): void
    {
        foreach (self::flags() as $flag) {
            [$kind, $name] = explode(':', $flag, 2);
            add_filter($kind.'_'.$name, [self::class, 'read'], PHP_INT_MAX);
        }
    }

    /**
     * The defaults and a deployment's additions, each once. A filter can add to
     * the list but not drop a default, and an entry not of the form
     * `option:<name>` or `transient:<name>` is ignored.
     *
     * @return list<string>
     */
    public static function flags(): array
    {
        $defaults = self::defaults();
        $added = apply_filters('uamswp_auditor_role_activation_flags', $defaults);
        $added = is_array($added) ? array_filter($added, self::is_flag(...)) : [];

        return array_values(array_unique(array_merge($defaults, $added)));
    }

    /**
     * A flag's value as an Auditor reads it: `false`, as if it were not set.
     */
    public static function read(mixed $value): mixed
    {
        return Session::is_auditor() ? false : $value;
    }

    /**
     * Whether an entry names an option or a transient.
     */
    private static function is_flag(mixed $entry): bool
    {
        return is_string($entry) && preg_match('/^(option|transient):\S+$/', $entry) === 1;
    }
}
