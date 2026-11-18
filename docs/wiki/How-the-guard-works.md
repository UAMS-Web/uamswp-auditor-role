Written against v0.3.0.

The role holds `manage_options` so that settings screens open, which means capabilities alone cannot keep it read-only. Four guard layers do, each default-deny: anything not known to be a read is refused. The reasoning behind each choice is in [`docs/DECISIONS.md`](https://github.com/UAMS-Web/uamswp-auditor-role/blob/main/docs/DECISIONS.md).

## The four layers

| Layer | Where | What it refuses |
| --- | --- | --- |
| Requests | `RequestGuard` | Every non-read request (POST, PUT, PATCH, DELETE), anywhere on the site, including the REST API after a `_method` override; `admin-post.php`; `options.php`; XML-RPC sign-in; admin GETs carrying an acting `action` or a nonce outside a list table's filter form; screens that write on load (`post-new.php`, `update.php`); the network admin. |
| AJAX | `RequestGuard` | Every `admin-ajax.php` action not on a short list verified read-only in core. |
| Capabilities | `MetaCaps` | Every never-granted capability and every acting meta capability (`activate_plugin`, `delete_post`, `create_app_password`, ...), mapped to `do_not_allow`. |
| Database | `DatabaseGuard` | Every statement that is not a read, through the `query` filter, during an Auditor session. The plugin's own writes (its log, the session token, adding a configured login) pass through `DatabaseGuard::bypass()`. |

A refused request answers `403`. A screen shows *This account is in read-only audit mode. Changes are refused and recorded.*; an AJAX or REST request gets the same message with the error code `uamswp_auditor_role_read_only`.

## Hidden screens

Screens whose viewing is the exposure (raw options, exports, form entries, mail and deploy settings, license keys, the Site Health info tab) are removed from the menu and refused by URL (`Screens`), with the message *This screen is hidden from Auditor accounts because it can show secrets or personal data.* A deployment adds to the list with [`uamswp_auditor_role_hidden_screens`](Filters.md).

## Compensating controls

- **An activity log per site** (`Log`), read on **Tools > Auditor log** by administrators only, with filters, CSV export and a retention setting (`LogViewer`, `Retention`). See [Reviewing the activity log](Reviewing-the-activity-log.md).
- **An optional end date per account** (`Account`), after which the account cannot sign in.
- **A Read-only audit mode badge** in the admin bar (`AdminBar`).

## What it does not cover

- A plugin that writes through its own database connection instead of `$wpdb` passes the `query` filter untouched. The [screen walk](Running-the-screen-walk.md) reads the binary log, so it still sees such a write.
- Side effects outside the database, such as an outbound request on load, are out of scope for the guard.

The writes found on ordinary page loads, and the decision for each, are in [`docs/WRITES-ON-LOAD.md`](https://github.com/UAMS-Web/uamswp-auditor-role/blob/main/docs/WRITES-ON-LOAD.md).
