<?php
/**
 * Workspaces: named branches of data (and optionally code) served by the staging site.
 *
 * Nobody edits on stage. Work happens in a workspace, created from stage. When it
 * is ready it is promoted into stage, and stage is released to production. Stage
 * is therefore always exactly one thing: production, or the next release.
 *
 * The request-time switch lives in extras/workspace-switch.php, loaded from
 * wp-config.php. This class is the rest: creating, listing, entering, leaving,
 * updating, promoting, and the guards that keep stage read-only.
 */
defined( 'ABSPATH' ) || exit;

class WPBR_Workspaces {

	public const PREFIX = 'ws/';

	/** Workspaces are on when wp-config.php loaded the switch file. */
	public static function enabled(): bool {
		return defined( 'WPBR_WORKSPACES_ON' ) && WPBR_WORKSPACES_ON && function_exists( 'wpbr_ws_sign' );
	}

	/** The staging branch, the only branch workspaces promote into. */
	public static function stage_branch(): string {
		return defined( 'WPBR_STAGE_BRANCH' ) ? WPBR_STAGE_BRANCH : WPBR_Dolt::current_branch();
	}

	/** The workspace this request runs in, or null on stage. */
	public static function current(): ?string {
		return defined( 'WPBR_WORKSPACE' ) ? WPBR_WORKSPACE : null;
	}

	public static function is_workspace( string $branch ): bool {
		return str_starts_with( $branch, self::PREFIX );
	}

	public static function branch( string $name ): string {
		return self::PREFIX . $name;
	}

	public static function name_of( string $branch ): string {
		return self::is_workspace( $branch ) ? substr( $branch, strlen( self::PREFIX ) ) : $branch;
	}

	public static function code_enabled(): bool {
		return self::enabled() && WPBR_Git::available();
	}

	private static function check_name( string $name ): void {
		if ( ! wpbr_ws_valid_name( $name ) ) {
			throw new RuntimeException( esc_html__( 'A workspace name is 1 to 40 lowercase letters, digits and dashes, starting with a letter or digit.', 'branchline' ) );
		}
	}

	// ------------------------------------------------------------------- listing

	/**
	 * Every workspace with what you need to decide what to do with it:
	 * rows ahead of stage, whether it is current with stage, and its code branch.
	 */
	public static function all( WPBR_Dolt $d, bool $with_counts = true ): array {
		$stage = self::stage_branch();
		// Distances are measured from stage, so the connection must be on stage.
		$own = $d->branch !== $stage;
		if ( $own ) {
			$d = new WPBR_Dolt( $stage );
		}
		try {
			return self::collect( $d, $stage, $with_counts );
		} finally {
			if ( $own ) {
				$d->close();
			}
		}
	}

	private static function collect( WPBR_Dolt $d, string $stage, bool $with_counts ): array {
		$cls = $with_counts ? new WPBR_Classifier( $d ) : null;
		$out = array();
		foreach ( $d->branches() as $b ) {
			if ( ! self::is_workspace( $b['name'] ) ) {
				continue;
			}
			$name = self::name_of( $b['name'] );
			$row  = array(
				'name'       => $name,
				'branch'     => $b['name'],
				'hash'       => $b['hash'],
				'last'       => (string) $b['latest_commit_message'],
				'last_date'  => (string) ( $b['latest_commit_date'] ?? '' ),
				'ahead'      => null,
				'behind'     => null,
				'current'    => null,
				'code'       => null,
				'code_ahead' => null,
				'code_dir'   => null,
			);
			if ( $with_counts ) {
				// Work in a workspace sits in its working set until something snapshots it.
				// Record it now so the counts below include what editors did a minute ago.
				$w = new WPBR_Dolt( $b['name'], false );
				try {
					/* translators: %s: workspace name */
					$w->snapshot( sprintf( __( 'Work in progress (%s)', 'branchline' ), $name ) );
				} finally {
					$w->close();
				}
				$base           = $d->merge_base( $b['name'] );
				$row['ahead']   = WPBR_Merge_Engine::total( WPBR_Merge_Engine::summarize( $d, $cls, $base, $b['name'], 0 ) );
				$row['behind']  = WPBR_Merge_Engine::total( WPBR_Merge_Engine::summarize( $d, $cls, $base, $stage, 0 ) );
				$row['current'] = $row['behind'] === 0;
			}
			if ( self::code_enabled() ) {
				$dir             = wpbr_ws_code_dir( $name );
				$row['code']     = WPBR_Git::branch_exists( $b['name'] );
				$row['code_dir'] = $row['code'] && $dir && is_dir( $dir ) ? $dir : null;
				if ( $row['code'] ) {
					try {
						$row['code_ahead'] = WPBR_Git::count( WPBR_Git::stage_branch(), $b['name'] );
					} catch ( Throwable $e ) {
						$row['code_ahead'] = null;
					}
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	public static function exists( WPBR_Dolt $d, string $name ): bool {
		return wpbr_ws_valid_name( $name ) && $d->branch_exists( self::branch( $name ) );
	}

	// ------------------------------------------------------------ create, delete

	/**
	 * Create a workspace from any ref: stage (default), production, another branch
	 * or a snapshot hash. With $code, also a git branch and worktree of the same name.
	 */
	public static function create( string $name, string $from = '', bool $code = false ): array {
		self::check_name( $name );
		$stage  = self::stage_branch();
		$from   = $from === '' ? $stage : $from;
		$branch = self::branch( $name );
		$d      = new WPBR_Dolt( $stage, false );
		try {
			if ( $d->branch_exists( $branch ) ) {
				/* translators: %s: workspace name */
				throw new RuntimeException( sprintf( esc_html__( 'Workspace %s already exists.', 'branchline' ), $name ) );
			}
			if ( $d->branch_exists( $from ) ) {
				// Branch from a branch: record its unsaved work first so the workspace starts with all of it.
				$src = new WPBR_Dolt( $from, false );
				/* translators: %s: workspace name */
				$src->snapshot( sprintf( __( 'Snapshot before creating workspace %s', 'branchline' ), $name ) );
				$src->close();
			}
			$d->branch_from( $branch, $from );
			$hash = (string) $d->var( 'SELECT hash FROM dolt_branches WHERE name = ?', array( $branch ) );
		} finally {
			$d->close();
		}
		$out = array(
			'name'     => $name,
			'branch'   => $branch,
			'from'     => $from,
			'hash'     => $hash,
			'code'     => false,
			'code_dir' => null,
		);
		if ( $code ) {
			if ( ! self::code_enabled() ) {
				throw new RuntimeException( esc_html__( 'Code branching needs WPBR_CODE_ROOT pointing at a git repository.', 'branchline' ) );
			}
			$dir = wpbr_ws_code_dir( $name );
			if ( ! is_dir( dirname( $dir ) ) ) {
				wp_mkdir_p( dirname( $dir ) );
			}
			// Code starts from the stage checkout, whatever the data started from.
			WPBR_Git::worktree_add( $dir, $branch, WPBR_Git::stage_branch() );
			WPBR_Git::link_shared( $dir );
			$out['code']     = true;
			$out['code_dir'] = $dir;
		}
		return $out;
	}

	public static function delete( string $name ): void {
		self::check_name( $name );
		$branch = self::branch( $name );
		$d      = new WPBR_Dolt( self::stage_branch(), false );
		try {
			if ( $d->branch_exists( $branch ) ) {
				$d->branch_delete( $branch );
			}
		} finally {
			$d->close();
		}
		if ( self::code_enabled() ) {
			$dir = wpbr_ws_code_dir( $name );
			if ( $dir ) {
				WPBR_Git::worktree_remove( $dir, $branch );
			}
		}
	}

	// ------------------------------------------------------------- enter, leave

	/** A signed token for a workspace, valid for $ttl seconds. */
	public static function token( string $name, ?int $ttl = null ): string {
		$ttl = $ttl ?? ( defined( 'WPBR_WORKSPACE_TTL' ) ? (int) WPBR_WORKSPACE_TTL : 12 * HOUR_IN_SECONDS );
		return wpbr_ws_sign( $name, time() + $ttl, (string) WPBR_WORKSPACE_SECRET );
	}

	/** A link that puts whoever opens it into the workspace. Share it for a preview. */
	public static function link( string $name, ?int $ttl = null, string $path = '/' ): string {
		return add_query_arg( 'wpbr_workspace', self::token( $name, $ttl ), home_url( $path ) );
	}

	public static function leave_link( string $path = '/' ): string {
		return add_query_arg( 'wpbr_leave', '1', home_url( $path ) );
	}

	/** Put this browser into a workspace for the rest of the session. */
	public static function enter( string $name ): void {
		self::check_name( $name );
		$ttl = defined( 'WPBR_WORKSPACE_TTL' ) ? (int) WPBR_WORKSPACE_TTL : 12 * HOUR_IN_SECONDS;
		wpbr_ws_set_cookie( self::token( $name, $ttl ), time() + $ttl );
	}

	public static function leave(): void {
		wpbr_ws_set_cookie( '', time() - HOUR_IN_SECONDS );
	}

	// ----------------------------------------------------------- update, promote

	/** The engine for bringing stage into a workspace. Stage is the upstream: its content wins, the workspace's structure wins. */
	public static function update_engine( string $name ): WPBR_Merge_Engine {
		self::check_name( $name );
		return new WPBR_Merge_Engine( self::branch( $name ), self::stage_branch(), self::stage_branch() );
	}

	/** The engine for promoting a workspace into stage. Same upstream, same rules, other direction. */
	public static function promote_engine( string $name ): WPBR_Merge_Engine {
		self::check_name( $name );
		return new WPBR_Merge_Engine( self::stage_branch(), self::branch( $name ), self::stage_branch() );
	}

	/**
	 * Is the workspace current with stage? True when stage has no meaningful rows
	 * the workspace lacks. Bookkeeping does not count. Promotion requires this,
	 * so every three-way merge into stage is small and its conflicts honest.
	 */
	public static function is_current( string $name ): bool {
		self::check_name( $name );
		$d = new WPBR_Dolt( self::stage_branch() );
		try {
			$cls  = new WPBR_Classifier( $d );
			$base = $d->merge_base( self::branch( $name ) );
			$data = WPBR_Merge_Engine::total( WPBR_Merge_Engine::summarize( $d, $cls, $base, self::stage_branch(), 0 ) ) === 0;
		} finally {
			$d->close();
		}
		if ( ! $data ) {
			return false;
		}
		if ( self::code_enabled() && WPBR_Git::branch_exists( self::branch( $name ) ) ) {
			return WPBR_Git::is_ancestor( WPBR_Git::stage_branch(), self::branch( $name ) );
		}
		return true;
	}

	/**
	 * Merge stage into the workspace: data through the engine, then code if the
	 * workspace has a code branch. Code is checked first so a code conflict never
	 * leaves the data half done.
	 */
	public static function update( string $name, array $decisions, array $pinned, string $message = '' ): array {
		$dir      = self::code_enabled() && WPBR_Git::branch_exists( self::branch( $name ) ) ? wpbr_ws_code_dir( $name ) : null;
		$has_code = $dir && is_dir( $dir );
		if ( $has_code ) {
			if ( ! WPBR_Git::is_clean( $dir ) ) {
				throw new RuntimeException( esc_html__( 'The workspace code checkout has uncommitted changes. Commit or stash them first.', 'branchline' ) );
			}
			[$ok, $err] = WPBR_Git::can_merge( WPBR_Git::stage_branch(), $dir );
			if ( ! $ok ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where displayed.
				throw new RuntimeException( __( 'Code would conflict with stage; resolve it in git first.', 'branchline' ) . ' ' . $err );
			}
		}
		/* translators: %s: workspace name */
		$r = self::update_engine( $name )->apply( $decisions, $pinned, $message ?: sprintf( __( 'Update workspace %s from stage', 'branchline' ), $name ) );
		if ( $has_code && ! WPBR_Git::is_ancestor( WPBR_Git::stage_branch(), self::branch( $name ) ) ) {
			/* translators: %s: workspace name */
			$r['code_hash'] = WPBR_Git::merge( WPBR_Git::stage_branch(), sprintf( 'Update workspace %s from stage', $name ), $dir );
		}
		return $r;
	}

	/**
	 * Merge the workspace into stage: data through the engine, then code into the
	 * stage checkout. Refuses when the workspace is not current with stage.
	 */
	public static function promote( string $name, array $decisions, array $pinned, string $message = '' ): array {
		if ( ! self::is_current( $name ) ) {
			throw new RuntimeException( esc_html__( 'The workspace is behind stage. Update it from stage first, then promote.', 'branchline' ) );
		}
		$has_code = self::code_enabled() && WPBR_Git::branch_exists( self::branch( $name ) );
		if ( $has_code ) {
			if ( WPBR_Git::current_branch() !== WPBR_Git::stage_branch() ) {
				/* translators: %s: branch name */
				throw new RuntimeException( sprintf( esc_html__( 'The stage checkout is not on its branch (%s). Check it out first.', 'branchline' ), esc_html( WPBR_Git::stage_branch() ) ) );
			}
			if ( ! WPBR_Git::is_clean() ) {
				throw new RuntimeException( esc_html__( 'The stage code checkout has uncommitted changes. Commit or stash them first.', 'branchline' ) );
			}
			[$ok, $err] = WPBR_Git::can_merge( self::branch( $name ) );
			if ( ! $ok ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where displayed.
				throw new RuntimeException( __( 'Code would conflict with stage; resolve it in git first.', 'branchline' ) . ' ' . $err );
			}
		}
		/* translators: %s: workspace name */
		$r = self::promote_engine( $name )->apply( $decisions, $pinned, $message ?: sprintf( __( 'Promote workspace %s', 'branchline' ), $name ) );
		if ( $has_code && ! WPBR_Git::is_ancestor( self::branch( $name ), WPBR_Git::stage_branch() ) ) {
			$r['code_hash'] = WPBR_Git::merge( self::branch( $name ), sprintf( 'Promote workspace %s', $name ) );
		}
		return $r;
	}

	// ------------------------------------------------------------------- guards

	public static function boot(): void {
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 101 );
		add_action( 'admin_post_wpbr_workspace_enter', array( self::class, 'handle_enter' ) );
		add_action( 'admin_post_wpbr_workspace_leave', array( self::class, 'handle_leave' ) );
		// Login sessions live on stage for every workspace, so switching never logs anyone out.
		require_once WPBR_DIR . '/includes/class-session-tokens.php';
		add_filter( 'session_token_manager', fn() => 'WPBR_Session_Tokens' );
		if ( self::current() !== null ) {
			add_action( 'send_headers', 'nocache_headers' );
			add_action( 'wp_body_open', array( self::class, 'bar' ) );
			add_action( 'in_admin_header', array( self::class, 'bar' ) );
			if ( ! defined( 'WPBR_WORKSPACE_MAIL' ) || WPBR_WORKSPACE_MAIL !== true ) {
				// A test order in a workspace must not email a real customer.
				add_filter( 'pre_wp_mail', '__return_true' );
			}
		} elseif ( ! defined( 'WPBR_STAGE_READ_ONLY' ) || WPBR_STAGE_READ_ONLY !== false ) {
			add_filter( 'user_has_cap', array( self::class, 'read_only_caps' ), 100 );
			add_action( 'wp_body_open', array( self::class, 'bar' ) );
			add_action( 'in_admin_header', array( self::class, 'bar' ) );
		}
	}

	/**
	 * On stage nobody edits. Editing capabilities are removed for everyone; the
	 * capabilities for running Branchline and administering the site stay.
	 */
	public static function read_only_caps( array $allcaps ): array {
		$keep  = array( WPBR_CAP_MANAGE, WPBR_CAP_PUBLISH, 'edit_dashboard' );
		$admin = ! empty( $allcaps['manage_options'] ) || ! empty( $allcaps[ WPBR_CAP_MANAGE ] );
		foreach ( $allcaps as $cap => $granted ) {
			if ( ! $granted || in_array( $cap, $keep, true ) ) {
				continue;
			}
			if ( preg_match( '/^(edit_|delete_|publish_|create_|assign_|manage_(categories|links|woocommerce|product_terms)|upload_files$|customize$|moderate_comments$|import$|export$)/', $cap ) ) {
				$allcaps[ $cap ] = false;
			}
		}
		if ( $admin ) {
			// WooCommerce sends anyone without edit_posts or this capability away from wp-admin.
			$allcaps['view_admin_dashboard'] = true;
		}
		return $allcaps;
	}

	/** The bar on every page that says where you are. */
	public static function bar(): void {
		$ws = self::current();
		if ( $ws !== null ) {
			$css  = 'background:#7a4a00;color:#fff';
			$text = sprintf(
				/* translators: %s: workspace name */
				esc_html__( 'Workspace: %s. Edits here stay here until this workspace is promoted to stage.', 'branchline' ),
				'<strong>' . esc_html( $ws ) . '</strong>'
			);
			$link = '<a href="' . esc_url( self::leave_link() ) . '" style="color:#fff;margin-left:12px">' . esc_html__( 'Leave workspace', 'branchline' ) . '</a>';
		} else {
			$css  = 'background:#1d2327;color:#fff';
			$text = esc_html__( 'Stage is read-only. It holds production, or the next release. Open a workspace to edit.', 'branchline' );
			$link = current_user_can( WPBR_CAP_MANAGE ) ? '<a href="' . esc_url( admin_url( 'admin.php?page=wpbr' ) ) . '" style="color:#fff;margin-left:12px">' . esc_html__( 'Workspaces', 'branchline' ) . '</a>' : '';
		}
		echo '<div class="wpbr-ws-bar" style="' . esc_attr( $css ) . ';font:13px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;padding:7px 16px;text-align:center;position:relative;z-index:9998">' . $text . $link . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both strings escaped above.
	}

	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( WPBR_CAP_MANAGE ) ) {
			return;
		}
		$ws = self::current();
		$bar->add_node(
			array(
				'id'    => 'wpbr-ws',
				/* translators: %s: workspace name */
				'title' => $ws !== null ? esc_html( sprintf( __( 'Workspace: %s', 'branchline' ), $ws ) ) : esc_html__( 'Stage (read-only)', 'branchline' ),
				'href'  => admin_url( 'admin.php?page=wpbr' ),
			)
		);
		try {
			$d   = new WPBR_Dolt( self::stage_branch() );
			$all = self::all( $d, false );
			$d->close();
		} catch ( Throwable $e ) {
			$all = array();
		}
		foreach ( $all as $w ) {
			if ( $w['name'] === $ws ) {
				continue;
			}
			$bar->add_node(
				array(
					'id'     => 'wpbr-ws-' . $w['name'],
					'parent' => 'wpbr-ws',
					/* translators: %s: workspace name */
					'title'  => esc_html( sprintf( __( 'Open %s', 'branchline' ), $w['name'] ) ),
					'href'   => self::enter_url( $w['name'] ),
				)
			);
		}
		if ( $ws !== null ) {
			$bar->add_node(
				array(
					'id'     => 'wpbr-ws-leave',
					'parent' => 'wpbr-ws',
					'title'  => esc_html__( 'Back to stage', 'branchline' ),
					'href'   => self::leave_url(),
				)
			);
		}
		$bar->add_node(
			array(
				'id'     => 'wpbr-ws-manage',
				'parent' => 'wpbr-ws',
				'title'  => esc_html__( 'Manage workspaces', 'branchline' ),
				'href'   => admin_url( 'admin.php?page=wpbr' ),
			)
		);
	}

	public static function enter_url( string $name ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'wpbr_workspace_enter',
					'name'   => $name,
				),
				admin_url( 'admin-post.php' )
			),
			'wpbr_workspace_enter'
		);
	}

	public static function leave_url(): string {
		return wp_nonce_url( add_query_arg( 'action', 'wpbr_workspace_leave', admin_url( 'admin-post.php' ) ), 'wpbr_workspace_leave' );
	}

	public static function handle_enter(): void {
		check_admin_referer( 'wpbr_workspace_enter' );
		if ( ! current_user_can( WPBR_CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Not allowed.', 'branchline' ) );
		}
		$name = sanitize_text_field( wp_unslash( $_GET['name'] ?? '' ) );
		try {
			$d  = new WPBR_Dolt( self::stage_branch() );
			$ok = self::exists( $d, $name );
			$d->close();
			if ( ! $ok ) {
				throw new RuntimeException( esc_html__( 'No such workspace.', 'branchline' ) );
			}
			self::enter( $name );
		} catch ( Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wpbr' ) );
		exit;
	}

	public static function handle_leave(): void {
		check_admin_referer( 'wpbr_workspace_leave' );
		self::leave();
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wpbr' ) );
		exit;
	}
}
