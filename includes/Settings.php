<?php

/**
 * The example settings page: one text option, saved through admin-post.
 *
 * It exists to show the Security APIs in use, each covered by a test:
 * a capability check, a nonce, input sanitized on the way in, and output
 * escaped on the way out. Replace it with the plugin's own screens.
 *
 * @see https://developer.wordpress.org/apis/security/
 */

declare(strict_types=1);

namespace UAMSWeb\PluginTemplate;

defined('ABSPATH') || exit;

final class Settings
{
    /** The admin-post action and the nonce action. */
    public const ACTION = 'uams_plugin_template_save';

    /** The nonce field name. */
    public const NONCE = 'uams_plugin_template_nonce';

    /** The capability that may change the setting. */
    public const CAPABILITY = 'manage_options';

    /** The option that holds the greeting. */
    public const OPTION = 'uams_plugin_template_greeting';

    /** The menu and page slug. */
    public const PAGE = 'uams-plugin-template';

    /**
     * Add the page under Settings.
     */
    public static function register_page(): void
    {
        add_options_page(
            __('UAMS Plugin Template', 'uams-plugin-template'),
            __('UAMS Plugin Template', 'uams-plugin-template'),
            self::CAPABILITY,
            self::PAGE,
            [self::class, 'render_page']
        );
    }

    /**
     * Register the option with its sanitize callback.
     */
    public static function register_setting(): void
    {
        register_setting('uams_plugin_template', self::OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => '',
        ]);
    }

    /**
     * Clean a submitted value: plain text, no markup, no stray whitespace.
     */
    public static function sanitize(mixed $value): string
    {
        return is_string($value) ? sanitize_text_field($value) : '';
    }

    /**
     * Print the page. Every dynamic value is escaped where it is printed.
     */
    public static function render_page(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to view this page.', 'uams-plugin-template'), 403);
        }

        $greeting = get_option(self::OPTION, '');
        $greeting = is_string($greeting) ? $greeting : '';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <?php wp_nonce_field(self::ACTION, self::NONCE); ?>
                <p>
                    <label for="uams-plugin-template-greeting"><?php esc_html_e('Greeting', 'uams-plugin-template'); ?></label>
                    <input type="text" id="uams-plugin-template-greeting" name="greeting" class="regular-text" value="<?php echo esc_attr($greeting); ?>">
                </p>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Save the form: capability first, then the nonce, then the sanitized value.
     */
    public static function handle_save(): void
    {
        if (! current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to do that.', 'uams-plugin-template'), 403);
        }

        check_admin_referer(self::ACTION, self::NONCE);

        // The nonce was verified by check_admin_referer() above.
        $submitted = isset($_POST['greeting']) ? sanitize_text_field(wp_unslash($_POST['greeting'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        update_option(self::OPTION, $submitted);

        wp_safe_redirect(add_query_arg('page', self::PAGE, admin_url('options-general.php')));
        exit;
    }
}
