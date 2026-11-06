<?php

/**
 * A small in-memory WordPress install for the Unit suite: per-site options,
 * roles and plugins that follow switch_to_blog(), and a `$wpdb` that answers
 * the option-name queries. Shared by the tests of Report and of the WP-CLI
 * command that prints it.
 */

declare(strict_types=1);

use Brain\Monkey\Functions;

require_once __DIR__.'/wordpress-site.php';

/** Core's administrator capabilities, as populate_roles() grants them. */
const UAMSWP_AUDITOR_ROLE_G4_CORE_CAPS = [
    'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins', 'edit_users', 'edit_files',
    'manage_options', 'moderate_comments', 'manage_categories', 'manage_links', 'upload_files', 'import',
    'unfiltered_html', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts',
    'edit_pages', 'read', 'level_10', 'level_9', 'level_8', 'level_7', 'level_6', 'level_5', 'level_4',
    'level_3', 'level_2', 'level_1', 'level_0', 'edit_others_pages', 'edit_published_pages',
    'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_posts',
    'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_private_posts',
    'read_private_posts', 'delete_private_pages', 'edit_private_pages', 'read_private_pages',
    'delete_users', 'create_users', 'unfiltered_upload', 'edit_dashboard', 'update_plugins',
    'delete_plugins', 'install_plugins', 'update_themes', 'install_themes', 'update_core', 'list_users',
    'remove_users', 'promote_users', 'edit_theme_options', 'delete_themes', 'export',
];

/**
 * Reset the fake install's state and create the fixture file Report requires.
 */
function uamswp_auditor_role_g4_report_setup(): void
{
    // The core file Report requires for get_plugins(), which the tests mock: an
    // empty fixture under the Unit suite's ABSPATH, which is not a WordPress install.
    $admin = ABSPATH.'wp-admin/includes';

    if (! is_dir($admin)) {
        mkdir($admin, 0777, true);
    }

    if (! is_file($admin.'/plugin.php')) {
        file_put_contents($admin.'/plugin.php', "<?php\n");
    }

    if (! defined('UAMSWP_AUDITOR_ROLE_VERSION')) {
        define('UAMSWP_AUDITOR_ROLE_VERSION', '0.1.0');
    }

    $GLOBALS['wp_version'] = '6.8.8';
    $GLOBALS['uamswp_auditor_role_g4_site'] = 1;
    $GLOBALS['uamswp_auditor_role_g4_switches'] = [];
}

/**
 * Clear the fake install's globals.
 */
function uamswp_auditor_role_g4_report_teardown(): void
{
    unset($GLOBALS['wpdb'], $GLOBALS['wp_version'], $GLOBALS['uamswp_auditor_role_g4_site'], $GLOBALS['uamswp_auditor_role_g4_switches']);
}

/**
 * The fake install: two sites, or one.
 *
 * @param  array<int, list<mixed>|null>|null  $option_names  Option names per site, for the secret scan.
 * @param  list<mixed>|null  $network_names  Network option names, or null for a failed query.
 */
function uamswp_auditor_role_g4_install(bool $multisite, bool $subdomain = false, ?array $option_names = null, ?array $network_names = []): void
{
    $sites = [
        1 => [
            'home' => 'https://example.org/',
            'theme' => 'uams-2020',
            'options' => ['blogname' => 'Main', 'blog_public' => '1', 'active_plugins' => [3 => 'gravityforms/gravityforms.php', 7 => 'acf/acf.php']],
            'caps' => [...array_fill_keys(UAMSWP_AUDITOR_ROLE_G4_CORE_CAPS, true), 'gform_full_access' => true, 'edit_physicians' => true, 'disabled_cap' => false],
            'details' => ['registered' => '2020-01-01 00:00:00', 'last_updated' => '2026-11-01 00:00:00', 'public' => '1', 'archived' => '0', 'deleted' => '0', 'spam' => '0'],
        ],
        2 => [
            'home' => 'https://example.org/two/',
            'theme' => 'genesis',
            'options' => ['blogname' => 'Two', 'blog_public' => '0', 'active_plugins' => false],
            'caps' => null,
            'details' => ['registered' => '2021-02-02 00:00:00', 'last_updated' => '2026-10-01 00:00:00', 'public' => '0', 'archived' => '1', 'deleted' => '1', 'spam' => '1'],
        ],
    ];

    if (! $multisite) {
        unset($sites[2]);
    }

    $option_names ??= [1 => ['blogname', 'wp_mail_smtp', 'zz_api_key'], 2 => ['facetwp_license', 'blogname', 'wp_mail_smtp']];
    $current = static fn (): int => $GLOBALS['uamswp_auditor_role_g4_site'];

    Functions\when('is_multisite')->justReturn($multisite);
    Functions\when('is_subdomain_install')->justReturn($subdomain);
    Functions\when('get_current_blog_id')->alias($current);
    Functions\when('get_sites')->alias(static function (array $args) use ($sites): array {
        expect($args)->toBe(['number' => 0]);

        return array_map(static fn (int $id): object => (object) ['blog_id' => (string) $id], array_keys($sites));
    });
    Functions\when('switch_to_blog')->alias(static function (int $id): bool {
        $GLOBALS['uamswp_auditor_role_g4_switches'][] = $id;
        $GLOBALS['uamswp_auditor_role_g4_site'] = $id;

        return true;
    });
    Functions\when('restore_current_blog')->alias(static function (): bool {
        $GLOBALS['uamswp_auditor_role_g4_switches'][] = 'restore';
        $GLOBALS['uamswp_auditor_role_g4_site'] = 1;

        return true;
    });
    Functions\when('home_url')->alias(static fn (string $path): string => $sites[$current()]['home']);
    Functions\when('get_stylesheet')->alias(static fn (): string => $sites[$current()]['theme']);
    Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $sites[$current()]['options'][$name] ?? $default);
    Functions\when('get_role')->alias(static function (string $role) use ($sites, $current): ?object {
        expect($role)->toBe('administrator');
        $caps = $sites[$current()]['caps'];

        return $caps === null ? null : (object) ['capabilities' => $caps];
    });
    Functions\when('get_site')->alias(static fn (int $id): object => (object) $sites[$id]['details']);
    Functions\when('get_site_option')->alias(static fn (string $name, mixed $default = false): mixed => [
        'active_sitewide_plugins' => ['uamswp-pusher/pusher.php' => 1700000000],
        'allowedthemes' => ['uams-2020' => true, 'genesis' => true],
        'site_name' => 'UAMS Network',
        'wppusher_token' => 'ghp_abcdefghijklmnop',
        'smtp' => ['host' => 'smtp.example.org', 'password' => 'hunter2'],
        'menu_items' => ['plugins' => '1'],
    ][$name] ?? $default);
    Functions\when('get_super_admins')->justReturn([2 => 'root', 5 => 'ops']);
    Functions\when('get_current_network_id')->justReturn(1);
    Functions\when('get_plugins')->justReturn(['gravityforms/gravityforms.php' => ['Version' => '2.9.1'], 'acf/acf.php' => ['Version' => 6], 'hello.php' => []]);
    Functions\when('get_mu_plugins')->justReturn(['uamswp-auditor-role-loader.php' => []]);
    Functions\when('get_dropins')->justReturn(['sunrise.php' => []]);
    Functions\when('wp_get_themes')->justReturn(['uams-2020' => new stdClass, 'genesis' => new stdClass]);

    $GLOBALS['wpdb'] = new class($option_names, $network_names, $current)
    {
        public string $options = 'wp_options';

        public string $sitemeta = 'wp_sitemeta';

        /** @var list<string> */
        public array $prepared = [];

        /**
         * @param  array<int, list<mixed>|null>  $option_names
         * @param  list<mixed>|null  $network_names
         */
        public function __construct(private array $option_names, private readonly ?array $network_names, private readonly Closure $current) {}

        public function db_version(): string
        {
            return '8.0.36';
        }

        public function prepare(string $query, mixed ...$args): string
        {
            $this->prepared[] = $query.' | '.implode(', ', array_map(strval(...), $args));

            return $query;
        }

        /**
         * @return list<mixed>|null
         */
        public function get_col(string $query): ?array
        {
            return str_contains($query, 'option_name') ? $this->option_names[($this->current)()] : $this->network_names;
        }
    };
}
