# Branches

Git-style branches for your WordPress database.

Every environment, production, staging, each developer, is a branch of one
database. Production keeps accumulating posts, comments, users and orders.
Staging gets the release: redesigned pages, new navigation, settings, a new
plugin and its tables. When the release is ready you merge, in either
direction, and nothing gets overwritten.

- **Pull** brings production's content down into a release branch.
- **Publish** merges the release branch into production.
- A **snapshot** records the current state of a branch, like a commit.

The merge is a real three-way merge at the row and column level, run by
[Dolt](https://github.com/dolthub/dolt), a MySQL-compatible database with git
semantics. Branches adds the part the database cannot know: what the rows mean.

## The rules

| Class | What it is | Who wins a clash |
|---|---|---|
| Structure | settings, navigation, menus, templates, global styles, reusable blocks, links | the release branch |
| Editorial | pages (configurable) | merged field by field; a real clash is shown to you |
| Content | posts, attachments, comments, users, terms, anything a plugin stores | production |
| Noise | transients, cron, edit locks, sessions, screen settings | never shown; each branch keeps its own |

Within a row, fields each side changed differently are combined. Modified
timestamps take the newest, counters take the larger, and serialized PHP or
JSON values are merged key by key. Only a true clash on the same field reaches
a rule or a person.

Because the database coordinates auto-increment IDs across branches, a post
created on staging and a post created on production never share an ID, so
references inside block JSON and serialized data stay valid after a merge.

## Moving an existing site

Install the plugin on the site as it is, on MySQL or MariaDB, and run from it:

    wp branches import --to=mysql://user:password@dolt-host:3306/dbname --branches=stage --dry-run
    wp branches import --to=mysql://user:password@dolt-host:3306/dbname --branches=stage

It copies every table, checks the row counts, records a baseline snapshot and
creates the branches you name. Nothing on the source changes. Then point
wp-config.php at the Dolt server and the site carries on with branches.

## Setup from scratch

1. Run a Dolt SQL server and create the database:

       dolt sql-server --port 3307
       mysql -h 127.0.0.1 -P 3307 -u root -e "CREATE DATABASE wp"

2. Point production's `wp-config.php` at it and install WordPress as usual:

       define('DB_NAME', 'wp');
       define('DB_HOST', '127.0.0.1:3307');

3. Snapshot and branch:

       mysql -h 127.0.0.1 -P 3307 -u root wp -e "CALL dolt_commit('-Am','baseline'); CALL dolt_branch('stage');"

4. Point the staging site at the branch. Same server, same credentials:

       define('DB_NAME', 'wp/stage');
       define('WP_HOME', 'https://stage.example.com');
       define('WP_SITEURL', 'https://stage.example.com');

5. Install this plugin on every environment. Optional: `define('WPBR_PRODUCTION_BRANCH', 'main');`

6. Tell it where each environment keeps its uploads, so attachment files follow
   their rows when a merge brings them in:

       define('WPBR_UPLOADS_DIRS', [
           'main'  => '/var/www/prod/wp-content/uploads',
           'stage' => '/var/www/stage/wp-content/uploads',
       ]);

   The branch a site runs on defaults to its own uploads directory. When
   environments are on different machines or in object storage, hook
   `wpbr_copy_attachment_file` and copy the file your own way.

## Laptops and remotes

A remote is where branches are shared: the Dolt server you already run,
DoltHub or a self-hosted DoltLab, a storage bucket, or a directory. Add it
once on each environment, from wp-admin or the command line:

    wp branches remote add origin https://doltremoteapi.dolthub.com/acme/site
    wp branches push

A developer clones the remote (`dolt clone <url>`), works on their own branch,
commits and pushes it the way they push code. On staging, fetch, track the
branch and merge it:

    wp branches fetch
    wp branches track origin/feature-nav
    wp branches merge feature-nav

The same three steps are buttons in wp-admin under Remotes. Merging a feature
branch uses the same rules as a pull: structure follows the branch being
merged in, content stays with the branch receiving it, and editorial clashes
are shown for a decision.

## Using it

In wp-admin, **Branches** shows the current branch, uncommitted changes and
recent snapshots. **Pull from production** and **Publish to production** open a
review screen: what is coming in, what was resolved automatically and why,
and the few clashes that need a decision, side by side. Apply runs the merge
in one transaction and records a commit.

From the command line, for pipelines:

    wp branches status
    wp branches snapshot --message="Release 2.4 ready"
    wp branches diff
    wp branches pull
    wp branches publish --yes --resolve=wp_posts:3f2a...=merged
    wp branches merge feature-nav
    wp branches remote | fetch | push | track origin/feature-nav

## Rolling back

Every merge and snapshot is a commit. The Branches screen lists them with a
"Roll back to this" action, and the command line has `wp branches rollback
<hash>`. The head you leave is saved as a tag first, listed under Saved
points, so a rollback can itself be undone.

## Who may do what

Two capabilities, both given to administrators on first load:

- `manage_branches` opens the screen, records snapshots, pulls, merges
  branches, manages remotes and rolls back a release branch.
- `publish_to_production` is needed to publish, and to roll production back.

Give editors `manage_branches` to let them pull and merge without being able
to publish.

## Filters

- `wpbr_editorial_post_types` array of post types merged field by field (default `['page']`)
- `wpbr_row_class` override the class of any row
- `wpbr_table_label` label for a table on the review screen
- `wpbr_noise_tables` tables whose every row is bookkeeping (queues, logs, rate limits)
- `wpbr_noise_option_prefixes` option name prefixes treated as bookkeeping
- `wpbr_uploads_dir` uploads directory for a branch
- `wpbr_copy_attachment_file` take over copying one attachment file (return true or false)

## Tested with

WordPress 7.1 on Dolt 2.7, three environments plus a laptop clone and a
remote.

- WooCommerce 11.2 installed on the release branch and published to
  production: 41 new tables, 190 settings, products. Orders placed in
  production pulled back while the release changed prices and the currency.
  Production's price won, the release's currency won.
- Elementor: the same page layout edited on both branches, heading in
  production, body and section layout in the release. Merged key by key with
  all three edits kept and no decision needed.
- A site on MySQL 8.4 imported onto Dolt, switched over, IDs continuing.
- 300,000 postmeta rows added on the release branch and published.

Unit tests for the rules and the semantic merge: `php tests/unit.php`.

## Limits in this version

- Code (themes, plugins) still deploys through git. Branches handles the database.
- Options and transients WordPress writes while a merge is being reviewed are
  committed as part of the pre-merge snapshot.
- Files of attachments removed by a merge are moved to `uploads/wpbr-trash/`,
  never deleted. Empty that folder yourself.
- Unique-key collisions between users created on both sides are shown for a decision.
- Tables with more than 5,000 changed rows are counted from table statistics,
  which include bookkeeping rows; the review screen says so.
- Multisite: table prefixes per site are understood, but a multisite install
  has not been run end to end.
- Needs a Dolt server you run or rent. Managed WordPress hosts do not offer one yet.
