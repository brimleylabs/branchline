<?php
/**
 * wp-admin > Branchline: the Workspaces and Releases cards and their handlers.
 * Shown on the staging site when workspaces are on.
 */
defined( 'ABSPATH' ) || exit;

class WPBR_Admin_Workspaces {

	public static function boot(): void {
		add_action( 'admin_post_wpbr_workspace', array( self::class, 'handle' ) );
		add_action( 'admin_post_wpbr_release_rollback', array( self::class, 'handle_release_rollback' ) );
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => 'wpbr' ), $args ), admin_url( 'admin.php' ) );
	}

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

	private static function out( string $html ): void {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every helper escapes at the point it builds the string.
	}

	// ------------------------------------------------------------------ actions

	/** Create or delete a workspace. */
	public static function handle(): void {
		check_admin_referer( 'wpbr_workspace' );
		if ( ! current_user_can( WPBR_CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Not allowed.', 'branchline' ) );
		}
		$op   = sanitize_key( wp_unslash( $_POST['op'] ?? '' ) );
		$name = strtolower( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) );
		try {
			if ( $op === 'create' ) {
				$from = sanitize_text_field( wp_unslash( $_POST['from'] ?? '' ) );
				$code = ! empty( $_POST['code'] );
				$r    = WPBR_Workspaces::create( $name, $from, $code );
				$msg  = $r['code']
					/* translators: 1: workspace name, 2: branch or snapshot it started from */
					? sprintf( __( 'Workspace %1$s created from %2$s, with its own code branch. Open it from the admin bar.', 'branchline' ), $name, $r['from'] )
					/* translators: 1: workspace name, 2: branch or snapshot it started from */
					: sprintf( __( 'Workspace %1$s created from %2$s. Open it from the admin bar.', 'branchline' ), $name, $r['from'] );
			} elseif ( $op === 'delete' ) {
				if ( WPBR_Workspaces::current() === $name ) {
					WPBR_Workspaces::leave();
				}
				WPBR_Workspaces::delete( $name );
				/* translators: %s: workspace name */
				$msg = sprintf( __( 'Workspace %s deleted. Its snapshots stay in the history of anything that merged it.', 'branchline' ), $name );
			} else {
				throw new RuntimeException( esc_html__( 'Unknown workspace operation.', 'branchline' ) );
			}
			self::redirect( $msg, true );
		} catch ( Throwable $e ) {
			/* translators: %s: error message */
			self::redirect( sprintf( __( 'Workspace operation failed: %s', 'branchline' ), $e->getMessage() ), false );
		}
	}

	public static function handle_release_rollback(): void {
		check_admin_referer( 'wpbr_release_rollback' );
		if ( ! current_user_can( WPBR_CAP_PUBLISH ) ) {
			wp_die( esc_html__( 'Rolling a release back needs the publish_to_production capability.', 'branchline' ) );
		}
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		try {
			$r = WPBR_Release::rollback( $name );
			/* translators: 1: release name, 2: short snapshot hash */
			$msg = sprintf( __( 'Release %1$s rolled back. Production is at %2$s.', 'branchline' ), $name, substr( $r['hash'], 0, 8 ) );
			if ( $r['code_ref'] && $r['deploy'] !== null ) {
				/* translators: %s: git tag */
				$msg .= ' ' . sprintf( __( 'Code %s deployed again.', 'branchline' ), $r['code_ref'] );
			} elseif ( $r['code_ref'] ) {
				/* translators: %s: git tag */
				$msg .= ' ' . sprintf( __( 'Deploy code %s by hand; no deploy command is configured.', 'branchline' ), $r['code_ref'] );
			} elseif ( WPBR_Git::available() ) {
				$msg .= ' ' . __( 'No earlier release tag exists, so the code was left as it is.', 'branchline' );
			}
			self::redirect( $msg, true );
		} catch ( Throwable $e ) {
			/* translators: %s: error message */
			self::redirect( sprintf( __( 'Rollback failed: %s', 'branchline' ), $e->getMessage() ), false );
		}
	}

	// ------------------------------------------------------------------- cards

	/** The Workspaces card. $branches is the list from dolt_branches. */
	public static function card( WPBR_Dolt $d, array $branches ): void {
		$stage   = WPBR_Workspaces::stage_branch();
		$current = WPBR_Workspaces::current();
		$all     = WPBR_Workspaces::all( $d );
		$code_on = WPBR_Workspaces::code_enabled();

		echo '<div class="card wpbr-workspaces"><h2>' . esc_html__( 'Workspaces', 'branchline' ) . '</h2>';
		echo '<p class="wpbr-sub">' . esc_html__( 'Work happens in a workspace. When it is ready, promote it to stage. Stage holds production or the next release, nothing else.', 'branchline' ) . '</p>';
		if ( $all ) {
			echo '<table class="wpbr-table"><thead><tr><th>' . esc_html__( 'Workspace', 'branchline' ) . '</th><th>' . esc_html__( 'Changes for stage', 'branchline' ) . '</th><th>' . esc_html__( 'Standing', 'branchline' ) . '</th>' . ( $code_on ? '<th>' . esc_html__( 'Code', 'branchline' ) . '</th>' : '' ) . '<th class="right"></th></tr></thead><tbody>';
			foreach ( $all as $w ) {
				$is_here = $w['name'] === $current;
				echo '<tr><td><strong>' . esc_html( $w['name'] ) . '</strong>' . ( $is_here ? ' <span class="wpbr-tag">' . esc_html__( 'you are here', 'branchline' ) . '</span>' : '' ) . '<br><span class="wpbr-fine">' . esc_html( $w['last'] ) . '</span></td>';
				/* translators: %d: number of rows */
				echo '<td>' . ( $w['ahead'] ? '<span class="chip add">' . sprintf( esc_html__( '%d rows', 'branchline' ), (int) $w['ahead'] ) . '</span>' : '<span class="wpbr-fine">' . esc_html__( 'none yet', 'branchline' ) . '</span>' ) . '</td>';
				if ( $w['current'] ) {
					echo '<td><span class="wpbr-tag ok">' . esc_html__( 'current with stage', 'branchline' ) . '</span></td>';
				} else {
					/* translators: %d: number of rows */
					echo '<td><span class="wpbr-tag warn">' . sprintf( esc_html__( 'behind stage by %d rows', 'branchline' ), (int) $w['behind'] ) . '</span></td>';
				}
				if ( $code_on ) {
					if ( $w['code'] ) {
						/* translators: %d: number of commits */
						echo '<td>' . sprintf( esc_html( _n( '%d commit ahead', '%d commits ahead', (int) $w['code_ahead'], 'branchline' ) ), (int) $w['code_ahead'] ) . '<br><code class="wpbr-url">' . esc_html( (string) $w['code_dir'] ) . '</code></td>';
					} else {
						echo '<td><span class="wpbr-fine">' . esc_html__( 'data only', 'branchline' ) . '</span></td>';
					}
				}
				$btns = '';
				if ( ! $is_here ) {
					$btns .= '<a class="button button-small" href="' . esc_url( WPBR_Workspaces::enter_url( $w['name'] ) ) . '">' . esc_html__( 'Open', 'branchline' ) . '</a>';
				}
				if ( ! $w['current'] ) {
					$btns .= '<a class="button button-small" href="' . esc_url(
						self::url(
							array(
								'view' => 'review',
								'dir'  => 'update',
								'ws'   => $w['name'],
							)
						)
					) . '">' . esc_html__( 'Update from stage', 'branchline' ) . '</a>';
				} elseif ( $w['ahead'] || $w['code_ahead'] ) {
					$btns .= '<a class="button button-small button-primary" href="' . esc_url(
						self::url(
							array(
								'view' => 'review',
								'dir'  => 'promote',
								'ws'   => $w['name'],
							)
						)
					) . '">' . esc_html__( 'Promote to stage', 'branchline' ) . '</a>';
				}
				$btns .= '<details class="wpbr-reveal wpbr-more-actions"><summary class="button-link">' . esc_html__( 'More', 'branchline' ) . '</summary><div class="wpbr-popover">';
				$btns .= '<p><label>' . esc_html__( 'Preview link, valid 12 hours', 'branchline' ) . '<br><input type="text" readonly class="wpbr-w-l" value="' . esc_attr( WPBR_Workspaces::link( $w['name'] ) ) . '" onfocus="this.select()"></label></p>';
				$btns .= self::ws_form( 'delete', $w['name'], __( 'Delete workspace', 'branchline' ), 'button-link button-link-delete' );
				$btns .= '</div></details>';
				self::out( '<td class="right"><div class="wpbr-btns">' . $btns . '</div></td></tr>' );
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="wpbr-fine">' . esc_html__( 'No workspaces yet. Create one below, open it from the admin bar, and edit there.', 'branchline' ) . '</p>';
		}

		// ---- create
		echo '<details class="wpbr-reveal"' . ( $all ? '' : ' open' ) . '><summary class="button button-small">' . esc_html__( 'New workspace', 'branchline' ) . '</summary>';
		self::out( '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpbr-inline">' . wp_nonce_field( 'wpbr_workspace', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="wpbr_workspace"><input type="hidden" name="op" value="create">' );
		echo '<input type="text" name="name" placeholder="' . esc_attr__( 'spring-campaign', 'branchline' ) . '" pattern="[a-z0-9][a-z0-9-]{0,39}" required class="wpbr-w-m">';
		echo '<label>' . esc_html__( 'from', 'branchline' ) . ' <select name="from">';
		echo '<option value="' . esc_attr( $stage ) . '">' . esc_html( $stage ) . ' (' . esc_html__( 'stage', 'branchline' ) . ')</option>';
		foreach ( $branches as $b ) {
			if ( $b['name'] === $stage ) {
				continue;
			}
			$label = $b['name'] === WPBR_PRODUCTION_BRANCH ? $b['name'] . ' (' . __( 'production', 'branchline' ) . ')' : $b['name'];
			echo '<option value="' . esc_attr( $b['name'] ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		if ( $code_on ) {
			echo '<label><input type="checkbox" name="code" value="1" checked> ' . esc_html__( 'branch the code too', 'branchline' ) . '</label>';
		}
		echo '<button class="button button-primary">' . esc_html__( 'Create', 'branchline' ) . '</button></form>';
		if ( $code_on ) {
			/* translators: 1: path of the code repository, 2: branch name */
			self::out( '<p class="wpbr-fine">' . sprintf( esc_html__( 'Code branches from %1$s (branch %2$s) into a checkout the workspace serves.', 'branchline' ), '<code>' . esc_html( WPBR_Git::root() ) . '</code>', '<code>' . esc_html( WPBR_Git::stage_branch() ) . '</code>' ) . '</p>' );
		} else {
			self::out( '<p class="wpbr-fine">' . sprintf( /* translators: %s: constant name in a code tag */ esc_html__( 'Workspaces branch data. To branch code as well, set %s to the git repository of this site, normally wp-content.', 'branchline' ), '<code>WPBR_CODE_ROOT</code>' ) . '</p>' );
		}
		echo '</details></div>';
	}

	/** The Releases card: stage against production, release now, past releases with rollback. */
	public static function release_card( int $data_ahead, int $data_behind ): void {
		$code_on = WPBR_Workspaces::code_enabled();
		$code    = null;
		if ( $code_on ) {
			try {
				$code = WPBR_Git::count( WPBR_Git::production_branch(), WPBR_Git::stage_branch() );
			} catch ( Throwable $e ) {
				$code = null;
			}
		}
		$releases = array();
		try {
			$releases = WPBR_Release::all();
		} catch ( Throwable $e ) {
			$releases = array();
		}
		echo '<div class="card wpbr-release"><h2>' . esc_html__( 'Release', 'branchline' ) . '</h2>';
		if ( ! $data_ahead && ! $code ) {
			echo '<p class="wpbr-sub"><span class="wpbr-tag ok">' . esc_html__( 'stage matches production', 'branchline' ) . '</span> ' . esc_html__( 'Nothing waiting to go out.', 'branchline' ) . '</p>';
		} else {
			$parts = array();
			if ( $data_ahead ) {
				/* translators: %d: number of rows */
				$parts[] = sprintf( _n( '%d row of data', '%d rows of data', $data_ahead, 'branchline' ), $data_ahead );
			}
			if ( $code ) {
				/* translators: %d: number of commits */
				$parts[] = sprintf( _n( '%d code commit', '%d code commits', $code, 'branchline' ), $code );
			}
			/* translators: %s: what stage holds beyond production, e.g. "12 rows of data and 3 code commits" */
			echo '<p class="wpbr-sub"><span class="wpbr-tag warn">' . esc_html__( 'stage is ahead of production', 'branchline' ) . '</span> ' . esc_html( sprintf( __( 'Stage holds the next release: %s.', 'branchline' ), implode( ' ' . __( 'and', 'branchline' ) . ' ', $parts ) ) ) . '</p>';
		}
		if ( $data_behind ) {
			/* translators: %d: number of rows */
			echo '<p class="wpbr-fine warn">' . sprintf( esc_html__( 'Production has %d rows stage has not pulled. Pull first so the release is a clean fast forward.', 'branchline' ), (int) $data_behind ) . '</p>';
		}
		if ( current_user_can( WPBR_CAP_PUBLISH ) && ( $data_ahead || $code ) ) {
			echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="wpbr-inline"><input type="hidden" name="page" value="wpbr"><input type="hidden" name="view" value="review"><input type="hidden" name="dir" value="publish">';
			echo '<label>' . esc_html__( 'Release name', 'branchline' ) . ' <input type="text" name="release" value="' . esc_attr( WPBR_Release::default_name() ) . '" pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,60}" class="wpbr-w-m"></label>';
			echo '<button class="button button-primary">' . esc_html__( 'Review and release to production', 'branchline' ) . '</button></form>';
			if ( $code_on ) {
				echo '<p class="wpbr-fine">' . ( WPBR_Release::has_deploy()
					? esc_html__( 'Code is tagged and the deploy command runs before the data is published.', 'branchline' )
					: esc_html__( 'Code is tagged. No deploy command is configured, so deploy the tag yourself before or right after.', 'branchline' ) ) . '</p>';
			}
		}
		if ( $releases ) {
			echo '<h3>' . esc_html__( 'Releases', 'branchline' ) . '</h3><table class="wpbr-table wpbr-log"><tbody>';
			foreach ( array_slice( $releases, 0, 10 ) as $i => $r ) {
				$undo = '';
				if ( $i === 0 && current_user_can( WPBR_CAP_PUBLISH ) ) {
					$undo = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpbr-btnform">' . wp_nonce_field( 'wpbr_release_rollback', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="wpbr_release_rollback"><input type="hidden" name="name" value="' . esc_attr( $r['name'] ) . '"><button class="button-link">' . esc_html__( 'Roll back this release', 'branchline' ) . '</button></form>';
				}
				echo '<tr><td class="wpbr-nowrap"><strong>' . esc_html( $r['name'] ) . '</strong></td><td class="wpbr-nowrap"><code>' . esc_html( substr( $r['hash'], 0, 8 ) ) . '</code></td><td class="wpbr-fine wpbr-nowrap">' . esc_html( substr( (string) $r['date'], 0, 16 ) ) . '</td>';
				self::out( '<td class="right wpbr-nowrap">' . $undo . '</td></tr>' );
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	/** Setup instructions when workspaces are not configured on this site. */
	public static function setup_hint(): void {
		if ( WPBR_Dolt::current_branch() === WPBR_PRODUCTION_BRANCH ) {
			return;
		}
		$secret = wp_generate_password( 48, false );
		echo '<details class="card wpbr-setup"><summary><h2>' . esc_html__( 'Workspaces: not set up on this site', 'branchline' ) . '</h2></summary>';
		echo '<p>' . esc_html__( 'Workspaces let editors, developers and agents each work on their own named branch, served by this staging site, and promote finished work into stage. Stage becomes read-only and always holds exactly one thing: production, or the next release. To turn it on, change the DB_NAME line in wp-config.php to:', 'branchline' ) . '</p>';
		echo '<pre class="wpbr-pre">' . esc_html( "define('WPBR_WORKSPACE_SECRET', '" . $secret . "');\nrequire_once __DIR__ . '/wp-content/plugins/branchline/extras/workspace-switch.php';\ndefine('DB_NAME', wpbr_workspace_db_name('" . WPBR_Dolt::base_database() . "', '" . WPBR_Dolt::current_branch() . "'));\n\n// Optional: branch code too. The git repository of this site, normally wp-content.\ndefine('WPBR_CODE_ROOT', __DIR__ . '/wp-content');" ) . '</pre>';
		echo '<p class="wpbr-fine">' . esc_html__( 'Never add this to production. Production runs one branch.', 'branchline' ) . '</p></details>';
	}

	private static function ws_form( string $op, string $name, string $label, string $css ): string {
		return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpbr-btnform">' . wp_nonce_field( 'wpbr_workspace', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="wpbr_workspace"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="name" value="' . esc_attr( $name ) . '"><button class="' . esc_attr( $css ) . '">' . esc_html( $label ) . '</button></form>';
	}
}
