=== UAMS Auditor Role ===
Contributors: uams
Tags: audit, roles, read-only
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Adds a read-only Auditor role so a person can review a WordPress install without Administrator rights.

== Description ==

A must-use plugin that registers a read-only `Auditor` role. A default-deny guard blocks every write the role could make, and an activity log records what each auditor opened.

== Installation ==

1. Deploy the plugin folder to `/wp-content/plugins/`, or upload the release ZIP on the Plugins screen.
2. Activate the plugin on the Plugins screen. On a multisite network, activate it for the network or for a single site.

== Changelog ==

= 0.1.0 =
* First version.
