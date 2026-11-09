# Changelog

## 1.1.0

Workspaces and releases.

- Workspaces: named branches of data, and optionally of code, created from stage, production, any branch or a snapshot, and served by the staging site. A request picks its branch before WordPress loads from a signed cookie, a signed preview link, a hostname, or the `WPBR_WORKSPACE` environment variable on the command line. `extras/workspace-switch.php` does the switching and is loaded from `wp-config.php` on staging only.
- Stage becomes read-only: editing capabilities are removed for everyone, so stage holds exactly production or the next release.
- Promote: merge a workspace into stage, data with the merge rules (stage's content wins, the workspace's structure wins) and then code with git, after a dry-run merge shows the code will not conflict. Refused while the workspace is behind stage; bookkeeping rows never count.
- Update: merge stage into a workspace, data then code.
- Release: stage to production as one named step. Production tagged before and after, code tagged with the same name, the site's deploy command run first with the tag in its environment. `release rollback` returns production to the tag taken before and deploys the previous release's code.
- Code workspaces: a git worktree per workspace under `wpbr-workspaces/`, served as `WP_CONTENT_DIR` for that workspace's requests, with uploads and any `WPBR_WORKSPACE_LINKS` paths linked in.
- Admin: Workspaces card (open, update, promote, preview link, delete, create), Release card (stage against production, release by name, past releases with rollback), a workspace switcher in the admin bar, a bar on every page naming the workspace or saying stage is read-only, promote and update review screens.
- CLI: `workspace list|create|delete|link|update`, `promote`, `release`, `release list`, `release rollback`; `status` shows the workspace and stage against production.
- Mail from a workspace is dropped unless `WPBR_WORKSPACE_MAIL` is true. Workspace requests are not page-cached and the object cache is keyed by workspace.
- The merge engine takes an upstream branch, so the rules work between any two branches, not only against production.
- Classifier: Elementor's `_elementor_*` caches and sync markers are bookkeeping.
- Unit tests for the switch file.

## 1.0.0

First public release.

- Pull production into a release branch, publish a release branch into production, merge any local branch into the current one.
- Rules by what a row means: structure follows the release, content follows production, pages merge field by field with true clashes shown for a decision, bookkeeping resolved silently and left out of counts.
- Field-level merge inside a row: newest timestamp, largest counter, serialized PHP and JSON merged key by key.
- Unique-key collisions attributed to their branch and resolved by the same rules.
- Review screen with summary, decisions first, changes by area with examples, rule resolutions behind a disclosure, apply pinned to the snapshots reviewed.
- WP-CLI: status, snapshot, log, diff, preview (JSON), pull, publish, merge, remote, fetch, push, track, rollback, import.
- Remotes: another Dolt server, DoltHub, DoltLab, a bucket or a directory.
- Attachment files copied along with their rows; files of removed attachments moved to `uploads/wpbr-trash/`.
- Rollback to any snapshot; the head you leave is saved as a tag and listed under Saved points.
- Capabilities `manage_branches` and `publish_to_production`.
- `wp branchline import` moves an existing MySQL or MariaDB site onto a Dolt server with a row count check and a baseline snapshot.
- Multisite: numbered table prefixes and the network tables understood by the rules, a sunrise file in `extras/` to map a staging host onto the stored network domain.
- Translation ready, text domain `branchline`.
- Unit tests for the rules and the semantic merge.
