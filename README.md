# UAMS Plugin Template

A GitHub template for a new UAMS-Web WordPress plugin. A plugin made from it passes the shared WordPress standard on its first commit: PHP 8.2 floor, Pest 5 unit suite, PHPUnit 9.6 integration suite against a real WordPress, Rector, PHPStan, Pint, WordPress security sniffs, and a download that holds only what the plugin runs.

## What is in it

| Path | Purpose |
| --- | --- |
| `uams-plugin-template.php` | The main file: full plugin header, direct-access guard, PHP floor guard, GPL notice. |
| `includes/` | Namespaced code (`UAMSWeb\PluginTemplate`) and a small autoloader, so a deployed branch needs no `vendor/`. `Settings` is a worked example of a capability check, a nonce, sanitized input and escaped output. |
| `uninstall.php` | The one place data is removed. Multisite-aware. Activation and deactivation never delete data. |
| `readme.txt` | WordPress.org-format readme. |
| `languages/` | The `.pot` file. |
| `tests/Unit/` | Pest 5 and Brain Monkey: no WordPress, no database. |
| `tests/Integration/` | PHPUnit 9.6 on `wp-phpunit`, with its own `composer.json`. |
| `scripts/` | `build-zip.mjs` (upload ZIP), `check-download-archive.mjs` (keep-list check), `lint-floor.php` (floor lint). |
| `docs/DECISIONS.md` | The decision log. |

## Create a plugin from this template

1. On GitHub, choose **Use this template**, and name the new repository for the plugin. Make it public unless it must be private (see below).
2. Rename the template's names, everywhere they appear (a case-sensitive search for each):

   | Find | Replace with | Notes |
   | --- | --- | --- |
   | `uams-plugin-template` | the plugin slug | File name `uams-plugin-template.php`, the text domain, the `.pot` file name, `composer.json` package name, `scripts/build-zip.mjs`, `.gitattributes`, `phpcs.xml.dist`, `phpstan.neon.dist`, `rector.php`, `phpunit.xml`, `tests/` |
   | `UAMSWeb\PluginTemplate` and `UAMSWeb\\PluginTemplate` | the plugin's namespace (see below) | `includes/`, `includes/autoload.php`, `tests/`, `composer.json`, `phpcs.xml.dist` |
   | `uams_plugin_template` and `UAMS_PLUGIN_TEMPLATE` | the plugin's prefix | Options, hooks, constants, the test database name |
   | `UAMS Plugin Template` | the plugin's name | Header, `readme.txt`, `.pot`, page titles |

   **The namespace.** Make it `UAMSWeb\Aether\<Product>` or `UAMSWeb\Aether\<Family>\<Product>` for a plugin in the Aether ecosystem, and `UAMSWeb\<Product>` for one outside it; the family level is optional. The prefix can be any depth, because [PSR-4](https://www.php-fig.org/psr/psr-4/) allows it, so the files under `includes/` do not move when the prefix changes. Three rules keep two packages from colliding:

   - A prefix has one owner or none. Two packages never map the same one, and no package maps a bare family prefix: `UAMSWeb\Aether\Forms\Settings\` and `UAMSWeb\Aether\Forms\Multisite\` are two packages' prefixes, and nobody maps `UAMSWeb\Aether\Forms\`.
   - A package may own a prefix with a companion nested inside it, but then the outer package has no directory under `includes/` named after the inner one. Composer picks the longest matching prefix, so an inner `UAMSWeb\People\Listings\` package is reached only if the outer `UAMSWeb\People\` package has no `includes/Listings/`.
   - The template's own placeholder is `UAMSWeb\PluginTemplate`, the shape for a plugin outside the Aether ecosystem. A plugin in the Aether ecosystem renames it to `UAMSWeb\Aether\<Product>` instead.

   The plugin loads its classes with its own small autoloader (`includes/autoload.php`), not Composer's, because a branch deploy has no `vendor/`. That changes two things. The prefix is a string in `includes/autoload.php` as well as in `composer.json`, which maps only `autoload-dev` (the tests), so change both and keep them in step. And the autoloader maps one prefix to `includes/` and does no longest-prefix matching, so the no-directory-under-`includes/` rule is the only thing that keeps an outer plugin from claiming an inner plugin's classes.

3. Update the header's `Plugin URI` and `Description`, `readme.txt`, and this README. Delete the example `Settings` screen once the plugin has its own, keeping the pattern.
4. Run `composer install` and `composer setup:integration`, then `composer test`, so the first commit is green.
5. Onboard the plugin to the shared sync, which delivers the shared skills and rules (this template is not itself a sync target). The synced copy of the archive check can replace `scripts/check-download-archive.mjs`.
6. Set the repository's visibility and checks (below), and require the `CI` check in a ruleset.

## Develop

```bash
composer install
composer setup:integration            # the integration suite's own Composer setup
composer test:unit                    # Pest
composer test:integration             # PHPUnit 9.6 against WordPress and a database
composer stan                         # PHPStan
composer test:pint                    # formatting (composer fix:pint to apply)
composer test:phpcs                   # WordPress security sniffs
composer test:refactor                # Rector, dry run
composer check:archive                # the download holds only what the plugin runs
LINT_PHP=/path/to/php8.2 composer lint:floor   # parse shipped files on the floor PHP
```

### The integration suite

It boots WordPress through `wp-phpunit` and **drops and rebuilds every table in its test database**.

1. Create a database you can afford to lose: `mysql -u root -e 'CREATE DATABASE uams_plugin_template_tests'`.
2. Check the plugin out inside a WordPress install (`wp-content/plugins/<slug>`), or point `WP_TESTS_ABSPATH` at a core copy: `wp core download --path=/tmp/wordpress --version=7.0.8`.
3. Run `composer test:integration`. Set `WP_MULTISITE=1` to run it as a multisite. `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD` and `WP_TESTS_DB_HOST` override the defaults. The bootstrap refuses to run against the install's own database.

## Public by default, and the switch for a private plugin

A plugin made from the template is **public** and runs its checks in GitHub Actions (`.github/workflows/ci.yml`).

To make a plugin **private**: make the repository private, delete `.github/workflows/ci.yml`, and run the same checks with the shared local CI runner instead (the `local-ci` skill and its `local-ci.json` template, delivered by the sync). Private repositories pay for Actions minutes, so the local runner is the default there. Keep `release.yml`; it only runs when a release is published.

## Cut a release

1. Raise `Version` in the header, `UAMS_PLUGIN_TEMPLATE_VERSION` in the main file, and `Stable tag` and the changelog in `readme.txt`.
2. Merge to `main`, then publish a GitHub release whose tag is the version (`v1.2.3`).
3. `release.yml` builds `uams-plugin-template-1.2.3.zip` from the tag with the download keep-list and attaches it to the release. Build one locally with `composer build` (it lands in `build/`, which is ignored).

Sites deploy a branch through WP Pusher, so nothing is built at deploy time and no `dist/` directory is committed. Runtime libraries a plugin needs are committed.

## The download keep-list

A branch download is what WP Pusher installs, and a server may serve the plugin folder for every file type but PHP. `.gitattributes` therefore excludes everything and names back only what the plugin runs. When a runtime folder is added, add a `-export-ignore` line for it; `composer check:archive` and CI fail a change that ships anything else.

## Licence

GPL-3.0-or-later. Copyright (C) 2026 University of Arkansas for Medical Sciences.
