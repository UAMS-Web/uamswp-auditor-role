Written against v0.3.0.

The activity log is a control only if someone reads it. Who reviews it is each deployment's decision; this page says where to read it and what to look for. The full guidance is [Reviewing the log in `docs/OPERATIONS.md`](https://github.com/UAMS-Web/uamswp-auditor-role/blob/main/docs/OPERATIONS.md#reviewing-the-log).

## Where to read it

- **In the admin:** **Tools > Auditor log** on each site, for administrators only. Filter by **Auditor**, **From**, **To** and **Event** (times are UTC), and **Export these rows as CSV** downloads what the filters select.
- **With WP-CLI:** `wp auditor log list`, with `--auditor`, `--from`, `--to`, `--event`, and `--all-sites` on a network. For example:

  ```bash
  wp auditor log list --event=refused --from=<YYYY-MM-DD> --all-sites --format=table
  ```

## Events

| Event | Written when |
| --- | --- |
| `view` | An Auditor opened a page, and nothing was refused. |
| `refused` | The guard refused the request: a save, an acting link, an AJAX action not on the list, a REST write, a hidden screen. `detail` names the reason. |
| `db_refused` | The page loaded, but it tried to write to the database and the guard refused the write. `detail` names each statement's verb and table. |
| `login` | An Auditor signed in. |
| `logout` | An Auditor signed out. |
| `login_refused` | A sign-in, or a live session, refused because the account's end date had passed. |

## What to look for

- **`refused` rows.** A few around a save button are an auditor finding out the mode is read-only. Repeated attempts on one screen, or on screens the audit does not need, are worth asking about. A `hidden:` reason is a direct visit to a screen kept from auditors.
- **New `db_refused` statements.** A plugin that writes when a page loads. Decide each new one as [`docs/WRITES-ON-LOAD.md`](https://github.com/UAMS-Web/uamswp-auditor-role/blob/main/docs/WRITES-ON-LOAD.md) describes.
- **`login_refused` rows:** an expired account still trying to sign in.
- **`login` rows from unexpected accounts or at unexpected times**, such as an account whose audit ended without an end date.
- **Gaps.** Audit work that leaves no `view` rows means the plugin may not be loaded: `wp plugin list --status=must-use` should list `uamswp-auditor-role-loader`.

## How often

- Weekly during an active audit.
- Monthly between audits, while Auditor accounts exist.
- Once more at offboarding, over the auditor's whole engagement.

Each review should run well inside the retention period. Rows are kept for 365 days by default; an administrator changes it under **Retention** on **Tools > Auditor log** (1 to 3,650 days), and a daily job prunes older rows. `wp cron event run uamswp_auditor_role_prune` runs the job now.

## Reference

- [WP-CLI commands](WP-CLI-commands.md): `wp auditor log list` and `export`.
- [Offboarding an auditor](Offboarding-an-auditor.md)
