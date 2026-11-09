# Branchline documentation

Version control for the WordPress database. Every environment is a branch. Pull production content down, publish releases up, and lose nothing.

| Read this | When |
|---|---|
| [Getting started](getting-started.md) | You want it running, on a new site or an existing one |
| [How it thinks](concepts.md) | You want to know what a merge will do before you run one |
| [Workflows](workflows.md) | Release day, a developer on a laptop, a rollback, a hotfix |
| [Command line](cli.md) | Every command, every flag, what it prints |
| [Configuration](configuration.md) | Constants, capabilities, filters |
| [For automation and agents](for-agents.md) | You are a pipeline or a coding agent driving it |
| [Troubleshooting](troubleshooting.md) | Something said no, or you want to go back to MySQL |
| [Workspaces and releases](workspaces.md) | Nobody edits on staging: named workspaces for data and code, promotion into stage, named releases with rollback |

## The problem in one paragraph

A WordPress site has two kinds of change moving in opposite directions. Code and structure move up: a developer builds a release on staging, with new templates, navigation, settings, a plugin and its tables, redesigned pages. Content moves down: production keeps taking posts, comments, orders, users and uploads the whole time. When the release is ready there is no safe way to combine them. Push the staging database and you destroy a week of production content. Pull production into staging and you destroy the release. Teams freeze content, or rebuild the structural work by hand in production, or hand-merge SQL.

## What Branchline does about it

The database becomes a version-controlled database. Dolt speaks MySQL, so WordPress does not know the difference, and it has git's model underneath: commits, branches, merges, remotes. Each environment reads and writes its own branch. When the release is ready, you merge, in either direction, and the merge is a true three-way merge against the point where the branches split.

The database can tell which rows changed on which side. It cannot tell who should win when both sides changed the same row. Branchline supplies that: a small set of rules about what WordPress rows mean, a field-level merge inside rows, and a review screen that shows only the clashes no rule can settle.
