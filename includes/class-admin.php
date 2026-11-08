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
        add_action('admin_post_wpbr_rollback', [self::class, 'handle_rollback']);
        add_action('admin_bar_menu', [self::class, 'admin_bar'], 100);
    }

    public static function menu(): void
    {
        add_menu_page('Branchline', 'Branchline', WPBR_CAP_MANAGE, self::SLUG, [self::class, 'render'], 'dashicons-randomize', 59);
    }

    private static function can_publish(): bool
    {
        return current_user_can(WPBR_CAP_PUBLISH);
    }

    public static function assets(string $hook): void
    {
        if (str_contains($hook, self::SLUG)) {
            wp_enqueue_style('wpbr-admin', WPBR_URL . 'assets/admin.css', [], WPBR_VERSION);
        }
    }

    public static function admin_bar(WP_Admin_Bar $bar): void
    {
        if (!current_user_can(WPBR_CAP_MANAGE)) return;
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
        if (!current_user_can(WPBR_CAP_MANAGE)) wp_die('Not allowed.');
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
        if (!current_user_can(WPBR_CAP_MANAGE)) wp_die('Not allowed.');
        $target = sanitize_text_field(wp_unslash($_POST['target'] ?? ''));
        $source = sanitize_text_field(wp_unslash($_POST['source'] ?? ''));
        if ($target === WPBR_PRODUCTION_BRANCH && !self::can_publish()) wp_die('Publishing to production needs the publish_to_production capability.');
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
        if (!current_user_can(WPBR_CAP_MANAGE)) wp_die('Not allowed.');
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

    /** Roll this branch back to an earlier snapshot. The current head is kept as a tag. */
    public static function handle_rollback(): void
    {
        check_admin_referer('wpbr_rollback');
        if (!current_user_can(WPBR_CAP_MANAGE)) wp_die('Not allowed.');
        $branch = WPBR_Dolt::current_branch();
        if ($branch === WPBR_PRODUCTION_BRANCH && !self::can_publish()) wp_die('Rolling production back needs the publish_to_production capability.');
        $hash = preg_replace('/[^a-z0-9]/', '', (string) wp_unslash($_POST['hash'] ?? ''));
        try {
            $d = new WPBR_Dolt($branch, false);
            $tag = $d->rollback_to($hash);
            $head = $d->head_hash();
            $d->close();
            wp_cache_flush();
            $msg = sprintf('%s rolled back to %s. The previous head is saved as %s, so nothing is lost.', $branch, substr($head, 0, 8), $tag);
        } catch (Throwable $e) {
            $msg = 'Rollback failed: ' . $e->getMessage();
        }
        wp_safe_redirect(self::url(['notice' => rawurlencode($msg)]));
        exit;
    }

    /** A one-button form posting to the remote handler. */
    private static function remote_form(string $op, array $fields, string $label, string $class = 'button button-small'): string
    {
        $h = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpbr-btnform">' . wp_nonce_field('wpbr_remote', '_wpnonce', true, false);
        $h .= '<input type="hidden" name="action" value="wpbr_remote"><input type="hidden" name="op" value="' . esc_attr($op) . '">';
        foreach ($fields as $k => $v) $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        return $h . '<button class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
    }

    // ------------------------------------------------------------------- screens

    public static function render(): void
    {
        if (!current_user_can(WPBR_CAP_MANAGE)) return;
        echo '<div class="wrap wpbr">';
        if (!WPBR_Dolt::is_dolt()) {
            echo '<h1>Branchline</h1>';
            echo '<div class="card"><h2>This site is not on Dolt yet</h2>';
            echo '<p>Branchline needs the database on a Dolt server, which speaks MySQL and adds branching. Moving an existing site takes one command, run from this site:</p>';
            echo '<pre class="wpbr-pre">wp branches import --to=mysql://USER:PASSWORD@DOLT_HOST:3306/' . esc_html(DB_NAME) . ' --branches=stage</pre>';
            echo '<p>It copies every table, checks the row counts, records a baseline snapshot and creates the branches you name. Then point wp-config.php at the Dolt server:</p>';
            echo '<pre class="wpbr-pre">define(\'DB_HOST\', \'DOLT_HOST:3306\');\ndefine(\'DB_NAME\', \'' . esc_html(DB_NAME) . '\');            // production\ndefine(\'DB_NAME\', \'' . esc_html(DB_NAME) . '/stage\');      // on the staging site</pre>';
            echo '<p class="wpbr-fine">Add <code>--dry-run</code> first to see the table list and sizes without copying anything.</p></div></div>';
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

    // ------------------------------------------------------------------ overview

    private static function overview(): void
    {
        $branch = WPBR_Dolt::current_branch();
        $is_prod = $branch === WPBR_PRODUCTION_BRANCH;
        $d = new WPBR_Dolt($branch);
        $head = $d->head_hash();
        $status = $d->status();
        $log = $d->log(10);
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
        $tags = $d->tags();
        $d->close();
        $may_rollback = !$is_prod || self::can_publish();

        echo '<h1>Branchline ' . self::pill($branch, $is_prod) . '</h1>';
        echo '<p class="wpbr-sub">This site reads and writes the <code>' . esc_html($branch) . '</code> branch of database <code>' . esc_html(WPBR_Dolt::base_database()) . '</code>. Production is <code>' . esc_html(WPBR_PRODUCTION_BRANCH) . '</code>.</p>';

        // ---- status bar: the numbers that matter and the three things you can do
        echo '<div class="card wpbr-status">';
        echo '<div class="wpbr-stats">';
        echo self::stat('<code>' . esc_html(substr($head, 0, 8)) . '</code>', 'current snapshot');
        echo self::stat((string) count($status), count($status) === 1 ? 'table with unsaved changes' : 'tables with unsaved changes', $status ? 'warn' : 'ok');
        if (!$is_prod) {
            echo self::stat((string) $ahead, 'rows changed here, not yet in production', $ahead ? 'info' : '');
            echo self::stat((string) $behind, 'rows changed in production, not yet here', $behind ? 'info' : '');
        }
        echo '</div>';
        echo '<div class="wpbr-actionbar">';
        if (!$is_prod) {
            if (self::can_publish()) {
                echo '<a class="button button-primary" href="' . esc_url(self::url(['view' => 'review', 'dir' => 'publish'])) . '">Publish to production</a>';
            }
            echo '<a class="button' . (self::can_publish() ? '' : ' button-primary') . '" href="' . esc_url(self::url(['view' => 'review', 'dir' => 'pull'])) . '">Pull from production</a>';
        }
        echo '<details class="wpbr-reveal"><summary class="button">Record snapshot</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpbr-inline">' . wp_nonce_field('wpbr_snapshot', '_wpnonce', true, false) . '<input type="hidden" name="action" value="wpbr_snapshot">';
        echo '<input type="text" name="message" class="regular-text" placeholder="What changed?"><button class="button button-primary">Save snapshot</button></form></details>';
        echo '</div>';
        if ($status) {
            echo '<p class="wpbr-fine">Unsaved changes in ' . implode(', ', array_map(fn($t) => '<code>' . esc_html($t['table_name']) . '</code>', $status)) . '. A merge records them in a snapshot first.</p>';
        }
        if ($is_prod) {
            echo '<p class="wpbr-fine">Pull and publish run from a release branch. Open the staging site to merge.</p>';
        } elseif ($ahead || $behind) {
            echo '<p class="wpbr-fine">Row counts leave out bookkeeping: transients, cron, locks and sessions.</p>';
        }
        echo '</div>';

        // ---- branches and remotes side by side
        echo '<div class="wpbr-cols">';

        echo '<div class="card"><h2>Branches</h2>';
        echo '<table class="wpbr-table"><thead><tr><th>Branch</th><th>Head</th><th>Last snapshot</th><th class="right"></th></tr></thead><tbody>';
        foreach ($branches as $b) {
            $is_this = $b['name'] === $branch;
            $is_p = $b['name'] === WPBR_PRODUCTION_BRANCH;
            $action = '';
            if ($is_this) {
                $action = '<span class="wpbr-tag">this site</span>';
            } elseif ($is_p) {
                $action = $is_prod ? '' : '<a class="button button-small" href="' . esc_url(self::url(['view' => 'review', 'dir' => 'pull'])) . '">Pull</a>';
            } else {
                $action = '<a class="button button-small" href="' . esc_url(self::url(['view' => 'review', 'dir' => 'merge', 'from' => $b['name']])) . '">Merge into ' . esc_html($branch) . '</a>';
            }
            echo '<tr><td><strong>' . esc_html($b['name']) . '</strong>' . ($is_p ? ' <span class="wpbr-tag prod">production</span>' : '') . '</td>';
            echo '<td><code>' . esc_html(substr($b['hash'], 0, 8)) . '</code></td>';
            echo '<td>' . esc_html((string) $b['latest_commit_message']) . '<br><span class="wpbr-fine">' . esc_html(self::when($b['latest_commit_date'] ?? '')) . '</span></td>';
            echo '<td class="right">' . $action . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<div class="card"><h2>Remotes</h2>';
        if ($remotes) {
            foreach ($remotes as $r) {
                echo '<div class="wpbr-remote"><div><strong>' . esc_html($r['name']) . '</strong><br><code class="wpbr-url">' . esc_html($r['url']) . '</code></div>';
                echo '<div class="wpbr-btns">' . self::remote_form('fetch', ['name' => $r['name']], 'Fetch') . self::remote_form('push', ['name' => $r['name']], 'Push ' . $branch) . self::remote_form('remove', ['name' => $r['name']], 'Remove', 'button-link button-link-delete') . '</div></div>';
            }
        } else {
            echo '<p class="wpbr-fine">No remotes yet. A remote is another Dolt server, DoltHub, DoltLab, a storage bucket or a directory. Developers clone it, work on their own branch and push it back.</p>';
        }
        echo '<details class="wpbr-reveal"' . ($remotes ? '' : ' open') . '><summary class="button button-small">Add a remote</summary>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpbr-inline">' . wp_nonce_field('wpbr_remote', '_wpnonce', true, false) . '<input type="hidden" name="action" value="wpbr_remote"><input type="hidden" name="op" value="add">';
        echo '<input type="text" name="name" placeholder="origin" value="origin" class="wpbr-w-s"><input type="text" name="url" placeholder="URL of the remote" class="wpbr-w-l"><button class="button">Add</button></form></details>';

        if ($remote_branches) {
            echo '<h3>Remote branches</h3><table class="wpbr-table"><thead><tr><th>Branch</th><th>Head</th><th>Last snapshot</th><th class="right"></th></tr></thead><tbody>';
            $local = array_column($branches, 'hash', 'name');
            foreach ($remote_branches as $rb) {
                $short = preg_replace('#^remotes/[^/]+/#', '', $rb['name']);
                $remote_name = preg_replace('#^remotes/([^/]+)/.*$#', '$1', $rb['name']);
                if (!isset($local[$short])) { $state = 'new'; $btn = 'Track'; }
                elseif ($local[$short] === $rb['hash']) { $state = 'up to date'; $btn = ''; }
                elseif ($short === $branch) { $state = 'behind this site'; $btn = ''; }
                else { $state = 'differs from local'; $btn = 'Update from remote'; }
                echo '<tr><td><strong>' . esc_html($short) . '</strong> <span class="wpbr-fine">' . esc_html($remote_name) . '</span></td><td><code>' . esc_html(substr($rb['hash'], 0, 8)) . '</code></td>';
                echo '<td>' . esc_html((string) $rb['latest_commit_message']) . '<br><span class="wpbr-fine">' . esc_html($state) . '</span></td>';
                echo '<td class="right">' . ($btn ? self::remote_form('track', ['ref' => $rb['name']], $btn) : '') . '</td></tr>';
            }
            echo '</tbody></table>';
        } elseif ($remotes) {
            echo '<p class="wpbr-fine">Fetch to list the branches on a remote.</p>';
        }
        echo '</div>';

        echo '</div>'; // cols

        // ---- activity, with a way back
        echo '<div class="card"><h2>Recent snapshots on ' . esc_html($branch) . '</h2><table class="wpbr-table wpbr-log"><tbody>';
        foreach ($log as $i => $c) {
            $restore = '';
            if ($i > 0 && $may_rollback) {
                $restore = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpbr-btnform">' . wp_nonce_field('wpbr_rollback', '_wpnonce', true, false) . '<input type="hidden" name="action" value="wpbr_rollback"><input type="hidden" name="hash" value="' . esc_attr($c['commit_hash']) . '"><button class="button-link">Roll back to this</button></form>';
            } elseif ($i === 0) {
                $restore = '<span class="wpbr-tag">current</span>';
            }
            echo '<tr><td class="wpbr-nowrap"><code>' . esc_html(substr($c['commit_hash'], 0, 8)) . '</code></td><td class="wpbr-nowrap wpbr-fine">' . esc_html(self::when($c['date'])) . '</td><td>' . esc_html($c['message']) . '</td><td class="right wpbr-nowrap">' . $restore . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="wpbr-fine">Rolling back moves this branch to an earlier snapshot. The head you leave is saved as a tag, so it can be restored the same way.</p>';
        if ($tags) {
            echo '<h3>Saved points</h3><table class="wpbr-table wpbr-log"><tbody>';
            foreach ($tags as $t) {
                $restore = $may_rollback ? '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpbr-btnform">' . wp_nonce_field('wpbr_rollback', '_wpnonce', true, false) . '<input type="hidden" name="action" value="wpbr_rollback"><input type="hidden" name="hash" value="' . esc_attr($t['tag_hash']) . '"><button class="button-link">Restore</button></form>' : '';
                echo '<tr><td class="wpbr-nowrap"><code>' . esc_html(substr($t['tag_hash'], 0, 8)) . '</code></td><td class="wpbr-nowrap wpbr-fine">' . esc_html(self::when($t['date'])) . '</td><td>' . esc_html($t['tag_name']) . '<br><span class="wpbr-fine">' . esc_html((string) $t['message']) . '</span></td><td class="right">' . $restore . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    // -------------------------------------------------------------------- review

    private static function review(): void
    {
        $branch = WPBR_Dolt::current_branch();
        $dir = sanitize_text_field(wp_unslash($_GET['dir'] ?? 'publish'));
        if (!in_array($dir, ['pull', 'publish', 'merge'], true)) $dir = 'publish';
        if ($dir === 'merge') {
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
            if ($dir === 'publish' && !self::can_publish()) {
                echo '<h1>Branches</h1><div class="notice notice-error"><p>Publishing to production needs the publish_to_production capability. Ask an administrator.</p></div>';
                return;
            }
            [$target, $source] = $dir === 'pull' ? [$branch, WPBR_PRODUCTION_BRANCH] : [WPBR_PRODUCTION_BRANCH, $branch];
        }
        $engine = new WPBR_Merge_Engine($target, $source);
        $p = $engine->preview();
        $titles = ['pull' => 'Pull from production', 'publish' => 'Publish to production', 'merge' => 'Merge ' . $source . ' into ' . $target];
        $verbs  = ['pull' => 'Pull into ', 'publish' => 'Publish to ', 'merge' => 'Merge into '];
        $combine_winner = in_array(WPBR_PRODUCTION_BRANCH, [$source, $target], true) ? 'production' : $target;
        $rows_in = WPBR_Merge_Engine::total($p['incoming']);
        $can_apply = (bool) $p['incoming'] && empty($p['up_to_date']);

        echo '<h1>' . esc_html($titles[$dir]) . ' <span class="wpbr-pill">' . esc_html($source) . ' <span class="wpbr-dim">to</span> ' . esc_html($target) . '</span></h1>';
        echo '<p class="wpbr-sub"><a href="' . esc_url(self::url()) . '">Back to Branchline</a></p>';

        if (!empty($p['up_to_date'])) {
            echo '<div class="wpbr-banner ok"><strong>Nothing to merge.</strong> <code>' . esc_html($target) . '</code> already contains everything on <code>' . esc_html($source) . '</code>.</div>';
            return;
        }
        if ($p['fast_forward']) {
            echo '<div class="wpbr-banner ok"><strong>Clean fast-forward.</strong> Nothing on <code>' . esc_html($target) . '</code> changed since the branches split, so no rules or decisions are needed.</div>';
        }

        // ---- summary tiles
        echo '<div class="card wpbr-status"><div class="wpbr-stats">';
        echo self::stat((string) $rows_in, 'rows come in', 'info');
        echo self::stat((string) count($p['incoming']), count($p['incoming']) === 1 ? 'area touched' : 'areas touched');
        echo self::stat((string) count($p['auto']), 'resolved by the rules', count($p['auto']) ? 'ok' : '');
        echo self::stat((string) count($p['human']), count($p['human']) === 1 ? 'needs your decision' : 'need your decision', count($p['human']) ? 'warn' : 'ok');
        if (!empty($p['attachments'])) echo self::stat((string) $p['attachments'], $p['attachments'] === 1 ? 'attachment, files copied' : 'attachments, files copied', 'info');
        echo '</div>';
        if (!empty($p['attachments']) && !($p['uploads_from'] && $p['uploads_to'] && $p['uploads_from'] !== $p['uploads_to'])) {
            echo '<p class="wpbr-fine warn">The uploads directory for <code>' . esc_html(!$p['uploads_from'] ? $source : $target) . '</code> is not configured, so attachment files will not be copied. Set <code>WPBR_UPLOADS_DIRS</code> in wp-config.php.</p>';
        }
        echo '</div>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('wpbr_apply', '_wpnonce', true, false);
        echo '<input type="hidden" name="action" value="wpbr_apply"><input type="hidden" name="target" value="' . esc_attr($target) . '"><input type="hidden" name="source" value="' . esc_attr($source) . '">';
        echo '<input type="hidden" name="target_hash" value="' . esc_attr($p['target_hash']) . '"><input type="hidden" name="source_hash" value="' . esc_attr($p['source_hash']) . '">';

        // ---- decisions first: this is the only part that needs a person
        if ($p['human']) {
            echo '<h2>Needs your decision <span class="wpbr-count">' . count($p['human']) . '</span></h2>';
            echo '<p class="wpbr-sub">Both branches changed the same thing. Pick which version <code>' . esc_html($target) . '</code> should have.</p>';
            foreach ($p['human'] as $h) {
                $k = esc_attr($h['key']);
                echo '<div class="wpbr-decision"><div class="wpbr-decision-head"><h3>' . esc_html($h['label']) . '</h3>' . self::class_tag($h['class']) . '</div><p class="why">' . esc_html($h['reason']) . '</p>';
                echo '<div class="sides"><div class="side"><h4>On ' . esc_html($target) . '</h4>' . self::show_row($h, 'ours') . '</div>';
                echo '<div class="side"><h4>On ' . esc_html($source) . '</h4>' . self::show_row($h, 'theirs') . '</div></div>';
                echo '<div class="wpbr-choices">';
                $has_merged = !empty($h['merged']) && $h['kind'] === 'conflict';
                if ($has_merged) echo '<label><input type="radio" name="decision[' . $k . ']" value="merged" checked><span>Combine</span><small>' . esc_html($combine_winner) . ' wins the clashing fields</small></label>';
                echo '<label><input type="radio" name="decision[' . $k . ']" value="ours"' . ($has_merged ? '' : ' checked') . '><span>Keep ' . esc_html($target) . '</span><small>drop the ' . esc_html($source) . ' version</small></label>';
                echo '<label><input type="radio" name="decision[' . $k . ']" value="theirs"><span>Take ' . esc_html($source) . '</span><small>replace the ' . esc_html($target) . ' version</small></label>';
                echo '</div></div>';
            }
        }

        // ---- what comes in
        echo '<h2>What comes in from ' . esc_html($source) . '</h2>';
        if (!$p['incoming']) {
            echo '<p class="wpbr-sub">Nothing. The branches are identical.</p>';
        } else {
            echo '<table class="wpbr-table wpbr-incoming"><thead><tr><th>Area</th><th>Changes</th><th>Examples</th></tr></thead><tbody>';
            foreach ($p['incoming'] as $t) {
                echo '<tr><td><strong>' . esc_html($t['label']) . '</strong><br><code>' . esc_html($t['table']) . '</code>' . (!empty($t['approx']) ? '<br><span class="wpbr-fine">large table: counts include bookkeeping</span>' : '') . '</td>';
                echo '<td class="wpbr-nowrap">' . self::chips($t) . '</td><td>';
                $samples = $t['samples'];
                $first = array_slice($samples, 0, 3);
                $rest  = array_slice($samples, 3);
                echo '<ul class="samples">';
                foreach ($first as $s) echo '<li class="' . esc_attr($s['type']) . '">' . esc_html($s['text']) . '</li>';
                echo '</ul>';
                if ($rest) {
                    echo '<details class="wpbr-more"><summary>' . count($rest) . ' more</summary><ul class="samples">';
                    foreach ($rest as $s) echo '<li class="' . esc_attr($s['type']) . '">' . esc_html($s['text']) . '</li>';
                    echo '</ul></details>';
                }
                echo '</td></tr>';
            }
            echo '</tbody></table>';
            echo '<p class="wpbr-fine">Bookkeeping rows (transients, cron, locks, sessions) are left out' . (!empty($p['silent']) ? ', and ' . (int) $p['silent'] . ' bookkeeping clash' . ($p['silent'] === 1 ? ' was' : 'es were') . ' resolved without review' : '') . '.</p>';
        }

        // ---- rules, collapsed: there for the audit trail, not for reading every time
        if ($p['auto']) {
            echo '<details class="wpbr-rules"><summary><h2>Resolved by the rules <span class="wpbr-count">' . count($p['auto']) . '</span></h2></summary>';
            echo '<table class="wpbr-table"><tbody>';
            foreach ($p['auto'] as $a) {
                echo '<tr><td><strong>' . esc_html($a['label']) . '</strong> ' . self::class_tag($a['class']) . '</td><td>' . esc_html($a['reason']) . '</td><td class="wpbr-nowrap wpbr-fine">' . esc_html(self::resolution_word($a['resolution'], $target, $source)) . '</td></tr>';
            }
            echo '</tbody></table></details>';
        }

        // ---- apply bar
        echo '<div class="card wpbr-applybar">';
        echo '<input type="text" name="message" class="regular-text" placeholder="Snapshot message, for example: Release 4 published">';
        echo '<button class="button button-primary button-hero"' . (!$can_apply ? ' disabled' : '') . '>' . esc_html($verbs[$dir] . $target) . '</button>';
        echo '<span class="wpbr-fine">Runs in one transaction, pinned to the snapshots reviewed here.</span>';
        echo '</div></form>';
    }

    // ------------------------------------------------------------------- helpers

    private static function pill(string $branch, bool $is_prod): string
    {
        return '<span class="wpbr-pill' . ($is_prod ? ' prod' : '') . '">' . esc_html($branch) . ($is_prod ? ' <span class="wpbr-dim">production</span>' : '') . '</span>';
    }

    private static function stat(string $value, string $label, string $tone = ''): string
    {
        return '<div class="wpbr-stat ' . esc_attr($tone) . '"><div class="n">' . $value . '</div><div class="l">' . esc_html($label) . '</div></div>';
    }

    private static function chips(array $t): string
    {
        $h = '';
        if ($t['added'])    $h .= '<span class="chip add">+' . (int) $t['added'] . ' added</span>';
        if ($t['modified']) $h .= '<span class="chip mod">' . (int) $t['modified'] . ' changed</span>';
        if ($t['deleted'])  $h .= '<span class="chip del">' . (int) $t['deleted'] . ' removed</span>';
        return $h;
    }

    private static function resolution_word(string $r, string $target, string $source): string
    {
        return match ($r) {
            'ours'   => 'kept ' . $target,
            'theirs' => 'took ' . $source,
            'merged' => 'combined',
            'delete' => 'removed',
            default  => $r,
        };
    }

    private static function when(string $ts): string
    {
        if ($ts === '') return '';
        $t = strtotime($ts);
        if (!$t) return $ts;
        $diff = time() - $t;
        if ($diff < 90) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' h ago';
        return wp_date('M j, H:i', $t);
    }

    private static function class_tag(string $class): string
    {
        return '<span class="class ' . esc_attr($class) . '">' . esc_html($class) . '</span>';
    }

    private static function show_row(array $h, string $side): string
    {
        $row = $h[$side] ?? null;
        if (!$row) return '<pre class="wpbr-fine">(does not exist on this branch)</pre>';
        $clashes = $h['clashes'] ?? null;
        $show = [];
        foreach ($row as $col => $val) {
            if ($clashes && !in_array($col, $clashes, true)) continue;
            if (!$clashes && in_array($col, ['option_id'], true)) continue;
            $show[] = $col . ': ' . (is_null($val) ? 'NULL' : wp_strip_all_tags((string) $val));
        }
        return '<pre>' . esc_html(implode("\n", $show)) . '</pre>';
    }
}
