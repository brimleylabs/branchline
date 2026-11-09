# Workspaces and releases

Workspaces change how a team works on a WordPress site. Nobody edits on staging. Everyone works in a named workspace, served by the staging site, and promotes finished work into stage. Stage is therefore always exactly one thing: production, or the next release, tested and waiting. A release moves stage to production as one named step, code and data together, and can be rolled back as one step.

The problem this solves: fifty changes are ready to go out together and twenty more are half finished on the same staging site. Before, publishing shipped all seventy. Now the twenty were never on stage.

## The three levels

| Level | What it is | Who writes to it |
|---|---|---|
| Workspace | A named branch of data, and optionally of code, created from stage. Many exist at once. | Editors, developers, agents. Anyone doing work. |
| Stage | Exactly one code commit and one data branch. Identical to production, or the next release. | Nobody directly. Only promotion from a workspace. |
| Production | One branch, one codebase, no switching logic. | Only a release from stage. Visitors, orders and comments as always. |

## The rules

1. Content flows down continuously: production to stage to every workspace, by pull, with the usual merge rules.
2. Changes flow up only by promotion: workspace to stage, stage to production. Never workspace to production.
3. A workspace must be current with stage before it can promote, the way a pull request must be up to date before merge. The check ignores bookkeeping, so production's constant churn never blocks you.
4. Promotion merges data with Branchline and code with git in one command. Code is checked for conflicts first, so a code conflict never leaves the data half done.
5. A release moves stage to production as one coupled step. Production is tagged before and after, the code is tagged with the same name, and the site's deploy command runs before the data merge, so new code is live for the seconds in which it has to read old data.
6. After a release, stage equals production again, and every open workspace shows as behind until it updates.

## Setting up

Workspaces run on the staging site only. In the staging site's `wp-config.php`, replace the `DB_NAME` line:

    define('WPBR_WORKSPACE_SECRET', 'a long random string');
    require_once __DIR__ . '/wp-content/plugins/branchline/extras/workspace-switch.php';
    define('DB_NAME', wpbr_workspace_db_name('mysite', 'stage'));

That is all for data workspaces. The Branchline screen shows these lines with a generated secret when workspaces are not yet set up.

To branch code as well, point Branchline at the site's git repository. Normally that is `wp-content`:

    define('WPBR_CODE_ROOT', __DIR__ . '/wp-content');

The repository must contain everything the site runs from that directory: plugins, themes, mu-plugins. Anything the repository leaves out, such as composer-managed plugins, is absent in a workspace unless you list it to be linked in:

    define('WPBR_WORKSPACE_LINKS', ['plugins/some-composer-plugin', 'vendor']);

Uploads are always linked. Never add any of this to production. Production runs one branch.

### Optional

| Constant | Default | What it does |
|---|---|---|
| `WPBR_WORKSPACE_TTL` | 43200 (12 hours) | How long a workspace cookie and a preview link stay valid |
| `WPBR_WORKSPACE_HOSTS` | not set | Array of hostname to workspace name, for a hostname per workspace instead of a cookie |
| `WPBR_WORKSPACE_CODE_DIR` | `<code root>/../wpbr-workspaces` | Where code checkouts are created. Must be served by the web server. |
| `WPBR_WORKSPACE_CODE_URL` | `<home>/wpbr-workspaces` | The URL that serves those checkouts |
| `WPBR_CODE_BRANCH_STAGE` | the checked-out branch | The git branch stage runs |
| `WPBR_CODE_BRANCH_PRODUCTION` | `main` | The git branch production runs, for the "commits ahead" count |
| `WPBR_RELEASE_COMMAND` | not set | A command that deploys code to production. Receives `WPBR_RELEASE` and `WPBR_RELEASE_REF` in its environment. |
| `WPBR_STAGE_READ_ONLY` | true | Remove editing capabilities on stage. Set to `false` to allow edits on stage anyway. |
| `WPBR_WORKSPACE_MAIL` | not set | Set to `true` to let a workspace send email. By default mail from a workspace is dropped. |
| `WPBR_GIT_BIN` | `git` | Path to git if it is not on the server's PATH |

## How a workspace runs

The staging site serves every workspace. Each request picks its branch before WordPress loads, from one of four signals: a signed cookie, a signed link, a hostname, or on the command line the `WPBR_WORKSPACE` environment variable. No cookie means stage.

- **Entering.** Pick a workspace in the admin bar, or open its preview link. The cookie is set and every request from that browser, admin and front end, runs on the workspace until you leave or it expires. You can log out and log in as a test customer and still be in the workspace; the cookie is separate from the login.
- **Preview links.** Each workspace has a signed link valid for twelve hours. Send it to a client. They see the workspace on their phone, no account needed, and cannot forge their way into any other workspace.
- **The bar.** Every page in a workspace carries a bar naming it. Every page on stage carries a bar saying stage is read-only.
- **Code.** A workspace with a code branch has its own checkout of the repository, and its requests use that checkout as `WP_CONTENT_DIR`. Edit a theme, add a plugin, commit on the workspace branch. Stage and the other workspaces run their own code.
- **Caches.** Requests in a workspace are marked not to be page-cached and the object cache is keyed by workspace, so nothing leaks between branches.
- **Side effects.** Mail sent from a workspace is dropped unless you say otherwise. Payment gateways are the plugin's own affair: put them in test mode on staging as you already should.
- **Command line.** `WPBR_WORKSPACE=spring wp post create ...` runs inside the workspace. Every Branchline command works the same way. This is how an agent works on its own branch of a site.

## The day

Create a workspace, from stage, or from production, another branch or a snapshot:

    wp branchline workspace create spring --code

Work in it. Editors through the browser, developers in the checkout, agents with `WPBR_WORKSPACE=spring wp ...`. Commit code on its branch as you go. Data needs no commits; Branchline snapshots it when it matters.

Watch its standing:

    wp branchline workspace list

When stage has moved on, because another workspace was promoted or production content was pulled, the workspace shows as behind. Bring stage in:

    wp branchline workspace update spring

Stage's content wins, the workspace's structure wins, pages merge field by field, and any true clash is shown for a decision, exactly as a pull from production works. Code merges stage's branch into the workspace branch.

When it is ready and current:

    wp branchline promote spring

Then test on stage, which now holds the release. When it is good:

    wp branchline release 2027-03-spring

Production is tagged `pre-release-2027-03-spring`, the code is tagged `release-2027-03-spring`, the deploy command runs, the data merges, and production is tagged `release-2027-03-spring`. Stage matches production again.

If it was wrong:

    wp branchline release rollback 2027-03-spring

Production data returns to the tag taken before the release, and the deploy command runs again with the previous release's tag. The head you left is saved as a tag, as every rollback does.

All of it has buttons on the Branchline screen of the staging site: a Workspaces card with Open, Update from stage and Promote to stage for each workspace, a New workspace form, and a Release card that shows stage against production and releases with a name.

## What stage being read-only means

With workspaces on, every editing capability is removed on stage for everyone: posts, pages, media, menus, settings screens of plugins, WooCommerce products. What stays is reading, running Branchline, and administering plugins and users. Network administrators on multisite keep everything, as WordPress always lets them. Set `WPBR_STAGE_READ_ONLY` to `false` to turn this off, but then the model no longer holds and stage can drift.

## Deleting a workspace

Deleting removes the data branch, the code branch and the checkout. Its snapshots stay in the history of anything that merged it. Promote first if the work matters.

## Limits worth knowing

- Workspaces branch code within one running WordPress core. A workspace cannot run a different WordPress version.
- The code checkout must be reachable by the web server at `WPBR_WORKSPACE_CODE_URL`, which the defaults arrange when the repository is `wp-content` inside the document root. Other layouts need the two constants set.
- Uploads are shared across stage and every workspace. A file uploaded in a workspace exists on disk for all of them; only the attachment row is branched.
- Cron runs without a cookie, so it runs on stage, never in a workspace.
- Promotion requires the stage checkout to be on its branch with no uncommitted changes, and the workspace checkout likewise for an update.
