<?php

/**
 * #20 and #21: the network export and the discovery report, against WordPress
 * and a database, on a single site and on a multisite network.
 */

declare(strict_types=1);

namespace UAMSWeb\AuditorRole\Tests\Integration;

use UAMSWeb\AuditorRole\Report;
use UAMSWeb\AuditorRole\Secrets;
use UAMSWeb\AuditorRole\Tests\Support\IntegrationTestCase;

final class ReportTest extends IntegrationTestCase
{
    private const PLANTED = 'planted-secret-value-6d1f0b';

    public function test_the_discovery_report_lists_every_item_and_no_option_value(): void
    {
        update_option('uamswp_g4_api_key', self::PLANTED);
        update_option('uamswp_g4_label', self::PLANTED.'-label');
        $admin = get_role('administrator');
        $this->assertNotNull($admin);
        $admin->add_cap('uamswp_g4_view_reports');

        if (is_multisite()) {
            self::factory()->blog->create();
            update_site_option('uamswp_g4_network_token', self::PLANTED.'-network');
        }

        $report = Report::discovery();
        $json = (string) wp_json_encode($report);

        foreach (['generated_at', 'type', 'versions', 'sites', 'network_active_plugins', 'installed_plugins', 'must_use_plugins', 'dropins', 'themes', 'administrator_caps_not_in_core', 'secret_looking_option_names', 'secret_looking_network_option_names'] as $key) {
            $this->assertArrayHasKey($key, $report);
        }

        $this->assertSame(is_multisite() ? (is_subdomain_install() ? 'multisite-subdomain' : 'multisite-subdirectory') : 'single', $report['type']);
        $this->assertSame($GLOBALS['wp_version'], $report['versions']['wordpress']);
        $this->assertCount(is_multisite() ? count(get_sites(['number' => 0])) : 1, $report['sites']);
        $this->assertContains('uamswp_g4_view_reports', $report['administrator_caps_not_in_core']);
        $this->assertNotContains('manage_options', $report['administrator_caps_not_in_core']);
        $this->assertContains('uamswp_g4_api_key', $report['secret_looking_option_names']);
        $this->assertNotContains('uamswp_g4_label', $report['secret_looking_option_names']);
        $this->assertNotEmpty($report['themes']);

        if (is_multisite()) {
            $this->assertContains('uamswp_g4_network_token', $report['secret_looking_network_option_names']);
        }

        $this->assertStringNotContainsString(self::PLANTED, $json);

        $admin->remove_cap('uamswp_g4_view_reports');
    }

    public function test_the_network_export_lists_settings_plugins_themes_and_sites_with_secrets_masked(): void
    {
        if (! is_multisite()) {
            $this->markTestSkipped('Multisite only.');
        }

        self::factory()->blog->create();
        update_site_option('uamswp_g4_api_key', self::PLANTED);
        update_site_option('uamswp_g4_settings', ['host' => 'smtp.example.org', 'password' => self::PLANTED.'-pass', 'nested' => ['token' => self::PLANTED.'-token']]);
        update_site_option('uamswp_g4_form', 'action=save&connection_info=https%3A%2F%2Fx%0A'.self::PLANTED);
        update_site_option('active_sitewide_plugins', ['uamswp-g4/uamswp-g4.php' => time()]);

        $export = Report::network_export();
        $json = (string) wp_json_encode($export);

        $this->assertTrue($export['multisite']);
        $this->assertSame(Secrets::MASK, $export['network_settings']['uamswp_g4_api_key']);
        $this->assertSame(['host' => 'smtp.example.org', 'password' => Secrets::MASK, 'nested' => ['token' => Secrets::MASK]], $export['network_settings']['uamswp_g4_settings']);
        $this->assertSame(Secrets::MASK, $export['network_settings']['uamswp_g4_form']);
        $this->assertContains('uamswp-g4/uamswp-g4.php', $export['network_active_plugins']);
        $this->assertNotEmpty($export['themes']);
        $this->assertCount(count(get_sites(['number' => 0])), $export['sites']);
        $this->assertStringNotContainsString(self::PLANTED, $json);
    }

    public function test_the_export_reads_only(): void
    {
        global $wpdb;

        $writes = 0;
        $count = static function (string $query) use (&$writes): string {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE)\b/i', $query) === 1) {
                $writes++;
            }

            return $query;
        };

        add_filter('query', $count);
        Report::network_export();
        Report::discovery();
        remove_filter('query', $count);

        $this->assertSame(0, $writes);
        $this->assertNotEmpty($wpdb->last_query);
    }
}
