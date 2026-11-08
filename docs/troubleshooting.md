# Troubleshooting

Each entry is the message you see, then the cause, then what to do.

## "This site is not on Dolt yet"

The database behind `DB_HOST` is MySQL or MariaDB. Branchline needs Dolt. Follow the import command shown on that screen, or see [Getting started](getting-started.md).

## "Could not connect to branch 'x'"

The branch does not exist on the Dolt server, or `DB_NAME` names a database that does not exist. Check with:

    mysql -h dolt-host -u root -p -e "SELECT name FROM dolt_branches" database

Branch names are case-sensitive.

## "A branch changed since the preview. Review again before applying."

Between loading the review and clicking Apply, a snapshot landed on one of the two branches. Usually it is production's own bookkeeping, recorded by another merge or by someone else's review. Reload the review and apply again.

## "Nothing to merge"

The target already contains everything on the source. A publish right after a pull with no changes in between does this. Nothing is wrong.

## "Unresolved conflict and --yes given"

A non-interactive merge found an editorial clash. The message names the key. Either run without `--yes` to be asked, or add `--resolve=<key>=merged` (or `ours`, `theirs`). Use `preview` to see the clash first.

## "Merge still has N conflicted tables"

A resolution failed to clear a conflict. The merge was rolled back and nothing changed. This is a bug worth reporting with the output of `preview`. In the meantime resolve that item with `ours` or `theirs` instead of `merged`.

## Attachment files did not copy

The review said "uploads directory not configured". Set `WPBR_UPLOADS_DIRS` on the environment that runs the merge, with entries for both branches. The directories must be readable and writable by PHP. On separate machines, hook `wpbr_copy_attachment_file`.

## A plugin's rows went the wrong way

Plugin tables are content by default, so production wins. If a plugin stores configuration in its own table, mark those rows as structure:

    add_filter('wpbr_row_class', fn($class, $table, $row) => $table === 'wp_myplugin_settings' ? 'structure' : $class, 10, 3);

## Production has unsaved changes all the time

Expected. WordPress writes options, transients and cron on nearly every request. They are noise, never shown in a review, and every merge records them as a snapshot first. See "Keeping production honest" in [Workflows](workflows.md).

## The review is slow on a huge table

Tables with more than 5,000 changed rows are counted from statistics instead of row by row, and the review says so. The merge itself is done by the database and is fast. If the review page itself times out, run the same merge from the command line, which has no page timeout.

## Import says a table does not match

The row count on Dolt differs from the source for a table. The import stops short of telling you to switch. Causes seen so far: a table that changed on the source while the import ran (a busy production site), or a row the target rejected. Rerun with `--replace` during a quiet moment. If one table consistently differs, open an issue with the table's `SHOW CREATE TABLE`.

## I rolled back and want to undo that

Under Saved points on the Branchline screen, the head you left is listed with a Restore action. From the command line, `wp branchline log` does not show tags; list them with:

    mysql -h dolt-host -u root -p -e "SELECT tag_name, tag_hash FROM dolt_tags" database

then `wp branchline rollback <tag_hash>`.

## I want to start a branch over from production

    mysql -h dolt-host -u root -p database -e "CALL dolt_branch('-D', 'stage'); CALL dolt_branch('stage', 'main')"

Everything on the old branch is gone from the branch list but remains reachable by hash until the server garbage-collects.
