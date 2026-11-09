# Branchline

Version control for the WordPress database. Every environment is a branch. Pull production content down, publish releases up, and lose nothing.

Code goes up, content comes down, and everything built in between gets overwritten. Branchline runs the WordPress database on [Dolt](https://github.com/dolthub/dolt), a MySQL-compatible database with git's branch and merge model built in, and adds the part the database cannot know: what the rows mean.

- **Every environment is a branch** of one database. Production, staging, each developer, each release.
- **Merging is a true three-way merge.** Both sides can change for as long as they like.
- **Rules that know WordPress.** Structure follows the release. Content follows production. Pages merge field by field. Bookkeeping is never shown.
- **Fields merge inside rows.** Serialized PHP and JSON merged key by key, so page builder layouts edited on both sides come through whole.
- **A review you can read**, with only the true clashes needing a decision.
- **Workspaces.** Nobody edits on staging. Editors, developers and agents each work in a named branch of data and code, served by the staging site, and promote finished work into stage. Stage is always production or the next release. Releases are named, tagged in git and in the database, and roll back as one unit.
- Remotes for laptops, attachment files that follow their rows, rollback with saved points, capabilities, an import command for existing sites, and a WP-CLI command set with JSON output for pipelines and agents.

## Documentation

Start with [docs/getting-started.md](docs/getting-started.md). The full set:

| | |
|---|---|
| [Getting started](docs/getting-started.md) | New site or existing site, first merge |
| [How it thinks](docs/concepts.md) | Branches, snapshots, the four classes, field merging, the review |
| [Workflows](docs/workflows.md) | Release day, hotfix, laptops, several releases, rollback |
| [Command line](docs/cli.md) | Every command and what it prints |
| [Configuration](docs/configuration.md) | Constants, capabilities, filters |
| [For automation and agents](docs/for-agents.md) | The safe loop, resolving by key, instructions to give your agent |
| [Troubleshooting](docs/troubleshooting.md) | Messages and what to do, and how to leave |
| [Workspaces and releases](docs/workspaces.md) | Named workspaces for data and code, promotion into stage, named releases with rollback |

## Quick start for an existing site

    wp plugin install branchline --activate
    wp branchline import --to=mysql://root:pw@dolt-host:3306/mysite --branches=stage --dry-run
    wp branchline import --to=mysql://root:pw@dolt-host:3306/mysite --branches=stage
    # point wp-config.php at dolt-host; staging uses DB_NAME 'mysite/stage'

## Verified with

WordPress 7.1 on Dolt 2.7. WooCommerce installed on a release branch and published, then orders pulled back while prices and currency changed on the release. Elementor layouts edited on both branches merged key by key. A MySQL 8.4 site imported and switched over, and a branch dumped back out to MySQL with the plugin removed. A subdirectory multisite network with two sites pulled and published. 300,000 rows previewed and published in about three seconds each. Unit tests: `php tests/unit.php`.

## Requirements

WordPress 6.4+, PHP 8.1+, a Dolt SQL server 1.42+. WP-CLI for the command line parts.

The move is reversible. A branch dumps with mysqldump and loads into MySQL or MariaDB unchanged; see [Leaving Branchline](docs/troubleshooting.md#i-want-to-leave-branchline).

## License

GPL-2.0-or-later. Dolt is Apache 2.0.
