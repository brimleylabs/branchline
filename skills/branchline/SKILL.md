---
name: branchline
description: Work safely on a WordPress site whose database runs on Dolt with the Branchline plugin. Use when a task changes WordPress content, settings, menus, templates or plugin data and the site has a Branchline screen in wp-admin or `wp branchline` on the command line. Covers making a branch, previewing a merge as JSON, resolving clashes by key, publishing, and rolling back.
---

# Branchline

The site's database is version controlled. Every environment is a branch. You get your own.

## Before you change anything

1. Find out which branch this install is on: `wp branchline status`. The first line says the branch. If it says `(production)`, stop and make a branch.
2. Make a branch for your task from staging, and point the install you work in at it. `DB_NAME` is `database/branch`.

       mysql -h <dolt-host> -u <user> -p <database> -e "CALL dolt_branch('task-<id>', 'stage')"

3. Work normally. WordPress does not know the difference.
4. When done: `wp branchline snapshot --message="<what you did>"`.

## Merging your work into staging

Run these from the staging install.

    wp branchline preview --into=stage --from=task-<id>

Read the JSON. `human` lists clashes that need a decision. If it is empty:

    wp branchline merge task-<id> --yes --message="<message>"

If it is not empty, for each item decide from `label`, `reason`, `clashes`, `ours`, `theirs`, `merged`, then:

    wp branchline merge task-<id> --yes --resolve=<key>=merged --resolve=<key2>=ours

`merged` is right when both sides made legitimate edits to different parts of a page. Escalate to a person when the clash is a duplicate user, or when `ours` and `theirs` are both substantial rewrites of the same field.

## Publishing to production

Only when instructed. From the staging install:

    wp branchline pull --yes           # bring production's content down first
    wp branchline publish --yes --message="<release>"

If `pull` fails naming a key, resolve it as above. Publish after a pull is normally a fast forward.

## Undo

    wp branchline log
    wp branchline rollback <hash>

The head you leave is saved as a tag. Do not roll production back without instruction.

## Rules the merge applies on its own

- Settings, menus, templates, global styles: the release side wins.
- Posts, comments, users, orders, plugin tables: production wins.
- Pages: merged field by field; same field changed on both sides needs a decision.
- Transients, cron, locks, sessions: ignored.
- Serialized PHP and JSON: merged key by key.

## Never

- Work on the production branch or the shared staging branch directly.
- Edit `dolt_conflicts_*` tables by hand.
- Resolve a duplicate user automatically.
