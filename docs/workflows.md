# Workflows

Each of these is a sequence you can follow on the day.

## Release day

Staging has the release. Production has a week of new content.

1. On staging, open Branchline and click **Pull from production**. Review. Production's content comes down. Any editorial clashes are shown; decide them. Apply.
2. Test the release on staging with production's real content in it.
3. Deploy the release's code to production through git as usual.
4. On staging, click **Publish to production**. Because you pulled first, this is almost always a clean fast forward: no rules, no decisions. Apply.
5. Production has the release and all of its own content. The merge is a snapshot on production named whatever you typed in the message box.

From the command line, the same four steps:

    wp branchline pull
    # test
    git push production main   # or however code deploys
    wp branchline publish

## A hotfix in production

Someone fixes a typo on a page directly in production while staging is mid-release.

Nothing to do now. The next pull brings the fix down. If staging also edited that page, the field is merged if it was a different field, or shown for a decision if it was the same field. Either way the fix is not lost and the release is not lost.

## A developer on a laptop

Once, on staging and production, add a remote. It can be the Dolt server itself, DoltHub, a self-hosted DoltLab, a storage bucket or a directory on a shared drive:

    wp branchline remote add origin https://doltremoteapi.dolthub.com/acme/site
    wp branchline push

On the laptop, with the Dolt client installed:

    dolt clone https://doltremoteapi.dolthub.com/acme/site
    cd site
    dolt checkout -b feature-nav
    # point a local WordPress at the local dolt sql-server, branch feature-nav, and work
    dolt commit -Am "New navigation"
    dolt push origin feature-nav

On staging, bring it in:

    wp branchline fetch
    wp branchline track origin/feature-nav
    wp branchline merge feature-nav

The same three steps are buttons under Remotes on the Branchline screen: Fetch, then Track next to the branch, then Merge into stage next to the new local branch.

Merging a feature branch uses the same rules as a pull: structure follows the branch being merged in, content stays with the branch receiving it, editorial clashes are shown.

## Several releases in flight

Create a branch per release from production and point a site at each:

    mysql -h dolt-host -u root -p site -e "CALL dolt_branch('release-24', 'main')"

Each release branch pulls from production independently. Publish them in whatever order they are ready. The second publish is a three-way merge against the first, with the same rules.

## Rolling back a release

On the production site, open Branchline. Under Recent snapshots, every merge is a line. Click **Roll back to this** on the snapshot before the release. Production is back where it was. The head you left is saved under Saved points, so if the rollback was the wrong call, click **Restore** there.

From the command line:

    wp branchline log
    wp branchline rollback <hash>

A rollback moves the branch pointer. It does not rewrite other branches, and the release branch still has all of its work. Fix forward on staging and publish again.

## Moving uploads between environments

Set `WPBR_UPLOADS_DIRS` on every environment and files follow rows automatically. For environments on different machines, hook the copy:

    add_filter('wpbr_copy_attachment_file', function ($handled, $src, $dst, $rel, $from_branch, $to_branch, $post_id) {
        // copy $rel from the $from_branch environment to the $to_branch environment however you like
        return true;   // or false if the file was not found
    }, 10, 7);

Returning `null` lets Branchline try a local copy. Returning `true` or `false` tells it you handled the file.

## Keeping production honest

Production writes options, transients and cron every minute. They are noise and are never shown in a review. But they do mean production always has unsaved changes, so the first thing any merge does is record them as a snapshot. You will see snapshots named "Snapshot before merge (main)" in production's history. That is expected.

If you want cleaner history, record snapshots on production at release boundaries with a message of your own:

    wp branchline snapshot --message="Before release 24"

## Automating it

Every step above runs non-interactively. See [For automation and agents](for-agents.md).
