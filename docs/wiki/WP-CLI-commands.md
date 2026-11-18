Written against v0.3.0.

Every command is under `wp auditor`, run from the WordPress root. On a multisite network a command acts on the main site unless `--url=<site URL>` names another, and the log commands take `--all-sites` to cover the whole network.

| Command | Does |
| --- | --- |
| `wp auditor discover [--format=<json or yaml>]` | The facts an install needs before deploying: type, versions, sites, plugins, themes, administrator capabilities not in core, secret-looking option names (names only, never values). JSON by default. |
| `wp auditor network-export [--file=<path>]` | Network settings, plugins, themes and sites as JSON, every secret-bearing value masked. |
| `wp auditor sync-sites` | Adds each login in `UAMSWP_AUDITOR_ROLE_LOGINS` to every site. |
| `wp auditor refresh-role [--network]` | Rewrites the role on the current site, or on every site with `--network`. |
| `wp auditor log list` | Prints log rows, newest first. |
| `wp auditor log export [--file=<path>]` | Exports every row the options select as CSV, to standard output or the file. |

Keep the output of `discover` and `network-export` out of the plugin repository.

## Log options

`log list` and `log export` take the same filters:

| Option | Selects |
| --- | --- |
| `--auditor=<login or ID>` | One auditor's rows. Not `--user`, which is WP-CLI's own global option (the user the command runs as) and never reaches the command. |
| `--from=<Y-m-d>` | Rows from this day, UTC. |
| `--to=<Y-m-d>` | Rows up to this day, UTC. |
| `--event=<event>` | `view`, `refused`, `db_refused`, `login`, `logout` or `login_refused`. |
| `--all-sites` | Every site of a multisite network. |

`log list` also takes `--limit=<number>` (default 100) and `--format=<table, csv, json or yaml>` (default `table`). In an export, a cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return is prefixed with `'`, so a spreadsheet shows it as text.

The log commands are read-only. Pruning old rows is the daily scheduled job's; `wp cron event run uamswp_auditor_role_prune` runs it now.

## Examples

```bash
wp auditor discover > discovery.json
wp auditor log list --auditor=<login> --from=<YYYY-MM-DD> --event=refused
wp auditor log export --all-sites --file=auditor-log.csv
```

## When WP-CLI cannot boot the install

Where an install's bootstrap fails under WP-CLI, `php scripts/discover-files.php <wordpress-path>`, run from a checkout of this repository, reports the same facts from files alone.
