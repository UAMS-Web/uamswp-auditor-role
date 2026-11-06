# Contributing

1. Work from an issue. Branch from `main` as `<issue number>-<short-name>`.
2. Run the checks listed in the README before opening a pull request: `composer test`, `composer stan`, `composer test:pint`, `composer test:phpcs`, `composer test:refactor` and `composer check:archive`.
3. Open the pull request against `main` with `Closes #<issue>` in its body, and fill in the test plan.
4. Record a decision that closes a question in `docs/DECISIONS.md` and in the issue's `## Decision` section.

Code follows the [WordPress Plugin Handbook](https://developer.wordpress.org/plugins/) and the [Security APIs](https://developer.wordpress.org/apis/security/): check capabilities, verify nonces, sanitize input, escape output.

<!-- uams-claude-skills:begin -->
## Branches and environments

Every change starts as an issue, is built on a branch linked to that issue, and reaches production through a pull request to `main`. This section covers that path and what each pull request's test plan has to cover.

| Branch | Role | Where its test plan runs |
| --- | --- | --- |
| `main` | Default branch, production | The local environment, before the pull request merges |

## From issue to merge

1. **File or pick up an issue.** The work is described there, with acceptance criteria.
2. **Create a branch from `main`, linked to the issue.** Use the issue's **Create a branch** link, or:

   ```sh
   gh issue develop <number> --base main --name <number>-<short-slug> --checkout
   ```

   The link shows the branch, and later its pull requests, on the issue.
3. **Open a pull request from the branch to `main`.** It carries the full description: what changed, why, and how it was verified. Its test plan is run on the local environment, and its boxes are checked before review.

## Test plans

Every pull request ends with a `## Test plan` checklist. Its first line names the environment it was run on, and every item is something a reviewer can check there:

- **Pull request to `main`:** "Run on the local environment." Items are things you checked yourself there: a page, a role, a save, a command. Check a box only for a check you actually ran.

The [pull request template](.github/pull_request_template.md) fills in the skeleton when a pull request is opened on GitHub.

## Changing code that has no tests

Before changing code that has no tests, add a test that captures what the code does now, whether or not that is what it should do, and see it pass. Commit that test on its own, then make the change. A test that now fails shows a behavior that changed: update the test if the change was intended, and fix the code if it was not.

The [`tests` skill](.claude/skills/tests/SKILL.md#pinning-legacy-behavior-before-a-change) has the detail: what to capture, how to show a test catches a change, and how to name it.

## Agents

Coding agents follow the same path. Their instructions are in [`AGENTS.md`](AGENTS.md) and the shared skills and rules under `.claude/`, which are synced from `UAMS-Web/uams-claude-skills`.
<!-- uams-claude-skills:end -->
