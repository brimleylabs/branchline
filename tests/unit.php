<?php
/**
 * Unit tests for the pure parts: the classifier rules and the semantic merge.
 * No WordPress, no database. Run: php tests/unit.php
 */
declare(strict_types=1);

// ---- minimal WordPress stand-ins
define( 'ABSPATH', __DIR__ . '/' );
function apply_filters( $hook, $value, ...$args ) {
	return $value; }
function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth ); }
function is_serialized( $data, $strict = true ): bool {
	if ( ! is_string( $data ) ) {
		return false;
	}
	$data = trim( $data );
	if ( $data === 'N;' ) {
		return true;
	}
	if ( strlen( $data ) < 4 || $data[1] !== ':' ) {
		return false;
	}
	return (bool) preg_match( '/^[aOsbidC]:/', $data );
}
class WPBR_Dolt {

	public function var( $sql, $params = array() ) {
		return 'post'; }
	public function ident( $n ) {
		return "`$n`"; }
}
$GLOBALS['wpdb'] = (object) array(
	'prefix'      => 'wp_',
	'base_prefix' => 'wp_',
);

require __DIR__ . '/../includes/class-classifier.php';
require __DIR__ . '/../includes/class-serialized-merge.php';

$pass = 0;
$fail = 0;
function ok( bool $cond, string $what ): void {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  ok   $what\n";
	} else {
		++$fail;
		echo "  FAIL $what\n"; }
}
function same( $a, $b, string $what ): void {
	ok( $a === $b, $what . ( ( $a === $b ) ? '' : ' (got ' . var_export( $a, true ) . ')' ) ); }

echo "Classifier\n";
$c = new WPBR_Classifier( new WPBR_Dolt() );
same( $c->classify( 'wp_options', array( 'option_name' => 'blogname' ) ), 'structure', 'a setting is structure' );
same( $c->classify( 'wp_options', array( 'option_name' => '_transient_foo' ) ), 'noise', 'a transient is noise' );
same( $c->classify( 'wp_options', array( 'option_name' => '_site_transient_update_core' ) ), 'noise', 'a site transient is noise' );
same( $c->classify( 'wp_options', array( 'option_name' => 'cron' ) ), 'noise', 'cron is noise' );
same( $c->classify( 'wp_options', array( 'option_name' => 'action_scheduler_lock_async-request-runner' ) ), 'noise', 'action scheduler lock is noise' );
same( $c->classify( 'wp_options', array( 'option_name' => 'user_count' ) ), 'content', 'user_count follows production' );
same(
	$c->classify(
		'wp_posts',
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
		)
	),
	'editorial',
	'a page is editorial'
);
same(
	$c->classify(
		'wp_posts',
		array(
			'post_type'   => 'post',
			'post_status' => 'publish',
		)
	),
	'content',
	'a post is content'
);
same(
	$c->classify(
		'wp_posts',
		array(
			'post_type'   => 'product',
			'post_status' => 'publish',
		)
	),
	'content',
	'a product is content'
);
same(
	$c->classify(
		'wp_posts',
		array(
			'post_type'   => 'nav_menu_item',
			'post_status' => 'publish',
		)
	),
	'structure',
	'a menu item is structure'
);
same(
	$c->classify(
		'wp_posts',
		array(
			'post_type'   => 'wp_template_part',
			'post_status' => 'publish',
		)
	),
	'structure',
	'a template part is structure'
);
same(
	$c->classify(
		'wp_posts',
		array(
			'post_type'   => 'page',
			'post_status' => 'auto-draft',
		)
	),
	'noise',
	'an auto-draft is noise'
);
same(
	$c->classify(
		'wp_postmeta',
		array(
			'meta_key' => '_edit_lock',
			'post_id'  => 1,
		)
	),
	'noise',
	'edit lock is noise'
);
same(
	$c->classify(
		'wp_postmeta',
		array(
			'meta_key' => '_wp_trash_meta_time',
			'post_id'  => 1,
		)
	),
	'noise',
	'trash meta is noise'
);
same( $c->classify( 'wp_users', array( 'user_login' => 'x' ) ), 'content', 'a user is content' );
same( $c->classify( 'wp_usermeta', array( 'meta_key' => 'session_tokens' ) ), 'noise', 'session tokens are noise' );
same( $c->classify( 'wp_usermeta', array( 'meta_key' => 'wp_persisted_preferences' ) ), 'noise', 'persisted preferences are noise' );
same( $c->classify( 'wp_usermeta', array( 'meta_key' => 'wp_capabilities' ) ), 'content', 'capabilities are content' );
same( $c->classify( 'wp_term_taxonomy', array( 'taxonomy' => 'nav_menu' ) ), 'structure', 'a nav menu taxonomy row is structure' );
same( $c->classify( 'wp_term_taxonomy', array( 'taxonomy' => 'category' ) ), 'content', 'a category is content' );
same( $c->classify( 'wp_wc_orders', array( 'id' => 5 ) ), 'content', 'a plugin table is content' );
same( $c->classify( 'wp_actionscheduler_logs', array( 'log_id' => 5 ) ), 'noise', 'action scheduler logs are noise' );
same( $c->classify( 'wp_wc_rate_limits', array( 'rate_limit_id' => 5 ) ), 'noise', 'rate limits are noise' );
same( $c->classify( 'wp_3_options', array( 'option_name' => 'blogname' ) ), 'structure', 'multisite table prefix is understood' );
same( $c->classify( 'wp_3_actionscheduler_logs', array( 'log_id' => 1 ) ), 'noise', 'multisite noise table is understood' );
ok(
	str_contains(
		$c->describe(
			'wp_posts',
			array(
				'post_type'  => 'page',
				'post_title' => 'About',
				'ID'         => 7,
			)
		),
		'Page "About" (#7)'
	),
	'describes a page'
);
ok(
	str_contains(
		$c->describe(
			'wp_wc_order_stats',
			array(
				'order_id' => 42,
				'status'   => 'wc-completed',
			)
		),
		'#42'
	),
	'describes a plugin row by its id column'
);
same( $c->table_label( 'wp_options' ), 'Settings', 'labels core tables' );
same( $c->table_label( 'wp_wc_orders' ), 'Plugin data: wc_orders', 'labels plugin tables' );
ok(
	str_contains(
		$c->describe(
			'wp_posts',
			array(
				'post_type'  => 'wp_navigation',
				'post_title' => 'Header',
				'ID'         => 9,
			)
		),
		'Navigation menu "Header" (#9)'
	),
	'describes core post types by a readable name'
);

echo "Multisite network tables\n";
same( $c->classify( 'wp_sitemeta', array( 'meta_key' => 'site_admins' ) ), 'structure', 'a network setting is structure' );
same( $c->classify( 'wp_sitemeta', array( 'meta_key' => '_site_transient_update_plugins' ) ), 'noise', 'a network transient is noise' );
same( $c->classify( 'wp_sitemeta', array( 'meta_key' => 'blog_count' ) ), 'content', 'blog_count follows production' );
same( $c->classify( 'wp_sitemeta', array( 'meta_key' => 'user_count' ) ), 'content', 'network user_count follows production' );
same( $c->classify( 'wp_blogs', array( 'blog_id' => 2 ) ), 'structure', 'a site row is structure' );
same( $c->classify( 'wp_site', array( 'id' => 1 ) ), 'structure', 'the network row is structure' );
same( $c->classify( 'wp_blogmeta', array( 'meta_key' => 'x' ) ), 'structure', 'site meta is structure' );
same( $c->classify( 'wp_signups', array( 'signup_id' => 1 ) ), 'content', 'a signup is content' );
same( $c->classify( 'wp_registration_log', array( 'ID' => 1 ) ), 'noise', 'the registration log is noise' );
same( $c->table_label( 'wp_sitemeta' ), 'Network settings', 'labels network settings' );
same( $c->table_label( 'wp_blogs' ), 'Sites', 'labels the sites table' );
same( $c->describe( 'wp_sitemeta', array( 'meta_key' => 'site_name' ) ), 'Network setting "site_name"', 'describes a network setting' );
same(
	$c->describe(
		'wp_blogs',
		array(
			'blog_id' => 2,
			'domain'  => 'example.com',
			'path'    => '/shop/',
		)
	),
	'Site "example.com/shop/" (#2)',
	'describes a site'
);
ok( in_array( 'last_updated', WPBR_Classifier::TIMESTAMP_MAX_COLUMNS, true ), 'a site last_updated clash takes the newest' );
same( $c->classify( 'wp_2_comments', array( 'comment_ID' => 1 ) ), 'content', 'subsite comments are content' );
same(
	$c->classify(
		'wp_2_posts',
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
		)
	),
	'editorial',
	'subsite pages are editorial'
);
same( $c->table_label( 'wp_2_options' ), 'Settings', 'subsite tables get the core label' );
// From inside site 2, whose own prefix is wp_2_, both numbered and base tables resolve.
$GLOBALS['wpdb']->prefix = 'wp_2_';
$c2                      = new WPBR_Classifier( new WPBR_Dolt() );
same( $c2->table_label( 'wp_2_posts' ), 'Posts, pages and templates', 'own numbered prefix resolves' );
same( $c2->table_label( 'wp_posts' ), 'Posts, pages and templates', 'base prefix resolves from a subsite' );
same( $c2->table_label( 'wp_3_posts' ), 'Posts, pages and templates', 'another site prefix resolves from a subsite' );
same( $c2->classify( 'wp_sitemeta', array( 'meta_key' => 'site_admins' ) ), 'structure', 'network tables resolve from a subsite' );
$GLOBALS['wpdb']->prefix = 'wp_';

echo "Serialized merge\n";
$base   = serialize(
	array(
		'color' => 'red',
		'limit' => 10,
		'flags' => array( 'a', 'b' ),
	)
);
$ours   = serialize(
	array(
		'color' => 'red',
		'limit' => 25,
		'flags' => array( 'a', 'b' ),
	)
);
$theirs = serialize(
	array(
		'color' => 'blue',
		'limit' => 10,
		'flags' => array( 'a', 'b', 'c' ),
	)
);
$m      = WPBR_Serialized_Merge::try_merge( $base, $ours, $theirs );
ok( $m !== null, 'serialized PHP with changes to different keys merges' );
$u = $m ? unserialize( $m ) : array();
same( $u['color'] ?? null, 'blue', '  theirs changed color' );
same( $u['limit'] ?? null, 25, '  ours changed limit' );
same( $u['flags'] ?? null, array( 'a', 'b', 'c' ), '  list extended on one side is kept' );

$clash = WPBR_Serialized_Merge::try_merge(
	$base,
	serialize(
		array(
			'color' => 'green',
			'limit' => 10,
			'flags' => array( 'a', 'b' ),
		)
	),
	$theirs
);
same( $clash, null, 'same key changed differently on both sides is a clash, not a silent pick' );

$jb = json_encode(
	array(
		'title' => 'Hello',
		'size'  => 'large',
		'items' => array(
			array(
				'id'   => 1,
				'text' => 'one',
			),
		),
	)
);
$jo = json_encode(
	array(
		'title' => 'Hello',
		'size'  => 'small',
		'items' => array(
			array(
				'id'   => 1,
				'text' => 'one',
			),
		),
	)
);
$jt = json_encode(
	array(
		'title' => 'Hi',
		'size'  => 'large',
		'items' => array(
			array(
				'id'   => 1,
				'text' => 'one changed',
			),
		),
	)
);
$jm = WPBR_Serialized_Merge::try_merge( $jb, $jo, $jt );
ok( $jm !== null, 'JSON with changes to different keys merges' );
$j = $jm ? json_decode( $jm, true ) : array();
same( $j['title'] ?? null, 'Hi', '  theirs changed title' );
same( $j['size'] ?? null, 'small', '  ours changed size' );
same( $j['items'][0]['text'] ?? null, 'one changed', '  nested list element change is kept' );

same( WPBR_Serialized_Merge::try_merge( null, 'plain text', 'other text' ), null, 'plain strings are not merged' );
same( WPBR_Serialized_Merge::try_merge( $base, $ours, $ours ), $ours, 'identical sides return that side' );
$no_base = WPBR_Serialized_Merge::try_merge( null, serialize( array( 'a' => 1 ) ), serialize( array( 'b' => 2 ) ) );
ok(
	$no_base !== null && unserialize( $no_base ) === array(
		'a' => 1,
		'b' => 2,
	),
	'without a base, disjoint keys combine'
);

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
