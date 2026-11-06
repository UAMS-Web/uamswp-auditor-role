# Offboarding and log review

An Auditor account has no end date unless one is set, so ending an auditor's access is a deliberate step, and the activity log is a control only if someone reads it. This document covers both. Who reviews the log is each deployment's decision.

Commands are WP-CLI, run from the WordPress root. On a multisite network, a command without `--url` acts on the main site; add `--url=<site URL>` to act on another. Replace `<login>` with the auditor's login. To remove the plugin itself rather than one auditor, see [Remove in `DEPLOY.md`](DEPLOY.md#remove).

## Offboarding an auditor

Run the steps in this order. The lock comes first so the account can do nothing while the rest happens. The export comes before the removal, because `--auditor` looks the account up and the account is gone after the removal. The viewer's **Auditor** filter still lists a removed auditor, from the rows the log holds.

### 1. Lock the account now

Set the account's end date to yesterday in the site's time zone. The plugin refuses a new sign-in after the end date and ends a live session on its next request.

```bash
wp user meta update <login> uamswp_auditor_role_end_date "$(wp eval 'echo wp_date("Y-m-d", time() - DAY_IN_SECONDS);')"
```

Compute the date with `wp_date()` as above, not with the shell's `date`: the end date is read in the site's time zone and the account works through the whole of that day, so a date taken from a server clock in UTC can be the site's today and leave the account open. Check it took:

```bash
wp eval '$u = get_user_by("login", "<login>"); var_dump(UAMSWeb\AuditorRole\Account::is_expired($u->ID));'   # bool(true)
```

On a multisite network the end date is one value for the account on every site.

### 2. Stop the network adding the login back (multisite only)

Remove the login from `UAMSWP_AUDITOR_ROLE_LOGINS` in `wp-config.php`. While it is listed, the plugin adds the account to every site it visits and to every new site, with the Auditor role.

### 3. Revoke every session

```bash
wp user session destroy <login> --all
wp user session list <login> --format=count   # 0
```

Sessions belong to the account, not to a site, so one command covers a whole network.

### 4. Export the auditor's log rows

Export before the retention job prunes them (365 days by default; see [Retention](#retention)).

- **In the admin, per site:** open **Tools > Auditor log**, choose the auditor under **Auditor**, select **Filter**, then **Export these rows as CSV**. The file holds only that auditor's rows on that site. On a multisite network, repeat it on each site the auditor used.
- **With WP-CLI, every site at once:**

  ```bash
  wp auditor log export --auditor=<login> --all-sites --file=auditor-log-<login>.csv   # without --all-sites on a single site
  ```

  The file holds only that auditor's rows. Use `--auditor`, never `--user`: WP-CLI reads `--user` as its own global option, the user the command runs as, so it never reaches the command and the export would hold every auditor's rows. Before v0.2.1 the command named the option `--user`, so on an older version export without it and keep only the `<login>` rows by the `user_login` column.

Store the export with the audit's records, outside this repository.

### 5. Remove the account

- **Single site:** `wp user delete <login> --yes`. An auditor cannot create content, so there is nothing to reassign.
- **Multisite, every site and the account:** `wp user delete <login> --network --yes`.
- **Multisite, one site only:** `wp user delete <login> --url=<site URL> --yes` removes the account from that site and keeps it on the others.

To keep the account but end its audit access instead, take the role off it on each site: `wp user remove-role <login> auditor`, with `--url=` per site on a network. Steps 1 and 3 already stop it signing in.

### 6. Check

```bash
wp user get <login>                                    # Error: Invalid user ID, email or login (after a deletion)
wp auditor log list --format=csv | grep -c '<login>'   # the rows are still there, under the login they were written with
```

The log keeps the rows after the account is gone, under the login and user ID they were written with, until the retention job prunes them.

## Reviewing the log

### Where to read it

- **The viewer:** **Tools > Auditor log** on each site, for administrators only (an Auditor never sees it). Filter by **Auditor**, a **From** and **To** date and an **Event**; times are UTC. **Export these rows as CSV** downloads what the filters select.
- **WP-CLI:** `wp auditor log list` prints the newest rows on a site, and `--all-sites` covers a network.

  ```bash
  wp auditor log list --event=refused --from=2026-11-01 --all-sites --format=table
  wp auditor log list --auditor=<login> --all-sites --format=table
  wp auditor log list --limit=500 --format=csv > recent.csv
  ```

  `--auditor=<login or ID>`, `--from`, `--to` and `--event` filter as the viewer does.
- **The export:** the viewer's CSV button, or `wp auditor log export [--all-sites] [--file=<path>]` for every row the filters select. A cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return is prefixed with `'`, so a spreadsheet shows it as text.

### What each event means

| Event | Written when |
| --- | --- |
| `view` | An Auditor opened a page, and nothing was refused. |
| `refused` | The guard refused the request: a save, an acting link, an AJAX action not on the list, a REST write, a hidden screen. `detail` names the reason, and any database writes refused with it. |
| `db_refused` | The page loaded, but it tried to write to the database and the guard refused the write. `detail` names each statement's verb and table, and the option for an option write. |
| `login` | An Auditor signed in. |
| `logout` | An Auditor signed out. |
| `login_refused` | A sign-in, or a live session, refused because the account's end date had passed. |

### What to look for

- **`refused` rows.** Each is an attempt to change something. A few around a save button are an auditor finding out the mode is read-only; repeated attempts on the same screen, or attempts on screens the audit does not need, are worth asking about. A `hidden:` reason means a direct visit to a screen kept from auditors.
- **New `db_refused` statements.** A plugin that writes when a page loads shows up here. Compare each new option or table with [`WRITES-ON-LOAD.md`](WRITES-ON-LOAD.md), and record a decision for any new one there.
- **`login_refused` rows.** An expired account still trying to sign in.
- **`login` rows from unexpected accounts or at unexpected times**, such as an account whose audit has ended but whose end date was never set.
- **Activity on a site the audit does not cover**, on a multisite network.
- **Gaps.** Audit work that leaves no `view` rows means the work happened somewhere else, or the plugin was not loaded. `wp mu-plugin list` should show the loader.

### Cadence

- **During an active audit:** weekly.
- **Between audits, while Auditor accounts exist:** monthly.
- **At offboarding:** once more, over the auditor's whole engagement, before step 5.

Each review should run well inside the retention period, so no row is pruned before someone has read it.

### Retention

The log keeps rows for 365 days by default. An administrator changes it on **Tools > Auditor log**, under **Retention** (1 to 3,650 days). A daily scheduled job deletes older rows: on a multisite network it runs from the main site and prunes each site by that site's own setting. To run it now:

```bash
wp cron event run uamswp_auditor_role_prune
```

The screen shows when the job last ran and how many rows it deleted.
