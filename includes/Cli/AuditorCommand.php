<?php

/**
 * WP-CLI: read-only reports and the multisite login sync for the Auditor role.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Cli;

use UAMSWeb\AuditorRole\Network;
use UAMSWeb\AuditorRole\Report;
use UAMSWeb\AuditorRole\Role;
use WP_CLI;

defined('ABSPATH') || exit;

final class AuditorCommand
{
    /**
     * Print what an install needs known before the role is deployed.
     *
     * Install type, versions, sites, plugins, themes, administrator capabilities
     * not in core, and option names that look secret-bearing. Names only, never
     * values. Keep the output out of the plugin repository.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : json or yaml.
     * ---
     * default: json
     * options:
     *   - json
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp auditor discover > discovery.json
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $assoc
     */
    public function discover(array $args, array $assoc): void
    {
        $this->print(Report::discovery(), $assoc['format'] ?? 'json');
    }

    /**
     * Export network settings, plugins, themes and sites with secrets masked.
     *
     * Network admin screens stay closed to the Auditor, so network-wide
     * settings reach a review only through this export.
     *
     * ## OPTIONS
     *
     * [--file=<path>]
     * : Write to this file instead of standard output.
     *
     * ## EXAMPLES
     *
     *     wp auditor network-export --file=network.json
     *
     * @subcommand network-export
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $assoc
     */
    public function network_export(array $args, array $assoc): void
    {
        $json = (string) wp_json_encode(Report::network_export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (isset($assoc['file'])) {
            if (file_put_contents($assoc['file'], $json."\n") === false) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                WP_CLI::error('Could not write '.$assoc['file']);
            }

            WP_CLI::success('Wrote '.$assoc['file']);

            return;
        }

        WP_CLI::line($json);
    }

    /**
     * Add each login in UAMSWP_AUDITOR_ROLE_LOGINS to every site with the Auditor role.
     *
     * The fallback for sites that existed before the logins were configured.
     * New sites are covered on creation and visited sites on the visit.
     *
     * @subcommand sync-sites
     */
    public function sync_sites(): void
    {
        if (! is_multisite()) {
            WP_CLI::error('This is not a multisite network.');
        }
        if (Network::logins() === []) {
            WP_CLI::error('UAMSWP_AUDITOR_ROLE_LOGINS names no logins.');
        }
        foreach (Network::sync_all() as $login => $count) {
            WP_CLI::log(sprintf('%s: added to %d site(s).', $login, $count));
        }
        WP_CLI::success('Done.');
    }

    /**
     * Rewrite the Auditor role on the current site, or on every site with --network.
     *
     * ## OPTIONS
     *
     * [--network]
     * : Every site of the network.
     *
     * @subcommand refresh-role
     *
     * @param  list<string>  $args
     * @param  array<string, string>  $assoc
     */
    public function refresh_role(array $args, array $assoc): void
    {
        $ids = is_multisite() && isset($assoc['network'])
            ? Report::site_ids()
            : [get_current_blog_id()];

        foreach ($ids as $id) {
            if (is_multisite()) {
                switch_to_blog($id);
            }

            Role::register();

            if (is_multisite()) {
                restore_current_blog();
            }
        }

        WP_CLI::success(sprintf('Rewrote the role on %d site(s).', count($ids)));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function print(array $data, string $format): void
    {
        if ($format === 'yaml') {
            WP_CLI::print_value($data, ['format' => 'yaml']);

            return;
        }

        WP_CLI::line((string) wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
