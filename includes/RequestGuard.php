<?php

/**
 * The request layer of the guard: refuses every request from an Auditor session
 * that is not a verified read.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole;

use WP_Error;

defined('ABSPATH') || exit;

final class RequestGuard
{
    /**
     * The request methods that read.
     *
     * The lists here are methods, not constants, so each entry is a line a
     * test is seen to cover.
     *
     * @return list<string>
     */
    public static function read_methods(): array
    {
        return ['GET', 'HEAD', 'OPTIONS'];
    }

    /**
     * The `action` values an admin GET may carry without a nonce: opening a
     * screen, never acting on one. `-1` is a list table's empty bulk action.
     *
     * @return list<string>
     */
    public static function get_actions(): array
    {
        return ['', '-1', 'edit', 'view', 'preview'];
    }

    /**
     * The scripts where a GET may carry a nonce: core list tables, whose filter
     * form submits its bulk-action nonce with an empty action.
     *
     * @return list<string>
     */
    public static function nonce_list_scripts(): array
    {
        return ['edit.php', 'upload.php', 'edit-tags.php', 'edit-comments.php', 'plugins.php', 'themes.php'];
    }

    /**
     * The admin scripts an Auditor never opens, because loading them writes or
     * acts: a new post's auto-draft, a new upload, an update run.
     *
     * @return list<string>
     */
    public static function blocked_scripts(): array
    {
        return [
            'admin-post.php',
            'post-new.php',
            'media-new.php',
            'press-this.php',
            'update.php',
            'upgrade.php',
            'async-upload.php',
            'plugin-install.php',
            'theme-install.php',
            'user-new.php',
        ];
    }

    /**
     * The `admin-ajax.php` actions verified as read-only in core: each one only
     * queries and prints. Heartbeat's own write callbacks are removed for an
     * Auditor in remove_heartbeat_writes().
     *
     * @return list<string>
     */
    public static function ajax_defaults(): array
    {
        return [
            'heartbeat',
            'fetch-list',
            'ajax-tag-search',
            'query-attachments',
            'get-attachment',
            'find_posts',
            'menu-get-metabox',
            'menu-quick-search',
            'get-revision-diffs',
            'dashboard-widgets',
            'wp-link-ajax',
        ];
    }

    /**
     * Whether the request has passed the check as an Auditor session. Null
     * until it has; a literal default would be a line no test can cover.
     */
    private static ?bool $checked = null;

    /** Why the request was refused, or null. */
    private static ?string $refusal = null;

    /**
     * Check on `plugins_loaded` from the auth cookies, and again on `init` once
     * WordPress has set the current user, which covers any other way in.
     */
    public static function boot(): void
    {
        add_action('plugins_loaded', [self::class, 'check'], PHP_INT_MIN);
        add_action('init', [self::class, 'check'], PHP_INT_MIN);
        add_filter('rest_pre_dispatch', [self::class, 'rest_pre_dispatch'], PHP_INT_MIN, 3);
        add_filter('authenticate', [self::class, 'refuse_xmlrpc'], PHP_INT_MAX);
        add_action('admin_init', [self::class, 'remove_heartbeat_writes']);
    }

    /**
     * Refuse the request when it is not a read the role may make.
     */
    public static function check(): void
    {
        if (self::$checked === true || ! Session::is_auditor()) {
            return;
        }

        self::$checked = true;

        $reason = self::reason(Session::script(), self::method(), self::request_params(), is_admin());

        if ($reason === null) {
            return;
        }

        if ($reason === 'confirm_admin_email') {
            wp_safe_redirect(admin_url());
            exit;
        }

        self::refuse($reason);
    }

    /**
     * Why a request would be refused, or null when it may go on.
     *
     * Kept free of side effects so each branch can be tested on its own.
     *
     * @param  array<array-key, mixed>  $params  The query and body parameters.
     */
    public static function reason(string $script, string $method, array $params, bool $is_admin): ?string
    {
        $action = isset($params['action']) && is_scalar($params['action']) ? (string) $params['action'] : '';
        $reads = in_array($method, self::read_methods(), true);

        if ($script === 'xmlrpc.php') {
            return 'xmlrpc';
        }

        if ($script === 'wp-login.php') {
            if ($action === 'confirm_admin_email') {
                return 'confirm_admin_email';
            }

            return $reads || $action === 'logout' ? null : 'login-write';
        }

        if ($script === 'admin-ajax.php') {
            return in_array($action, self::ajax_actions(), true) ? null : 'ajax:'.$action;
        }

        if (isset($params['_method']) && is_scalar($params['_method']) && ! in_array(strtoupper((string) $params['_method']), self::read_methods(), true)) {
            return 'method-override';
        }

        if (! $reads) {
            return 'method:'.$method;
        }

        if (! $is_admin) {
            return null;
        }

        if (is_network_admin()) {
            return 'network-admin';
        }

        if (in_array($script, self::blocked_scripts(), true)) {
            return 'script:'.$script;
        }

        if (Screens::is_hidden($script, $params)) {
            return 'hidden:'.Screens::label($script, $params);
        }

        return self::get_reason($script, $params);
    }

    /**
     * Why an admin GET would be refused: an acting `action`, or a nonce outside
     * a list table's filter form.
     *
     * @param  array<array-key, mixed>  $params
     */
    public static function get_reason(string $script, array $params): ?string
    {
        $defaults = self::get_actions();
        $allowed = apply_filters('uamswp_auditor_role_get_actions', $defaults, $script, $params);
        $allowed = is_array($allowed) ? $allowed : $defaults;

        $actions = [];

        foreach (['action', 'action2'] as $key) {
            $value = isset($params[$key]) && is_scalar($params[$key]) ? (string) $params[$key] : '';
            $actions[] = $value;

            if (! in_array($value, $allowed, true)) {
                return 'action:'.$value;
            }
        }

        foreach (['_wpnonce', '_ajax_nonce'] as $nonce) {
            if (! isset($params[$nonce])) {
                continue;
            }

            $is_list_filter = in_array($script, self::nonce_list_scripts(), true)
                && array_diff($actions, ['', '-1']) === [];

            if (! $is_list_filter) {
                return 'nonce:'.$script;
            }
        }

        return null;
    }

    /**
     * The allowlisted AJAX actions, with a deployment's additions.
     *
     * @return list<string>
     */
    public static function ajax_actions(): array
    {
        $defaults = self::ajax_defaults();
        $actions = apply_filters('uamswp_auditor_role_ajax_actions', $defaults);

        return is_array($actions) ? array_values(array_filter($actions, is_string(...))) : $defaults;
    }

    /**
     * Refuse a REST request that is not a read, after any method override.
     *
     * @param  mixed  $server  The REST server, unused.
     * @param  mixed  $request  The request, read through its getters.
     */
    public static function rest_pre_dispatch(mixed $result, mixed $server, mixed $request): mixed
    {
        if (! is_object($request) || ! method_exists($request, 'get_method') || ! method_exists($request, 'get_route')) {
            return $result;
        }

        $method = $request->get_method();

        if (! Session::is_auditor() || in_array($method, self::read_methods(), true)) {
            return $result;
        }

        $route = $request->get_route();

        self::$refusal = 'rest:'.(is_string($method) ? $method : '').' '.(is_string($route) ? $route : '');

        return new WP_Error(
            'uamswp_auditor_role_read_only',
            __('This account is in read-only audit mode. Changes are refused and recorded.', 'uamswp-auditor-role'),
            ['status' => 403]
        );
    }

    /**
     * Refuse XML-RPC sign-in for an Auditor account.
     */
    public static function refuse_xmlrpc(mixed $user): mixed
    {
        return self::xmlrpc_refusal($user, defined('XMLRPC_REQUEST'));
    }

    /**
     * The sign-in result for a user: an error for an Auditor over XML-RPC, the
     * user otherwise. Separate from refuse_xmlrpc() so both answers can be
     * tested in one process, where a constant cannot be undefined.
     */
    public static function xmlrpc_refusal(mixed $user, bool $is_xmlrpc): mixed
    {
        if ($is_xmlrpc && Role::user_is_auditor($user)) {
            return new WP_Error('uamswp_auditor_role_xmlrpc', __('XML-RPC is turned off for Auditor accounts.', 'uamswp-auditor-role'));
        }

        return $user;
    }

    /**
     * Take autosave and post-lock refreshes out of Heartbeat for an Auditor, so
     * the allowlisted action stays a read.
     */
    public static function remove_heartbeat_writes(): void
    {
        if (! Session::is_auditor()) {
            return;
        }

        remove_filter('heartbeat_received', 'wp_refresh_post_lock', 10);
        remove_filter('heartbeat_received', 'heartbeat_autosave', 500);
        remove_filter('heartbeat_received', 'wp_refresh_metabox_loader_nonces', 10);
    }

    /**
     * Why the request was refused, or null.
     */
    public static function refusal(): ?string
    {
        return self::$refusal;
    }

    /**
     * Forget the state. Used by the tests.
     */
    public static function reset(): void
    {
        self::$checked = null;
        self::$refusal = null;
    }

    /**
     * Stop the request with a clear message in the shape its caller expects.
     */
    private static function refuse(string $reason): never
    {
        self::$refusal = $reason;

        $message = __('This account is in read-only audit mode. Changes are refused and recorded.', 'uamswp-auditor-role');

        if (wp_doing_ajax()) {
            wp_send_json_error(['code' => 'uamswp_auditor_role_read_only', 'message' => $message], 403);
        }

        if (self::is_rest_uri()) {
            wp_send_json(['code' => 'uamswp_auditor_role_read_only', 'message' => $message, 'data' => ['status' => 403]], 403);
        }

        if (str_starts_with($reason, 'hidden:')) {
            $message = __('This screen is hidden from Auditor accounts because it can show secrets or personal data.', 'uamswp-auditor-role');
        }

        wp_die(
            esc_html($message),
            esc_html__('Read-only audit mode', 'uamswp-auditor-role'),
            ['response' => 403, 'back_link' => true]
        );
    }

    /**
     * Whether the request is for the REST API. The guard runs before WordPress
     * parses the request, so the URL is read directly.
     */
    private static function is_rest_uri(): bool
    {
        if (isset($_GET['rest_route'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return true;
        }

        if (! isset($_SERVER['REQUEST_URI']) || ! is_string($_SERVER['REQUEST_URI'])) {
            return false;
        }

        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));

        return str_contains($uri, '/'.rest_get_url_prefix().'/');
    }

    private static function method(): string
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'get';

        return strtoupper($method);
    }

    /**
     * The query and body parameters, keys only trusted as routing values.
     *
     * @return array<array-key, mixed>
     */
    private static function request_params(): array
    {
        // Routing values only, compared against fixed lists; nothing is stored or printed.
        $params = array_merge(wp_unslash($_GET), wp_unslash($_POST)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        if (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) && is_string($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) && ! isset($params['_method'])) {
            $params['_method'] = sanitize_key(wp_unslash($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']));
        }

        return $params;
    }
}
