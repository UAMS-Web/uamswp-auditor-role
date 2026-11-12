=== UAMS Auditor Role ===
Contributors: uams
Tags: audit, roles, read-only
Requires at least: 6.8
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.3.0
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

= 0.3.0 =
* Added: more screens are hidden from an Auditor: Settings > Connectors, the Customizer, the Site Editor and Fonts, Comments, ACF, Better Image Sizes, Regenerate Thumbnails, and the settings of Ajax Search Pro, Edit Flow, SEOPress, Genesis, UAMS Settings, WP Accessibility, Media Library Assistant, and the SEOPress and WP Mail SMTP setup wizards. A hidden-screen entry may end in `*` to match every page slug with that prefix.
* Added: an Auditor can open the Plugins screen as a read-only list, network-active plugins included, view drafts of every post type on the front end, open list-screen previews and post-title links, and read the Find-a-Doc provider and location lists.
* Added: the `uamswp_auditor_role_activation_flags` filter, for plugin activation-redirect flags that read as unset for the role.
* Changed: the admin bar drops the "+ New", FacetWP Cache and comment-count menus for an Auditor.
* Fixed: a plugin's post-activation redirect no longer repeats on every screen for an Auditor, who cannot clear its flag. The `activate_plugins` check passes for the role only on a site's Plugins screen, and SEOPress's and WP Mail SMTP's flags read as unset.
* Fixed: ShortPixel's background processor no longer runs in an Auditor session.
* The screen walk records every target with its status, outcome and redirect, walks the hidden screens that have no menu item, and uploads its report from CI.

= 0.2.2 =
* Security: while regular plugins load, before a login cookie can be validated, a cookie that names an Auditor of the site now refuses database writes. A plugin that wrote as it loaded passed before, and the request's later checks treated it as no Auditor session until WordPress set the current user.
* Added: the screen walk (`tests/Walk/`), which visits every admin screen and AJAX action as an Auditor and fails on any write, read from MySQL's binary log. See `docs/WALK.md`.
* Fixed: the deploy and operations docs list must-use plugins with `wp plugin list --status=must-use`.

= 0.2.1 =
* Fixed: `wp auditor log list` and `export` filter by auditor with `--auditor=<login or ID>`. The option was named `--user`, which WP-CLI reads as its own global option, so it never reached the command and every auditor's rows were returned.

= 0.2.0 =
* Fixed: saving an Auditor's end date from the profile screen failed its nonce check, because the nonce and the date shared a field name.
* Fixed: the discovery report and the network export no longer list `[false]` when a plugin or theme option is not an array.
* Changed: the activity log writes one row per request: `refused`, `db_refused` or `view`.
* Changed: `wp auditor log` is read-only (`list` and `export`, with `--all-sites`); `wp auditor log prune` is removed, and the daily job prunes every site of a network from the main site.
* Added: the `uamswp_auditor_role_logins` filter over `UAMSWP_AUDITOR_ROLE_LOGINS`.
* Every class meets the mutation-clean bar, with mutation testing in CI.

= 0.1.0 =
* The read-only Auditor role, its guard, the activity log and the WP-CLI commands.
