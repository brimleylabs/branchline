=== Branchline ===
Contributors: brimleylabs
Tags: staging, version control, deployment, database, merge
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Version control for the WordPress database. Every environment is a branch. Pull production content down, publish releases up, and lose nothing.

== Description ==

Code goes up from staging to production. Content comes down from production to staging. Everything built in between, the redesigned pages, the new navigation, the settings, the plugin you configured, gets overwritten by one sync or the other.

Branchline ends that. It runs your WordPress database on [Dolt](https://github.com/dolthub/dolt), a MySQL-compatible database with git's branch and merge model built in, and adds the part the database cannot know: what the rows mean.

**Every environment is a branch of one database.** Production is one branch. Staging is another. Each developer can have their own. A branch for every release if you want.

**Merging is a real three-way merge.** Not a copy, not a table push, not a replay of recorded changes. Both branches can change for as long as they like, and merging them loses nothing from either side.

**Rules that know WordPress.** Settings, navigation, menus, templates and global styles follow the release. Posts, comments, users, orders and plugin data follow production. Pages are merged field by field, and only a true clash on the same field is shown to a person. Transients, cron, locks and sessions are never shown.

**Inside a row, fields are merged.** Modified timestamps take the newest, counters take the larger, and serialized PHP or JSON values are merged key by key. A page builder layout edited on both branches comes through with both edits intact.

**A review you can read.** What comes in, by area, with examples. What the rules resolved and why. The few clashes that need a decision, side by side, with the consequence of each choice spelled out. Apply runs the merge in one transaction, pinned to the snapshots you reviewed.

**Everything else a team needs.**

* Publish, pull, and merge any branch into any other, from wp-admin or the command line.
* Remotes, so developers clone the database to a laptop and push branches back the way they push code.
* Attachment files follow their rows between environments.
* Rollback to any snapshot, with the head you leave saved as a tag.
* Two capabilities: one for pulling and merging, one for publishing to production.
* One command to move an existing site from MySQL or MariaDB onto Dolt.
* A full WP-CLI command set for pipelines, with JSON output for automation and agents.

= Verified with =

* WooCommerce installed on a release branch and published to production, then orders placed in production pulled back while the release changed prices and the currency. Production's price won, the release's currency won.
* Elementor layouts edited on both branches, merged key by key with every edit kept.
* A site on MySQL 8.4 imported onto Dolt and switched over with IDs continuing.
* 300,000 rows added on a release branch, previewed and published in about three seconds each.

= What you need =

A Dolt SQL server. Dolt is open source, free, and installs as a single binary. It can run on the same machine as WordPress, on its own server, or as a hosted service. Branchline does not work on a plain MySQL database, because the branching happens in the database itself.

Full documentation, including workflows for release teams, laptops, rollback and automation, is in the plugin's docs folder and on GitHub.

== Installation ==

**Moving an existing site**

1. Run a Dolt SQL server. One command: `dolt sql-server --port 3306`.
2. Install and activate Branchline on your site as it is, on MySQL or MariaDB.
3. From the site, run `wp branchline import --to=mysql://user:password@dolt-host:3306/dbname --branches=stage --dry-run` to see what will be copied, then run it again without `--dry-run`.
4. Point `wp-config.php` at the Dolt server. Production uses `DB_NAME` as before. The staging site uses `DB_NAME` with the branch appended, for example `dbname/stage`.
5. Open Branchline in wp-admin on the staging site.

**Starting fresh**

1. Run a Dolt SQL server and create a database.
2. Install WordPress against it as you would against MySQL.
3. Record a baseline and create a branch: `wp branchline snapshot --message="Baseline"` then `mysql -e "CALL dolt_branch('stage')"`.
4. Point the staging site at `dbname/stage` and install Branchline on both.

Optional constants in `wp-config.php`:

* `WPBR_PRODUCTION_BRANCH`, the branch that counts as production. Default `main`.
* `WPBR_UPLOADS_DIRS`, an array of branch name to uploads directory, so attachment files are copied along with their rows.

== Frequently Asked Questions ==

= Does this work on my managed WordPress host? =

Only if you can point WordPress at a Dolt server you run or rent. Branchline does not change your host's MySQL. The branching lives in Dolt. If your host does not offer Dolt, run it on a small server of your own and connect to it.

= Is Dolt safe for production? =

Dolt is a mature open source database used in production by many companies. It speaks the MySQL wire protocol, so WordPress and every plugin talk to it as they would to MySQL. Your database is also a full version history, which is a stronger safety story than a nightly backup.

= What happens when both sides change the same thing? =

The rules decide most cases. Structure follows the release, content follows production, bookkeeping is ignored. For pages, fields each side changed differently are combined, and only a change to the same field on both sides reaches you, shown side by side with three choices.

= What about serialized options and page builder data? =

Serialized PHP and JSON values are merged key by key. Two sides that change different keys are combined with no decision needed. Only a change to the same key on both sides is a clash.

= Does it move uploaded files? =

Yes. Tell it where each environment keeps its uploads with `WPBR_UPLOADS_DIRS`, and files for attachments that arrive in a merge are copied. Files of attachments a merge removes go to a trash folder under uploads, never deleted outright. Hosts with environments on different machines or object storage hook one filter and do the copy their own way.

= Can I undo a merge? =

Yes. Every merge is a snapshot. Roll back to any snapshot from the Branchline screen or with `wp branchline rollback`. The head you leave is saved as a tag, so the rollback itself can be undone.

= Does it work with multisite? =

Numbered table prefixes are understood by the rules. A multisite install has not been run end to end yet, so treat it as experimental.

= How do developers work on laptops? =

Add a remote, which can be your Dolt server, DoltHub, a self-hosted DoltLab, a storage bucket or a directory. Developers clone it, work on their own branch and push. Staging fetches, tracks the branch and merges it, from wp-admin or the command line.

= Can an automated pipeline or an AI agent drive it? =

Yes. Every command has non-interactive flags, the preview prints JSON, clashes have stable keys that can be resolved by flag, and the plugin ships a skill file that teaches a coding agent the workflow.

== Screenshots ==

1. The Branchline screen on a staging site: current snapshot, unsaved changes, rows ahead of and behind production, and the three actions.
2. Publishing to production: what comes in by area, and the one page both sides changed, shown side by side with three labelled choices.
3. Merging a developer's feature branch into staging after fetching it from a remote.
4. Pulling production content back into staging, with production's edits arriving and the release's work untouched.
5. Branches and remotes side by side: fetch, push, track a remote branch, merge any branch into this one.
6. Snapshots with rollback, and saved points so a rollback can itself be undone.
7. The command line: preview, pull and publish with JSON output for pipelines.

== Changelog ==

= 1.0.0 =
* First public release.
* Pull, publish and merge any branch with WordPress-aware rules, field-level and semantic merging, a review screen and a WP-CLI command set.
* Remotes: fetch, push, track remote branches.
* Attachment files follow their rows; files of removed attachments are moved to a trash folder.
* Rollback to any snapshot with the previous head saved as a tag.
* Capabilities for managing branches and for publishing to production.
* Import an existing MySQL or MariaDB site onto Dolt.
* Multisite table prefixes understood.

== Upgrade Notice ==

= 1.0.0 =
First public release.
