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
- **Plugins screen on multisite follows core.** Where the network has not enabled the Plugins menu for site administrators, the role cannot open it either; `wp auditor discover` and `wp auditor network-export` carry the plugin list. *Superseded on 2026-11-10: see "The Plugins screen past the network setting".*
- **Dashboard widgets that write or summarize a hidden screen are removed** for the role (Quick Draft, WP Mail SMTP reports), through a filterable list.
- **`wp-login.php` sign-in, password reset and a User Switching switch-back are not an Auditor session**, so a reset by email works and an administrator testing the role can switch back.

**Why.** Each one either closes a gap the issue text left open or follows from testing the role against the uamshealth.com plugin set on a local multisite.

**Rejected.** Allowing transients by default (the issue asks for refusal and a per-case decision). Granting the Plugins screen past the network setting (wider than a site administrator; adopted on 2026-11-10, see below). An allowlist of usermeta keys for the session token (a window another write could pass through).

**Companion.** `docs/WRITES-ON-LOAD.md`, `docs/DEPLOY.md`, and the pull request for #3.

## 2026-11-06 Mutation testing in the Unit suite and CI (#4)

**Decision.**

- **`composer mutate`** runs Pest's `--mutate --everything --parallel --min=100 --path=includes`, so it exits non-zero on any mutant not killed. `--everything` because the tests do not declare `covers()`. A later `--path` scopes it to one class.
- **A child-process fix in `tests/bootstrap.php`.** Pest swaps a mutant in through a `file://` stream wrapper, and Patchwork (Brain Monkey) wraps the same protocol. In a mutant's child the two collided on a directory call and the child fatal-errored before any test ran, and Pest scores a failed child as killed. Every mutant read as killed whatever the tests asserted. In a child, the bootstrap now turns Pest's wrapper off before Patchwork loads and loads the mutated copy through the plugin's class path. A planted mutant no test can see now survives, and a planted mutant a test asserts against is killed.
- **The CI job mutates changed files only, on a push to `main` as well as on a pull request.** The shared template mutates everything on a push. Until each class reaches the bar under its own issue, that would hold `main` red for code no pull request touched. A manual run still mutates everything, and a weekly schedule (Mondays) runs the same full pass on `main` to catch decay no pull request touches (#42).
- **The run is read by `scripts/check-mutation-run.php`**, not by Pest's exit status alone: it fails on zero mutants and on any untested or uncovered mutant. The `pest-testing` skill's checker it replaces is not synced to this repository.
- **Linux only**, like every other job here.

**Why.** The later issues close against a mutation-clean bar, and a bar is only as good as the instrument that measures it. The collision was found because a mutant planted to be unkillable was reported killed.

**Rejected.** Mutating everything on a push to `main` (red on code no change touched). Trusting Pest's exit status (passes on zero mutants in some configurations). Replacing Brain Monkey to avoid Patchwork (a rewrite of every unit test for a bootstrap-sized problem).

**Companion.** The pull request for #4, which records the honest per-class baseline.

## 2026-11-06 Bringing every class to mutation-clean (#5 to #21)

**Decision.**

- **Lists are methods, not constants.** The capability, screen, request, AJAX, event and retention lists (`Role::granted()`, `MetaCaps::defaults()`, `Screens`, `RequestGuard::get_actions()` and the rest) return arrays from methods. A constant has no executable line, so a test can never be seen to cover an entry, and each entry's removal was an uncovered mutant. Each test pins its list against the names in the issue, written out in the test.
- **Entries the prefix rule already covers are removed** from the never-granted and denied lists (`install_`, `update_`, `delete_`): removing one changed nothing.
- **Equivalent mutants are designed out, not annotated.** No class carries `@pest-mutate-ignore`. Values only the keys of which are read, casts a value already has, and default numbers a mutant could shift were restructured instead.
- **The request guard's XML-RPC decision takes the request kind as an argument** (`RequestGuard::xmlrpc_refusal()`), because a constant cannot be undefined in a test process, and the guard reads the auth cookies only once `plugins_loaded` has fired.
- **One log row per request**: `refused` with any refused statements, else `db_refused`, else `view`. Two rows for one request double-counted.
- **The log command is read-only** (`list` and `export`, with `--all-sites`), per #13; pruning is the scheduled job's alone, registered once per network on the main site, and one run prunes every site by that site's retention.
- **Side effects the Unit suite cannot observe go through seams**: the CSV export's headers and each admin-post handler's `exit` are overridable closures, defaulting to `header()` and `exit`.
- **The end-date nonce has its own field name.** It shared the date field's name, so the date overwrote the nonce and every save from the profile screen failed its nonce check.
- **Reports read option lists that are not arrays as empty**, instead of casting `false` to `[false]`.
- **`uamswp_auditor_role_logins` filters the multisite logins** after the constant, so a deployment or a test can supply them.
- **`includes/autoload.php` has no direct-access guard.** It only registers an autoloader, so a direct request prints and loads nothing, and the guard ran where no test could cover it.
- **The database guard keeps every refused statement for the request** and logs the first ten; its bypass is a saved-and-restored flag rather than a depth counter.
- **Test stand-ins fail fast.** A WordPress class the Unit suite needs is a Mockery double or a `class_exists()`-guarded definition excluded from static analysis, and one shared WP-CLI double serves every command test (`tests/Support/wp-cli-double.php`): two guarded doubles of one class break whichever loads second. A database stand-in fails on an unqueued read, and a test that calls back into the class under test fails on re-entry, because a looping or recursing mutant times out on a CI runner while it reads as killed locally.

**Why.** The issues close against the mutation-clean bar from #4, and each choice here either made a mutant killable or removed one that no input could reach. Two real defects surfaced on the way: the end-date save and the report casts.

**Rejected.** Annotating equivalent mutants (each one was avoidable). Keeping lists as constants with a separate test of the constant (the mutant stays uncovered: Pest cannot attribute a constant to a test). A per-PR WP-CLI double (it broke the suite when two merged).

**Companion.** Pull requests #26, #27, #28, #30 and #31.

## 2026-11-06 The screen walk, and refusing writes while plugins load (#19)

**Decision.**

- **The walk reads writes from MySQL's binary log**, outside WordPress, rather than from the guard's own log or from table checksums. Every committed change by any connection is in it, so a write that passes the guard by any path shows, and it costs a position read per request where checksums of a 1.8 GB database would not finish. It requires the log on in row format, and the walk checks that and proves itself on a probe write before trusting an empty window.
- **Every menu slug on every site, and each AJAX action once.** A screen renders its own site's data, so it is visited on each site; an action is the same code on every site that registers it, so it is visited once. On the local network that is 3,994 requests rather than about 14,600.
- **WP-Cron is off on a walked install.** A cron run a visit sets off writes as an anonymous request, and the walk cannot tell it from the visit's own write.
- **While regular plugins load, a login cookie that names an Auditor refuses writes, unvalidated.** The functions that validate a cookie are pluggable and load after the plugins, so until then the guard could not tell an Auditor's request, and a plugin that wrote as it loaded passed. The first walk found Ajax Search Pro doing so on every request. The cookie's HMAC is not checked: a forged cookie that names an Auditor can only refuse its own request's writes. That answer is held apart from the validated one, which it never stands in for. Before, the "not an Auditor" answer the early write caused was held for the whole request, so the `plugins_loaded` request check also skipped and writes before `init` passed.
- **The walk's classes are held to the mutation-clean bar**, in `tests/Walk/src/`, which joins the coverage source, and the test bootstrap swaps a mutant in for any class Composer maps.
- **CI walks the public plugin set in `tests/Walk/plugins.txt`** on the WordPress version the integration suite runs, so a plugin that needs a newer core (Contact Form 7 needs 7.1) is left out, and walks again with a planted write that must fail the run.

**Why.** #19 asks for evidence that the guard holds against the real plugin set, and evidence needs an instrument the guard does not control.

**Rejected.** Checksumming every table around each request (minutes per request on the local database). Reading only the guard's refusals (it cannot see what it let through). Validating the cookie early by loading the pluggable functions ahead of plugins (a plugin may replace them). Visiting each AJAX action on every site (four times the requests for the same code).

**Companion.** `docs/WALK.md`, `docs/WRITES-ON-LOAD.md`, and the pull request for #19.

## 2026-11-09 CI calls the shared chain (#39)

**Decision.**

- **`ci.yml` calls `UAMS-Web/uams-ci-workflows` (pinned to `v0.9.0`) through one `checks` job** for the lint, static-analysis and Unit chain, with `floor-lint`, `download-archive` and `phpcs` switched on, `linux-only`, and `pest-command: composer test:unit`. The job named `CI` stays and `needs:` the call and the plain jobs.
- **`integration`, `mutate` and `walk` stay as plain jobs beside the call.** The chain has no MySQL service or WordPress download, this plugin's `mutate` selects changed files under `includes/` and `tests/Walk/src/` and reads its run with `scripts/check-mutation-run.php`, and the screen walk is this plugin's own. The call passes `mutate: false`, so the chain's mutation jobs do not run.
- **The setup action installs the Integration suite's Composer setup by default**, as the plugin template's does, because the chain's PHPStan job calls the action with no inputs and the analysis scans `tests/Integration/vendor`.
- **The three plain jobs no longer wait on `rector` and `phpstan`**, which now run inside the call; they run beside it.

**Why.** One CI definition for every repository, with nothing the old file checked left uncovered.

**Rejected.** Moving `mutate` into the chain (mutation testing is out of scope for #39, and the chain's job reads its run with a different script). Passing `integration: 'true'` from the caller (the chain gives a caller no way to set the called action's inputs).

## 2026-11-10 ShortPixel's background processor in an Auditor session (area: third-party plugins)

**Decision.**

- **ShortPixel's processor script is turned off for an Auditor** through ShortPixel's own `shortpixel/processorjs/disable` filter, so an Auditor's browser no longer asks `admin-ajax.php` to optimize images from the site's shared queue. Media and editor screens still show each image's ShortPixel status.
- **ShortPixel's settings and Bulk screens are hidden.** The settings screen shows the API key, and the role holds `manage_options`; ShortPixel runs the processor on the Bulk screen even with the filter set, and that screen only starts a run.
- **`shortpixel_image_processing` stays off the AJAX allowlist.** It writes files and rows.

**Why.** The request guard refused the action correctly, and ShortPixel showed the refusal as a fatal error every few seconds on the Media screens.

**Rejected.** Not loading ShortPixel for an Auditor through `shortpixel/plugin/init` (simpler, but it hides the optimization status an audit can need). Allowlisting the action (a write).

**Companion.** `docs/WRITES-ON-LOAD.md`.

## 2026-11-10 Find-a-Doc's provider and location list screens (area: role)

**Decision.**

- **Four more granted capabilities.** `edit_physicians` and `read_private_physicians` (UAMSWP Find-a-Doc's `provider` post type) and `edit_locations` and `read_private_locations` (its `location` post type) join the granted list. Each opens a list screen or shows private posts on it, as `edit_posts` and `read_private_posts` do for posts; the guard refuses every write they would allow.
- **None of the types' other capabilities are granted.** `edit_others_*`, `edit_published_*`, `publish_*` and `delete_*` stay off the list, so the post editor stays closed for both types.
- **`Role::VERSION` is raised to `2`**, so every site rewrites the role.

**Why.** Both post types map `edit_posts` to their own capability, so without it the Providers and Locations menus showed only their taxonomy screens.

**Rejected.** Granting the capabilities through `uamswp_auditor_role_capabilities` in a deployment (the plugin already names UAMS plugins in its hidden screens, and a filter would leave the default role unable to read two of the site's main content types).

## 2026-11-10 The Customizer (area: capabilities)

**Decision.**

- **`customize` is denied** for an Auditor in `MetaCaps::defaults()`. Core maps it to `edit_theme_options`, which the role holds so Appearance > Menus and Widgets open, so without the denial the Customizer, its menu item, the admin bar "Customize" link and the themes screen's "Customize" and "Live Preview" buttons were all available.
- **`customize.php` is a hidden screen** as well, so a direct visit gets the hidden-screen message.

**Why.** Every Customizer control saves a changeset, so the screen is a write surface with nothing to read that Appearance and the front end do not already show. `edit_css` was already denied.

**Rejected.** Dropping `edit_theme_options` from the granted list (it would close Menus and Widgets, which an audit reads).

## 2026-11-10 Viewing drafts on the front end (area: capabilities)

**Decision.**

- **`edit_post` and `edit_page` answer `read` for an Auditor on a front-end read** (not the admin, AJAX, REST or cron; a GET or HEAD), for a post of a viewable type in a protected status (draft, pending, scheduled, and any status a plugin registers as protected). WordPress shows such a post, by preview link or by `?p=<id>`, only to a user who passes `edit_post`, and every post type's own edit capability resolves through these two.
- **Everywhere else the check is unchanged**, so the post editor stays closed, and published and private posts keep their own capabilities.
- **The front-end admin bar "Edit" link and the theme's `edit_post_link()` output are removed** for the role, since the allowance makes them appear on a draft and they lead to the refused editor.

**Why.** Reading another user's draft is part of an audit, and the role held no capability that allowed it.

**Rejected.** Granting `edit_others_posts`, `edit_others_pages` and each custom post type's equivalents (it opens the post editor for every post, whose load writes the post lock and autosave, and needs every type's capabilities named per deployment). Checking the `WP_CLI` and `XMLRPC_REQUEST` constants as well (XML-RPC sign-in is already refused for the role, a WP-CLI run is server access with the database guard still in place, and a constant cannot be tested both ways in one process).

## 2026-11-10 The Plugins screen past the network setting (area: capabilities)

**Decision.**

- **An Auditor can open the Plugins screen on a network that has not enabled the Plugins menu for site administrators.** For an Auditor's `activate_plugins` check, `MetaCaps::map()` drops the `manage_network_plugins` that core's `map_meta_cap()` adds for that network setting, so the check resolves to `activate_plugins`, which the role holds. *Narrowed on 2026-11-12: the check passes only on the site's Plugins screen; see "The plugins check off the Plugins screen".*
- **The screen stays a list.** `activate_plugin`, `deactivate_plugin` and `deactivate_plugins` stay denied, and the `install_`, `update_` and `delete_` families stay never granted, so no row action shows. The bulk "Activate" and "Deactivate" options, which core adds without a capability check, are removed for the role through `bulk_actions-plugins`. Network-active plugins, and inactive network-only ones, are listed for the role through `show_network_active_plugins`; core labels each "Network Active" or "Network Only" in place of its actions and gives it no bulk checkbox.
- **Site administrators are unchanged**: the network setting still closes the screen to them.

**Why.** An audit reads the plugin list, and `wp auditor discover` and `wp auditor network-export` give it only to someone with server access.

**Rejected.** Keeping the earlier decision that the screen follows core (the list stays out of reach in the browser). Filtering the `menu_items` site option to add `plugins` in an Auditor session (it changes a network option's value for every reader in the request, not one capability check).

## 2026-11-12 The plugins check off the Plugins screen (area: capabilities)

**Decision.**

- **An Auditor passes the `activate_plugins` check only on a site's Plugins screen** (`plugins.php`, not the network admin's), where `manage_network_plugins` is still dropped from it. On every other request the check is denied, on a single site and on a network with the Plugins menu enabled for site administrators as well, where the role's own `activate_plugins` had passed it everywhere since the role was built.
- **Core's Plugins menu entry, and its Installed Plugins submenu entry, ask for `read` in an Auditor session**, so the entry shows on every screen and opens `plugins.php`, where the check passes.

**Why.** Plugins read `activate_plugins` as "may act on plugins". WP Mail SMTP redirects such a user to its setup wizard while its activation flag is set, and deletes the flag first; the Auditor's delete is refused, so every main-site admin request redirected, and the screen walk loaded none of 38 screens (#84). A flag kept in an option with no expiry would redirect the Auditor until an administrator loaded an admin screen.

**Rejected.** Keeping the check true everywhere and making known activation-redirect flags read as unset for an Auditor (each plugin with the pattern needs its own entry, and an unknown one is found only when it redirects). Leaving the role as it is and making the screen walk wait out the redirect (the defect stays for a flag with no expiry, and the walk stops showing it).

## 2026-11-12 Plugin activation redirects keyed on a capability the role holds (area: third-party plugins)

**Decision.**

- **Listed activation-redirect flags read as `false` in an Auditor session** (`ActivationFlags`), through the `option_<name>` and `transient_<name>` filters, hooked late on `init`. The default list is SEOPress's `seopress_activated` option and WP Mail SMTP's `wp_mail_smtp_activation_redirect` transient (its redirect is keyed on `activate_plugins`, which an Auditor passes on the Plugins screen), and `uamswp_auditor_role_activation_flags` adds `option:<name>` or `transient:<name>` entries but cannot drop a default. Nothing is written, so the next administrator is still redirected.
- **SEOPress's and WP Mail SMTP's setup wizards (`page:seopress-setup`, `page:wp-mail-smtp-setup-wizard`) are hidden screens.** Each saves its plugin's settings, and WP Mail SMTP's takes the mailer's credentials.

**Why.** SEOPress redirects a user who can `manage_options` to its wizard while the option is `yes`, and deletes the option first. The role holds `manage_options` so settings screens can be read, and its delete is refused, so the redirect repeated on every admin screen until an administrator loaded one (#87). On the CI walk it sent all 38 of the main site's ordinary screens to the wizard.

**Rejected.** Cancelling any redirect a plugin issues during `admin_init` on an Auditor's screen view (it reaches unknown plugins too, but cancels legitimate redirects with them). Taking SEOPress out of the CI walk (the defect stays on a real install).
