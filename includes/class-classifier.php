<?php
/**
 * Decides what a row *means* so the merge can apply the right rule.
 *
 *   structure  Things a release changes on purpose: settings, navigation, templates,
 *              menus, global styles, reusable blocks. The release branch wins.
 *   editorial  Pages and other hand-edited content that can legitimately change on
 *              both sides. Merged cell by cell; a real clash is shown to a human.
 *   content    Things production accumulates: posts, comments, users, uploads,
 *              orders, form entries, anything a plugin stores. Production wins.
 *   noise      Transients, cron, locks, sessions. Never shown; the receiving branch keeps its own.
 */
defined( 'ABSPATH' ) || exit;

class WPBR_Classifier {

	public const STRUCTURE = 'structure';
	public const EDITORIAL = 'editorial';
	public const CONTENT   = 'content';
	public const NOISE     = 'noise';

	private const STRUCTURE_POST_TYPES = array(
		'nav_menu_item',
		'wp_navigation',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_block',
		'wp_font_face',
		'wp_font_family',
		'customize_changeset',
		'custom_css',
		'oembed_cache',
	);
	private const EDITORIAL_POST_TYPES = array( 'page' );

	private const NOISE_OPTION_PREFIXES = array( '_transient_', '_site_transient_', 'wpbr_', 'action_scheduler_lock_', 'wc_remote_inbox_notifications_', 'woocommerce_admin_notice_' );
	private const NOISE_OPTIONS         = array(
		'cron',
		'recently_activated',
		'auto_updater.lock',
		'core_updater.lock',
		'auto_core_update_notified',
		'can_compress_scripts',
		'fresh_site',
		'finished_updating_comment_type',
		'db_upgraded',
		'https_detection_errors',
		'https_migration_required',
		'theme_switched',
		'rewrite_rules',
		'recovery_keys',
		'recovery_mode_email_last_sent',
		'wp_force_deactivated_plugins',
		'admin_email_lifespan',
		'wp_calendar_block_has_published_posts',
		'finished_splitting_shared_terms',
		'auto_plugin_theme_update_emails',
		'woocommerce_queue_flush_rewrite_rules',
		'woocommerce_admin_notices',
		'woocommerce_maybe_regenerate_images_hash',
		'wc_blocks_db_schema_version',
		'woocommerce_admin_install_timestamp',
		'woocommerce_remote_variant_assignment',
		'wc_admin_last_run_time',
		'action_scheduler_hybrid_store_demarkation',
		'action_scheduler_migration_status',
	);
	// Production is the source of truth for these even though they live in wp_options.
	private const CONTENT_OPTIONS     = array( 'user_count', 'post_count', 'blog_count' );
	private const NOISE_META_KEYS     = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_trash_meta_comments_status', '_wp_desired_post_slug' );
	private const NOISE_USERMETA_KEYS = array(
		'session_tokens',
		'wp_dashboard_quick_press_last_post_id',
		'community-events-location',
		'wp_user-settings',
		'wp_user-settings-time',
		'closedpostboxes_',
		'metaboxhidden_',
		'meta-box-order_',
		'screen_layout_',
		'managenav-menuscolumnshidden',
		'nav_menu_recently_edited',
		'wp_persisted_preferences',
		'default_password_nag',
		'dismissed_wp_pointers',
		'show_welcome_panel',
		'wc_last_active',
		'_woocommerce_tracks_anon_id',
		'woocommerce_admin_task_list_tracked_started_tasks',
	);
	/** Whole tables that are bookkeeping (table name without prefix). */
	private const NOISE_TABLES = array( 'actionscheduler_actions', 'actionscheduler_claims', 'actionscheduler_groups', 'actionscheduler_logs', 'wc_rate_limits', 'wc_admin_note_actions', 'wc_admin_notes', 'registration_log', 'blog_versions' );

	/** Columns whose clashes are resolved mechanically rather than treated as edits. */
	public const TIMESTAMP_MAX_COLUMNS = array( 'post_modified', 'post_modified_gmt', 'last_updated' );
	public const COUNTER_COLUMNS       = array( 'comment_count', 'count' );

	/** Readable names for the post types WordPress itself creates. */
	private const POST_TYPE_LABELS = array(
		'post'                => 'Post',
		'page'                => 'Page',
		'attachment'          => 'Attachment',
		'revision'            => 'Revision',
		'nav_menu_item'       => 'Menu item',
		'wp_navigation'       => 'Navigation menu',
		'wp_template'         => 'Template',
		'wp_template_part'    => 'Template part',
		'wp_global_styles'    => 'Global styles',
		'wp_block'            => 'Pattern',
		'wp_font_face'        => 'Font face',
		'wp_font_family'      => 'Font family',
		'customize_changeset' => 'Customizer changeset',
		'custom_css'          => 'Custom CSS',
		'oembed_cache'        => 'Embed cache',
	);

	private string $prefix;
	private array $post_type_cache = array();

	public function __construct( private WPBR_Dolt $dolt ) {
		global $wpdb;
		$this->prefix      = $wpdb->prefix;
		$this->base_prefix = $wpdb->base_prefix ?: $wpdb->prefix;
	}

	private string $base_prefix;

	public function prefix(): string {
		return $this->prefix; }

	/** Human label for a table, for the review screen. */
	public function table_label( string $table ): string {
		$t      = $this->strip( $table );
		$labels = array(
			'posts'              => 'Posts, pages and templates',
			'postmeta'           => 'Post fields',
			'options'            => 'Settings',
			'comments'           => 'Comments',
			'commentmeta'        => 'Comment fields',
			'users'              => 'Users',
			'usermeta'           => 'User fields',
			'terms'              => 'Categories, tags and menus',
			'term_taxonomy'      => 'Taxonomies',
			'termmeta'           => 'Term fields',
			'term_relationships' => 'Term assignments',
			'links'              => 'Links',
			// Multisite network tables.
			'site'               => 'Network',
			'sitemeta'           => 'Network settings',
			'blogs'              => 'Sites',
			'blogmeta'           => 'Site fields',
			'signups'            => 'Signups',
			'registration_log'   => 'Registration log',
			'blog_versions'      => 'Site versions',
		);
		$label  = $labels[ $t ] ?? ( 'Plugin data: ' . $t );
		return apply_filters( 'wpbr_table_label', $label, $table );
	}

	/** Tables whose every row is bookkeeping: queues, logs, rate limits. */
	public function is_noise_table( string $table ): bool {
		return in_array( $this->strip( $table ), apply_filters( 'wpbr_noise_tables', self::NOISE_TABLES ), true );
	}

	/** Classify one row. $row is an associative array of column => value (any side). */
	public function classify( string $table, array $row ): string {
		$t = $this->strip( $table );
		if ( $this->is_noise_table( $table ) ) {
			return apply_filters( 'wpbr_row_class', self::NOISE, $table, $row );
		}
		$class = match ( $t ) {
			'options'            => $this->classify_option( (string) ( $row['option_name'] ?? '' ) ),
			'posts'              => ( ( $row['post_status'] ?? '' ) === 'auto-draft' ) ? self::NOISE : $this->classify_post_type( (string) ( $row['post_type'] ?? '' ) ),
			'postmeta'           => $this->classify_postmeta( $row ),
			'comments', 'commentmeta', 'users' => self::CONTENT,
			'usermeta'           => $this->is_noise_usermeta( (string) ( $row['meta_key'] ?? '' ) ) ? self::NOISE : self::CONTENT,
			'terms'              => self::CONTENT,
			'term_taxonomy'      => ( ( $row['taxonomy'] ?? '' ) === 'nav_menu' ) ? self::STRUCTURE : self::CONTENT,
			'termmeta'           => self::CONTENT,
			'term_relationships' => $this->classify_post_id( (int) ( $row['object_id'] ?? 0 ) ),
			'links'              => self::STRUCTURE,
			// Multisite: network settings follow the same rules as site settings; the
			// network, its sites and their fields are structure; signups are content.
			'sitemeta'           => $this->classify_option( (string) ( $row['meta_key'] ?? '' ) ),
			'site', 'blogs', 'blogmeta' => self::STRUCTURE,
			'signups'            => self::CONTENT,
			default              => self::CONTENT,
		};
		return apply_filters( 'wpbr_row_class', $class, $table, $row );
	}

	public function classify_option( string $name ): string {
		foreach ( apply_filters( 'wpbr_noise_option_prefixes', self::NOISE_OPTION_PREFIXES ) as $p ) {
			if ( str_starts_with( $name, $p ) ) {
				return self::NOISE;
			}
		}
		if ( in_array( $name, self::NOISE_OPTIONS, true ) ) {
			return self::NOISE;
		}
		if ( in_array( $name, self::CONTENT_OPTIONS, true ) ) {
			return self::CONTENT;
		}
		return self::STRUCTURE;
	}

	public function classify_post_type( string $type ): string {
		if ( in_array( $type, self::STRUCTURE_POST_TYPES, true ) ) {
			return self::STRUCTURE;
		}
		if ( in_array( $type, apply_filters( 'wpbr_editorial_post_types', self::EDITORIAL_POST_TYPES ), true ) ) {
			return self::EDITORIAL;
		}
		return self::CONTENT;
	}

	private function classify_postmeta( array $row ): string {
		$key = (string) ( $row['meta_key'] ?? '' );
		if ( in_array( $key, self::NOISE_META_KEYS, true ) ) {
			return self::NOISE;
		}
		return $this->classify_post_id( (int) ( $row['post_id'] ?? 0 ) );
	}

	private function is_noise_usermeta( string $key ): bool {
		foreach ( self::NOISE_USERMETA_KEYS as $k ) {
			if ( $key === $k || str_starts_with( $key, $k ) || str_ends_with( $key, $k ) ) {
				return true;
			}
		}
		return false;
	}

	/** Class of the post a child row belongs to; checks this branch, then any branch via history. */
	private function classify_post_id( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return self::CONTENT;
		}
		if ( ! array_key_exists( $post_id, $this->post_type_cache ) ) {
			$type = $this->dolt->var( 'SELECT post_type FROM ' . $this->dolt->ident( $this->prefix . 'posts' ) . ' WHERE ID = ?', array( $post_id ) );
			if ( $type === null ) {
				$type = $this->dolt->var( 'SELECT post_type FROM ' . $this->dolt->ident( 'dolt_history_' . $this->prefix . 'posts' ) . ' WHERE ID = ? ORDER BY commit_date DESC LIMIT 1', array( $post_id ) );
			}
			$this->post_type_cache[ $post_id ] = (string) $type;
		}
		return $this->classify_post_type( $this->post_type_cache[ $post_id ] );
	}

	/** Short description of a row for the review screen. */
	public function describe( string $table, array $row ): string {
		$t = $this->strip( $table );
		return match ( $t ) {
			'options'  => 'Setting "' . ( $row['option_name'] ?? '?' ) . '"',
			'posts'    => $this->post_type_label( (string) ( $row['post_type'] ?? 'post' ) ) . ' "' . ( $row['post_title'] ?: ( $row['post_name'] ?? '' ) ) . '" (#' . ( $row['ID'] ?? '?' ) . ')',
			'sitemeta' => 'Network setting "' . ( $row['meta_key'] ?? '?' ) . '"',
			'site'     => 'Network "' . ( $row['domain'] ?? '?' ) . ( $row['path'] ?? '' ) . '"',
			'blogs'    => 'Site "' . ( $row['domain'] ?? '?' ) . ( $row['path'] ?? '' ) . '" (#' . ( $row['blog_id'] ?? '?' ) . ')',
			'blogmeta' => 'Site field "' . ( $row['meta_key'] ?? '?' ) . '" for site #' . ( $row['blog_id'] ?? '?' ),
			'signups'  => 'Signup "' . ( $row['user_login'] ?? ( $row['user_email'] ?? '?' ) ) . '"',
			'postmeta' => 'Field "' . ( $row['meta_key'] ?? '?' ) . '" on post #' . ( $row['post_id'] ?? '?' ),
			'comments' => 'Comment #' . ( $row['comment_ID'] ?? '?' ) . ' by ' . ( $row['comment_author'] ?? '?' ),
			'users'    => 'User "' . ( $row['user_login'] ?? '?' ) . '"',
			'usermeta' => 'User field "' . ( $row['meta_key'] ?? '?' ) . '" for user #' . ( $row['user_id'] ?? '?' ),
			'terms'    => 'Term "' . ( $row['name'] ?? '?' ) . '"',
			'term_taxonomy' => ucfirst( str_replace( '_', ' ', (string) ( $row['taxonomy'] ?? 'term' ) ) ) . ' #' . ( $row['term_taxonomy_id'] ?? '?' ),
			'term_relationships' => 'Term ' . ( $row['term_taxonomy_id'] ?? '?' ) . ' on object #' . ( $row['object_id'] ?? '?' ),
			default    => $this->table_label( $table ) . ' ' . $this->row_handle( $row ),
		};
	}

	/** "wp_navigation" -> "Navigation menu"; unknown types become "My type". */
	public function post_type_label( string $type ): string {
		if ( isset( self::POST_TYPE_LABELS[ $type ] ) ) {
			return self::POST_TYPE_LABELS[ $type ];
		}
		return ucfirst( str_replace( '_', ' ', $type ?: 'post' ) );
	}

	/** "#123" from the first id-like column, else the first short scalar, so plugin rows are tellable apart. */
	private function row_handle( array $row ): string {
		foreach ( $row as $col => $val ) {
			if ( $val !== null && $val !== '' && preg_match( '/(^|_)id$/i', (string) $col ) ) {
				return '#' . $val;
			}
		}
		foreach ( $row as $val ) {
			if ( is_scalar( $val ) && $val !== '' && strlen( (string) $val ) <= 40 ) {
				return '"' . $val . '"';
			}
		}
		return 'row';
	}

	/** Columns that carry the meaning of a row; the rest are bookkeeping. */
	public function meaningful_columns( string $table ): array {
		$t = $this->strip( $table );
		return match ( $t ) {
			'posts'   => array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order', 'post_type', 'post_author', 'post_date', 'post_date_gmt', 'post_password', 'comment_status', 'ping_status', 'guid', 'post_mime_type' ),
			'options' => array( 'option_value', 'autoload' ),
			'blogs'   => array( 'domain', 'path', 'public', 'archived', 'mature', 'spam', 'deleted', 'lang_id' ),
			default   => array(),
		};
	}

	/** "wp_posts" -> "posts"; on multisite "wp_3_posts" -> "posts" too. */
	private function strip( string $table ): string {
		// Longest match first: this site's own prefix (wp_3_ on site 3), then any site's
		// numbered prefix under the base prefix, then the base prefix itself.
		if ( str_starts_with( $table, $this->prefix ) ) {
			$rest = substr( $table, strlen( $this->prefix ) );
			if ( ! preg_match( '/^\d+_/', $rest ) ) {
				return $rest;
			}
		}
		if ( preg_match( '/^' . preg_quote( $this->base_prefix, '/' ) . '\d+_(.+)$/', $table, $m ) ) {
			return $m[1];
		}
		if ( str_starts_with( $table, $this->base_prefix ) ) {
			return substr( $table, strlen( $this->base_prefix ) );
		}
		return $table;
	}
}
