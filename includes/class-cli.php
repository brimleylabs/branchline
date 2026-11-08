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
