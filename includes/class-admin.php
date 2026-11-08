<?php
/**
 * wp-admin > Branches: overview, review and apply screens.
 */
defined('ABSPATH') || exit;

class WPBR_Admin
{
    private const SLUG = 'wpbr';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_wpbr_snapshot', [self::class, 'handle_snapshot']);
        add_action('admin_post_wpbr_apply', [self::class, 'handle_apply']);
        add_action('admin_post_wpbr_remote', [self::class, 'handle_remote']);
        add_action('admin_bar_menu', [self::class, 'admin_bar'], 100);
    }

    public static function menu(): void
    {
        add_menu_page('Branches', 'Branches', 'manage_options', self::SLUG, [self::class, 'render'], 'dashicons-randomize', 59);
    }

    public static function assets(string $hook): void
    {
        if (str_contains($hook, self::SLUG)) {
            wp_enqueue_style('wpbr-admin', WPBR_URL . 'assets/admin.css', [], WPBR_VERSION);
        }
    }

    public static function admin_bar(WP_Admin_Bar $bar): void
    {
        if (!current_user_can('manage_options')) return;
        $branch = WPBR_Dolt::current_branch();
        $bar->add_node([
            'id' => 'wpbr', 'title' => 'Branch: ' . esc_html($branch) . ($branch === WPBR_PRODUCTION_BRANCH ? ' (production)' : ''),
            'href' => admin_url('admin.php?page=' . self::SLUG),
        ]);
    }

    private static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    // ------------------------------------------------------------------ actions

    public static function handle_snapshot(): void
    {
        check_admin_referer('wpbr_snapshot');
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $branch = WPBR_Dolt::current_branch();
        try {
            $d = new WPBR_Dolt($branch, false);
            $hash = $d->snapshot(sanitize_text_field(wp_unslash($_POST['message'] ?? '')) ?: 'Snapshot from wp-admin');
            $d->close();
            $msg = $hash ? 'Snapshot recorded: ' . substr($hash, 0, 8) : 'Nothing to snapshot. The branch has no uncommitted changes.';
        } catch (Throwable $e) {
            $msg = 'Snapshot failed: ' . $e->getMessage();
        }
        wp_safe_redirect(self::url(['notice' => rawurlencode($msg)]));
        exit;
    }

    public static function handle_apply(): void
    {
        check_admin_referer('wpbr_apply');
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $target = sanitize_text_field(wp_unslash($_POST['target'] ?? ''));
        $source = sanitize_text_field(wp_unslash($_POST['source'] ?? ''));
        $pinned = ['target_hash' => sanitize_text_field(wp_unslash($_POST['target_hash'] ?? '')), 'source_hash' => sanitize_text_field(wp_unslash($_POST['source_hash'] ?? ''))];
        $decisions = [];
        foreach ((array) ($_POST['decision'] ?? []) as $k => $v) {
            $decisions[sanitize_text_field($k)] = in_array($v, ['ours', 'theirs', 'merged', 'delete'], true) ? $v : '';
        }
        try {
            $engine = new WPBR_Merge_Engine($target, $source);
            $r = $engine->apply($decisions, $pinned, sanitize_text_field(wp_unslash($_POST['message'] ?? '')));
            $msg = sprintf('Merged %s into %s. New head %s. %d item(s) resolved.', $source, $target, substr($r['hash'], 0, 8), count($r['applied']));
            $files = WPBR_Files::describe($r['files'] ?? ['attachments' => 0]);
            if ($files) $msg .= ' ' . $files;
        } catch (Throwable $e) {
            $msg = 'Merge not applied: ' . $e->getMessage();
        }
        wp_safe_redirect(self::url(['notice' => rawurlencode($msg)]));
        exit;
    }

    /** Remote operations: add, remove, fetch, push, track. */
    public static function handle_remote(): void
    {
        check_admin_referer('wpbr_remote');
        if (!current_user_can('manage_options')) wp_die('Not allowed.');
        $op     = sanitize_text_field(wp_unslash($_POST['op'] ?? ''));
        $name   = sanitize_text_field(wp_unslash($_POST['name'] ?? 'origin'));
        $branch = WPBR_Dolt::current_branch();
        try {
            $d = new WPBR_Dolt($branch, false);
            switch ($op) {
                case 'add':
                    $url = trim((string) wp_unslash($_POST['url'] ?? ''));
                    if ($name === '' || $url === '') throw new RuntimeException('A remote needs a name and a URL.');
                    $d->remote_add($name, $url);
                    $msg = "Remote $name added.";
                    break;
                case 'remove':
                    $d->remote_remove($name);
                    $msg = "Remote $name removed.";
                    break;
                case 'fetch':
                    $d->fetch($name);
                    $msg = "Fetched $name. Remote branches are listed below.";
                    break;
                case 'push':
                    $d->snapshot('Snapshot before push (' . $branch . ')');
                    $out = $d->push($name, $branch);
                    $msg = "Pushed $branch to $name. " . trim(str_replace('\n', ' ', $out));
                    break;
                case 'track':
                    $ref = preg_replace('#^remotes/#', '', sanitize_text_field(wp_unslash($_POST['ref'] ?? '')));
                    if (!str_contains($ref, '/')) throw new RuntimeException('Pick a remote branch.');
                    [$remote, $rbranch] = explode('/', $ref, 2);
                    if (!$d->branch_exists($rbranch)) {
                        $d->branch_from($rbranch, $ref);
                        $msg = "Branch $rbranch created from $ref. You can merge it into $branch now.";
                    } else {
                        $t = new WPBR_Dolt($rbranch, false);
                        $out = $t->pull_remote($remote, $rbranch);
                        $t->close();
                        $msg = "Branch $rbranch updated from $ref. " . trim(str_replace('\n', ' ', $out));
                    }
                    break;
                default:
                    throw new RuntimeException('Unknown remote operation.');
            }
            $d->close();
        } catch (Throwable $e) {
            $msg = 'Remote operation failed: ' . $e->getMessage();
        }
        wp_safe_redirect(self::url(['notice' => rawurlencode($msg)]));
        exit;
    }

    private static function remote_form(string $op, array $fields, string $label, string $class = 'button'): string
    {
        $h = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">' . wp_nonce_field('wpbr_remote', '_wpnonce', true, false);
        $h .= '<input type="hidden" name="action" value="wpbr_remote"><input type="hidden" name="op" value="' . esc_attr($op) . '">';
        foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        return $h . '<button class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    // ------------------------------------------------------------------- screens

    public static function render(): void
    {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap wpbr">';
        if (!WPBR_Dolt::is_dolt()) {
            echo '<h1>Branches</h1><div class="notice notice-error"><p>This site is not running on a Dolt database. Branches needs one. See the readme for setup.</p></div></div>';
            return;
        }
        if (!empty($_GET['notice'])) {
            $n = sanitize_text_field(wp_unslash($_GET['notice']));
            $cls = str_starts_with($n, 'Merge not applied') || str_contains($n, 'failed') ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . $cls . ' is-dismissible"><p>' . esc_html($n) . '</p></div>';
        }
        $view = sanitize_text_field(wp_unslash($_GET['view'] ?? 'overview'));
        try {
            if ($view === 'review') self::review(); else self::overview();
        } catch (Throwable $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
        echo '</div>';
    }

    private static function overview(): void
    {
        $branch = WPBR_Dolt::current_branch();
        $is_prod = $branch === WPBR_PRODUCTION_BRANCH;
        $d = new WPBR_Dolt($branch);
        $status = $d->status();
        $log = $d->log(8);
        $branches = $d->branches();
        $prod_hash = '';
        foreach ($branches as $b) if ($b['name'] === WPBR_PRODUCTION_BRANCH) $prod_hash = $b['hash'];
        $ahead = $behind = 0;
        if (!$is_prod && $prod_hash) {
            $cls  = new WPBR_Classifier($d);
            $base = $d->merge_base(WPBR_PRODUCTION_BRANCH);
            $ahead  = WPBR_Merge_Engine::total(WPBR_Merge_Engine::summarize($d, $cls, $base, $branch, 0));
            $behind = WPBR_Merge_Engine::total(WPBR_Merge_Engine::summarize($d, $cls, $base, WPBR_PRODUCTION_BRANCH, 0));
        }
        $remotes = $d->remotes();
        $remote_branches = $remotes ? $d->remote_branches() : [];
        $d->close();

        echo '<h1>Branches <span class="wpbr-pill' . ($is_prod ? ' prod' : '') . '">' . esc_html($branch) . ($is_prod ? ' · production' : '') . '</span></h1>';
        echo '<p class="muted">This site reads and writes the <code>' . esc_html($branch) . '</code> branch of database <code>' . esc_html(WPBR_Dolt::base_database()) . '</code>. Production is <code>' . esc_html(WPBR_PRODUCTION_BRANCH) . '</code>.</p>';

        echo '<div class="wpbr-grid">';
        echo '<div class="card"><h2>Uncommitted changes</h2><p class="big">' . count($status) . ' table' . (count($status) === 1 ? '' : 's') . '</p>';
        echo '<p class="muted">' . ($status ? esc_html(implode(', ', array_column($status, 'table_name'))) : 'Everything is recorded in a snapshot.') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('wpbr_snapshot', '_wpnonce', true, false) . '<input type="hidden" name="action" value="wpbr_snapshot">';
        echo '<p><input type="text" name="message" class="regular-text" placeholder="What changed?" style="width:100%"></p><button class="button">Record snapshot</button></form></div>';

        if (!$is_prod) {
            echo '<div class="card"><h2>Compared with production</h2>';
            echo '<p class="big">' . (int) $ahead . ' <span class="muted" style="font-size:14px;font-weight:400">rows changed here</span></p>';
            echo '<p class="big">' . (int) $behind . ' <span class="muted" style="font-size:14px;font-weight:400">rows changed in production</span></p>';
            echo '<p class="muted">Bookkeeping rows (transients, cron, locks, sessions) are not counted.</p>';
            echo '<div class="actions"><a class="button button-primary" href="' . esc_url(self::url(['view' => 'review', 'dir' => 'publish'])) . '">Publish to production</a>';
            echo '<a class="button" href="' . esc_url(self::url(['view' => 'review', 'dir' => 'pull'])) . '">Pull from production</a></div></div>';
        } else {
            echo '<div class="card"><h2>Production</h2><p class="muted">Pull and publish run from a release branch. Open the staging site to merge.</p></div>';
        }

        echo '<div class="card"><h2>Branches</h2><table class="wpbr-table"><tr><th>Name</th><th>Head</th><th>Last snapshot</th><th></th></tr>';
        foreach ($branches as $b) {
            $action = '';
            if ($b['name'] !== $branch && $b['name'] !== WPBR_PRODUCTION_BRANCH) {
                $action = '<a class="button button-small" href="' . esc_url(self::url(['view' => 'review', 'dir' => 'merge', 'from' => $b['name']])) . '">Merge into ' . esc_html($branch) . '</a>';
            }
            echo '<tr><td>' . esc_html($b['name']) . ($b['name'] === $branch ? ' <span class="wpbr-pill">this site</span>' : '') . '</td><td><code>' . esc_html(substr($b['hash'], 0, 8)) . '</code></td><td>' . esc_html(mb_strimwidth((string) $b['latest_commit_message'], 0, 60, '…')) . '</td><td>' . $action . '</td></tr>';
        }
        echo '</table></div></div>';

        // Remotes: where laptops clone from and push to.
        echo '<div class="card"><h2>Remotes</h2>';
        echo '<p class="muted">A remote is another Dolt server, DoltHub, DoltLab, a storage bucket or a directory. Developers clone it, work on their own branch, and push it back. Fetch here, then track the branch and merge it into <code>' . esc_html($branch) . '</code>.</p>';
        if ($remotes) {
            echo '<table class="wpbr-table"><tr><th>Name</th><th>URL</th><th></th></tr>';
            foreach ($remotes as $r) {
                echo '<tr><td>' . esc_html($r['name']) . '</td><td><code>' . esc_html($r['url']) . '</code></td><td class="rowactions">';
                echo self::remote_form('fetch', ['name' => $r['name']], 'Fetch') . ' ';
                echo self::remote_form('push', ['name' => $r['name']], 'Push ' . $branch) . ' ';
                echo self::remote_form('remove', ['name' => $r['name']], 'Remove', 'button button-link-delete');
                echo '</td></tr>';
            }
            echo '</table>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpbr-inline">' . wp_nonce_field('wpbr_remote', '_wpnonce', true, false) . '<input type="hidden" name="action" value="wpbr_remote"><input type="hidden" name="op" value="add">';
        echo '<input type="text" name="name" placeholder="origin" value="' . ($remotes ? '' : 'origin') . '" style="width:120px"> <input type="text" name="url" placeholder="https://doltremoteapi.dolthub.com/org/db or file:///path or aws://bucket" style="width:420px"> <button class="button">Add remote</button></form>';
        if ($remote_branches) {
            echo '<h3>Remote branches</h3><table class="wpbr-table"><tr><th>Branch</th><th>Head</th><th>Last snapshot</th><th></th></tr>';
            $local = array_column($branches, 'hash', 'name');
            foreach ($remote_branches as $rb) {
                $short = preg_replace('#^remotes/[^/]+/#', '', $rb['name']);
                $state = !isset($local[$short]) ? 'not tracked yet' : ($local[$short] === $rb['hash'] ? 'up to date' : 'differs from local');
                if ($short === $branch && $state === 'differs from local') $state = 'this site has newer snapshots; push to update the remote';
                $can_track = $state === 'not tracked yet' || $state === 'differs from local';
                echo '<tr><td><code>' . esc_html($rb['name']) . '</code></td><td><code>' . esc_html(substr($rb['hash'], 0, 8)) . '</code></td><td>' . esc_html(mb_strimwidth((string) $rb['latest_commit_message'], 0, 50, '…')) . '</td>';
                echo '<td><span class="muted">' . esc_html($state) . '</span> ' . ($can_track ? self::remote_form('track', ['ref' => $rb['name']], isset($local[$short]) ? 'Update ' . $short : 'Track as ' . $short, 'button button-small') : '') . '</td></tr>';
            }
            echo '</table>';
        } elseif ($remotes) {
            echo '<p class="muted">Fetch to see remote branches.</p>';
        }
        echo '</div>';

        echo '<div class="card"><h2>Recent snapshots on ' . esc_html($branch) . '</h2><ul class="log">';
        foreach ($log as $c) {
            echo '<li><code>' . esc_html(substr($c['commit_hash'], 0, 8)) . '</code> <span class="muted">' . esc_html(substr($c['date'], 0, 16)) . '</span> ' . esc_html($c['message']) . '</li>';
        }
        echo '</ul></div>';
    }

    private static function review(): void
    {
        $branch = WPBR_Dolt::current_branch();
        $dir = sanitize_text_field(wp_unslash($_GET['dir'] ?? 'publish'));
        if (!in_array($dir, ['pull', 'publish', 'merge'], true)) $dir = 'publish';
        if ($dir === 'merge') {
            // Any other local branch (a developer's feature branch, say) into this one.
            $from = sanitize_text_field(wp_unslash($_GET['from'] ?? ''));
            $d = new WPBR_Dolt($branch);
            $ok = $from !== '' && $from !== $branch && $d->branch_exists($from);
            $d->close();
            if (!$ok) {
                echo '<h1>Branches</h1><div class="notice notice-error"><p>Pick an existing branch to merge into ' . esc_html($branch) . '.</p></div>';
                return;
            }
            [$target, $source] = [$branch, $from];
        } else {
            if ($branch === WPBR_PRODUCTION_BRANCH) {
                echo '<h1>Branches</h1><div class="notice notice-error"><p>Pull and publish run from a release branch.</p></div>';
                return;
            }
            [$target, $source] = $dir === 'pull' ? [$branch, WPBR_PRODUCTION_BRANCH] : [WPBR_PRODUCTION_BRANCH, $branch];
        }
        $engine = new WPBR_Merge_Engine($target, $source);
        $p = $engine->preview();
        $titles = ['pull' => 'Pull from production', 'publish' => 'Publish to production', 'merge' => 'Merge ' . $source . ' into ' . $target];
        // Which side wins a clashing editorial field when "Combine" is chosen.
        $combine_winner = in_array(WPBR_PRODUCTION_BRANCH, [$source, $target], true) ? 'production' : $target;

        echo '<h1>' . esc_html($titles[$dir]) . ' <span class="wpbr-pill">' . esc_html($source) . ' → ' . esc_html($target) . '</span></h1>';
        echo '<p class="muted"><a href="' . esc_url(self::url()) . '">← Back</a></p>';

        if (!empty($p['up_to_date'])) {
            echo '<div class="ff"><code>' . esc_html($target) . '</code> already contains everything on <code>' . esc_html($source) . '</code>. Nothing to merge.</div>';
        } elseif ($p['fast_forward']) {
            echo '<div class="ff">Nothing on <code>' . esc_html($target) . '</code> has changed since the branches split. This merge is a clean fast-forward.</div>';
        }

        echo '<h2>What comes in from ' . esc_html($source) . '</h2>';
        if (!$p['incoming']) {
            echo '<p class="muted">Nothing. The branches are identical.</p>';
        } else {
            echo '<table class="wpbr-table"><tr><th>Area</th><th class="num">Added</th><th class="num">Changed</th><th class="num">Removed</th><th>Examples</th></tr>';
            foreach ($p['incoming'] as $t) {
                echo '<tr><td><strong>' . esc_html($t['label']) . '</strong><br><code>' . esc_html($t['table']) . '</code>' . (!empty($t['approx']) ? '<br><span class="muted">large table: totals include bookkeeping rows</span>' : '') . '</td>';
                echo '<td class="num add">' . ($t['added'] ?: '') . '</td><td class="num mod">' . ($t['modified'] ?: '') . '</td><td class="num del">' . ($t['deleted'] ?: '') . '</td><td><ul class="samples">';
                foreach ($t['samples'] as $s) echo '<li class="' . esc_attr($s['type']) . '">' . esc_html($s['text']) . '</li>';
                echo '</ul></td></tr>';
            }
            echo '</table>';
            echo '<p class="muted">Bookkeeping rows (transients, cron, locks, sessions) are left out of these counts.</p>';
        }
        if (!empty($p['attachments'])) {
            if ($p['uploads_from'] && $p['uploads_to'] && $p['uploads_from'] !== $p['uploads_to']) {
                echo '<p>' . (int) $p['attachments'] . ' attachment(s) come in. Their files will be copied from <code>' . esc_html($p['uploads_from']) . '</code> to <code>' . esc_html($p['uploads_to']) . '</code>.</p>';
            } else {
                echo '<div class="notice notice-warning inline"><p>' . (int) $p['attachments'] . ' attachment(s) come in, but the uploads directory for <code>' . esc_html(!$p['uploads_from'] ? $source : $target) . '</code> is not configured. Set <code>WPBR_UPLOADS_DIRS</code> in wp-config.php or the files will have to be copied by hand.</p></div>';
            }
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('wpbr_apply', '_wpnonce', true, false);
        echo '<input type="hidden" name="action" value="wpbr_apply"><input type="hidden" name="target" value="' . esc_attr($target) . '"><input type="hidden" name="source" value="' . esc_attr($source) . '">';
        echo '<input type="hidden" name="target_hash" value="' . esc_attr($p['target_hash']) . '"><input type="hidden" name="source_hash" value="' . esc_attr($p['source_hash']) . '">';

        if ($p['auto']) {
            echo '<h2>Resolved by the rules (' . count($p['auto']) . ')</h2>';
            foreach ($p['auto'] as $a) {
                echo '<div class="conflict auto"><h3>' . esc_html($a['label']) . self::class_tag($a['class']) . '</h3><p class="why">' . esc_html($a['reason']) . ' <span class="muted">(' . esc_html($a['resolution']) . ')</span></p></div>';
            }
        }

        if (!empty($p['silent'])) {
            echo '<p class="muted">' . (int) $p['silent'] . ' bookkeeping clash' . ($p['silent'] === 1 ? '' : 'es') . ' (transients, cron, locks) resolved without review.</p>';
        }

        if ($p['human']) {
            echo '<h2>Needs your decision (' . count($p['human']) . ')</h2>';
            foreach ($p['human'] as $h) {
                echo '<div class="conflict"><h3>' . esc_html($h['label']) . self::class_tag($h['class']) . '</h3><p class="why">' . esc_html($h['reason']) . '</p>';
                echo '<div class="sides"><div class="side"><h4>On ' . esc_html($target) . ' (keep ours)</h4>' . self::show_row($h, 'ours') . '</div>';
                echo '<div class="side"><h4>On ' . esc_html($source) . ' (take theirs)</h4>' . self::show_row($h, 'theirs') . '</div></div>';
                $k = esc_attr($h['key']);
                echo '<div class="choices"><label><input type="radio" name="decision[' . $k . ']" value="ours" required> Keep ' . esc_html($target) . '</label>';
                echo '<label><input type="radio" name="decision[' . $k . ']" value="theirs"> Take ' . esc_html($source) . '</label>';
                if (!empty($h['merged']) && $h['kind'] === 'conflict') {
                    echo '<label><input type="radio" name="decision[' . $k . ']" value="merged" checked> Combine (' . esc_html($combine_winner) . ' wins the clashing fields)</label>';
                }
                echo '</div></div>';
            }
        } elseif ($p['incoming']) {
            echo '<p class="muted">No clashes need a decision.</p>';
        }

        echo '<p><input type="text" name="message" class="regular-text" placeholder="Snapshot message for this merge" style="width:420px"></p>';
        $verb = ['pull' => 'Apply: pull into ', 'publish' => 'Apply: publish to ', 'merge' => 'Apply: merge into '][$dir];
        echo '<p><button class="button button-primary button-hero"' . (!$p['incoming'] ? ' disabled' : '') . '>' . esc_html($verb . $target) . '</button></p></form>';
    }

    private static function class_tag(string $class): string
    {
        return ' <span class="class ' . esc_attr($class) . '">' . esc_html($class) . '</span>';
    }

    private static function show_row(array $h, string $side): string
    {
        $row = $h[$side] ?? null;
        if (!$row) return '<pre class="muted">(does not exist on this branch)</pre>';
        $clashes = $h['clashes'] ?? null;
        $show = [];
        foreach ($row as $col => $val) {
            if ($clashes && !in_array($col, $clashes, true)) continue;
            if (!$clashes && in_array($col, ['option_id'], true)) continue;
            $show[] = $col . ': ' . (is_null($val) ? 'NULL' : mb_strimwidth(wp_strip_all_tags((string) $val), 0, 400, '…'));
        }
        return '<pre>' . esc_html(implode("\n", $show)) . '</pre>';
    }

}
