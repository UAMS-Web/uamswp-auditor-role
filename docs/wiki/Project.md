UAMS Auditor Role is developed and maintained by UAMS Web Services in [UAMS-Web/uamswp-auditor-role](https://github.com/UAMS-Web/uamswp-auditor-role), under GPL-3.0-or-later.

## Report a problem

Open an issue in [the repository's tracker](https://github.com/UAMS-Web/uamswp-auditor-role/issues). Say which version is installed, whether the install is a single site or a network, what the auditor did, and what the activity log recorded for it. Leave out host names, credentials and anything else particular to one install.

**Warning:** a way for an Auditor account to change something is a security problem. Report it privately through the repository's **Security** tab rather than in a public issue.

## Releases

Each release on the [Releases](https://github.com/UAMS-Web/uamswp-auditor-role/releases) page attaches the upload ZIP and its checksum list, and `readme.txt` in the repository carries the changelog. These pages are republished from the release's tag when a release is published, so each page names the release it was written against.

## Design decisions

Every settled choice, and the alternatives rejected, is in [`docs/DECISIONS.md`](https://github.com/UAMS-Web/uamswp-auditor-role/blob/main/docs/DECISIONS.md). Read it before proposing a change to the guard.

## Developing

Building, the test suites, the mutation-testing bar and cutting a release are in the [README](https://github.com/UAMS-Web/uamswp-auditor-role#develop).

## Editing this wiki

The pages live in [`docs/wiki/`](https://github.com/UAMS-Web/uamswp-auditor-role/tree/main/docs/wiki) and change through a pull request like any other file. The **Publish wiki** workflow copies the folder to the wiki when a release is published, or when it is run by hand, and replaces every page, so an edit made in the wiki's own editor is lost on the next publish.
