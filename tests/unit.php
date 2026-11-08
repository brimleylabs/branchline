<?php
/**
 * Unit tests for the pure parts: the classifier rules and the semantic merge.
 * No WordPress, no database. Run: php tests/unit.php
 */
declare(strict_types=1);

// ---- minimal WordPress stand-ins
define('ABSPATH', __DIR__ . '/');
function apply_filters($hook, $value, ...$args) { return $value; }
function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
function is_serialized($data, $strict = true): bool
{
    if (!is_string($data)) return false;
    $data = trim($data);
    if ($data === 'N;') return true;
    if (strlen($data) < 4 || $data[1] !== ':') return false;
    return (bool) preg_match('/^[aOsbidC]:/', $data);
}
class WPBR_Dolt
{
    public function var($sql, $params = []) { return 'post'; }
    public function ident($n) { return "`$n`"; }
}
$GLOBALS['wpdb'] = (object) ['prefix' => 'wp_', 'base_prefix' => 'wp_'];

require __DIR__ . '/../includes/class-classifier.php';
require __DIR__ . '/../includes/class-serialized-merge.php';

$pass = 0; $fail = 0;
function ok(bool $cond, string $what): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $what\n"; } else { $fail++; echo "  FAIL $what\n"; }
}
function same($a, $b, string $what): void { ok($a === $b, $what . (($a === $b) ? '' : ' (got ' . var_export($a, true) . ')')); }

echo "Classifier\n";
$c = new WPBR_Classifier(new WPBR_Dolt());
same($c->classify('wp_options', ['option_name' => 'blogname']), 'structure', 'a setting is structure');
same($c->classify('wp_options', ['option_name' => '_transient_foo']), 'noise', 'a transient is noise');
same($c->classify('wp_options', ['option_name' => '_site_transient_update_core']), 'noise', 'a site transient is noise');
same($c->classify('wp_options', ['option_name' => 'cron']), 'noise', 'cron is noise');
same($c->classify('wp_options', ['option_name' => 'action_scheduler_lock_async-request-runner']), 'noise', 'action scheduler lock is noise');
same($c->classify('wp_options', ['option_name' => 'user_count']), 'content', 'user_count follows production');
same($c->classify('wp_posts', ['post_type' => 'page', 'post_status' => 'publish']), 'editorial', 'a page is editorial');
same($c->classify('wp_posts', ['post_type' => 'post', 'post_status' => 'publish']), 'content', 'a post is content');
same($c->classify('wp_posts', ['post_type' => 'product', 'post_status' => 'publish']), 'content', 'a product is content');
same($c->classify('wp_posts', ['post_type' => 'nav_menu_item', 'post_status' => 'publish']), 'structure', 'a menu item is structure');
same($c->classify('wp_posts', ['post_type' => 'wp_template_part', 'post_status' => 'publish']), 'structure', 'a template part is structure');
same($c->classify('wp_posts', ['post_type' => 'page', 'post_status' => 'auto-draft']), 'noise', 'an auto-draft is noise');
same($c->classify('wp_postmeta', ['meta_key' => '_edit_lock', 'post_id' => 1]), 'noise', 'edit lock is noise');
same($c->classify('wp_postmeta', ['meta_key' => '_wp_trash_meta_time', 'post_id' => 1]), 'noise', 'trash meta is noise');
same($c->classify('wp_users', ['user_login' => 'x']), 'content', 'a user is content');
same($c->classify('wp_usermeta', ['meta_key' => 'session_tokens']), 'noise', 'session tokens are noise');
same($c->classify('wp_usermeta', ['meta_key' => 'wp_persisted_preferences']), 'noise', 'persisted preferences are noise');
same($c->classify('wp_usermeta', ['meta_key' => 'wp_capabilities']), 'content', 'capabilities are content');
same($c->classify('wp_term_taxonomy', ['taxonomy' => 'nav_menu']), 'structure', 'a nav menu taxonomy row is structure');
same($c->classify('wp_term_taxonomy', ['taxonomy' => 'category']), 'content', 'a category is content');
same($c->classify('wp_wc_orders', ['id' => 5]), 'content', 'a plugin table is content');
same($c->classify('wp_actionscheduler_logs', ['log_id' => 5]), 'noise', 'action scheduler logs are noise');
same($c->classify('wp_wc_rate_limits', ['rate_limit_id' => 5]), 'noise', 'rate limits are noise');
same($c->classify('wp_3_options', ['option_name' => 'blogname']), 'structure', 'multisite table prefix is understood');
same($c->classify('wp_3_actionscheduler_logs', ['log_id' => 1]), 'noise', 'multisite noise table is understood');
ok(str_contains($c->describe('wp_posts', ['post_type' => 'page', 'post_title' => 'About', 'ID' => 7]), 'Page "About" (#7)'), 'describes a page');
ok(str_contains($c->describe('wp_wc_order_stats', ['order_id' => 42, 'status' => 'wc-completed']), '#42'), 'describes a plugin row by its id column');
same($c->table_label('wp_options'), 'Settings', 'labels core tables');
same($c->table_label('wp_wc_orders'), 'Plugin data: wc_orders', 'labels plugin tables');

echo "Serialized merge\n";
$base   = serialize(['color' => 'red',  'limit' => 10, 'flags' => ['a', 'b']]);
$ours   = serialize(['color' => 'red',  'limit' => 25, 'flags' => ['a', 'b']]);
$theirs = serialize(['color' => 'blue', 'limit' => 10, 'flags' => ['a', 'b', 'c']]);
$m = WPBR_Serialized_Merge::try_merge($base, $ours, $theirs);
ok($m !== null, 'serialized PHP with changes to different keys merges');
$u = $m ? unserialize($m) : [];
same($u['color'] ?? null, 'blue', '  theirs changed color');
same($u['limit'] ?? null, 25, '  ours changed limit');
same($u['flags'] ?? null, ['a', 'b', 'c'], '  list extended on one side is kept');

$clash = WPBR_Serialized_Merge::try_merge($base, serialize(['color' => 'green', 'limit' => 10, 'flags' => ['a', 'b']]), $theirs);
same($clash, null, 'same key changed differently on both sides is a clash, not a silent pick');

$jb = json_encode(['title' => 'Hello', 'size' => 'large', 'items' => [['id' => 1, 'text' => 'one']]]);
$jo = json_encode(['title' => 'Hello', 'size' => 'small', 'items' => [['id' => 1, 'text' => 'one']]]);
$jt = json_encode(['title' => 'Hi',    'size' => 'large', 'items' => [['id' => 1, 'text' => 'one changed']]]);
$jm = WPBR_Serialized_Merge::try_merge($jb, $jo, $jt);
ok($jm !== null, 'JSON with changes to different keys merges');
$j = $jm ? json_decode($jm, true) : [];
same($j['title'] ?? null, 'Hi', '  theirs changed title');
same($j['size'] ?? null, 'small', '  ours changed size');
same($j['items'][0]['text'] ?? null, 'one changed', '  nested list element change is kept');

same(WPBR_Serialized_Merge::try_merge(null, 'plain text', 'other text'), null, 'plain strings are not merged');
same(WPBR_Serialized_Merge::try_merge($base, $ours, $ours), $ours, 'identical sides return that side');
$no_base = WPBR_Serialized_Merge::try_merge(null, serialize(['a' => 1]), serialize(['b' => 2]));
ok($no_base !== null && unserialize($no_base) === ['a' => 1, 'b' => 2], 'without a base, disjoint keys combine');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
