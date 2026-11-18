Written against v0.3.0.

An Auditor account has no end date unless one is set, so ending an auditor's access is a deliberate step. The order matters: the account is locked first so it can do nothing while the rest happens, and its log rows are exported before the account is removed, because the export looks the account up by login. Every command and its check are in [Offboarding an auditor in `docs/OPERATIONS.md`](https://github.com/UAMS-Web/uamswp-auditor-role/blob/main/docs/OPERATIONS.md#offboarding-an-auditor).

Commands are WP-CLI, run from the WordPress root. Replace `<login>` with the auditor's login; on a multisite network, add `--url=<site URL>` to act on a site other than the main one.

## Steps

1. **Lock the account.** Set its end date to yesterday in the site's time zone, computed with `wp_date()` rather than the shell's `date`:

   ```bash
   wp user meta update <login> uamswp_auditor_role_end_date "$(wp eval 'echo wp_date("Y-m-d", time() - DAY_IN_SECONDS);')"
   ```

2. **On a multisite network, remove the login from `UAMSWP_AUDITOR_ROLE_LOGINS`** in `wp-config.php`. While it is listed, the plugin adds the account back to every site it visits.
3. **Revoke every session:** `wp user session destroy <login> --all`. One command covers a whole network.
4. **Export the auditor's rows** before the retention job prunes them:

   ```bash
   wp auditor log export --auditor=<login> --all-sites --file=auditor-log-<login>.csv
   ```

   Leave out `--all-sites` on a single site. Use `--auditor`, never `--user` (see [Troubleshooting](Troubleshooting.md#an-export-for-one-auditor-holds-every-auditors-rows)). Store the file with the audit's records.
5. **Remove the account:** `wp user delete <login> --yes` on a single site, or `wp user delete <login> --network --yes` across a network. To keep the account and end only its audit access, run `wp user remove-role <login> auditor` on each site instead.
6. **Check:** `wp user get <login>` reports the user is gone, and the log still holds the rows under the login they were written with.

## Reference

- [WP-CLI commands](WP-CLI-commands.md): `wp auditor log export` and its options.
- [Reviewing the activity log](Reviewing-the-activity-log.md): the review to run over the auditor's whole engagement before step 5.
