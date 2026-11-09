# For automation and agents

Branchline is built to be driven without a person at the keyboard: by a deployment pipeline, or by a coding agent working on a WordPress site. This page is written for that reader.

## The model in six lines

- Each environment is a branch of one Dolt database. The branch is in `DB_NAME` as `database/branch`.
- `pull` merges production into the current branch. `publish` merges the current branch into production. `merge <branch>` merges any local branch into the current one.
- Every merge is a three-way merge. Rules decide conflicts by what the row means. Only editorial clashes on the same field need a decision.
- `preview` prints the full plan as JSON and changes nothing.
- `--yes` makes a merge non-interactive. Every item in `human[]` must then be answered with `--resolve=<key>=<choice>`, or the command fails naming the first unanswered key.
- Every merge is a snapshot. `rollback <hash>` undoes one and keeps the head you left as a tag.

## The safe loop

1. `wp branchline preview --into=<target> --from=<source>` and read the JSON.
2. If `up_to_date` is true, stop. Nothing to do.
3. If `human` is empty, run the merge with `--yes`.
4. If `human` is not empty, for each item read `label`, `reason`, `clashes`, `ours`, `theirs` and `merged`. Decide, or escalate to a person with those fields. Then run the merge with `--yes` and one `--resolve` per key.
5. Check the exit code. On 0, the output ends with `Success:` and the new head hash.

Keys are stable for a given pair of snapshot hashes. If either branch moves between preview and apply, the apply refuses with "A branch changed since the preview". Preview again.

## Choosing a resolution

| Choice | Effect |
|---|---|
| `merged` | the combined row: fields each side changed differently are merged, clashing fields go to production (or to the target when production is not involved). Offered when `merged` is non-null. |
| `ours` | keep the target branch's row as is |
| `theirs` | take the source branch's row as is |
| `delete` | remove the row |

For an editorial clash where one side is a hotfix in production and the other is a redesign in staging, `merged` is almost always right. For a duplicate user created on both sides, escalate: the right answer depends on who they are.

## Working on your own branch

An agent should never work on production or on the shared staging branch. Make a branch, point your WordPress at it, work, then merge.

    mysql -h dolt-host -u root -p site -e "CALL dolt_branch('agent-task-123', 'stage')"
    # set DB_NAME to site/agent-task-123 in the working WordPress install
    # ... do the work ...
    wp branchline snapshot --message="Task 123: new pricing section"

Then on the staging install:

    wp branchline merge agent-task-123 --yes

If a merge is refused for decisions, the output lists each key with its label and reason. Resolve or escalate as above.

## What to tell a person

When escalating, pass along, for each item in `human[]`: `label`, `reason`, the clashing column names, and the two values from `ours` and `theirs` for those columns. That is exactly what the review screen shows, and it is enough to decide.

## Working in a workspace

When the staging site has [workspaces](workspaces.md) on, the branch-per-task pattern above has first-class commands and you do not touch `DB_NAME`:

    wp branchline workspace create task-123 --code      # data branch, code branch, checkout
    WPBR_WORKSPACE=task-123 wp post update 42 --post_title="..."
    WPBR_WORKSPACE=task-123 wp plugin activate my-plugin
    # code lives in the checkout the create command printed; commit on branch ws/task-123
    wp branchline workspace list                        # is it current with stage?
    wp branchline workspace update task-123 --yes       # if not
    wp branchline promote task-123 --yes                # into stage, when instructed
    wp branchline release --yes                         # stage to production, only when instructed

Stage is read-only, so an agent cannot edit it by accident. `promote` refuses a workspace that is behind stage. `release` tags code and data with one name and `release rollback <name>` undoes both.

## Things an agent should not do

- Do not run `rollback` on production without a person's instruction. It is reversible, but it is production.
- Do not resolve a duplicate-user violation automatically.
- Do not edit the `dolt_conflicts_*` tables directly. Use the commands.
- Do not push to a remote branch another agent or person is working on. Push your own branch.

## Teaching your agent

Whatever tool you use, the instructions below are enough for a coding agent to operate Branchline safely. Paste them into the agent's project instructions, a rules file, or whatever your tool reads at the start of a task.

> **Branchline.** This site's database is version controlled. Every environment is a branch and you get your own.
>
> Before changing anything, run `wp branchline status`. If the first line says `(production)`, stop and make a branch. Make a branch for your task from staging with `CALL dolt_branch('task-<id>', 'stage')` on the Dolt server, point the install you work in at it by setting `DB_NAME` to `database/task-<id>`, then work normally. When done, run `wp branchline snapshot --message="<what you did>"`.
>
> To merge your work into staging, run `wp branchline preview --into=stage --from=task-<id>` from the staging install and read the JSON. If `human` is empty, run `wp branchline merge task-<id> --yes --message="<message>"`. If it is not, decide each item from `label`, `reason`, `clashes`, `ours`, `theirs` and `merged`, then pass one `--resolve=<key>=<choice>` per item. `merged` is right when both sides made legitimate edits to different parts of a page. Escalate to a person when the clash is a duplicate user, or when both sides rewrote the same field.
>
> Publish to production only when instructed: `wp branchline pull --yes` first, then `wp branchline publish --yes --message="<release>"`.
>
> To undo, `wp branchline log` then `wp branchline rollback <hash>`. The head you leave is saved as a tag. Never roll production back without instruction.
>
> The merge applies these rules on its own: settings, menus, templates and global styles follow the release side; posts, comments, users, orders and plugin tables follow production; pages merge field by field; transients, cron, locks and sessions are ignored; serialized PHP and JSON merge key by key.
>
> Never work on the production branch or the shared staging branch directly, never edit `dolt_conflicts_*` tables by hand, and never resolve a duplicate user automatically.
