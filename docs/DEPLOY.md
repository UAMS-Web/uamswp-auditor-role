# Deploy and verify

The plugin runs as a must-use plugin, so a site administrator cannot deactivate it. WordPress loads only the top level of `mu-plugins/`, so a one-file loader stub sits there and loads the plugin's folder.

```text
wp-content/mu-plugins/
├── uamswp-auditor-role-loader.php      the stub, copied from loader/
└── uamswp-auditor-role/                the plugin folder, from the release ZIP
    ├── uamswp-auditor-role.php
    ├── includes/
    └── ...
```

The stub fails closed. When the folder is missing, or the server runs PHP older than 8.2, an Auditor account holds no capability at all and administrators see a notice. The role is stored in the database, and without the guard it would open settings screens with nothing behind them.

## Copy

1. Download `uamswp-auditor-role-<version>.zip` and `uamswp-auditor-role-<version>.sha256` from the GitHub release.
2. Unzip into `wp-content/mu-plugins/`, which creates `mu-plugins/uamswp-auditor-role/`.
3. Copy `mu-plugins/uamswp-auditor-role/loader/uamswp-auditor-role-loader.php` to `mu-plugins/uamswp-auditor-role-loader.php`.

## Verify

From `wp-content/mu-plugins/`, check every file against the release's list:

```bash
shasum -a 256 -c uamswp-auditor-role-<version>.sha256      # macOS
sha256sum -c uamswp-auditor-role-<version>.sha256          # Linux
```

Every line must read `OK`. A changed or missing file prints `FAILED` and the command exits non-zero. On Windows, compare `Get-FileHash -Algorithm SHA256 <file>` for each line by hand or with a short PowerShell loop.

Then confirm the plugin loaded:

```bash
wp mu-plugin list
wp role list | grep auditor        # appears after the first request on each site
```

## First run on an install

1. Run `wp auditor discover > discovery.json` and keep the result out of this repository. It lists the plugins, the administrator capabilities plugins added, and option names that look secret-bearing.
2. Add the plugin capabilities an audit needs to see, through the `uamswp_auditor_role_capabilities` filter, in a small must-use file of the install's own. A plugin's custom post types stay hidden from the role until their `edit_*` capability is added.
3. Hide any further screen that shows a secret, through `uamswp_auditor_role_hidden_screens`.
4. On a multisite network, set the logins in `wp-config.php` and add them to the existing sites:

   ```php
   define('UAMSWP_AUDITOR_ROLE_LOGINS', ['jdoe', 'asmith']);
   ```

   ```bash
   wp auditor sync-sites
   ```

   New sites get each login when they are created, and a site a login visits gets it on the visit.

## Remove

A must-use plugin is never uninstalled from the Plugins screen. To end one auditor's access instead, follow [`OPERATIONS.md`](OPERATIONS.md#offboarding-an-auditor). To remove the plugin:

1. Export the log: `wp auditor log export --all-sites --file=auditor-log.csv` on a network, or without `--all-sites` on a single site.
2. Delete the role on each site, while the guard is still loaded: `wp role delete auditor`, with `--url=` per site on a multisite network. Accounts that held only the role are left with none.
3. Only then delete the stub and the folder from `mu-plugins/`.

The order matters. With the files gone and the role still in the database, an Auditor account holds `manage_options` with no guard and no fail-closed stub behind it.
