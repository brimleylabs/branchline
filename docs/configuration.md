# Configuration

## Constants

Set these in `wp-config.php`, before `wp-settings.php` is loaded.

| Constant | Default | What it does |
|---|---|---|
| `DB_NAME` | | `database` on production, `database/branch` on every other environment. This is how a site knows its branch. |
| `WPBR_PRODUCTION_BRANCH` | `main` | The branch that counts as production. Every other branch is a release branch. |
| `WPBR_UPLOADS_DIRS` | not set | Array of branch name to uploads directory. Needed for attachment files to be copied between environments. The branch a site runs on defaults to its own uploads directory. |

## Capabilities

Two capabilities. Both are added to the administrator role the first time the plugin loads.

| Capability | Allows |
|---|---|
| `manage_branches` | Open the Branchline screen, record snapshots, pull, merge branches, manage remotes, roll back a release branch |
| `publish_to_production` | Publish to production, roll production back |

To let editors pull and merge without being able to publish:

    wp cap add editor manage_branches

WP-CLI commands run with full rights, as every WP-CLI command does. Restrict shell access accordingly.

## Filters

### Classifying rows

`wpbr_row_class` ( string $class, string $table, array $row )

Override the class of any row: `structure`, `content`, `editorial` or `noise`. The row is an associative array of column to value, from whichever side has it.

    add_filter('wpbr_row_class', function ($class, $table, $row) {
        if ($table === 'wp_posts' && ($row['post_type'] ?? '') === 'landing_page') return 'editorial';
        return $class;
    }, 10, 3);

`wpbr_editorial_post_types` ( array $types )

Post types merged field by field with true clashes shown for a decision. Default `['page']`.

`wpbr_noise_tables` ( array $tables )

Tables, named without the prefix, whose every row is bookkeeping. Default: the Action Scheduler tables, `wc_rate_limits`, `wc_admin_notes`, `wc_admin_note_actions`.

`wpbr_noise_option_prefixes` ( array $prefixes )

Option name prefixes treated as bookkeeping. Default includes `_transient_`, `_site_transient_`, `action_scheduler_lock_`.

### Labels

`wpbr_table_label` ( string $label, string $table )

The label shown for a table on the review screen. Plugin tables default to "Plugin data: name".

### Files

`wpbr_uploads_dir` ( ?string $dir, string $branch )

The uploads directory for a branch, after `WPBR_UPLOADS_DIRS` is consulted.

`wpbr_copy_attachment_file` ( ?bool $handled, string $src, string $dst, string $rel, string $from_branch, string $to_branch, int $post_id )

Take over copying one file. Return `true` if you copied it, `false` if it could not be found, `null` to let Branchline copy it locally. Use this when environments are on different machines or uploads live in object storage.

`wpbr_trash_attachment_file` ( ?bool $handled, string $src, string $rel, string $branch, int $post_id )

Take over moving one file of a removed attachment out of the way. Same return convention.

## Dolt server settings worth knowing

Branchline sets what it needs per session. Two server-level settings matter for operations:

- Remotes on a Dolt server are served when it runs with `--remotesapi-port`. Developers clone from that port.
- Garbage collection (`dolt gc`) is safe to run; snapshots and tags keep everything Branchline relies on reachable.

## Multisite

A multisite network works the same way: one Dolt database, one branch per environment, every site in the network on the same branch. The rules understand numbered table prefixes (`wp_3_posts` follows the same rules as `wp_posts`) and the network tables: network settings in `sitemeta` follow the option rules, the `site`, `blogs` and `blogmeta` tables are structure, `signups` is content, and `registration_log` and `blog_versions` are bookkeeping. Branchline appears in each site's admin menu, and the capabilities are granted to the administrator role of each site.

Verified: a subdirectory network with two sites, pages and settings changed on the release branch, posts and comments arriving in production, pulled and published with every row landing where the rules say.

### The one thing multisite needs

The network stores its domain once, in the `site` and `blogs` tables, and every branch carries the same value. A single-site install points staging at its own host with `WP_HOME` and `WP_SITEURL`. A network cannot, and rewriting the stored host on the staging branch would publish the staging host back to production, because those rows are structure.

So instead of rewriting the rows, map the host at request time. Copy `extras/sunrise-multisite.php` from the plugin to `wp-content/sunrise.php` on every environment that is not production, and in that environment's `wp-config.php`:

    define('SUNRISE', true);
    define('WPBR_MS_CANONICAL_HOST', 'www.example.com');   // the host stored in the network tables
    define('WPBR_MS_HOST', 'stage.example.com');           // this environment's host

The file resolves incoming requests for the staging host against the stored host and swaps the host in every URL WordPress builds. Production needs nothing. Links typed into content keep whatever host was typed, which is what you want: content is content.

Setting up the staging environment is then: copy the production install's files, set `DB_NAME` to `database/stage`, add the three lines above. If your network's domain was created with a port number in it, also set `COOKIE_DOMAIN` to an empty string, since a port is not a valid cookie domain.
