UAMS Auditor Role is a must-use WordPress plugin that adds a read-only `Auditor` role. A person with the role can review content, site structure, templates, plugins and settings without Administrator or Super Admin rights; every change they try is refused and recorded in an activity log.

This wiki holds the long-form documentation: deploying the plugin, the tasks an administrator runs while auditors use it, the reference for its guard, filters and commands, and troubleshooting. The [README](https://github.com/UAMS-Web/uamswp-auditor-role#readme) keeps the overview and the development notes, and the detailed procedures stay in the repository's [`docs/`](https://github.com/UAMS-Web/uamswp-auditor-role/tree/main/docs) folder, which these pages link to.

## Getting started

- [Deploying the plugin](Deploying-the-plugin.md): from a release ZIP to a first Auditor account on a single site or a network.

## Guides

- [Offboarding an auditor](Offboarding-an-auditor.md): end one auditor's access and keep their log rows.
- [Reviewing the activity log](Reviewing-the-activity-log.md): where to read the log, what each event means, and how often to look.
- [Running the screen walk](Running-the-screen-walk.md): prove the guard holds against an install's own plugins before deploying there.

## Troubleshooting

- [Troubleshooting](Troubleshooting.md): one entry per symptom, with its cause and fix.

## Reference

- [How the guard works](How-the-guard-works.md): the four default-deny layers and the hidden screens.
- [Filters](Filters.md): every filter a deployment can use to change the role.
- [WP-CLI commands](WP-CLI-commands.md): the `wp auditor` commands.

## Project

- [Project](Project.md): where the plugin is developed, how a release is published, and how to report a problem.
