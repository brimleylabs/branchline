# Command line

Every command is `wp branchline <command>`, run from the WordPress install whose branch you want to act on. The branch is read from `DB_NAME`.

Exit codes: 0 on success, 1 on any error. Errors print a one-line reason starting with `Error:`.

## Reading state

### status

    wp branchline status

Prints the branch, the production branch name, the head hash, the tables with unsaved changes, the last five snapshots and every branch with its head.

### log

    wp branchline log [--count=20] [--format=table|json|csv]

Snapshots on this branch, newest first. In `json` format each row has `hash`, `when`, `message`.

### diff

    wp branchline diff [--from=<ref>] [--to=<ref>]

Row counts added, modified and deleted per table between two refs. Defaults: from production, to this branch. A ref is a branch name or a snapshot hash.

### preview

    wp branchline preview --into=<branch> --from=<branch>

A dry run of any merge, printed as JSON. Nothing changes. Fields:

| Field | Meaning |
|---|---|
| `target`, `source` | branch names |
| `target_hash`, `source_hash`, `base` | the snapshots the preview is pinned to |
| `up_to_date` | true when the target already contains the source |
| `fast_forward` | true when the target has not moved since the split |
| `incoming[]` | per table: `table`, `label`, `added`, `modified`, `deleted`, `approx`, `samples[]` |
| `attachments` | attachments arriving whose files will be copied |
| `uploads_from`, `uploads_to` | the uploads directories in play, or null when unknown |
| `auto[]` | conflicts the rules resolved: `key`, `label`, `class`, `resolution`, `reason` |
| `human[]` | conflicts needing a decision: the same fields plus `ours`, `theirs`, `base`, `merged`, `clashes[]` |
| `silent` | count of bookkeeping conflicts resolved without review |

`approx` is true on a table with more than 5,000 changed rows, where the counts come from table statistics and include bookkeeping rows.

## Changing state

### snapshot

    wp branchline snapshot [--message=<text>]

Records unsaved changes as a snapshot. Prints the new hash, or says there was nothing to snapshot.

### pull

    wp branchline pull [--yes] [--resolve=<key>=<choice>]... [--message=<text>]

Merges production into this branch. Prints what comes in, what the rules resolved, and any clashes needing a decision. Without `--yes` it asks for each decision and then for confirmation. With `--yes` it requires every decision to be given with `--resolve`, and errors naming the first unresolved key otherwise.

Choices: `ours` keeps this branch's row, `theirs` takes the other branch's row, `merged` takes the combined row where one is offered, `delete` removes the row.

### publish

    wp branchline publish [--yes] [--resolve=<key>=<choice>]... [--message=<text>]

Merges this branch into production. Same options as pull.

### merge

    wp branchline merge <branch> [--yes] [--resolve=<key>=<choice>]... [--message=<text>]

Merges any local branch into this one. Same options as pull. The branch must exist locally; fetch and track it first if it only exists on a remote.

### rollback

    wp branchline rollback <hash> [--yes]

Moves this branch back to a snapshot in its history. Unsaved changes are recorded first, the current head is saved as a tag named `before-rollback-<branch>-<timestamp>`, then the branch is reset. Prints the new head and the tag name.

## Remotes

### remote

    wp branchline remote
    wp branchline remote add <name> <url>
    wp branchline remote remove <name>

Lists, adds or removes a remote. URLs: another Dolt server (`http://host:port/db` for a server with remotes enabled), DoltHub (`https://doltremoteapi.dolthub.com/org/db`), DoltLab, a bucket (`aws://bucket/path`, `gs://bucket/path`), or a directory (`file:///path`).

### fetch

    wp branchline fetch [--remote=origin]

Fetches, then lists the remote's branches with head hashes.

### push

    wp branchline push [--remote=origin] [--branch=<name>]

Records unsaved changes as a snapshot and pushes the branch. Defaults to this branch.

### track

    wp branchline track <remote>/<branch> [--as=<local-name>]

Creates a local branch from a remote one, or fast-forwards the local branch if it already exists.

## Moving a site

### import

    wp branchline import --to=mysql://user:pass@host:port/db [--from=mysql://...] [--branches=a,b] [--batch=1000] [--replace] [--dry-run] [--yes]

Copies a MySQL or MariaDB database onto a Dolt server. `--from` defaults to the database this site is running on. Prints each table as it goes, compares row counts, records a baseline snapshot, creates the named branches, and prints the `wp-config.php` lines to use. `--dry-run` lists tables and sizes and copies nothing. `--replace` drops tables that already exist on the target.

## Examples

A pipeline step that publishes if and only if nothing needs a decision:

    wp branchline publish --yes --message="Release $CI_COMMIT_TAG" || exit 1

Publish and resolve one known clash in favour of the combined row:

    wp branchline publish --yes --resolve=wp_posts:3f2a9c1d7e5b4a6f8c0d2e1f3a5b7c9d=merged

Find the key for a clash without applying anything:

    wp branchline preview --into=main --from=stage | jq '.human[] | {key, label, reason}'

Nightly: pull production into staging so the release branch never drifts far:

    wp branchline pull --yes || notify "Staging pull needs a decision"
