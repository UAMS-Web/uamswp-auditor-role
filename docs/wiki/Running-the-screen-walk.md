Written against v0.3.0.

The screen walk is the evidence that the guard holds against a real plugin set. It signs in as an Auditor, visits every admin menu slug and every hidden script screen of every site and every `admin-ajax.php` action, and fails on any database write other than the plugin's own activity log. Run it on a copy of an install before the plugin goes to that install's servers. The full procedure and the report format are in [`docs/WALK.md`](https://github.com/UAMS-Web/uamswp-auditor-role/blob/main/docs/WALK.md).

The walk reads writes from MySQL's binary log, not from WordPress, so a write that passes the guard by any path still shows. Before it walks, it writes a row into a scratch schema (`uamswp_walk_probe`) and fails unless the log shows that write.

## What you need

- A copy of the install with the plugin deployed through its stub ([Deploying the plugin](Deploying-the-plugin.md)).
- MySQL with the binary log on in row format (the default from MySQL 8.0), and a database user with `REPLICATION CLIENT`, `REPLICATION SLAVE` and `CREATE`.
- WP-CLI, PHP with `curl` and `mysqli`, and this repository checked out with `composer install` run.

## Steps

1. **Run nothing else against the database while it walks.** The walk charges every write in a request's window to that request, so a WP-CLI command or another visitor writing at the same time reads as that screen's write.
2. **Turn WP-Cron off** in `wp-config.php`: `define('DISABLE_WP_CRON', true);`.
3. **Create an Auditor account** with a password, on every site:

   ```bash
   wp user create walker walker@example.test --role=auditor --user_pass=<password>
   wp --exec="define('UAMSWP_AUDITOR_ROLE_LOGINS', 'walker');" auditor sync-sites   # multisite only
   ```

4. **Run the walk** from the repository's root:

   ```bash
   WALK_PASSWORD=<password> php tests/Walk/walk.php \
     --wp-root=<path to WordPress> --admin=<a super admin's login> \
     --auditor=walker --password-env=WALK_PASSWORD \
     --db-host=<database host> --db-user=<database user> --db-name=<the site's database> \
     --report=walk.md
   ```

   Add `--login-path=<path>` when the sign-in page is not `/wp-login.php`, and `--db-password-env=<variable>` when the database user has a password.
5. **Read the result.** The command exits 0 on a pass, 1 on an unexpected write or on visiting nothing, and 2 when it could not run. The report opens with **Passed** or **Failed** and lists each unexpected write as the target, the table and how the log recorded it.

**Note:** keep the reports of a particular install out of the repository.

## Reference

- [Troubleshooting](Troubleshooting.md#the-screen-walk-reports-a-write-on-a-screen): reading a failure.
- [How the guard works](How-the-guard-works.md)
- [`docs/WRITES-ON-LOAD.md`](https://github.com/UAMS-Web/uamswp-auditor-role/blob/main/docs/WRITES-ON-LOAD.md): the decision for each write found so far.
