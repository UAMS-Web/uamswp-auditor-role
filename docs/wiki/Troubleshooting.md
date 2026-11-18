Written against v0.3.0.

- [An administrator notice says Auditor accounts hold no capabilities](#an-administrator-notice-says-auditor-accounts-hold-no-capabilities)
- [An auditor cannot see a plugin's posts](#an-auditor-cannot-see-a-plugins-posts)
- [A screen answers "This screen is hidden from Auditor accounts"](#a-screen-answers-this-screen-is-hidden-from-auditor-accounts)
- [A dashboard widget or screen fails with a critical error for an auditor](#a-dashboard-widget-or-screen-fails-with-a-critical-error-for-an-auditor)
- [The log fills with db_refused rows from one plugin](#the-log-fills-with-db_refused-rows-from-one-plugin)
- [An export for one auditor holds every auditor's rows](#an-export-for-one-auditor-holds-every-auditors-rows)
- [An auditor can still sign in after the audit ended](#an-auditor-can-still-sign-in-after-the-audit-ended)
- [The screen walk reports a write on a screen](#the-screen-walk-reports-a-write-on-a-screen)

## An administrator notice says Auditor accounts hold no capabilities

The notice reads *UAMS Auditor Role: the loader in mu-plugins/ cannot find mu-plugins/uamswp-auditor-role/. Auditor accounts hold no capabilities until the folder is restored.*, or *UAMS Auditor Role requires PHP 8.2 or newer and has not loaded. Auditor accounts hold no capabilities until it loads.*

**Cause:** the plugin fails closed. The role is stored in the database and holds `manage_options`, so without the guard it would open settings screens with nothing behind them; when the plugin cannot load, the loader stub (or, on old PHP, the plugin's own floor guard) takes every capability from Auditor accounts instead.

**Fix:** restore `mu-plugins/uamswp-auditor-role/` from the release and check it against the checksum list ([Deploying the plugin](Deploying-the-plugin.md)), or move the site to PHP 8.2 or newer.

## An auditor cannot see a plugin's posts

The plugin's menu item or post list is missing for the auditor, though an administrator sees it.

**Cause:** a custom post type is shown only to accounts holding its `edit_*` capability, and the role holds only the capabilities it is given.

**Fix:** add the capability through `uamswp_auditor_role_capabilities`, as in [Filters](Filters.md#example-let-the-role-see-a-plugins-post-type). The list then opens and every save is still refused.

## A screen answers "This screen is hidden from Auditor accounts"

The full text is *This screen is hidden from Auditor accounts because it can show secrets or personal data.*, with a `403`, and the log records a `refused` row with a `hidden:` reason.

**Cause:** the screen is on the hidden list (raw options, exports, form entries, mail and deploy settings, license keys, the Site Health info tab, and any a deployment added). This is the intended behavior.

**Fix:** none for the auditor. A deployment that decides the screen is safe to show removes it from the list through `uamswp_auditor_role_hidden_screens` and records why.

## A dashboard widget or screen fails with a critical error for an auditor

**Cause:** the code writes to the database on an ordinary page load and does not handle the refused write. The Quick Draft widget was one: its refused `INSERT` into `posts` made it fail.

**Fix:** find the write in the `db_refused` rows of **Tools > Auditor log**, then decide it as [`docs/WRITES-ON-LOAD.md`](https://github.com/UAMS-Web/uamswp-auditor-role/blob/main/docs/WRITES-ON-LOAD.md) describes. For a widget, removing it for the role through `uamswp_auditor_role_hidden_dashboard_widgets` is usually right.

## The log fills with db_refused rows from one plugin

**Cause:** the plugin refreshes a cache, a license check or a setting on every page load. A transient is the usual case; the page still renders and the value is computed again on the next load.

**Fix:** a refused cache write is harmless and can stay refused. A write the deployment decides is safe can be allowed with `uamswp_auditor_role_allow_query`, matched as narrowly as possible ([Filters](Filters.md#allowing-a-refused-write)), and recorded in `docs/WRITES-ON-LOAD.md`.

## An export for one auditor holds every auditor's rows

**Cause:** the command was given `--user=<login>`. WP-CLI reads `--user` as its own global option, the user the command runs as, so it never reaches the command. Before v0.2.1 the command named the option `--user`.

**Fix:** use `--auditor=<login>`. On a version older than v0.2.1, export without it and keep only the rows whose `user_login` is the auditor's.

## An auditor can still sign in after the audit ended

**Cause:** an Auditor account has no end date unless one is set, and an end date set to today leaves the account working through the whole of today in the site's time zone. On a multisite network, a login still listed in `UAMSWP_AUDITOR_ROLE_LOGINS` is added back to every site it visits.

**Fix:** follow [Offboarding an auditor](Offboarding-an-auditor.md): an end date of yesterday computed with `wp_date()`, the login removed from the constant, and every session destroyed.

## The screen walk reports a write on a screen

**Cause:** either the Auditor's request, or something it set off, wrote to that table, or something else wrote while it ran: a WP-CLI command, a cron run or another visitor. The walk charges every write in a request's window to that request.

**Fix:** visit the target again alone, with nothing else running and WP-Cron off. If it is clean then, the write was not the screen's. If it repeats, decode the binary log over that span with `mysqlbinlog --base64-output=DECODE-ROWS -v <log file>` to find the row, and decide it: a write the guard should have refused is a defect to [report](https://github.com/UAMS-Web/uamswp-auditor-role/issues); a write the deployment accepts is allowed with `uamswp_auditor_role_allow_query` and recorded. See [Running the screen walk](Running-the-screen-walk.md).
