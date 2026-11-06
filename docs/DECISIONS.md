# Decision log

The canonical record of why the project is the way it is: the reasoning and the rejected alternatives behind decisions that have closed. It guards against silently reopening a settled decision.

**Consult it before acting.** Before proposing a change, reopening a question or picking an approach, check here first. The path under consideration may already be a decided-and-recorded one, or an explicitly rejected one. If it is, follow the record, or make the case to revisit it.

**When a decision closes, record the verdict in both places.** Capture the what, the why and the rejected alternatives here (the cross-ticket index) and in the GitHub issue's `## Decision` section (the per-ticket record), keeping the two consistent. Put the full reasoning in the relevant `docs/` companion when the decision touches a documented area. The issue tracker stays the system of record for a single ticket's status.

## How to add an entry

Entries run oldest first, one per closed decision, so a new entry is appended to the end of the file. Keep it terse; the companion or the issue carries the depth.

Every entry heading is `## YYYY-MM-DD`, a space, and a short title. Leave exactly one blank line above each heading. The check (`decision-log` skill) fails on a heading dated earlier than the one before it, and on a heading that sits directly under the previous line.

```markdown
## YYYY-MM-DD <short decision title> (<#issue or area>)

**Decision.** <what was decided, in a sentence or two>

**Why.** <the reasoning that settled it>

**Rejected.** <the alternatives considered, and why each lost>

**Companion.** <link to the docs/ companion or the GitHub issue holding the full reasoning>
```

---

## 2026-11-05 Scope and shape of the Auditor role (area: design)

**Decision.**

- **One must-use plugin** registers a read-only `Auditor` role, so the role cannot be deactivated by a site administrator.
- **Default-deny read-only guard.** The role holds `manage_options` so settings pages can be viewed, which makes the guard the only control against a write. Anything not on an allowlist is refused.
- **Database write block.** A `query` filter refuses non-read statements during an Auditor session, except a short allowlist (the session token, the activity log, adding an auditor login to a site).
- **Sensitive screens are hidden by default.** The denylist is filterable, and an entry can be removed per screen.
- **No default expiry.** An optional end date per account; login is refused after it.
- **No `list_users`.** The role cannot list other users.
- **Password reset by email only.** The role cannot change its own password from the profile screen.
- **Custom activity-log table**, with an administrator-only viewer, a CSV export and a WP-CLI command. Retention is a setting on the log screen, one year by default, pruned by a scheduled job outside any Auditor session.
- **Network visibility** comes from a separate read-only WP-CLI export with secrets masked. Network admin screens stay unreachable.

**Why.** Capabilities alone cannot express "view but never change", the install count makes a per-site manual setup impractical, and an audit needs a record of what the account touched.

**Rejected.** Using an existing activity-log plugin (not on every install). A hard expiry by default (the audit has no fixed end). Showing every settings screen (viewing a secret is the exposure).

**Companion.** The plugin's issues on this repository's tracker.

## 2026-11-05 Choices made while building the guard (#3)

**Decision.**

- **Two more granted capabilities.** `upload_files` (the Media library) and `manage_categories` (the term screens) join the granted list. Their writes are refused like every other.
- **The guard reads the auth cookies on `plugins_loaded`**, before WordPress sets the current user, and checks again on `init`, so a POST is refused before any regular plugin's `init` or `admin_init` handler runs and any other way in is still covered.
- **Every non-read request is refused site-wide**, not only in the admin and the REST API, because the database guard would refuse a front-end form's write anyway and a clear refusal beats a silent failure.
- **Refused statements fail quietly.** The `query` filter returns an empty statement, so wpdb returns false and the page renders. The refusal is logged with its table and, for options, the option name. Transients are refused like any other write; see `docs/WRITES-ON-LOAD.md`.
- **The plugin's own writes go through `DatabaseGuard::bypass()`**, and the session token through a subclass of core's session manager, not through a table or meta-key allowlist in the filter, which a third-party write to the same table could ride.
- **Fail closed.** The loader stub and the PHP floor guard strip every capability from an Auditor account when the plugin is not loaded, because the role persists in the database with `manage_options`.
- **Plugins screen on multisite follows core.** Where the network has not enabled the Plugins menu for site administrators, the role cannot open it either; `wp auditor discover` and `wp auditor network-export` carry the plugin list.
- **Dashboard widgets that write or summarize a hidden screen are removed** for the role (Quick Draft, WP Mail SMTP reports), through a filterable list.
- **`wp-login.php` sign-in, password reset and a User Switching switch-back are not an Auditor session**, so a reset by email works and an administrator testing the role can switch back.

**Why.** Each one either closes a gap the issue text left open or follows from testing the role against the uamshealth.com plugin set on a local multisite.

**Rejected.** Allowing transients by default (the issue asks for refusal and a per-case decision). Granting the Plugins screen past the network setting (wider than a site administrator). An allowlist of usermeta keys for the session token (a window another write could pass through).

**Companion.** `docs/WRITES-ON-LOAD.md`, `docs/DEPLOY.md`, and the pull request for #3.

## 2026-11-06 Mutation testing in the Unit suite and CI (#4)

**Decision.**

- **`composer mutate`** runs Pest's `--mutate --everything --parallel --min=100 --path=includes`, so it exits non-zero on any mutant not killed. `--everything` because the tests do not declare `covers()`. A later `--path` scopes it to one class.
- **A child-process fix in `tests/bootstrap.php`.** Pest swaps a mutant in through a `file://` stream wrapper, and Patchwork (Brain Monkey) wraps the same protocol. In a mutant's child the two collided on a directory call and the child fatal-errored before any test ran, and Pest scores a failed child as killed. Every mutant read as killed whatever the tests asserted. In a child, the bootstrap now turns Pest's wrapper off before Patchwork loads and loads the mutated copy through the plugin's class path. A planted mutant no test can see now survives, and a planted mutant a test asserts against is killed.
- **The CI job mutates changed files only, on a push to `main` as well as on a pull request.** The shared template mutates everything on a push. Until each class reaches the bar under its own issue, that would hold `main` red for code no pull request touched. A manual run still mutates everything.
- **The run is read by `scripts/check-mutation-run.php`**, not by Pest's exit status alone: it fails on zero mutants and on any untested or uncovered mutant. The `pest-testing` skill's checker it replaces is not synced to this repository.
- **Linux only**, like every other job here.

**Why.** The later issues close against a mutation-clean bar, and a bar is only as good as the instrument that measures it. The collision was found because a mutant planted to be unkillable was reported killed.

**Rejected.** Mutating everything on a push to `main` (red on code no change touched). Trusting Pest's exit status (passes on zero mutants in some configurations). Replacing Brain Monkey to avoid Patchwork (a rewrite of every unit test for a bootstrap-sized problem).

**Companion.** The pull request for #4, which records the honest per-class baseline.

