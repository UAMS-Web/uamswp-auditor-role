# Screen walk

The screen walk is the evidence that the guard holds against a real plugin set. It signs in as an Auditor, visits every admin menu slug of every site and every `admin-ajax.php` action, and fails on any database write other than the plugin's own activity log. Run it on a copy of an install before the plugin goes to that install's servers.

## How it sees writes

The walk reads MySQL's binary log, not WordPress. For each request it notes the log's position, makes the request, waits briefly for writes a response leaves behind, and lists every table written since. So a write that passes the guard by any path still shows: a plugin's own database connection, a request a page sets off, or code that runs before the guard is armed.

Before it walks, it proves the instrument works. It checks that the binary log is on in row format, then writes a row into a scratch schema (`uamswp_walk_probe`) and fails unless the log shows that write. A clean result means something only after that check passes.

## What it visits

- **Every admin menu slug on every site**, top-level and submenu, as a super admin sees them, so screens an Auditor cannot open are visited too and must still write nothing. A slug that names a script opens it (`edit.php?post_type=page`); any other slug opens as `admin.php?page=<slug>`.
- **Every admin-ajax action once**, on the first site that registers it: each `wp_ajax_` hook registered when the admin loads, plus core's own action lists in `wp-admin/admin-ajax.php`. Each is sent as a POST with no nonce.

`tests/Walk/enumerate.php` builds each site's list; `tests/Walk/walk.php` runs everything.

## Run it on an install

You need a copy of the install with the plugin deployed through its stub ([`DEPLOY.md`](DEPLOY.md)), MySQL with the binary log on in row format (the default from MySQL 8.0), a database user with `REPLICATION CLIENT` (to read the log's position), `REPLICATION SLAVE` (to read its events) and `CREATE` (for the scratch schema), as `root` has, WP-CLI, and PHP with the `curl` and `mysqli` extensions.

1. **Run nothing else against the database while it walks.** The walk charges every write in a request's window to that request, so a WP-CLI command, a cron run or another visitor writing at the same time reads as that screen's write. On the first local run, WP-CLI commands run against the same database during the walk showed as 24 writes on five screens in a 15-second span; each screen was clean when visited again alone.
2. **Turn WP-Cron off** in `wp-config.php`: `define('DISABLE_WP_CRON', true);`. A cron run that a visit sets off writes as an anonymous request, and the walk would charge it to that visit.
3. **Create an Auditor account** on every site, with a password: `wp user create walker walker@example.test --role=auditor --user_pass=<password>`, then add it to every site of a network with `wp --exec="define('UAMSWP_AUDITOR_ROLE_LOGINS', 'walker');" auditor sync-sites`.
4. **Run the walk** from this repository's root, after `composer install`:

   ```bash
   WALK_PASSWORD=<password> php tests/Walk/walk.php \
     --wp-root=<path to WordPress> --admin=<a super admin's login> \
     --auditor=walker --password-env=WALK_PASSWORD \
     --db-host=127.0.0.1 --db-user=root --db-name=<the site's database> \
     --report=walk.md
   ```

   Options: `--login-path` if the sign-in page is not `/wp-login.php` (for example `/uams-login/` behind WPS Hide Login), `--db-password-env` names an environment variable holding the database password, `--insecure` skips TLS verification for a local certificate, `--settle-ms` sets the wait after each response (default 200), `--limit` stops after that many targets, and `--wp` names the WP-CLI command if it is not `wp`.
5. **Read the report.** It opens with **Passed** or **Failed** and the counts, lists each unexpected write as the target, the table and how the log recorded it, and counts each HTTP status. The file named by `--report` then lists every target in the order visited, with its status, where a redirect sent it, its unexpected writes, and what the plugin did with it: `hidden` (the hidden-screen message), `refused` (the read-only refusal, on a screen or as an AJAX or REST error) or `none` (the plugin let WordPress answer, which includes WordPress refusing the screen itself). The list counts the same targets as the walk's `Inventoried` line, unless `--limit` cut the run short. The console prints the summary only. The command exits 0 on a pass, 1 on an unexpected write or on visiting nothing, and 2 when it could not run.

Expect it to take a while on a large network: about 2.6 seconds a request on the local uamshealth.com copy.

The outcome is read from the English text the plugin sends. On a site that translates the plugin, a hidden or refused screen reads as `none`, while an AJAX or REST refusal still reads as `refused` by its error code; the status column still shows the 403.

## Reading a failure

A row in the report means a request by the Auditor, or something it set off, changed that table, or that something else wrote while it ran. Visit the target again alone first: if it is clean then, the write was not the screen's. To find the row, decode the binary log over the walk's time with `mysqlbinlog --base64-output=DECODE-ROWS -v <log file>`, or re-run with `--limit` around the target and decode just that span.

Decide each one as [`WRITES-ON-LOAD.md`](WRITES-ON-LOAD.md) describes. A write the guard should have refused is a defect in the guard: fix it, and walk again. A write the deployment accepts is allowed with the `uamswp_auditor_role_allow_query` filter and recorded in `WRITES-ON-LOAD.md`. Results for a particular install stay out of this repository.

## In CI

CI's **Screen walk (multisite)** job builds a two-site subdirectory network on the WordPress version the integration suite runs, installs and network-activates the public plugins in `tests/Walk/plugins.txt`, deploys this plugin through its stub, serves the network with PHP's built-in server and `tests/Walk/router.php`, and walks it. It then installs `tests/Walk/fixtures/planted-write.php`, a must-use plugin that writes through its own connection on every admin request by an Auditor, walks again, and fails unless that walk fails and names the write. The control proves the job can fail. The report, with every target, goes to the job summary and to the run's `screen-walk-report` artifact.

Add a plugin to `tests/Walk/plugins.txt` to walk it in CI; it must support the WordPress version the job installs.
