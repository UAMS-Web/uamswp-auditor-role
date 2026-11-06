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

A must-use plugin that registers a read-only `Auditor` role. A default-deny guard blocks every write the role could make: non-read requests, acting GET requests, AJAX actions not verified read-only, acting capabilities and, underneath, every database write. Screens that show secrets or personal data are hidden. An activity log records what each auditor opened and what was refused, with an administrator-only viewer, CSV export and a retention setting.

== Installation ==

1. Unzip the release into `/wp-content/mu-plugins/`, creating `mu-plugins/uamswp-auditor-role/`.
2. Copy `mu-plugins/uamswp-auditor-role/loader/uamswp-auditor-role-loader.php` to `mu-plugins/`.
3. Check the files against the release's `.sha256` list. See `docs/DEPLOY.md` in the repository.

== Changelog ==

= 0.1.0 =
* The read-only Auditor role, its guard, the activity log and the WP-CLI commands.
