# UAMS Auditor Role

A must-use WordPress plugin that registers a read-only `Auditor` role, so a person can review content, site structure, templates, plugins and settings on a UAMS install without Administrator or Super Admin rights.

Viewing is not the same as changing. The role holds enough capabilities to open settings screens, so a default-deny guard refuses every write the role could make, and an activity log records what each auditor opened. The design decisions are in [`docs/DECISIONS.md`](docs/DECISIONS.md); the work is tracked in this repository's issues.

Status: built and verified on a local multisite. Not yet rolled out (#23). Deploy with [`docs/DEPLOY.md`](docs/DEPLOY.md).

## How it works

The role holds `manage_options` so settings screens open, which means capabilities alone cannot keep it read-only. Four guard layers do, each default-deny:

| Layer | Where | What it refuses |
| --- | --- | --- |
| Requests | `RequestGuard` | Every non-read request (POST, PUT, PATCH, DELETE), anywhere on the site, including the REST API after a `_method` override; `admin-post.php`; `options.php`; XML-RPC sign-in; admin GETs carrying an acting `action` or a nonce outside a list table's filter form; screens that write on load (`post-new.php`, `update.php`); the network admin. |
| AJAX | `RequestGuard` | Every `admin-ajax.php` action not on a short list verified read-only in core. |
| Capabilities | `MetaCaps` | Every never-granted capability and every acting meta capability (`activate_plugin`, `delete_post`, `create_app_password`, ...), mapped to `do_not_allow`. |
| Database | `DatabaseGuard` | Every statement that is not a read, through the `query` filter, during an Auditor session. The plugin's own writes (its log, the session token, adding a configured login) pass through `DatabaseGuard::bypass()`. |

Screens whose viewing is the exposure (raw options, exports, form entries, mail and deploy settings, license keys, the Site Health info tab) are hidden from the menu and refused by URL (`Screens`).

The compensating controls: an activity log per site (`Log`), an administrator-only viewer under **Tools > Auditor log** with filters, CSV export and a retention setting (`LogViewer`, `Retention`), an optional end date per account (`Account`), and a **Read-only audit mode** badge in the admin bar (`AdminBar`).

### Filters

| Filter | Changes |
| --- | --- |
| `uamswp_auditor_role_capabilities` | The capabilities the role holds. A never-granted one is dropped. |
| `uamswp_auditor_role_denied_caps` | Adds capabilities denied to the role. |
| `uamswp_auditor_role_hidden_screens` | The hidden screens: `page:<slug>`, `page:<prefix>*` (every page slug that starts with the prefix) or `<script>.php[?param=value]`. |
| `uamswp_auditor_role_hidden_dashboard_widgets` | Dashboard widgets removed for the role. |
| `uamswp_auditor_role_activation_flags` | Adds plugin activation-redirect flags that read as unset for the role: `option:<name>` or `transient:<name>`. |
| `uamswp_auditor_role_ajax_actions` | The allowlisted AJAX actions. Add one only after reading what it does. |
| `uamswp_auditor_role_get_actions` | The `action` values an admin GET may carry. |
| `uamswp_auditor_role_allow_query` | Lets one refused statement through; see [`docs/WRITES-ON-LOAD.md`](docs/WRITES-ON-LOAD.md). |
| `uamswp_auditor_role_exempt_login_actions` | The `wp-login.php` actions that are not an Auditor session. |
| `uamswp_auditor_role_logins` | The multisite logins, after `UAMSWP_AUDITOR_ROLE_LOGINS`. |

### WP-CLI

| Command | Does |
| --- | --- |
| `wp auditor discover` | The facts an install needs before deploying: type, versions, sites, plugins, themes, administrator capabilities not in core, secret-looking option names (names only). |
| `wp auditor network-export [--file=]` | Network settings, plugins, themes and sites as JSON, every secret-bearing value masked. |
| `wp auditor sync-sites` | Adds each login in `UAMSWP_AUDITOR_ROLE_LOGINS` to every site. |
| `wp auditor refresh-role [--network]` | Rewrites the role. |
| `wp auditor log list` / `export` | Reads the log or exports it as CSV, with `--auditor`, `--from`, `--to`, `--event`, and `--all-sites` on a network. Read-only: pruning is the daily scheduled job's (`wp cron event run uamswp_auditor_role_prune` runs it now). |

Where an install's bootstrap fails under WP-CLI, `php scripts/discover-files.php <wordpress-path>` reports from files alone.

## What is in it

| Path | Purpose |
| --- | --- |
| `uamswp-auditor-role.php` | The main file: full plugin header, direct-access guard, PHP floor guard (fails closed), GPL notice. |
| `loader/` | The must-use loader stub, copied to the top level of `mu-plugins/`. Fails closed when the plugin folder is missing. |
| `includes/` | Namespaced code (`UAMSWeb\AuditorRole`) and a small autoloader, so a deployed branch needs no `vendor/`. |
| `uninstall.php` | The one place data is removed. Multisite-aware. |
| `readme.txt` | WordPress.org-format readme. |
| `languages/` | The `.pot` file. |
| `tests/Unit/` | Pest 5 and Brain Monkey: no WordPress, no database. |
| `tests/Integration/` | PHPUnit 9.6 on `wp-phpunit`, with its own `composer.json`. |
| `tests/Walk/` | The screen walk: every admin screen and AJAX action visited as an Auditor, with writes read from MySQL's binary log. |
| `scripts/` | `build-zip.mjs` (upload ZIP), `checksums.mjs` (the release checksum list), `check-download-archive.mjs` (keep-list check), `lint-floor.php` (floor lint), `discover-files.php` (the file-only discovery fallback). |
| `docs/DECISIONS.md` | The decision log. |
| `docs/DEPLOY.md` | Copy, verify by checksum, first run, removal. |
| `docs/WRITES-ON-LOAD.md` | Screens that write on load, and the decision for each. |
| `docs/OPERATIONS.md` | Offboarding an auditor, and the periodic review of the activity log. |
| `docs/WALK.md` | Running the screen walk on an install, and reading its result. |

## Develop

```bash
composer install
composer setup:integration            # the integration suite's own Composer setup
composer test:unit                    # Pest
composer test:coverage                # Pest with line coverage of includes/ (needs PCOV)
composer mutate                       # mutation-test includes/ (needs PCOV); fails on any mutant not killed
composer mutate -- --path=includes/Role.php   # one class
composer test:integration             # PHPUnit 9.6 against WordPress and a database
composer stan                         # PHPStan
composer test:pint                    # formatting (composer fix:pint to apply)
composer test:phpcs                   # WordPress security sniffs
composer test:refactor                # Rector, dry run
composer check:archive                # the download holds only what the plugin runs
LINT_PHP=/path/to/php8.2 composer lint:floor   # parse shipped files on the floor PHP
```

### The test bar

Code under `includes/` is held to **mutation-clean**, not to a line-coverage percentage. A class meets the bar when `composer mutate -- --path=<file>` leaves no unexplained mutant:

- **killed:** a unit test fails when the mutant is applied;
- **deleted:** the mutant showed dead code, and it was removed;
- **equivalent:** no input can tell the mutant apart, so the line carries `@pest-mutate-ignore` and the covering test's docblock says which mutant survives and why.

An uncovered mutant (no test reaches the line) and an untested one (a test reaches it but nothing fails) both fail the bar. Line coverage only shows what a test touched; `composer test:coverage` is for finding where to start.

Read a run before trusting it. `0 Mutations for 0 Files created` is a setup failure (a wrong `--path`, no PCOV, no covering test), never a pass, and `scripts/check-mutation-run.php <log>` fails on it. A mutant whose child process crashes is scored as killed, so a run that kills everything deserves a second look: `tests/bootstrap.php` carries the fix for the one such crash found so far, between Pest's mutation stream wrapper and Patchwork. A mutant that times out also reads as killed to Pest but fails the checker, so a test that calls back into the class under test fails on re-entry instead of recursing, and test stand-ins for the database fail on a read nobody queued instead of looping.

The screen walk's classes in `tests/Walk/src/` are held to the same bar. CI's `mutate` job mutates the PHP files under `includes/` and `tests/Walk/src/` that a pull request (or a push to `main`) adds or changes, and fails on any mutant not killed. A manual run of the workflow mutates everything, and so does the weekly scheduled run (Mondays, on `main`), which catches decay no pull request touches.

Both need a coverage driver. PCOV locally: `pecl install pcov`, or the extension Herd bundles.

### The integration suite

It boots WordPress through `wp-phpunit` and **drops and rebuilds every table in its test database**.

1. Create a database you can afford to lose: `mysql -u root -e 'CREATE DATABASE uamswp_auditor_role_tests'`.
2. Check the plugin out inside a WordPress install (`wp-content/plugins/<slug>`), or point `WP_TESTS_ABSPATH` at a core copy: `wp core download --path=/tmp/wordpress --version=7.0.8`.
3. Run `composer test:integration`. Set `WP_MULTISITE=1` to run it as a multisite. `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD` and `WP_TESTS_DB_HOST` override the defaults. The bootstrap refuses to run against the install's own database.

## Public by default, and the switch for a private plugin

A plugin made from the template is **public** and runs its checks in GitHub Actions (`.github/workflows/ci.yml`), which calls the shared chain in `UAMS-Web/uams-ci-workflows` and keeps `integration`, `mutate` and `walk` as plain jobs beside the call.

To make a plugin **private**: make the repository private, delete `.github/workflows/ci.yml`, and run the same checks with the shared local CI runner instead (the `local-ci` skill and its `local-ci.json` template, delivered by the sync). Private repositories pay for Actions minutes, so the local runner is the default there. Keep `release.yml`; it only runs when a release is published.

## Cut a release

1. Raise `Version` in the header, `UAMS_PLUGIN_TEMPLATE_VERSION` in the main file, and `Stable tag` and the changelog in `readme.txt`.
2. Merge to `main`, then publish a GitHub release whose tag is the version (`v1.2.3`).
3. `release.yml` builds `uamswp-auditor-role-1.2.3.zip` from the tag with the download keep-list, writes `uamswp-auditor-role-1.2.3.sha256` (`node scripts/checksums.mjs`), and attaches both to the release. Build one locally with `composer build` (it lands in `build/`, which is ignored).

Sites deploy a branch through WP Pusher, so nothing is built at deploy time and no `dist/` directory is committed. Runtime libraries a plugin needs are committed.

## The download keep-list

A branch download is what WP Pusher installs, and a server may serve the plugin folder for every file type but PHP. `.gitattributes` therefore excludes everything and names back only what the plugin runs. When a runtime folder is added, add a `-export-ignore` line for it; `composer check:archive` and CI fail a change that ships anything else.

## Licence

GPL-3.0-or-later. Copyright (C) 2026 University of Arkansas for Medical Sciences.
