Written against v0.3.0.

At the end of this walkthrough the plugin runs as a must-use plugin on the install, its files are checked against the release, and an Auditor account can sign in and see the **Read-only audit mode** badge in the admin bar. The full procedure, including removal, is [`docs/DEPLOY.md`](https://github.com/UAMS-Web/uamswp-auditor-role/blob/main/docs/DEPLOY.md).

You need WordPress 6.8 or newer, PHP 8.2 or newer, WP-CLI, and write access to `wp-content/mu-plugins/`.

**Note:** run the [screen walk](Running-the-screen-walk.md) on a copy of the install first. It is the evidence that the guard holds against that install's own plugins.

## Steps

1. **Download the release.** From [Releases](https://github.com/UAMS-Web/uamswp-auditor-role/releases), take `uamswp-auditor-role-<version>.zip` and `uamswp-auditor-role-<version>.sha256`.
2. **Unzip it into `wp-content/mu-plugins/`.** You now have `mu-plugins/uamswp-auditor-role/`.
3. **Copy the loader stub to the top level.** WordPress loads only the top level of `mu-plugins/`, so copy `mu-plugins/uamswp-auditor-role/loader/uamswp-auditor-role-loader.php` to `mu-plugins/uamswp-auditor-role-loader.php`.
4. **Check every file.** From `wp-content/mu-plugins/`, run `shasum -a 256 -c uamswp-auditor-role-<version>.sha256` on macOS or `sha256sum -c uamswp-auditor-role-<version>.sha256` on Linux. Every line reads `OK`; a changed or missing file prints `FAILED`.
5. **Confirm it loaded.** `wp plugin list --status=must-use` lists `uamswp-auditor-role-loader`, and `wp role list` lists `auditor` after the first request on each site.
6. **Record what the install has.** Run `wp auditor discover > discovery.json` and keep the file out of the repository. It lists the plugins, the administrator capabilities plugins added, and option names that look secret-bearing (names only).
7. **Fit the role to this install.** In a small must-use file of the install's own, add the plugin capabilities the audit needs through [`uamswp_auditor_role_capabilities`](Filters.md), and hide any further screen that shows a secret through [`uamswp_auditor_role_hidden_screens`](Filters.md). A plugin's custom post types stay hidden from the role until their `edit_*` capability is added.
8. **Create the first account.** On a single site, `wp user create <login> <email> --role=auditor`. On a multisite network, create the user with the role on the main site first (`wp user create <login> <email> --role=auditor`), then list the login in `wp-config.php` and add it to every other existing site:

   ```php
   define('UAMSWP_AUDITOR_ROLE_LOGINS', ['<login>']);
   ```

   ```bash
   wp auditor sync-sites
   ```

   New sites get each login when they are created. `sync-sites` skips a login that has no user, and a site where the account is already a member keeps the role it has there, so check the count it reports for each login.
9. **Optionally set an end date.** An administrator sets it on the account's **Edit User** screen. The account works through the whole of that day in the site's time zone; after it, the account cannot sign in and a live session ends on its next request.
10. **Sign in as the auditor.** The admin bar shows **Read-only audit mode**, and a save on any screen answers *This account is in read-only audit mode. Changes are refused and recorded.*

**Warning:** the plugin fails closed. If its folder is missing, or the server runs PHP older than 8.2, Auditor accounts hold no capabilities and administrators see a notice. See [Troubleshooting](Troubleshooting.md#an-administrator-notice-says-auditor-accounts-hold-no-capabilities).

## Next

- [Reviewing the activity log](Reviewing-the-activity-log.md)
- [Offboarding an auditor](Offboarding-an-auditor.md)
- [How the guard works](How-the-guard-works.md)
