# Getting started

Two paths. Most people have a site already, so start there.

## What you need

- WordPress 6.4 or newer, PHP 8.1 or newer, WP-CLI on the server for the command line parts.
- A Dolt SQL server, version 1.42 or newer. Dolt is a single binary, open source under Apache 2.0. Install it from [github.com/dolthub/dolt](https://github.com/dolthub/dolt) and run it with:

      dolt sql-server --host 0.0.0.0 --port 3306 --user root --password yourpassword

  It can run on the same machine as WordPress, on its own server, or as a hosted service. Every environment that should share branches connects to the same Dolt server.

## Path one: move an existing site

Nothing on your current database is changed. The import reads it and writes a copy to Dolt.

1. Install and activate Branchline on the site as it is. The Branchline screen in wp-admin will tell you the site is not on Dolt yet and show the import command with your database name filled in.

2. See what will be copied:

       wp branchline import --to=mysql://root:yourpassword@dolt-host:3306/mysite --branches=stage --dry-run

   It lists every table with its row count and size.

3. Copy it:

       wp branchline import --to=mysql://root:yourpassword@dolt-host:3306/mysite --branches=stage

   Each table is created on Dolt, the rows are copied in batches, and the row counts are compared. When every table matches, a baseline snapshot is recorded and the branches you named are created from it. The command prints the `wp-config.php` lines to use next. If any table does not match, it says which and stops short of telling you to switch.

4. Point production at Dolt. In `wp-config.php`:

       define('DB_HOST', 'dolt-host:3306');
       define('DB_NAME', 'mysite');

   The site carries on exactly as before. Auto-increment IDs continue from where they were.

5. Point your staging site at the branch. Same server, same credentials, the branch appended to the database name:

       define('DB_NAME', 'mysite/stage');
       define('WP_HOME', 'https://stage.example.com');
       define('WP_SITEURL', 'https://stage.example.com');

6. Install and activate Branchline on staging too. Open Branchline in wp-admin there. You will see the stage branch, production alongside it, and the Publish and Pull actions.

## Path two: a new site

1. Run a Dolt SQL server and create a database:

       mysql -h dolt-host -P 3306 -u root -p -e "CREATE DATABASE mysite"

2. Install WordPress against it as you would against MySQL. Dolt accepts the same connection settings.

3. Install and activate Branchline. Record a baseline and create a branch:

       wp branchline snapshot --message="Baseline"
       mysql -h dolt-host -P 3306 -u root -p mysite -e "CALL dolt_branch('stage')"

4. Point a second WordPress install at `mysite/stage` and install Branchline there.

## Two settings worth adding now

**Which branch is production.** The default is `main`, which is also Dolt's default. If yours is named differently:

    define('WPBR_PRODUCTION_BRANCH', 'production');

**Where each environment keeps its uploads.** With this set, files for attachments that arrive in a merge are copied over, and files for attachments a merge removes are moved to a trash folder. Set it on every environment:

    define('WPBR_UPLOADS_DIRS', [
        'main'  => '/var/www/prod/wp-content/uploads',
        'stage' => '/var/www/stage/wp-content/uploads',
    ]);

If the environments are on different machines or uploads live in object storage, see the `wpbr_copy_attachment_file` filter in [Configuration](configuration.md).

## Your first merge

On staging, change something structural: rename a menu item, change the tagline, edit a page. On production, publish a post. Then on staging open Branchline and click **Pull from production**.

The review shows the post coming in under Posts, nothing to decide, and an Apply button. Apply it. Staging now has the post, and still has its menu, tagline and page edit.

Now click **Publish to production**. The review shows the tagline and the page going up. Apply it. Production has the release, and still has its post.

That round trip, with nothing lost in either direction, is the whole point. [How it thinks](concepts.md) explains what happens when both sides touch the same thing.
