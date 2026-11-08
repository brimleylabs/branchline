<?php
/**
 * wp branches <command>
 *
 *   status                      Branch, uncommitted changes, recent snapshots
 *   snapshot [--message=<m>]    Record the working set as a commit
 *   diff [--from=<ref>] [--to=<ref>]   Row counts changed between two refs
 *   pull [--yes] [--resolve=<key>=<ours|theirs|merged|delete>]...   Merge production into this branch
 *   publish [--yes] [--resolve=...]                                  Merge this branch into production
 *   preview --into=<branch> --from=<branch>   Dry run of any merge, printed as JSON
 *   merge <branch> [--yes] [--resolve=...]    Merge any local branch into this one
 *   remote [add <name> <url> | remove <name>] Remotes (another Dolt server, DoltHub, DoltLab, a bucket, a directory)
 *   fetch [--remote=origin]                   Fetch remote branches
 *   push [--remote=origin] [--branch=<b>]     Push a branch (default: this one)
 *   track <remote>/<branch> [--as=<name>]     Create or update a local branch from a remote one
 *   log [--count=20]                          Snapshots on this branch
 *   rollback <hash> [--yes]                   Move this branch back to a snapshot (current head kept as a tag)
 */
defined('ABSPATH') || exit;

class WPBR_CLI
{
    public function status($args, $assoc): void
    {
        $branch = WPBR_Dolt::current_branch();
        $d = new WPBR_Dolt($branch);
        WP_CLI::line('Branch:      ' . $branch . ($branch === WPBR_PRODUCTION_BRANCH ? ' (production)' : ''));
        WP_CLI::line('Production:  ' . WPBR_PRODUCTION_BRANCH);
        WP_CLI::line('Head:        ' . $d->head_hash());
        $st = $d->status();
        WP_CLI::line('Uncommitted: ' . (count($st) ? count($st) . ' table(s): ' . implode(', ', array_column($st, 'table_name')) : 'none'));
        WP_CLI::line('Recent snapshots:');
        foreach ($d->log(5) as $c) {
            WP_CLI::line(sprintf('  %s  %s  %s', substr($c['commit_hash'], 0, 8), substr($c['date'], 0, 16), $c['message']));
        }
        WP_CLI::line('Branches:');
        foreach ($d->branches() as $b) {
            WP_CLI::line(sprintf('  %-12s %s  %s', $b['name'], substr($b['hash'], 0, 8), substr((string) $b['latest_commit_message'], 0, 60)));
        }
        $d->close();
    }

    public function snapshot($args, $assoc): void
    {
        $d = new WPBR_Dolt(WPBR_Dolt::current_branch(), false);
        $hash = $d->snapshot($assoc['message'] ?? 'Snapshot via wp-cli');
        $d->close();
        WP_CLI::success($hash ? "Snapshot $hash" : 'Nothing to snapshot.');
    }

    public function diff($args, $assoc): void
    {
        $branch = WPBR_Dolt::current_branch();
        $d = new WPBR_Dolt($branch);
        $from = $assoc['from'] ?? WPBR_PRODUCTION_BRANCH;
        $to   = $assoc['to'] ?? $branch;
        $rows = array_filter($d->diff_stat($from, $to), fn($r) => $r['rows_added'] || $r['rows_deleted'] || $r['rows_modified']);
        $d->close();
        WP_CLI\Utils\format_items('table', array_map(fn($r) => ['table' => $r['table_name'], 'added' => $r['rows_added'], 'modified' => $r['rows_modified'], 'deleted' => $r['rows_deleted']], $rows), ['table', 'added', 'modified', 'deleted']);
    }

    public function pull($args, $assoc): void
    {
        $branch = WPBR_Dolt::current_branch();
        if ($branch === WPBR_PRODUCTION_BRANCH) WP_CLI::error('This is the production branch. Pull runs on a release branch.');
        $this->run_merge(new WPBR_Merge_Engine($branch, WPBR_PRODUCTION_BRANCH), $assoc);
    }

    public function publish($args, $assoc): void
    {
        $branch = WPBR_Dolt::current_branch();
        if ($branch === WPBR_PRODUCTION_BRANCH) WP_CLI::error('This is the production branch. Publish runs on a release branch.');
        $this->run_merge(new WPBR_Merge_Engine(WPBR_PRODUCTION_BRANCH, $branch), $assoc);
    }

    public function merge($args, $assoc): void
    {
        $branch = WPBR_Dolt::current_branch();
        $from = $args[0] ?? '';
        if ($from === '' || $from === $branch) WP_CLI::error('Give the branch to merge into ' . $branch . '.');
        $d = new WPBR_Dolt($branch);
        if (!$d->branch_exists($from)) { $d->close(); WP_CLI::error("No local branch '$from'. Fetch and track it first."); }
        $d->close();
        $this->run_merge(new WPBR_Merge_Engine($branch, $from), $assoc);
    }

    public function remote($args, $assoc): void
    {
        $d = new WPBR_Dolt(WPBR_Dolt::current_branch(), false);
        $op = $args[0] ?? 'list';
        if ($op === 'add') {
            if (empty($args[1]) || empty($args[2])) WP_CLI::error('Usage: wp branches remote add <name> <url>');
            $d->remote_add($args[1], $args[2]);
            WP_CLI::success("Remote {$args[1]} added.");
        } elseif ($op === 'remove') {
            if (empty($args[1])) WP_CLI::error('Usage: wp branches remote remove <name>');
            $d->remote_remove($args[1]);
            WP_CLI::success("Remote {$args[1]} removed.");
        } else {
            $rows = $d->remotes();
            if (!$rows) WP_CLI::line('No remotes. Add one: wp branches remote add origin <url>');
            else WP_CLI\Utils\format_items('table', $rows, ['name', 'url']);
        }
        $d->close();
    }

    public function fetch($args, $assoc): void
    {
        $d = new WPBR_Dolt(WPBR_Dolt::current_branch(), false);
        $remote = $assoc['remote'] ?? 'origin';
        $d->fetch($remote);
        $rows = $d->remote_branches();
        $d->close();
        WP_CLI::success("Fetched $remote.");
        WP_CLI\Utils\format_items('table', array_map(fn($r) => ['branch' => $r['name'], 'head' => substr($r['hash'], 0, 8), 'last snapshot' => substr((string) $r['latest_commit_message'], 0, 60)], $rows), ['branch', 'head', 'last snapshot']);
    }

    public function push($args, $assoc): void
    {
        $branch = $assoc['branch'] ?? WPBR_Dolt::current_branch();
        $d = new WPBR_Dolt($branch, false);
        $d->snapshot('Snapshot before push (' . $branch . ')');
        $msg = $d->push($assoc['remote'] ?? 'origin', $branch);
        $d->close();
        WP_CLI::success(trim(str_replace('\n', ' ', $msg)) ?: "Pushed $branch.");
    }

    public function track($args, $assoc): void
    {
        $ref = $args[0] ?? '';
        if (!str_contains($ref, '/')) WP_CLI::error('Give <remote>/<branch>, for example origin/feature-x.');
        [$remote, $rbranch] = explode('/', preg_replace('#^remotes/#', '', $ref), 2);
        $local = $assoc['as'] ?? $rbranch;
        $d = new WPBR_Dolt(WPBR_Dolt::current_branch(), false);
        $d->fetch($remote);
        if (!$d->branch_exists($local)) {
            $d->branch_from($local, "$remote/$rbranch");
            $d->close();
            WP_CLI::success("Branch $local created from $remote/$rbranch.");
            return;
        }
        $d->close();
        $t = new WPBR_Dolt($local, false);
        $msg = $t->pull_remote($remote, $rbranch);
        $t->close();
        WP_CLI::success("Branch $local updated from $remote/$rbranch. " . trim(str_replace('\n', ' ', $msg)));
    }

    /**
     * Move this site's database onto a Dolt server.
     *
     * wp branches import --to=mysql://user:pass@dolt-host:3306/dbname [--from=mysql://...] [--branches=stage,dev] [--batch=1000] [--replace] [--dry-run] [--yes]
     *
     * --from defaults to the database this site is running on. Nothing on the source is changed.
     */
    public function import($args, $assoc): void
    {
        if (empty($assoc['to'])) WP_CLI::error('Give --to=mysql://user:password@host:port/database for the Dolt server.');
        try {
            $to   = WPBR_Import::parse_dsn($assoc['to']);
            $from = !empty($assoc['from']) ? WPBR_Import::parse_dsn($assoc['from']) : WPBR_Import::current_site();
            $imp  = new WPBR_Import($from, $to);
        } catch (Throwable $e) {
            WP_CLI::error($e->getMessage());
        }
        if (!$imp->target_is_dolt()) WP_CLI::error('The target is not a Dolt server.');
        $plan = $imp->plan();
        $rows = array_sum(array_column($plan, 'rows'));
        $bytes = array_sum(array_column($plan, 'bytes'));
        WP_CLI::line(sprintf('Source: %s:%d/%s   %d tables, %s rows, %s', $from['host'], $from['port'], $from['db'], count($plan), number_format($rows), size_format($bytes)));
        WP_CLI::line(sprintf('Target: %s:%d/%s (Dolt)', $to['host'], $to['port'], $to['db']));
        if (!empty($assoc['dry-run'])) {
            WP_CLI\Utils\format_items('table', array_map(fn($p) => ['table' => $p['table'], 'rows' => $p['rows'], 'size' => size_format($p['bytes'])], $plan), ['table', 'rows', 'size']);
            $imp->close();
            return;
        }
        if (empty($assoc['yes'])) WP_CLI::confirm('Copy these tables to the Dolt server?');
        $t0 = microtime(true);
        try {
            $results = $imp->run(fn($l) => WP_CLI::line($l), (int) ($assoc['batch'] ?? 1000), !empty($assoc['replace']), array_filter(explode(',', (string) ($assoc['branches'] ?? ''))));
        } catch (Throwable $e) {
            $imp->close();
            WP_CLI::error($e->getMessage());
        }
        $imp->close();
        $bad = array_filter($results, fn($r) => !$r['ok']);
        WP_CLI::line(sprintf('Done in %.1f s. %d tables, %s rows.', microtime(true) - $t0, count($results), number_format(array_sum(array_column($results, 'target')))));
        if ($bad) {
            WP_CLI\Utils\format_items('table', $bad, ['table', 'source', 'target']);
            WP_CLI::error(count($bad) . ' table(s) have a different row count on the target. Check them before switching.');
        }
        WP_CLI::success('Every table matches. Now point wp-config.php at the Dolt server:');
        WP_CLI::line(sprintf("  define('DB_HOST', '%s:%d');", $to['host'], $to['port']));
        WP_CLI::line(sprintf("  define('DB_NAME', '%s');          // production", $to['db']));
        foreach (array_filter(explode(',', (string) ($assoc['branches'] ?? ''))) as $b) {
            WP_CLI::line(sprintf("  define('DB_NAME', '%s/%s');   // on the %s site", $to['db'], trim($b), trim($b)));
        }
    }

    /** wp branches log [--count=20] */
    public function log($args, $assoc): void
    {
        $d = new WPBR_Dolt(WPBR_Dolt::current_branch());
        $rows = array_map(fn($c) => ['snapshot' => substr($c['commit_hash'], 0, 8), 'hash' => $c['commit_hash'], 'when' => substr($c['date'], 0, 16), 'message' => $c['message']], $d->log((int) ($assoc['count'] ?? 20)));
        $d->close();
        $format = $assoc['format'] ?? 'table';
        WP_CLI\Utils\format_items($format, $rows, $format === 'table' ? ['snapshot', 'when', 'message'] : ['hash', 'when', 'message']);
    }

    /** wp branches rollback <hash> [--yes]: move this branch back to a snapshot; the current head is kept as a tag. */
    public function rollback($args, $assoc): void
    {
        $hash = $args[0] ?? '';
        if ($hash === '') WP_CLI::error('Give the snapshot hash to roll back to. See: wp branches log');
        $branch = WPBR_Dolt::current_branch();
        $d = new WPBR_Dolt($branch, false);
        if (!$d->has_commit($hash)) { $d->close(); WP_CLI::error("Snapshot $hash is not in the history of $branch."); }
        if (empty($assoc['yes'])) WP_CLI::confirm("Roll $branch back to $hash? The current head is saved as a tag first.");
        $tag = $d->rollback_to($hash);
        $head = $d->head_hash();
        $d->close();
        wp_cache_flush();
        WP_CLI::success("$branch is now at $head. Previous head saved as tag $tag.");
    }

    public function preview($args, $assoc): void
    {
        if (empty($assoc['into']) || empty($assoc['from'])) WP_CLI::error('Give --into=<branch> and --from=<branch>.');
        $engine = new WPBR_Merge_Engine($assoc['into'], $assoc['from']);
        WP_CLI::line(wp_json_encode($engine->preview(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function run_merge(WPBR_Merge_Engine $engine, array $assoc): void
    {
        $p = $engine->preview();
        WP_CLI::line(sprintf('Merging %s into %s%s', $p['source'], $p['target'], $p['fast_forward'] ? ' (fast forward)' : ''));
        WP_CLI::line('Incoming:');
        foreach ($p['incoming'] as $t) {
            WP_CLI::line(sprintf('  %-32s +%d ~%d -%d%s', $t['label'], $t['added'], $t['modified'], $t['deleted'], !empty($t['approx']) ? '  (large table, totals include bookkeeping)' : ''));
        }
        if (!empty($p['attachments'])) {
            WP_CLI::line(sprintf('Attachments: %d; files %s', $p['attachments'], $p['uploads_from'] && $p['uploads_to'] ? "copied from {$p['uploads_from']} to {$p['uploads_to']}" : 'NOT copied (set WPBR_UPLOADS_DIRS)'));
        }
        if ($p['auto']) {
            WP_CLI::line('Resolved automatically:');
            foreach ($p['auto'] as $a) WP_CLI::line(sprintf('  %-50s %-7s %s', $a['label'], $a['resolution'], $a['reason']));
        }
        if (!empty($p['silent'])) {
            WP_CLI::line(sprintf('Bookkeeping clashes resolved without review: %d', $p['silent']));
        }
        $decisions = [];
        foreach ((array) ($assoc['resolve'] ?? []) as $r) {
            [$k, $v] = array_pad(explode('=', $r, 2), 2, null);
            if ($k && $v) $decisions[$k] = $v;
        }
        if ($p['human']) {
            WP_CLI::line('Need a decision:');
            foreach ($p['human'] as $h) {
                WP_CLI::line(sprintf('  %s  [%s]  %s', $h['key'], $h['label'], $h['reason']));
                if (!isset($decisions[$h['key']])) {
                    if (!empty($assoc['yes'])) WP_CLI::error('Unresolved conflict and --yes given. Pass --resolve=' . $h['key'] . '=ours|theirs|merged.');
                    $decisions[$h['key']] = $this->ask($h);
                }
            }
        }
        if (empty($assoc['yes'])) {
            WP_CLI::confirm('Apply this merge?');
        }
        $r = $engine->apply($decisions, ['target_hash' => $p['target_hash'], 'source_hash' => $p['source_hash']], $assoc['message'] ?? '');
        $files = WPBR_Files::describe($r['files'] ?? ['attachments' => 0]);
        WP_CLI::success('Merged. New head ' . $r['hash'] . ' on ' . $p['target'] . '.' . ($files ? ' ' . $files : ''));
    }

    private function ask(array $h): string
    {
        $opts = ['ours', 'theirs'];
        if (!empty($h['merged'])) $opts[] = 'merged';
        fwrite(STDOUT, '    choose ' . implode('/', $opts) . ': ');
        $in = trim((string) fgets(STDIN));
        return in_array($in, $opts, true) ? $in : $this->ask($h);
    }
}
