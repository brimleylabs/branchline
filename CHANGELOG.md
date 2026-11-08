# Changelog

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
