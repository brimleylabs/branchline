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

Numbered table prefixes (`wp_3_posts`) are understood by the rules. A multisite install has not been run end to end. Treat it as experimental and test a merge on a copy first.
