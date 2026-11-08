# How it thinks

Read this once and you will be able to predict what any merge does.

## Branches, snapshots, merges

A **branch** is a complete, independent copy of the database that shares history with the others. Each environment reads and writes one branch. Writes are instant and local to that branch.

A **snapshot** is a commit: the state of a branch at a moment, with a message. WordPress keeps writing between snapshots. Before any merge, Branchline records whatever is unsaved on both branches as a snapshot, so the merge starts from a known point.

A **merge** brings one branch's changes into another. Branchline always merges three ways: the target branch, the source branch, and the **merge base**, the last snapshot both branches share. That is what makes it possible to tell "changed on one side" from "changed on both sides". Tools that copy, push tables or replay recorded changes have no merge base, so they cannot make that distinction.

Three merges have names:

- **Pull** merges production into the branch you are on.
- **Publish** merges the branch you are on into production.
- **Merge** brings any other local branch, say a developer's feature branch, into the branch you are on.

## What the database decides on its own

For every row, the database compares base, target and source.

| Situation | Result |
|---|---|
| Changed on one side only | That change is taken. No rule, no decision. |
| Added on one side only | Added. |
| Removed on one side, untouched on the other | Removed. |
| Same change on both sides | Taken once. |
| Changed differently on both sides | A conflict. Branchline decides. |
| Removed on one side, changed on the other | A conflict. Branchline decides. |
| Created separately on both sides with the same unique key | A violation. Branchline decides. |

Most merges have no conflicts at all. The rules below only run on the rows in the last three lines.

## What a row means

Branchline classifies every conflicting row into one of four classes.

**Structure.** Things a release changes on purpose: settings in `wp_options`, navigation menus and menu items, block templates and template parts, global styles, reusable blocks, font faces, links. The release branch wins. When a pull brings production into staging, staging's structure is kept. When a publish sends staging to production, staging's structure replaces production's.

**Content.** Things production accumulates: posts, attachments, comments, users and their fields, terms and taxonomies, and every table a plugin creates, including orders, products, form entries and analytics. Production wins. Whichever direction the merge runs, production's version of a content row is kept.

**Editorial.** Pages, by default. Hand-edited content that can legitimately change on both sides, where neither side should silently win. Fields are merged one by one. Only when both sides changed the same field is the row shown to a person. The filter `wpbr_editorial_post_types` adds other post types to this class.

**Noise.** Transients, cron, update locks, rewrite rules, session tokens, screen settings, edit locks, auto-drafts, the Action Scheduler queue, rate limits. Never shown, never counted. The receiving branch keeps its own.

A plugin or theme can override the class of any row with the `wpbr_row_class` filter, and mark whole tables as noise with `wpbr_noise_tables`.

## Inside a row: merging fields

When both sides changed a row, Branchline looks at each column.

1. Columns only one side changed take that side's value.
2. `post_modified` and `post_modified_gmt` take the newest.
3. Counters such as `comment_count` take the larger.
4. Serialized PHP and JSON values are parsed and merged key by key, recursively. Keys only one side changed are combined. A page builder layout where production changed a heading and staging changed a paragraph comes through with both.
5. What remains is a true clash: both sides changed the same field to different values.

A true clash on a structure row goes to the release. On a content row it goes to production. On an editorial row it is shown for a decision, with a suggested combined version in which production wins the clashing fields and everything else is merged.

## Created on both sides

Two branches can each create a setting with the same name, or a user with the same login, and the database will refuse to hold both. Branchline works out which copy came from which branch by looking at the source branch's snapshot, then applies the class rule. Two copies of a structure setting: the release's copy stays. Two copies of a content row: production's stays. Two users with the same login: shown for a decision, because guessing wrong would merge two people.

## The review

The review screen is the preview of all of the above for a specific merge, run for real inside a transaction and then rolled back, so nothing moves until you apply.

- Summary tiles: rows coming in, areas touched, resolved by the rules, need your decision, attachments.
- **Needs your decision**, first, because it is the only part that needs a person. Each clash shows both versions side by side and three choices with their consequences spelled out.
- **What comes in**, by area, with counts and examples. Bookkeeping rows are left out of the counts.
- **Resolved by the rules**, collapsed, with the reason for each.
- Apply, which runs the merge again, applies the same resolutions plus your decisions, records a snapshot and copies attachment files. It is pinned to the snapshot hashes you reviewed. If either branch moved in between, it refuses and asks you to review again.

## Fast forwards

If the target branch has not changed since the branches split, there is nothing to merge in the three-way sense. The target simply advances to the source. The review says so and skips the rules.

## IDs

Auto-increment counters are coordinated across branches by the database. A post created on staging and a post created on production never share an ID, so references inside block markup, serialized settings and plugin tables stay valid after a merge. There are no ID offsets to configure and no ID translation.

## What the database does not know

Files. Attachment files live in each environment's uploads directory, not in the database. Branchline copies the files for attachments that arrive in a merge, original and every generated size, from the source environment's uploads to the target's, using the directory map in `WPBR_UPLOADS_DIRS`. Files for attachments a merge removes are moved to `uploads/wpbr-trash/<timestamp>/`, never deleted.

Code. Themes and plugins still deploy through git. Branchline handles the database. The usual order on release day is: deploy code to production, then publish the database.
