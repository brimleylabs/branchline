<?php
/**
 * wp-admin > Branchline: overview, review and apply screens.
 */
defined( 'ABSPATH' ) || exit;

class WPBR_Admin {

	private const SLUG = 'wpbr';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_action( 'admin_post_wpbr_snapshot', array( self::class, 'handle_snapshot' ) );
		add_action( 'admin_post_wpbr_apply', array( self::class, 'handle_apply' ) );
		add_action( 'admin_post_wpbr_remote', array( self::class, 'handle_remote' ) );
		add_action( 'admin_post_wpbr_rollback', array( self::class, 'handle_rollback' ) );
		add_action( 'admin_bar_menu', array( self::class, 'admin_bar' ), 100 );
	}

	public static function menu(): void {
		add_menu_page( 'Branchline', 'Branchline', WPBR_CAP_MANAGE, self::SLUG, array( self::class, 'render' ), 'dashicons-randomize', 59 );
	}

	private static function can_publish(): bool {
		return current_user_can( WPBR_CAP_PUBLISH );
	}

	public static function assets( string $hook ): void {
		if ( str_contains( $hook, self::SLUG ) ) {
			wp_enqueue_style( 'wpbr-admin', WPBR_URL . 'assets/admin.css', array(), WPBR_VERSION );
		}
	}

	public static function admin_bar( WP_Admin_Bar $bar ): void {
		if ( ! current_user_can( WPBR_CAP_MANAGE ) ) {
			return;
		}
		$branch = WPBR_Dolt::current_branch();
		$title  = $branch === WPBR_PRODUCTION_BRANCH
			/* translators: %s: branch name */
			? sprintf( __( 'Branch: %s (production)', 'branchline' ), $branch )
			/* translators: %s: branch name */
			: sprintf( __( 'Branch: %s', 'branchline' ), $branch );
		$bar->add_node(
			array(
				'id'    => 'wpbr',
				'title' => esc_html( $title ),
				'href'  => admin_url( 'admin.php?page=' . self::SLUG ),
			)
		);
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/** Back to the overview with a notice. */
	private static function redirect( string $msg, bool $ok ): void {
		wp_safe_redirect(
			self::url(
				array(
					'notice' => rawurlencode( $msg ),
					'kind'   => $ok ? 'ok' : 'error',
				)
			)
		);
		exit;
	}

	// ------------------------------------------------------------------ actions

	public static function handle_snapshot(): void {
		check_admin_referer( 'wpbr_snapshot' );
		if ( ! current_user_can( WPBR_CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Not allowed.', 'branchline' ) );
		}
		$branch = WPBR_Dolt::current_branch();
		try {
			$d    = new WPBR_Dolt( $branch, false );
			$hash = $d->snapshot( sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) ) ?: __( 'Snapshot from wp-admin', 'branchline' ) );
			$d->close();
			$msg = $hash
				/* translators: %s: short snapshot hash */
				? sprintf( __( 'Snapshot recorded: %s', 'branchline' ), substr( $hash, 0, 8 ) )
				: __( 'Nothing to snapshot. The branch has no uncommitted changes.', 'branchline' );
			self::redirect( $msg, true );
		} catch ( Throwable $e ) {
			/* translators: %s: error message */
			self::redirect( sprintf( __( 'Snapshot failed: %s', 'branchline' ), $e->getMessage() ), false );
		}
	}

	public static function handle_apply(): void {
		check_admin_referer( 'wpbr_apply' );
		if ( ! current_user_can( WPBR_CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Not allowed.', 'branchline' ) );
		}
		$target  = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
		$source  = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
		$dir     = sanitize_key( wp_unslash( $_POST['dir'] ?? '' ) );
		$ws      = strtolower( sanitize_text_field( wp_unslash( $_POST['ws'] ?? '' ) ) );
		$release = sanitize_text_field( wp_unslash( $_POST['release'] ?? '' ) );
		if ( $target === WPBR_PRODUCTION_BRANCH && ! self::can_publish() ) {
			wp_die( esc_html__( 'Publishing to production needs the publish_to_production capability.', 'branchline' ) );
		}
		$pinned        = array(
			'target_hash' => sanitize_text_field( wp_unslash( $_POST['target_hash'] ?? '' ) ),
			'source_hash' => sanitize_text_field( wp_unslash( $_POST['source_hash'] ?? '' ) ),
		);
		$decisions     = array();
		$raw_decisions = isset( $_POST['decision'] ) ? (array) wp_unslash( $_POST['decision'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key and value is sanitized below.
		foreach ( $raw_decisions as $k => $v ) {
			$v                                      = sanitize_text_field( $v );
			$decisions[ sanitize_text_field( $k ) ] = in_array( $v, array( 'ours', 'theirs', 'merged', 'delete' ), true ) ? $v : '';
		}
		try {
			$message = sanitize_text_field( wp_unslash( $_POST['message'] ?? '' ) );
			if ( $dir === 'promote' && WPBR_Workspaces::enabled() ) {
				$r = WPBR_Workspaces::promote( $ws, $decisions, $pinned, $message );
				/* translators: 1: workspace name, 2: short snapshot hash */
				$msg = sprintf( __( 'Workspace %1$s promoted to stage. New head %2$s.', 'branchline' ), $ws, substr( $r['hash'], 0, 8 ) );
				if ( ! empty( $r['code_hash'] ) ) {
					/* translators: %s: short git commit hash */
					$msg .= ' ' . sprintf( __( 'Code merged at %s.', 'branchline' ), substr( $r['code_hash'], 0, 8 ) );
				}
			} elseif ( $dir === 'update' && WPBR_Workspaces::enabled() ) {
				$r = WPBR_Workspaces::update( $ws, $decisions, $pinned, $message );
				/* translators: 1: workspace name, 2: short snapshot hash */
				$msg = sprintf( __( 'Workspace %1$s updated from stage. New head %2$s.', 'branchline' ), $ws, substr( $r['hash'], 0, 8 ) );
				if ( ! empty( $r['code_hash'] ) ) {
					/* translators: %s: short git commit hash */
					$msg .= ' ' . sprintf( __( 'Code merged at %s.', 'branchline' ), substr( $r['code_hash'], 0, 8 ) );
				}
			} elseif ( $release !== '' && $target === WPBR_PRODUCTION_BRANCH ) {
				$r = WPBR_Release::run( $release, $decisions, $pinned, true );
				/* translators: 1: release name, 2: short snapshot hash */
				$msg = sprintf( __( 'Release %1$s is in production. New head %2$s.', 'branchline' ), $release, substr( $r['hash'], 0, 8 ) );
				if ( ! empty( $r['code_tag'] ) ) {
					$msg .= ' ' . ( $r['deploy'] !== null
						/* translators: %s: git tag */
						? sprintf( __( 'Code %s tagged and deployed.', 'branchline' ), $r['code_tag'] )
						/* translators: %s: git tag */
						: sprintf( __( 'Code %s tagged; deploy it with your usual command.', 'branchline' ), $r['code_tag'] ) );
				}
			} else {
				$engine = new WPBR_Merge_Engine( $target, $source );
				$r      = $engine->apply( $decisions, $pinned, $message );
				$n      = count( $r['applied'] );
				$msg    = sprintf(
					/* translators: 1: source branch, 2: target branch, 3: short snapshot hash, 4: number of resolved items */
					_n( 'Merged %1$s into %2$s. New head %3$s. %4$d item resolved.', 'Merged %1$s into %2$s. New head %3$s. %4$d items resolved.', $n, 'branchline' ),
					$source,
					$target,
					substr( $r['hash'], 0, 8 ),
					$n
				);
			}
			$files = WPBR_Files::describe( $r['files'] ?? array( 'attachments' => 0 ) );
			if ( $files ) {
				$msg .= ' ' . $files;
			}
			self::redirect( $msg, true );
		} catch ( Throwable $e ) {
			/* translators: %s: error message */
			self::redirect( sprintf( __( 'Merge not applied: %s', 'branchline' ), $e->getMessage() ), false );
		}
	}

	/** Remote operations: add, remove, fetch, push, track. */
	public static function handle_remote(): void {
		check_admin_referer( 'wpbr_remote' );
		if ( ! current_user_can( WPBR_CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Not allowed.', 'branchline' ) );
		}
		$op     = sanitize_text_field( wp_unslash( $_POST['op'] ?? '' ) );
		$name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? 'origin' ) );
		$branch = WPBR_Dolt::current_branch();
		try {
			$d = new WPBR_Dolt( $branch, false );
			switch ( $op ) {
				case 'add':
					$url = trim( sanitize_text_field( wp_unslash( $_POST['url'] ?? '' ) ) );
					if ( $name === '' || $url === '' ) {
						throw new RuntimeException( esc_html__( 'A remote needs a name and a URL.', 'branchline' ) );
					}
					$d->remote_add( $name, $url );
					/* translators: %s: remote name */
					$msg = sprintf( __( 'Remote %s added.', 'branchline' ), $name );
					break;
				case 'remove':
					$d->remote_remove( $name );
					/* translators: %s: remote name */
					$msg = sprintf( __( 'Remote %s removed.', 'branchline' ), $name );
					break;
				case 'fetch':
					$d->fetch( $name );
					/* translators: %s: remote name */
					$msg = sprintf( __( 'Fetched %s. Remote branches are listed below.', 'branchline' ), $name );
					break;
				case 'push':
					/* translators: %s: branch name */
					$d->snapshot( sprintf( __( 'Snapshot before push (%s)', 'branchline' ), $branch ) );
					$out = $d->push( $name, $branch );
					/* translators: 1: branch name, 2: remote name, 3: message from the database */
					$msg = trim( sprintf( __( 'Pushed %1$s to %2$s. %3$s', 'branchline' ), $branch, $name, trim( str_replace( '\n', ' ', $out ) ) ) );
					break;
				case 'track':
					$ref = preg_replace( '#^remotes/#', '', sanitize_text_field( wp_unslash( $_POST['ref'] ?? '' ) ) );
					if ( ! str_contains( $ref, '/' ) ) {
						throw new RuntimeException( esc_html__( 'Pick a remote branch.', 'branchline' ) );
					}
					[$remote, $rbranch] = explode( '/', $ref, 2 );
					if ( ! $d->branch_exists( $rbranch ) ) {
						$d->branch_from( $rbranch, $ref );
						/* translators: 1: new local branch name, 2: remote branch reference, 3: this site's branch name */
						$msg = sprintf( __( 'Branch %1$s created from %2$s. You can merge it into %3$s now.', 'branchline' ), $rbranch, $ref, $branch );
					} else {
						$t   = new WPBR_Dolt( $rbranch, false );
						$out = $t->pull_remote( $remote, $rbranch );
						$t->close();
						/* translators: 1: local branch name, 2: remote branch reference, 3: message from the database */
						$msg = trim( sprintf( __( 'Branch %1$s updated from %2$s. %3$s', 'branchline' ), $rbranch, $ref, trim( str_replace( '\n', ' ', $out ) ) ) );
					}
					break;
				default:
					throw new RuntimeException( esc_html__( 'Unknown remote operation.', 'branchline' ) );
			}
			$d->close();
			self::redirect( $msg, true );
		} catch ( Throwable $e ) {
			/* translators: %s: error message */
			self::redirect( sprintf( __( 'Remote operation failed: %s', 'branchline' ), $e->getMessage() ), false );
		}
	}

	/** Roll this branch back to an earlier snapshot. The current head is kept as a tag. */
	public static function handle_rollback(): void {
		check_admin_referer( 'wpbr_rollback' );
		if ( ! current_user_can( WPBR_CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Not allowed.', 'branchline' ) );
		}
		$branch = WPBR_Dolt::current_branch();
		if ( $branch === WPBR_PRODUCTION_BRANCH && ! self::can_publish() ) {
			wp_die( esc_html__( 'Rolling production back needs the publish_to_production capability.', 'branchline' ) );
		}
		$hash = preg_replace( '/[^a-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['hash'] ?? '' ) ) );
		try {
			$d    = new WPBR_Dolt( $branch, false );
			$tag  = $d->rollback_to( $hash );
			$head = $d->head_hash();
			$d->close();
			wp_cache_flush();
			/* translators: 1: branch name, 2: short snapshot hash, 3: tag name */
			self::redirect( sprintf( __( '%1$s rolled back to %2$s. The previous head is saved as %3$s, so nothing is lost.', 'branchline' ), $branch, substr( $head, 0, 8 ), $tag ), true );
		} catch ( Throwable $e ) {
			/* translators: %s: error message */
			self::redirect( sprintf( __( 'Rollback failed: %s', 'branchline' ), $e->getMessage() ), false );
		}
	}

	/** A one-button form posting to the remote handler. Returns escaped HTML. */
	private static function remote_form( string $op, array $fields, string $label, string $css = 'button button-small' ): string {
		$h  = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpbr-btnform">' . wp_nonce_field( 'wpbr_remote', '_wpnonce', true, false );
		$h .= '<input type="hidden" name="action" value="wpbr_remote"><input type="hidden" name="op" value="' . esc_attr( $op ) . '">';
		foreach ( $fields as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		return $h . '<button class="' . esc_attr( $css ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** A link to a review screen. Returns escaped HTML. */
	private static function review_link( array $args, string $label, string $css ): string {
		return '<a class="' . esc_attr( $css ) . '" href="' . esc_url( self::url( array_merge( array( 'view' => 'review' ), $args ) ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/** A one-button rollback form. Returns escaped HTML. */
	private static function rollback_form( string $hash, string $label ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpbr-btnform">' . wp_nonce_field( 'wpbr_rollback', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="wpbr_rollback"><input type="hidden" name="hash" value="' . esc_attr( $hash ) . '"><button class="button-link">' . esc_html( $label ) . '</button></form>';
	}

	/** Echo HTML that the helpers above have already escaped piece by piece. */
	private static function out( string $html ): void {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every helper in this class escapes at the point it builds the string.
	}

	/** A branch name wrapped in <code>, escaped. */
	private static function code( string $s ): string {
		return '<code>' . esc_html( $s ) . '</code>';
	}

	// ------------------------------------------------------------------- screens

	public static function render(): void {
		if ( ! current_user_can( WPBR_CAP_MANAGE ) ) {
			return;
		}
		echo '<div class="wrap wpbr">';
		if ( ! WPBR_Dolt::is_dolt() ) {
			echo '<h1>Branchline</h1>';
			echo '<div class="card"><h2>' . esc_html__( 'This site is not on Dolt yet', 'branchline' ) . '</h2>';
			echo '<p>' . esc_html__( 'Branchline needs the database on a Dolt server, which speaks MySQL and adds branching. Moving an existing site takes one command, run from this site:', 'branchline' ) . '</p>';
			echo '<pre class="wpbr-pre">wp branchline import --to=mysql://USER:PASSWORD@DOLT_HOST:3306/' . esc_html( DB_NAME ) . ' --branches=stage</pre>';
			echo '<p>' . esc_html__( 'It copies every table, checks the row counts, records a baseline snapshot and creates the branches you name. Then point wp-config.php at the Dolt server:', 'branchline' ) . '</p>';
			echo '<pre class="wpbr-pre">' . esc_html( "define('DB_HOST', 'DOLT_HOST:3306');\ndefine('DB_NAME', '" . DB_NAME . "');            // production\ndefine('DB_NAME', '" . DB_NAME . "/stage');      // on the staging site" ) . '</pre>';
			/* translators: %s: the --dry-run flag, in a code tag */
			echo '<p class="wpbr-fine">' . sprintf( esc_html__( 'Add %s first to see the table list and sizes without copying anything.', 'branchline' ), '<code>--dry-run</code>' ) . '</p></div></div>';
			return;
		}
		// Read-only view parameters. Nothing below changes state; the handlers above check nonces.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['notice'] ) ) {
			$n    = sanitize_text_field( wp_unslash( $_GET['notice'] ) );
			$kind = sanitize_key( wp_unslash( $_GET['kind'] ?? 'ok' ) );
			echo '<div class="notice ' . ( $kind === 'error' ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $n ) . '</p></div>';
		}
		$view = sanitize_key( wp_unslash( $_GET['view'] ?? 'overview' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		try {
			if ( $view === 'review' ) {
				self::review();
			} else {
				self::overview();
			}
		} catch ( Throwable $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
		}
		echo '</div>';
	}

	// ------------------------------------------------------------------ overview

	private static function overview(): void {
		$branch    = WPBR_Dolt::current_branch();
		$is_prod   = $branch === WPBR_PRODUCTION_BRANCH;
		$d         = new WPBR_Dolt( $branch );
		$head      = $d->head_hash();
		$status    = $d->status();
		$log       = $d->log( 10 );
		$branches  = $d->branches();
		$prod_hash = '';
		foreach ( $branches as $b ) {
			if ( $b['name'] === WPBR_PRODUCTION_BRANCH ) {
				$prod_hash = $b['hash'];
			}
		}
		$ws_on = WPBR_Workspaces::enabled();
		$ws    = $ws_on ? WPBR_Workspaces::current() : null;
		// The branch this one is measured against: production, or stage for a workspace.
		$upstream = $ws !== null ? WPBR_Workspaces::stage_branch() : WPBR_PRODUCTION_BRANCH;
		$ahead    = 0;
		$behind   = 0;
		if ( ! $is_prod && $prod_hash ) {
			$cls    = new WPBR_Classifier( $d );
			$base   = $d->merge_base( $upstream );
			$ahead  = WPBR_Merge_Engine::total( WPBR_Merge_Engine::summarize( $d, $cls, $base, $branch, 0 ) );
			$behind = WPBR_Merge_Engine::total( WPBR_Merge_Engine::summarize( $d, $cls, $base, $upstream, 0 ) );
		}
		$remotes         = $d->remotes();
		$remote_branches = $remotes ? $d->remote_branches() : array();
		$tags            = $d->tags();
		$may_rollback    = ! $is_prod || self::can_publish();

		self::out( '<h1>Branchline ' . self::pill( $branch, $is_prod ) . '</h1>' );
		if ( $ws !== null ) {
			self::out(
				'<p class="wpbr-sub">' . sprintf(
					/* translators: 1: workspace name, 2: stage branch name, each in a code tag */
					esc_html__( 'You are in workspace %1$s. Edits here stay here until it is promoted to %2$s.', 'branchline' ),
					self::code( $ws ),
					self::code( $upstream )
				) . '</p>'
			);
		} else {
			self::out(
				'<p class="wpbr-sub">' . sprintf(
					/* translators: 1: branch name, 2: database name, 3: production branch name, each in a code tag */
					esc_html__( 'This site reads and writes the %1$s branch of database %2$s. Production is %3$s.', 'branchline' ),
					self::code( $branch ),
					self::code( WPBR_Dolt::base_database() ),
					self::code( WPBR_PRODUCTION_BRANCH )
				) . '</p>'
			);
		}

		// ---- status bar: the numbers that matter and the three things you can do
		echo '<div class="card wpbr-status">';
		echo '<div class="wpbr-stats">';
		self::out( self::stat( self::code( substr( $head, 0, 8 ) ), __( 'current snapshot', 'branchline' ) ) );
		/* translators: %d is shown separately as the number; this is its label */
		self::out( self::stat( esc_html( (string) count( $status ) ), _n( 'table with unsaved changes', 'tables with unsaved changes', count( $status ), 'branchline' ), $status ? 'warn' : 'ok' ) );
		if ( $ws !== null ) {
			self::out( self::stat( esc_html( (string) $ahead ), __( 'rows changed here, not yet on stage', 'branchline' ), $ahead ? 'info' : '' ) );
			self::out( self::stat( esc_html( (string) $behind ), __( 'rows changed on stage, not yet here', 'branchline' ), $behind ? 'warn' : 'ok' ) );
		} elseif ( ! $is_prod ) {
			self::out( self::stat( esc_html( (string) $ahead ), __( 'rows changed here, not yet in production', 'branchline' ), $ahead ? 'info' : '' ) );
			self::out( self::stat( esc_html( (string) $behind ), __( 'rows changed in production, not yet here', 'branchline' ), $behind ? 'info' : '' ) );
		}
		echo '</div>';
		echo '<div class="wpbr-actionbar">';
		if ( $ws !== null ) {
			if ( $behind ) {
				self::out(
					self::review_link(
						array(
							'dir' => 'update',
							'ws'  => $ws,
						),
						__( 'Update from stage', 'branchline' ),
						'button button-primary'
					)
				);
				echo '<span class="wpbr-fine">' . esc_html__( 'Promotion needs the workspace current with stage.', 'branchline' ) . '</span>';
			} else {
				self::out(
					self::review_link(
						array(
							'dir' => 'promote',
							'ws'  => $ws,
						),
						__( 'Promote to stage', 'branchline' ),
						'button button-primary'
					)
				);
			}
			echo '<a class="button" href="' . esc_url( WPBR_Workspaces::leave_url() ) . '">' . esc_html__( 'Back to stage', 'branchline' ) . '</a>';
		} elseif ( ! $is_prod ) {
			if ( self::can_publish() && ! $ws_on ) {
				self::out( self::review_link( array( 'dir' => 'publish' ), __( 'Publish to production', 'branchline' ), 'button button-primary' ) );
			}
			self::out( self::review_link( array( 'dir' => 'pull' ), __( 'Pull from production', 'branchline' ), self::can_publish() && ! $ws_on ? 'button' : 'button button-primary' ) );
		}
		echo '<details class="wpbr-reveal"><summary class="button">' . esc_html__( 'Record snapshot', 'branchline' ) . '</summary>';
		self::out( '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpbr-inline">' . wp_nonce_field( 'wpbr_snapshot', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="wpbr_snapshot">' );
		echo '<input type="text" name="message" class="regular-text" placeholder="' . esc_attr__( 'What changed?', 'branchline' ) . '"><button class="button button-primary">' . esc_html__( 'Save snapshot', 'branchline' ) . '</button></form></details>';
		echo '</div>';
		if ( $status ) {
			$list = implode( ', ', array_map( fn( $t ) => self::code( $t['table_name'] ), $status ) );
			/* translators: %s: comma-separated list of table names in code tags */
			self::out( '<p class="wpbr-fine">' . sprintf( esc_html__( 'Unsaved changes in %s. A merge records them in a snapshot first.', 'branchline' ), $list ) . '</p>' );
		}
		if ( $is_prod ) {
			echo '<p class="wpbr-fine">' . esc_html__( 'Pull and publish run from a release branch. Open the staging site to merge.', 'branchline' ) . '</p>';
		} elseif ( $ahead || $behind ) {
			echo '<p class="wpbr-fine">' . esc_html__( 'Row counts leave out bookkeeping: transients, cron, locks and sessions.', 'branchline' ) . '</p>';
		}
		echo '</div>';

		// ---- workspaces and releases: the staging site's way of working
		if ( $ws_on && ! $is_prod ) {
			if ( $ws === null ) {
				WPBR_Admin_Workspaces::release_card( $ahead, $behind );
			}
			WPBR_Admin_Workspaces::card( $d, $branches );
		} elseif ( ! $is_prod && class_exists( 'WPBR_Admin_Workspaces' ) ) {
			WPBR_Admin_Workspaces::setup_hint();
		}
		$d->close();

		// ---- branches and remotes side by side
		echo '<div class="wpbr-cols">';

		echo '<div class="card"><h2>' . esc_html__( 'Branches', 'branchline' ) . '</h2>';
		echo '<table class="wpbr-table"><thead><tr><th>' . esc_html__( 'Branch', 'branchline' ) . '</th><th>' . esc_html__( 'Head', 'branchline' ) . '</th><th>' . esc_html__( 'Last snapshot', 'branchline' ) . '</th><th class="right"></th></tr></thead><tbody>';
		foreach ( $branches as $b ) {
			$is_this = $b['name'] === $branch;
			$is_p    = $b['name'] === WPBR_PRODUCTION_BRANCH;
			$action  = '';
			if ( $is_this ) {
				$action = '<span class="wpbr-tag">' . esc_html__( 'this site', 'branchline' ) . '</span>';
			} elseif ( $is_p ) {
				$action = $is_prod ? '' : self::review_link( array( 'dir' => 'pull' ), __( 'Pull', 'branchline' ), 'button button-small' );
			} else {
				$action = self::review_link(
					array(
						'dir'  => 'merge',
						'from' => $b['name'],
					),
					/* translators: %s: this site's branch name */
					sprintf( __( 'Merge into %s', 'branchline' ), $branch ),
					'button button-small'
				);
			}
			echo '<tr><td><strong>' . esc_html( $b['name'] ) . '</strong>' . ( $is_p ? ' <span class="wpbr-tag prod">' . esc_html__( 'production', 'branchline' ) . '</span>' : '' ) . '</td>';
			echo '<td><code>' . esc_html( substr( $b['hash'], 0, 8 ) ) . '</code></td>';
			echo '<td>' . esc_html( (string) $b['latest_commit_message'] ) . '<br><span class="wpbr-fine">' . esc_html( self::when( $b['latest_commit_date'] ?? '' ) ) . '</span></td>';
			self::out( '<td class="right">' . $action . '</td></tr>' );
		}
		echo '</tbody></table></div>';

		echo '<div class="card"><h2>' . esc_html__( 'Remotes', 'branchline' ) . '</h2>';
		if ( $remotes ) {
			foreach ( $remotes as $r ) {
				echo '<div class="wpbr-remote"><div><strong>' . esc_html( $r['name'] ) . '</strong><br><code class="wpbr-url">' . esc_html( $r['url'] ) . '</code></div>';
				self::out(
					'<div class="wpbr-btns">'
					. self::remote_form( 'fetch', array( 'name' => $r['name'] ), __( 'Fetch', 'branchline' ) )
					/* translators: %s: branch name */
					. self::remote_form( 'push', array( 'name' => $r['name'] ), sprintf( __( 'Push %s', 'branchline' ), $branch ) )
					. self::remote_form( 'remove', array( 'name' => $r['name'] ), __( 'Remove', 'branchline' ), 'button-link button-link-delete' )
					. '</div></div>'
				);
			}
		} else {
			echo '<p class="wpbr-fine">' . esc_html__( 'No remotes yet. A remote is another Dolt server, DoltHub, DoltLab, a storage bucket or a directory. Developers clone it, work on their own branch and push it back.', 'branchline' ) . '</p>';
		}
		echo '<details class="wpbr-reveal"' . ( $remotes ? '' : ' open' ) . '><summary class="button button-small">' . esc_html__( 'Add a remote', 'branchline' ) . '</summary>';
		self::out( '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpbr-inline">' . wp_nonce_field( 'wpbr_remote', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="wpbr_remote"><input type="hidden" name="op" value="add">' );
		echo '<input type="text" name="name" placeholder="origin" value="origin" class="wpbr-w-s"><input type="text" name="url" placeholder="' . esc_attr__( 'URL of the remote', 'branchline' ) . '" class="wpbr-w-l"><button class="button">' . esc_html__( 'Add', 'branchline' ) . '</button></form></details>';

		if ( $remote_branches ) {
			echo '<h3>' . esc_html__( 'Remote branches', 'branchline' ) . '</h3><table class="wpbr-table"><thead><tr><th>' . esc_html__( 'Branch', 'branchline' ) . '</th><th>' . esc_html__( 'Head', 'branchline' ) . '</th><th>' . esc_html__( 'Last snapshot', 'branchline' ) . '</th><th class="right"></th></tr></thead><tbody>';
			$local = array_column( $branches, 'hash', 'name' );
			foreach ( $remote_branches as $rb ) {
				$short       = preg_replace( '#^remotes/[^/]+/#', '', $rb['name'] );
				$remote_name = preg_replace( '#^remotes/([^/]+)/.*$#', '$1', $rb['name'] );
				if ( ! isset( $local[ $short ] ) ) {
					$state = __( 'new', 'branchline' );
					$btn   = __( 'Track', 'branchline' );
				} elseif ( $local[ $short ] === $rb['hash'] ) {
					$state = __( 'up to date', 'branchline' );
					$btn   = '';
				} elseif ( $short === $branch ) {
					$state = __( 'behind this site', 'branchline' );
					$btn   = '';
				} else {
					$state = __( 'differs from local', 'branchline' );
					$btn   = __( 'Update from remote', 'branchline' );
				}
				echo '<tr><td><strong>' . esc_html( $short ) . '</strong> <span class="wpbr-fine">' . esc_html( $remote_name ) . '</span></td><td><code>' . esc_html( substr( $rb['hash'], 0, 8 ) ) . '</code></td>';
				echo '<td>' . esc_html( (string) $rb['latest_commit_message'] ) . '<br><span class="wpbr-fine">' . esc_html( $state ) . '</span></td>';
				self::out( '<td class="right">' . ( $btn ? self::remote_form( 'track', array( 'ref' => $rb['name'] ), $btn ) : '' ) . '</td></tr>' );
			}
			echo '</tbody></table>';
		} elseif ( $remotes ) {
			echo '<p class="wpbr-fine">' . esc_html__( 'Fetch to list the branches on a remote.', 'branchline' ) . '</p>';
		}
		echo '</div>';

		echo '</div>'; // cols

		// ---- activity, with a way back
		/* translators: %s: branch name */
		echo '<div class="card"><h2>' . sprintf( esc_html__( 'Recent snapshots on %s', 'branchline' ), esc_html( $branch ) ) . '</h2><table class="wpbr-table wpbr-log"><tbody>';
		foreach ( $log as $i => $c ) {
			$restore = '';
			if ( $i > 0 && $may_rollback ) {
				$restore = self::rollback_form( $c['commit_hash'], __( 'Roll back to this', 'branchline' ) );
			} elseif ( $i === 0 ) {
				$restore = '<span class="wpbr-tag">' . esc_html__( 'current', 'branchline' ) . '</span>';
			}
			echo '<tr><td class="wpbr-nowrap"><code>' . esc_html( substr( $c['commit_hash'], 0, 8 ) ) . '</code></td><td class="wpbr-nowrap wpbr-fine">' . esc_html( self::when( $c['date'] ) ) . '</td><td>' . esc_html( $c['message'] ) . '</td>';
			self::out( '<td class="right wpbr-nowrap">' . $restore . '</td></tr>' );
		}
		echo '</tbody></table>';
		echo '<p class="wpbr-fine">' . esc_html__( 'Rolling back moves this branch to an earlier snapshot. The head you leave is saved as a tag, so it can be restored the same way.', 'branchline' ) . '</p>';
		if ( $tags ) {
			echo '<h3>' . esc_html__( 'Saved points', 'branchline' ) . '</h3><table class="wpbr-table wpbr-log"><tbody>';
			foreach ( $tags as $t ) {
				$restore = $may_rollback ? self::rollback_form( $t['tag_hash'], __( 'Restore', 'branchline' ) ) : '';
				echo '<tr><td class="wpbr-nowrap"><code>' . esc_html( substr( $t['tag_hash'], 0, 8 ) ) . '</code></td><td class="wpbr-nowrap wpbr-fine">' . esc_html( self::when( $t['date'] ) ) . '</td><td>' . esc_html( $t['tag_name'] ) . '<br><span class="wpbr-fine">' . esc_html( (string) $t['message'] ) . '</span></td>';
				self::out( '<td class="right">' . $restore . '</td></tr>' );
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	// -------------------------------------------------------------------- review

	private static function review(): void {
		$branch = WPBR_Dolt::current_branch();
		// Read-only view parameters; the apply handler checks the nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$dir     = sanitize_key( wp_unslash( $_GET['dir'] ?? 'publish' ) );
		$from    = sanitize_text_field( wp_unslash( $_GET['from'] ?? '' ) );
		$ws      = strtolower( sanitize_text_field( wp_unslash( $_GET['ws'] ?? '' ) ) );
		$release = sanitize_text_field( wp_unslash( $_GET['release'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $dir, array( 'pull', 'publish', 'merge', 'promote', 'update' ), true ) ) {
			$dir = 'publish';
		}
		$upstream = WPBR_PRODUCTION_BRANCH;
		if ( in_array( $dir, array( 'promote', 'update' ), true ) ) {
			if ( ! WPBR_Workspaces::enabled() ) {
				echo '<h1>Branchline</h1><div class="notice notice-error"><p>' . esc_html__( 'Workspaces are not set up on this site.', 'branchline' ) . '</p></div>';
				return;
			}
			$d  = new WPBR_Dolt( WPBR_Workspaces::stage_branch() );
			$ok = WPBR_Workspaces::exists( $d, $ws );
			$d->close();
			if ( ! $ok ) {
				echo '<h1>Branchline</h1><div class="notice notice-error"><p>' . esc_html__( 'No such workspace.', 'branchline' ) . '</p></div>';
				return;
			}
			$upstream = WPBR_Workspaces::stage_branch();
			if ( $dir === 'promote' ) {
				[$target, $source] = array( $upstream, WPBR_Workspaces::branch( $ws ) );
				if ( ! WPBR_Workspaces::is_current( $ws ) ) {
					echo '<h1>' . esc_html__( 'Promote to stage', 'branchline' ) . '</h1><p class="wpbr-sub"><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to Branchline', 'branchline' ) . '</a></p>';
					/* translators: %s: workspace name in a code tag */
					$why = sprintf( esc_html__( 'Stage has changed since %s was created or last updated. Update the workspace from stage first, check it, then promote.', 'branchline' ), self::code( $ws ) );
					self::out(
						'<div class="wpbr-banner warn"><strong>' . esc_html__( 'Not current with stage.', 'branchline' ) . '</strong> ' . $why . ' ' . self::review_link(
							array(
								'dir' => 'update',
								'ws'  => $ws,
							),
							__( 'Update from stage', 'branchline' ),
							'button button-primary'
						) . '</div>'
					);
					return;
				}
			} else {
				[$target, $source] = array( WPBR_Workspaces::branch( $ws ), $upstream );
			}
		} elseif ( $dir === 'merge' ) {
			$d  = new WPBR_Dolt( $branch );
			$ok = $from !== '' && $from !== $branch && $d->branch_exists( $from );
			$d->close();
			if ( ! $ok ) {
				/* translators: %s: this site's branch name */
				echo '<h1>Branchline</h1><div class="notice notice-error"><p>' . sprintf( esc_html__( 'Pick an existing branch to merge into %s.', 'branchline' ), esc_html( $branch ) ) . '</p></div>';
				return;
			}
			[$target, $source] = array( $branch, $from );
		} else {
			if ( $branch === WPBR_PRODUCTION_BRANCH ) {
				echo '<h1>Branchline</h1><div class="notice notice-error"><p>' . esc_html__( 'Pull and publish run from a release branch.', 'branchline' ) . '</p></div>';
				return;
			}
			if ( $dir === 'publish' && ! self::can_publish() ) {
				echo '<h1>Branchline</h1><div class="notice notice-error"><p>' . esc_html__( 'Publishing to production needs the publish_to_production capability. Ask an administrator.', 'branchline' ) . '</p></div>';
				return;
			}
			[$target, $source] = $dir === 'pull' ? array( $branch, WPBR_PRODUCTION_BRANCH ) : array( WPBR_PRODUCTION_BRANCH, $branch );
			if ( $dir === 'publish' && $release !== '' && ! WPBR_Release::valid_name( $release ) ) {
				echo '<h1>Branchline</h1><div class="notice notice-error"><p>' . esc_html__( 'A release name is letters, digits, dots, dashes and underscores.', 'branchline' ) . '</p></div>';
				return;
			}
		}
		$engine         = new WPBR_Merge_Engine( $target, $source, $upstream );
		$p              = $engine->preview();
		$titles         = array(
			'pull'    => __( 'Pull from production', 'branchline' ),
			/* translators: %s: release name */
			'publish' => $release !== '' ? sprintf( __( 'Release %s to production', 'branchline' ), $release ) : __( 'Publish to production', 'branchline' ),
			/* translators: 1: source branch, 2: target branch */
			'merge'   => sprintf( __( 'Merge %1$s into %2$s', 'branchline' ), $source, $target ),
			/* translators: %s: workspace name */
			'promote' => sprintf( __( 'Promote %s to stage', 'branchline' ), $ws ),
			/* translators: %s: workspace name */
			'update'  => sprintf( __( 'Update %s from stage', 'branchline' ), $ws ),
		);
		$verbs          = array(
			/* translators: %s: target branch name */
			'pull'    => sprintf( __( 'Pull into %s', 'branchline' ), $target ),
			/* translators: %s: target branch name */
			'publish' => $release !== '' ? __( 'Release to production', 'branchline' ) : sprintf( __( 'Publish to %s', 'branchline' ), $target ),
			/* translators: %s: target branch name */
			'merge'   => sprintf( __( 'Merge into %s', 'branchline' ), $target ),
			'promote' => __( 'Promote to stage', 'branchline' ),
			'update'  => __( 'Update workspace', 'branchline' ),
		);
		$combine_winner = in_array( $upstream, array( $source, $target ), true ) ? ( $upstream === WPBR_PRODUCTION_BRANCH ? __( 'production', 'branchline' ) : $upstream ) : $target;
		$rows_in        = WPBR_Merge_Engine::total( $p['incoming'] );
		$can_apply      = (bool) $p['incoming'] && empty( $p['up_to_date'] );

		echo '<h1>' . esc_html( $titles[ $dir ] ) . ' <span class="wpbr-pill">' . esc_html( $source ) . ' <span class="wpbr-dim">' . esc_html_x( 'to', 'between a source branch and a target branch', 'branchline' ) . '</span> ' . esc_html( $target ) . '</span></h1>';
		echo '<p class="wpbr-sub"><a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Back to Branchline', 'branchline' ) . '</a></p>';

		if ( ! empty( $p['up_to_date'] ) ) {
			/* translators: 1: target branch, 2: source branch, each in a code tag */
			self::out( '<div class="wpbr-banner ok"><strong>' . esc_html__( 'Nothing to merge.', 'branchline' ) . '</strong> ' . sprintf( esc_html__( '%1$s already contains everything on %2$s.', 'branchline' ), self::code( $target ), self::code( $source ) ) . '</div>' );
			return;
		}
		if ( $p['fast_forward'] ) {
			/* translators: %s: target branch in a code tag */
			self::out( '<div class="wpbr-banner ok"><strong>' . esc_html__( 'Clean fast-forward.', 'branchline' ) . '</strong> ' . sprintf( esc_html__( 'Nothing on %s changed since the branches split, so no rules or decisions are needed.', 'branchline' ), self::code( $target ) ) . '</div>' );
		}

		// ---- summary tiles
		echo '<div class="card wpbr-status"><div class="wpbr-stats">';
		self::out( self::stat( esc_html( (string) $rows_in ), __( 'rows come in', 'branchline' ), 'info' ) );
		self::out( self::stat( esc_html( (string) count( $p['incoming'] ) ), _n( 'area touched', 'areas touched', count( $p['incoming'] ), 'branchline' ) ) );
		self::out( self::stat( esc_html( (string) count( $p['auto'] ) ), __( 'resolved by the rules', 'branchline' ), count( $p['auto'] ) ? 'ok' : '' ) );
		self::out( self::stat( esc_html( (string) count( $p['human'] ) ), _n( 'needs your decision', 'need your decision', count( $p['human'] ), 'branchline' ), count( $p['human'] ) ? 'warn' : 'ok' ) );
		if ( ! empty( $p['attachments'] ) ) {
			self::out( self::stat( esc_html( (string) $p['attachments'] ), _n( 'attachment, files copied', 'attachments, files copied', (int) $p['attachments'], 'branchline' ), 'info' ) );
		}
		echo '</div>';
		if ( ! empty( $p['attachments'] ) && ! ( $p['uploads_from'] && $p['uploads_to'] && $p['uploads_from'] !== $p['uploads_to'] ) ) {
			/* translators: 1: branch name in a code tag, 2: the constant name WPBR_UPLOADS_DIRS in a code tag */
			self::out( '<p class="wpbr-fine warn">' . sprintf( esc_html__( 'The uploads directory for %1$s is not configured, so attachment files will not be copied. Set %2$s in wp-config.php.', 'branchline' ), self::code( ! $p['uploads_from'] ? $source : $target ), '<code>WPBR_UPLOADS_DIRS</code>' ) . '</p>' );
		}
		echo '</div>';

		self::out( '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'wpbr_apply', '_wpnonce', true, false ) );
		echo '<input type="hidden" name="action" value="wpbr_apply"><input type="hidden" name="target" value="' . esc_attr( $target ) . '"><input type="hidden" name="source" value="' . esc_attr( $source ) . '">';
		echo '<input type="hidden" name="target_hash" value="' . esc_attr( $p['target_hash'] ) . '"><input type="hidden" name="source_hash" value="' . esc_attr( $p['source_hash'] ) . '">';
		echo '<input type="hidden" name="dir" value="' . esc_attr( $dir ) . '"><input type="hidden" name="ws" value="' . esc_attr( $ws ) . '"><input type="hidden" name="release" value="' . esc_attr( $release ) . '">';
		if ( $release !== '' ) {
			/* translators: %s: release name in a code tag */
			self::out( '<p class="wpbr-fine">' . sprintf( esc_html__( 'Production is tagged %s before and after, so this release can be rolled back as a unit.', 'branchline' ), self::code( WPBR_Release::TAG . $release ) ) . ( WPBR_Git::available() ? ' ' . ( WPBR_Release::has_deploy() ? esc_html__( 'The code is tagged the same and the deploy command runs first.', 'branchline' ) : esc_html__( 'The code is tagged the same; deploy it with your usual command.', 'branchline' ) ) : '' ) . '</p>' );
		}

		// ---- decisions first: this is the only part that needs a person
		if ( $p['human'] ) {
			echo '<h2>' . esc_html__( 'Needs your decision', 'branchline' ) . ' <span class="wpbr-count">' . (int) count( $p['human'] ) . '</span></h2>';
			/* translators: %s: target branch in a code tag */
			self::out( '<p class="wpbr-sub">' . sprintf( esc_html__( 'Both branches changed the same thing. Pick which version %s should have.', 'branchline' ), self::code( $target ) ) . '</p>' );
			foreach ( $p['human'] as $h ) {
				$k = esc_attr( $h['key'] );
				self::out( '<div class="wpbr-decision"><div class="wpbr-decision-head"><h3>' . esc_html( $h['label'] ) . '</h3>' . self::class_tag( $h['class'] ) . '</div><p class="why">' . esc_html( $h['reason'] ) . '</p>' );
				/* translators: %s: branch name */
				self::out( '<div class="sides"><div class="side"><h4>' . sprintf( esc_html__( 'On %s', 'branchline' ), esc_html( $target ) ) . '</h4>' . self::show_row( $h, 'ours' ) . '</div>' );
				/* translators: %s: branch name */
				self::out( '<div class="side"><h4>' . sprintf( esc_html__( 'On %s', 'branchline' ), esc_html( $source ) ) . '</h4>' . self::show_row( $h, 'theirs' ) . '</div></div>' );
				echo '<div class="wpbr-choices">';
				$has_merged = ! empty( $h['merged'] ) && $h['kind'] === 'conflict';
				if ( $has_merged ) {
					/* translators: %s: the side that wins clashing fields (a branch name or the word production) */
					echo '<label><input type="radio" name="decision[' . esc_attr( $k ) . ']" value="merged" checked><span>' . esc_html__( 'Combine', 'branchline' ) . '</span><small>' . sprintf( esc_html__( '%s wins the clashing fields', 'branchline' ), esc_html( $combine_winner ) ) . '</small></label>';
				}
				/* translators: %s: branch name */
				echo '<label><input type="radio" name="decision[' . esc_attr( $k ) . ']" value="ours"' . ( $has_merged ? '' : ' checked' ) . '><span>' . sprintf( esc_html__( 'Keep %s', 'branchline' ), esc_html( $target ) ) . '</span><small>' . sprintf( esc_html__( 'drop the %s version', 'branchline' ), esc_html( $source ) ) . '</small></label>';
				/* translators: %s: branch name */
				echo '<label><input type="radio" name="decision[' . esc_attr( $k ) . ']" value="theirs"><span>' . sprintf( esc_html__( 'Take %s', 'branchline' ), esc_html( $source ) ) . '</span><small>' . sprintf( esc_html__( 'replace the %s version', 'branchline' ), esc_html( $target ) ) . '</small></label>';
				echo '</div></div>';
			}
		}

		// ---- what comes in
		/* translators: %s: source branch name */
		echo '<h2>' . sprintf( esc_html__( 'What comes in from %s', 'branchline' ), esc_html( $source ) ) . '</h2>';
		if ( ! $p['incoming'] ) {
			echo '<p class="wpbr-sub">' . esc_html__( 'Nothing. The branches are identical.', 'branchline' ) . '</p>';
		} else {
			echo '<table class="wpbr-table wpbr-incoming"><thead><tr><th>' . esc_html__( 'Area', 'branchline' ) . '</th><th>' . esc_html__( 'Changes', 'branchline' ) . '</th><th>' . esc_html__( 'Examples', 'branchline' ) . '</th></tr></thead><tbody>';
			foreach ( $p['incoming'] as $t ) {
				echo '<tr><td><strong>' . esc_html( $t['label'] ) . '</strong><br><code>' . esc_html( $t['table'] ) . '</code>' . ( ! empty( $t['approx'] ) ? '<br><span class="wpbr-fine">' . esc_html__( 'large table: counts include bookkeeping', 'branchline' ) . '</span>' : '' ) . '</td>';
				self::out( '<td class="wpbr-nowrap">' . self::chips( $t ) . '</td><td>' );
				$samples = $t['samples'];
				$first   = array_slice( $samples, 0, 3 );
				$rest    = array_slice( $samples, 3 );
				echo '<ul class="samples">';
				foreach ( $first as $s ) {
					echo '<li class="' . esc_attr( $s['type'] ) . '">' . esc_html( $s['text'] ) . '</li>';
				}
				echo '</ul>';
				if ( $rest ) {
					/* translators: %d: number of further examples */
					echo '<details class="wpbr-more"><summary>' . sprintf( esc_html( _n( '%d more', '%d more', count( $rest ), 'branchline' ) ), (int) count( $rest ) ) . '</summary><ul class="samples">';
					foreach ( $rest as $s ) {
						echo '<li class="' . esc_attr( $s['type'] ) . '">' . esc_html( $s['text'] ) . '</li>';
					}
					echo '</ul></details>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
			$silent = (int) ( $p['silent'] ?? 0 );
			if ( $silent ) {
				/* translators: %d: number of bookkeeping clashes */
				echo '<p class="wpbr-fine">' . sprintf( esc_html( _n( 'Bookkeeping rows (transients, cron, locks, sessions) are left out, and %d bookkeeping clash was resolved without review.', 'Bookkeeping rows (transients, cron, locks, sessions) are left out, and %d bookkeeping clashes were resolved without review.', $silent, 'branchline' ) ), (int) $silent ) . '</p>';
			} else {
				echo '<p class="wpbr-fine">' . esc_html__( 'Bookkeeping rows (transients, cron, locks, sessions) are left out.', 'branchline' ) . '</p>';
			}
		}

		// ---- rules, collapsed: there for the audit trail, not for reading every time
		if ( $p['auto'] ) {
			echo '<details class="wpbr-rules"><summary><h2>' . esc_html__( 'Resolved by the rules', 'branchline' ) . ' <span class="wpbr-count">' . (int) count( $p['auto'] ) . '</span></h2></summary>';
			echo '<table class="wpbr-table"><tbody>';
			foreach ( $p['auto'] as $a ) {
				self::out( '<tr><td><strong>' . esc_html( $a['label'] ) . '</strong> ' . self::class_tag( $a['class'] ) . '</td><td>' . esc_html( $a['reason'] ) . '</td><td class="wpbr-nowrap wpbr-fine">' . esc_html( self::resolution_word( $a['resolution'], $target, $source ) ) . '</td></tr>' );
			}
			echo '</tbody></table></details>';
		}

		// ---- apply bar
		echo '<div class="card wpbr-applybar">';
		echo '<input type="text" name="message" class="regular-text" placeholder="' . esc_attr__( 'Snapshot message, for example: Release 4 published', 'branchline' ) . '">';
		echo '<button class="button button-primary button-hero"' . ( ! $can_apply ? ' disabled' : '' ) . '>' . esc_html( $verbs[ $dir ] ) . '</button>';
		echo '<span class="wpbr-fine">' . esc_html__( 'Runs in one transaction, pinned to the snapshots reviewed here.', 'branchline' ) . '</span>';
		echo '</div></form>';
	}

	// ------------------------------------------------------------------- helpers

	/** Escaped HTML. */
	private static function pill( string $branch, bool $is_prod ): string {
		if ( WPBR_Workspaces::is_workspace( $branch ) ) {
			return '<span class="wpbr-pill ws">' . esc_html( WPBR_Workspaces::name_of( $branch ) ) . ' <span class="wpbr-dim">' . esc_html__( 'workspace', 'branchline' ) . '</span></span>';
		}
		return '<span class="wpbr-pill' . ( $is_prod ? ' prod' : '' ) . '">' . esc_html( $branch ) . ( $is_prod ? ' <span class="wpbr-dim">' . esc_html__( 'production', 'branchline' ) . '</span>' : '' ) . '</span>';
	}

	/** Escaped HTML. $value must already be escaped by the caller. */
	private static function stat( string $value, string $label, string $tone = '' ): string {
		return '<div class="wpbr-stat ' . esc_attr( $tone ) . '"><div class="n">' . $value . '</div><div class="l">' . esc_html( $label ) . '</div></div>';
	}

	/** Escaped HTML. */
	private static function chips( array $t ): string {
		$h = '';
		if ( $t['added'] ) {
			/* translators: %d: number of rows */
			$h .= '<span class="chip add">' . sprintf( esc_html__( '+%d added', 'branchline' ), (int) $t['added'] ) . '</span>';
		}
		if ( $t['modified'] ) {
			/* translators: %d: number of rows */
			$h .= '<span class="chip mod">' . sprintf( esc_html__( '%d changed', 'branchline' ), (int) $t['modified'] ) . '</span>';
		}
		if ( $t['deleted'] ) {
			/* translators: %d: number of rows */
			$h .= '<span class="chip del">' . sprintf( esc_html__( '%d removed', 'branchline' ), (int) $t['deleted'] ) . '</span>';
		}
		return $h;
	}

	private static function resolution_word( string $r, string $target, string $source ): string {
		return match ( $r ) {
			/* translators: %s: branch name */
			'ours'   => sprintf( __( 'kept %s', 'branchline' ), $target ),
			/* translators: %s: branch name */
			'theirs' => sprintf( __( 'took %s', 'branchline' ), $source ),
			'merged' => __( 'combined', 'branchline' ),
			'delete' => __( 'removed', 'branchline' ),
			default  => $r,
		};
	}

	private static function when( string $ts ): string {
		if ( $ts === '' ) {
			return '';
		}
		$t = strtotime( $ts );
		if ( ! $t ) {
			return $ts;
		}
		$diff = time() - $t;
		if ( $diff < 90 ) {
			return __( 'just now', 'branchline' );
		}
		if ( $diff < 3600 ) {
			/* translators: %d: number of minutes */
			return sprintf( __( '%d min ago', 'branchline' ), (int) floor( $diff / 60 ) );
		}
		if ( $diff < 86400 ) {
			/* translators: %d: number of hours */
			return sprintf( __( '%d h ago', 'branchline' ), (int) floor( $diff / 3600 ) );
		}
		return wp_date( _x( 'M j, H:i', 'short date and time for the snapshot list', 'branchline' ), $t );
	}

	/** Escaped HTML. */
	private static function class_tag( string $row_class ): string {
		return '<span class="class ' . esc_attr( $row_class ) . '">' . esc_html( $row_class ) . '</span>';
	}

	/** Escaped HTML. */
	private static function show_row( array $h, string $side ): string {
		$row = $h[ $side ] ?? null;
		if ( ! $row ) {
			return '<pre class="wpbr-fine">' . esc_html__( '(does not exist on this branch)', 'branchline' ) . '</pre>';
		}
		$clashes = $h['clashes'] ?? null;
		$show    = array();
		foreach ( $row as $col => $val ) {
			if ( $clashes && ! in_array( $col, $clashes, true ) ) {
				continue;
			}
			if ( ! $clashes && in_array( $col, array( 'option_id' ), true ) ) {
				continue;
			}
			$show[] = $col . ': ' . ( is_null( $val ) ? 'NULL' : wp_strip_all_tags( (string) $val ) );
		}
		return '<pre>' . esc_html( implode( "\n", $show ) ) . '</pre>';
	}
}
