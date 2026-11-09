<?php
/**
 * wp branchline <command>
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
 *
 * Workspaces (staging site with extras/workspace-switch.php loaded):
 *   workspace list                            Every workspace, its changes, whether it is current with stage
 *   workspace create <name> [--from=<ref>] [--code]   New workspace from stage (default), production, a branch or a snapshot
 *   workspace delete <name> [--yes]
 *   workspace link <name>                     A signed preview link that opens the workspace in a browser
 *   workspace update <name> [--yes] [--resolve=...]   Bring stage into the workspace
 *   promote <name> [--yes] [--resolve=...]    Merge a current workspace into stage (data, then code)
 *   release [<name>] [--yes] [--no-deploy] [--resolve=...]   Stage to production, tagged, code first
 *   release list                              Releases so far
 *   release rollback <name> [--yes] [--no-deploy]   Production back to before a release; previous code deployed again
 *
 * Any wp command runs inside a workspace with WPBR_WORKSPACE=<name> in the environment.
 */
defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI hands ($args, $assoc) to every command whether it needs them or not.

class WPBR_CLI {

	public function status( $args, $assoc ): void {
		$branch = WPBR_Dolt::current_branch();
		$d      = new WPBR_Dolt( $branch );
		/* translators: %s: branch name */
		WP_CLI::line( sprintf( __( 'Branch:      %s', 'branchline' ), $branch . ( $branch === WPBR_PRODUCTION_BRANCH ? ' ' . __( '(production)', 'branchline' ) : '' ) ) );
		/* translators: %s: production branch name */
		WP_CLI::line( sprintf( __( 'Production:  %s', 'branchline' ), WPBR_PRODUCTION_BRANCH ) );
		/* translators: %s: snapshot hash */
		WP_CLI::line( sprintf( __( 'Head:        %s', 'branchline' ), $d->head_hash() ) );
		$st = $d->status();
		if ( count( $st ) ) {
			/* translators: 1: number of tables, 2: comma-separated table names */
			WP_CLI::line( sprintf( _n( 'Uncommitted: %1$d table: %2$s', 'Uncommitted: %1$d tables: %2$s', count( $st ), 'branchline' ), count( $st ), implode( ', ', array_column( $st, 'table_name' ) ) ) );
		} else {
			WP_CLI::line( __( 'Uncommitted: none', 'branchline' ) );
		}
		WP_CLI::line( __( 'Recent snapshots:', 'branchline' ) );
		foreach ( $d->log( 5 ) as $c ) {
			WP_CLI::line( sprintf( '  %s  %s  %s', substr( $c['commit_hash'], 0, 8 ), substr( $c['date'], 0, 16 ), $c['message'] ) );
		}
		WP_CLI::line( __( 'Branches:', 'branchline' ) );
		foreach ( $d->branches() as $b ) {
			WP_CLI::line( sprintf( '  %-12s %s  %s', $b['name'], substr( $b['hash'], 0, 8 ), (string) $b['latest_commit_message'] ) );
		}
		if ( WPBR_Workspaces::enabled() ) {
			$ws = WPBR_Workspaces::current();
			/* translators: %s: workspace name or the word "none" */
			WP_CLI::line( sprintf( __( 'Workspace:   %s', 'branchline' ), $ws ?? __( 'none (stage, read-only)', 'branchline' ) ) );
			$stage = WPBR_Workspaces::stage_branch();
			if ( $branch === $stage ) {
				$cls    = new WPBR_Classifier( $d );
				$base   = $d->merge_base( WPBR_PRODUCTION_BRANCH );
				$ahead  = WPBR_Merge_Engine::total( WPBR_Merge_Engine::summarize( $d, $cls, $base, $stage, 0 ) );
				$behind = WPBR_Merge_Engine::total( WPBR_Merge_Engine::summarize( $d, $cls, $base, WPBR_PRODUCTION_BRANCH, 0 ) );
				$code   = WPBR_Git::available() ? WPBR_Git::count( WPBR_Git::production_branch(), WPBR_Git::stage_branch() ) : null;
				if ( ! $ahead && ! $code ) {
					WP_CLI::line( __( 'Stage:       matches production', 'branchline' ) );
				} else {
					/* translators: 1: rows of data, 2: code commits or "n/a" */
					WP_CLI::line( sprintf( __( 'Stage:       ahead of production by %1$d rows of data, %2$s code commits', 'branchline' ), $ahead, $code === null ? 'n/a' : (string) $code ) );
				}
				if ( $behind ) {
					/* translators: %d: number of rows */
					WP_CLI::line( sprintf( __( '             behind production by %d rows (pull first)', 'branchline' ), $behind ) );
				}
			}
		}
		$d->close();
	}

	// ------------------------------------------------------------ workspaces

	/** wp branchline workspace <list|create|delete|link|update> ... */
	public function workspace( $args, $assoc ): void {
		$this->need_workspaces();
		$op   = $args[0] ?? 'list';
		$name = strtolower( (string) ( $args[1] ?? '' ) );
		try {
			switch ( $op ) {
				case 'list':
					$d    = new WPBR_Dolt( WPBR_Workspaces::stage_branch() );
					$rows = WPBR_Workspaces::all( $d );
					$d->close();
					if ( ! $rows ) {
						WP_CLI::line( __( 'No workspaces. Create one: wp branchline workspace create <name>', 'branchline' ) );
						return;
					}
					WP_CLI\Utils\format_items(
						$assoc['format'] ?? 'table',
						array_map(
							fn( $w ) => array(
								'workspace' => $w['name'],
								'rows'      => $w['ahead'],
								'standing'  => $w['current'] ? __( 'current', 'branchline' ) : sprintf( /* translators: %d: rows */ __( 'behind by %d', 'branchline' ), $w['behind'] ),
								'code'      => $w['code'] === null ? '' : ( $w['code'] ? sprintf( '%d commits ahead', (int) $w['code_ahead'] ) : __( 'data only', 'branchline' ) ),
								'last'      => $w['last'],
							),
							$rows
						),
						array( 'workspace', 'rows', 'standing', 'code', 'last' )
					);
					return;
				case 'create':
					if ( $name === '' ) {
						WP_CLI::error( __( 'Usage: wp branchline workspace create <name> [--from=<ref>] [--code]', 'branchline' ) );
					}
					$r = WPBR_Workspaces::create( $name, (string) ( $assoc['from'] ?? '' ), ! empty( $assoc['code'] ) );
					/* translators: 1: workspace name, 2: what it started from, 3: branch name */
					WP_CLI::success( sprintf( __( 'Workspace %1$s created from %2$s on branch %3$s.', 'branchline' ), $name, $r['from'], $r['branch'] ) . ( $r['code'] ? ' ' . sprintf( /* translators: %s: directory */ __( 'Code checkout: %s', 'branchline' ), $r['code_dir'] ) : '' ) );
					/* translators: %s: URL */
					WP_CLI::line( sprintf( __( 'Open it in a browser: %s', 'branchline' ), WPBR_Workspaces::link( $name ) ) );
					/* translators: %s: workspace name */
					WP_CLI::line( sprintf( __( 'Run wp inside it:     WPBR_WORKSPACE=%s wp ...', 'branchline' ), $name ) );
					return;
				case 'delete':
					if ( $name === '' ) {
						WP_CLI::error( __( 'Usage: wp branchline workspace delete <name> [--yes]', 'branchline' ) );
					}
					if ( empty( $assoc['yes'] ) ) {
						/* translators: %s: workspace name */
						WP_CLI::confirm( sprintf( __( 'Delete workspace %s and its code checkout?', 'branchline' ), $name ) );
					}
					WPBR_Workspaces::delete( $name );
					/* translators: %s: workspace name */
					WP_CLI::success( sprintf( __( 'Workspace %s deleted.', 'branchline' ), $name ) );
					return;
				case 'link':
					if ( $name === '' ) {
						WP_CLI::error( __( 'Usage: wp branchline workspace link <name> [--hours=12]', 'branchline' ) );
					}
					WP_CLI::line( WPBR_Workspaces::link( $name, (int) ( ( $assoc['hours'] ?? 12 ) * HOUR_IN_SECONDS ) ) );
					return;
				case 'update':
					if ( $name === '' ) {
						WP_CLI::error( __( 'Usage: wp branchline workspace update <name> [--yes]', 'branchline' ) );
					}
					$this->run_merge( WPBR_Workspaces::update_engine( $name ), $assoc, fn( $dec, $pin, $msg ) => WPBR_Workspaces::update( $name, $dec, $pin, $msg ) );
					return;
				default:
					WP_CLI::error( __( 'Usage: wp branchline workspace <list|create|delete|link|update>', 'branchline' ) );
			}
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/** wp branchline promote <name> [--yes] [--resolve=...] */
	public function promote( $args, $assoc ): void {
		$this->need_workspaces();
		$name = strtolower( (string) ( $args[0] ?? '' ) );
		if ( $name === '' ) {
			WP_CLI::error( __( 'Give the workspace to promote. See: wp branchline workspace list', 'branchline' ) );
		}
		try {
			if ( ! WPBR_Workspaces::is_current( $name ) ) {
				/* translators: %s: workspace name */
				WP_CLI::error( sprintf( __( 'Workspace %1$s is behind stage. Run: wp branchline workspace update %1$s', 'branchline' ), $name ) );
			}
			$this->run_merge( WPBR_Workspaces::promote_engine( $name ), $assoc, fn( $dec, $pin, $msg ) => WPBR_Workspaces::promote( $name, $dec, $pin, $msg ) );
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	/** wp branchline release [<name>] | release list | release rollback <name> */
	public function release( $args, $assoc ): void {
		$this->need_workspaces();
		$op = $args[0] ?? '';
		try {
			if ( $op === 'list' ) {
				$rows = WPBR_Release::all();
				if ( ! $rows ) {
					WP_CLI::line( __( 'No releases yet.', 'branchline' ) );
					return;
				}
				WP_CLI\Utils\format_items(
					$assoc['format'] ?? 'table',
					array_map(
						fn( $r ) => array(
							'release'  => $r['name'],
							'snapshot' => substr( $r['hash'], 0, 8 ),
							'when'     => substr( $r['date'], 0, 16 ),
						),
						$rows
					),
					array( 'release', 'snapshot', 'when' )
				);
				return;
			}
			if ( $op === 'rollback' ) {
				$name = (string) ( $args[1] ?? '' );
				if ( $name === '' ) {
					WP_CLI::error( __( 'Usage: wp branchline release rollback <name> [--yes] [--no-deploy]', 'branchline' ) );
				}
				if ( empty( $assoc['yes'] ) ) {
					/* translators: %s: release name */
					WP_CLI::confirm( sprintf( __( 'Roll production back to before release %s? The current head is saved as a tag first.', 'branchline' ), $name ) );
				}
				$r = WPBR_Release::rollback( $name, empty( $assoc['no-deploy'] ) );
				/* translators: 1: release name, 2: snapshot hash, 3: tag name */
				WP_CLI::success( sprintf( __( 'Release %1$s rolled back. Production is at %2$s; the head you left is tag %3$s.', 'branchline' ), $name, $r['hash'], $r['saved_as'] ) );
				if ( $r['code_ref'] ) {
					WP_CLI::line(
						$r['deploy'] !== null
						/* translators: %s: git tag */
						? sprintf( __( 'Code %s deployed again.', 'branchline' ), $r['code_ref'] )
						/* translators: %s: git tag */
						: sprintf( __( 'Deploy code %s yourself; no deploy command ran.', 'branchline' ), $r['code_ref'] )
					);
				} elseif ( WPBR_Git::available() ) {
					WP_CLI::line( __( 'No earlier release tag exists, so the code was left as it is. Deploy the right code yourself if the release changed any.', 'branchline' ) );
				}
				return;
			}
			$name   = $op !== '' ? $op : WPBR_Release::default_name();
			$stage  = WPBR_Workspaces::stage_branch();
			$branch = WPBR_Dolt::current_branch();
			if ( $branch !== $stage ) {
				WP_CLI::error( __( 'Release runs from stage, not from a workspace.', 'branchline' ) );
			}
			if ( ! WPBR_Release::valid_name( $name ) ) {
				WP_CLI::error( __( 'A release name is letters, digits, dots, dashes and underscores.', 'branchline' ) );
			}
			/* translators: %s: release name */
			WP_CLI::line( sprintf( __( 'Release %s: stage to production', 'branchline' ), $name ) );
			$deploy = empty( $assoc['no-deploy'] );
			$this->run_merge( new WPBR_Merge_Engine( WPBR_PRODUCTION_BRANCH, $stage ), $assoc, fn( $dec, $pin, $msg ) => WPBR_Release::run( $name, $dec, $pin, $deploy, fn( $l ) => WP_CLI::line( '  ' . $l ) ) );
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
	}

	private function need_workspaces(): void {
		if ( ! WPBR_Workspaces::enabled() ) {
			WP_CLI::error( __( 'Workspaces are not set up on this site. Load extras/workspace-switch.php from wp-config.php on the staging site; the Branchline screen shows the lines.', 'branchline' ) );
		}
	}

	public function snapshot( $args, $assoc ): void {
		$d    = new WPBR_Dolt( WPBR_Dolt::current_branch(), false );
		$hash = $d->snapshot( $assoc['message'] ?? __( 'Snapshot via wp-cli', 'branchline' ) );
		$d->close();
		/* translators: %s: snapshot hash */
		WP_CLI::success( $hash ? sprintf( __( 'Snapshot %s', 'branchline' ), $hash ) : __( 'Nothing to snapshot.', 'branchline' ) );
	}

	public function diff( $args, $assoc ): void {
		$branch = WPBR_Dolt::current_branch();
		$d      = new WPBR_Dolt( $branch );
		$from   = $assoc['from'] ?? WPBR_PRODUCTION_BRANCH;
		$to     = $assoc['to'] ?? $branch;
		$rows   = array_filter( $d->diff_stat( $from, $to ), fn( $r ) => $r['rows_added'] || $r['rows_deleted'] || $r['rows_modified'] );
		$d->close();
		WP_CLI\Utils\format_items(
			'table',
			array_map(
				fn( $r ) => array(
					'table'    => $r['table_name'],
					'added'    => $r['rows_added'],
					'modified' => $r['rows_modified'],
					'deleted'  => $r['rows_deleted'],
				),
				$rows
			),
			array( 'table', 'added', 'modified', 'deleted' )
		);
	}

	public function pull( $args, $assoc ): void {
		$branch = WPBR_Dolt::current_branch();
		if ( $branch === WPBR_PRODUCTION_BRANCH ) {
			WP_CLI::error( __( 'This is the production branch. Pull runs on a release branch.', 'branchline' ) );
		}
		$this->run_merge( new WPBR_Merge_Engine( $branch, WPBR_PRODUCTION_BRANCH ), $assoc );
	}

	public function publish( $args, $assoc ): void {
		$branch = WPBR_Dolt::current_branch();
		if ( $branch === WPBR_PRODUCTION_BRANCH ) {
			WP_CLI::error( __( 'This is the production branch. Publish runs on a release branch.', 'branchline' ) );
		}
		$this->run_merge( new WPBR_Merge_Engine( WPBR_PRODUCTION_BRANCH, $branch ), $assoc );
	}

	public function merge( $args, $assoc ): void {
		$branch = WPBR_Dolt::current_branch();
		$from   = $args[0] ?? '';
		if ( $from === '' || $from === $branch ) {
			/* translators: %s: this site's branch name */
			WP_CLI::error( sprintf( __( 'Give the branch to merge into %s.', 'branchline' ), $branch ) );
		}
		$d = new WPBR_Dolt( $branch );
		if ( ! $d->branch_exists( $from ) ) {
			$d->close();
			/* translators: %s: branch name */
			WP_CLI::error( sprintf( __( 'No local branch "%s". Fetch and track it first.', 'branchline' ), $from ) );
		}
		$d->close();
		$this->run_merge( new WPBR_Merge_Engine( $branch, $from ), $assoc );
	}

	public function remote( $args, $assoc ): void {
		$d  = new WPBR_Dolt( WPBR_Dolt::current_branch(), false );
		$op = $args[0] ?? 'list';
		if ( $op === 'add' ) {
			if ( empty( $args[1] ) || empty( $args[2] ) ) {
				WP_CLI::error( __( 'Usage: wp branchline remote add <name> <url>', 'branchline' ) );
			}
			$d->remote_add( $args[1], $args[2] );
			/* translators: %s: remote name */
			WP_CLI::success( sprintf( __( 'Remote %s added.', 'branchline' ), $args[1] ) );
		} elseif ( $op === 'remove' ) {
			if ( empty( $args[1] ) ) {
				WP_CLI::error( __( 'Usage: wp branchline remote remove <name>', 'branchline' ) );
			}
			$d->remote_remove( $args[1] );
			/* translators: %s: remote name */
			WP_CLI::success( sprintf( __( 'Remote %s removed.', 'branchline' ), $args[1] ) );
		} else {
			$rows = $d->remotes();
			if ( ! $rows ) {
				WP_CLI::line( __( 'No remotes. Add one: wp branchline remote add origin <url>', 'branchline' ) );
			} else {
				WP_CLI\Utils\format_items( 'table', $rows, array( 'name', 'url' ) );
			}
		}
		$d->close();
	}

	public function fetch( $args, $assoc ): void {
		$d      = new WPBR_Dolt( WPBR_Dolt::current_branch(), false );
		$remote = $assoc['remote'] ?? 'origin';
		$d->fetch( $remote );
		$rows = $d->remote_branches();
		$d->close();
		/* translators: %s: remote name */
		WP_CLI::success( sprintf( __( 'Fetched %s.', 'branchline' ), $remote ) );
		WP_CLI\Utils\format_items(
			'table',
			array_map(
				fn( $r ) => array(
					'branch'        => $r['name'],
					'head'          => substr( $r['hash'], 0, 8 ),
					'last snapshot' => (string) $r['latest_commit_message'],
				),
				$rows
			),
			array( 'branch', 'head', 'last snapshot' )
		);
	}

	public function push( $args, $assoc ): void {
		$branch = $assoc['branch'] ?? WPBR_Dolt::current_branch();
		$d      = new WPBR_Dolt( $branch, false );
		/* translators: %s: branch name */
		$d->snapshot( sprintf( __( 'Snapshot before push (%s)', 'branchline' ), $branch ) );
		$msg = $d->push( $assoc['remote'] ?? 'origin', $branch );
		$d->close();
		/* translators: %s: branch name */
		WP_CLI::success( trim( str_replace( '\n', ' ', $msg ) ) ?: sprintf( __( 'Pushed %s.', 'branchline' ), $branch ) );
	}

	public function track( $args, $assoc ): void {
		$ref = $args[0] ?? '';
		if ( ! str_contains( $ref, '/' ) ) {
			WP_CLI::error( __( 'Give <remote>/<branch>, for example origin/feature-x.', 'branchline' ) );
		}
		[$remote, $rbranch] = explode( '/', preg_replace( '#^remotes/#', '', $ref ), 2 );
		$local              = $assoc['as'] ?? $rbranch;
		$d                  = new WPBR_Dolt( WPBR_Dolt::current_branch(), false );
		$d->fetch( $remote );
		if ( ! $d->branch_exists( $local ) ) {
			$d->branch_from( $local, "$remote/$rbranch" );
			$d->close();
			/* translators: 1: local branch name, 2: remote name, 3: remote branch name */
			WP_CLI::success( sprintf( __( 'Branch %1$s created from %2$s/%3$s.', 'branchline' ), $local, $remote, $rbranch ) );
			return;
		}
		$d->close();
		$t   = new WPBR_Dolt( $local, false );
		$msg = $t->pull_remote( $remote, $rbranch );
		$t->close();
		/* translators: 1: local branch name, 2: remote name, 3: remote branch name, 4: message from the database */
		WP_CLI::success( trim( sprintf( __( 'Branch %1$s updated from %2$s/%3$s. %4$s', 'branchline' ), $local, $remote, $rbranch, trim( str_replace( '\n', ' ', $msg ) ) ) ) );
	}

	/**
	 * Move this site's database onto a Dolt server.
	 *
	 * wp branchline import --to=mysql://user:pass@dolt-host:3306/dbname [--from=mysql://...] [--branches=stage,dev] [--batch=1000] [--replace] [--dry-run] [--yes]
	 *
	 * --from defaults to the database this site is running on. Nothing on the source is changed.
	 */
	public function import( $args, $assoc ): void {
		if ( empty( $assoc['to'] ) ) {
			WP_CLI::error( __( 'Give --to=mysql://user:password@host:port/database for the Dolt server.', 'branchline' ) );
		}
		try {
			$to   = WPBR_Import::parse_dsn( $assoc['to'] );
			$from = ! empty( $assoc['from'] ) ? WPBR_Import::parse_dsn( $assoc['from'] ) : WPBR_Import::current_site();
			$imp  = new WPBR_Import( $from, $to );
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		if ( ! $imp->target_is_dolt() ) {
			WP_CLI::error( __( 'The target is not a Dolt server.', 'branchline' ) );
		}
		$plan  = $imp->plan();
		$rows  = array_sum( array_column( $plan, 'rows' ) );
		$bytes = array_sum( array_column( $plan, 'bytes' ) );
		/* translators: 1: host, 2: port, 3: database name, 4: number of tables, 5: number of rows, 6: data size */
		WP_CLI::line( sprintf( __( 'Source: %1$s:%2$d/%3$s   %4$d tables, %5$s rows, %6$s', 'branchline' ), $from['host'], $from['port'], $from['db'], count( $plan ), number_format_i18n( $rows ), size_format( $bytes ) ) );
		/* translators: 1: host, 2: port, 3: database name */
		WP_CLI::line( sprintf( __( 'Target: %1$s:%2$d/%3$s (Dolt)', 'branchline' ), $to['host'], $to['port'], $to['db'] ) );
		if ( ! empty( $assoc['dry-run'] ) ) {
			WP_CLI\Utils\format_items(
				'table',
				array_map(
					fn( $p ) => array(
						'table' => $p['table'],
						'rows'  => $p['rows'],
						'size'  => size_format( $p['bytes'] ),
					),
					$plan
				),
				array( 'table', 'rows', 'size' )
			);
			$imp->close();
			return;
		}
		if ( empty( $assoc['yes'] ) ) {
			WP_CLI::confirm( __( 'Copy these tables to the Dolt server?', 'branchline' ) );
		}
		$t0 = microtime( true );
		try {
			$results = $imp->run( fn( $l ) => WP_CLI::line( $l ), (int) ( $assoc['batch'] ?? 1000 ), ! empty( $assoc['replace'] ), array_filter( explode( ',', (string) ( $assoc['branches'] ?? '' ) ) ) );
		} catch ( Throwable $e ) {
			$imp->close();
			WP_CLI::error( $e->getMessage() );
		}
		$imp->close();
		$bad = array_filter( $results, fn( $r ) => ! $r['ok'] );
		/* translators: 1: seconds, 2: number of tables, 3: number of rows */
		WP_CLI::line( sprintf( __( 'Done in %1$.1f s. %2$d tables, %3$s rows.', 'branchline' ), microtime( true ) - $t0, count( $results ), number_format_i18n( array_sum( array_column( $results, 'target' ) ) ) ) );
		if ( $bad ) {
			WP_CLI\Utils\format_items( 'table', $bad, array( 'table', 'source', 'target' ) );
			/* translators: %d: number of tables */
			WP_CLI::error( sprintf( _n( '%d table has a different row count on the target. Check it before switching.', '%d tables have a different row count on the target. Check them before switching.', count( $bad ), 'branchline' ), count( $bad ) ) );
		}
		WP_CLI::success( __( 'Every table matches. Now point wp-config.php at the Dolt server:', 'branchline' ) );
		WP_CLI::line( sprintf( "  define('DB_HOST', '%s:%d');", $to['host'], $to['port'] ) );
		WP_CLI::line( sprintf( "  define('DB_NAME', '%s');          // %s", $to['db'], __( 'production', 'branchline' ) ) );
		foreach ( array_filter( explode( ',', (string) ( $assoc['branches'] ?? '' ) ) ) as $b ) {
			/* translators: %s: branch name */
			WP_CLI::line( sprintf( "  define('DB_NAME', '%s/%s');   // %s", $to['db'], trim( $b ), sprintf( __( 'on the %s site', 'branchline' ), trim( $b ) ) ) );
		}
	}

	/** wp branchline log [--count=20] */
	public function log( $args, $assoc ): void {
		$d    = new WPBR_Dolt( WPBR_Dolt::current_branch() );
		$rows = array_map(
			fn( $c ) => array(
				'snapshot' => substr( $c['commit_hash'], 0, 8 ),
				'hash'     => $c['commit_hash'],
				'when'     => substr( $c['date'], 0, 16 ),
				'message'  => $c['message'],
			),
			$d->log( (int) ( $assoc['count'] ?? 20 ) )
		);
		$d->close();
		$format = $assoc['format'] ?? 'table';
		WP_CLI\Utils\format_items( $format, $rows, $format === 'table' ? array( 'snapshot', 'when', 'message' ) : array( 'hash', 'when', 'message' ) );
	}

	/** wp branchline rollback <hash> [--yes]: move this branch back to a snapshot; the current head is kept as a tag. */
	public function rollback( $args, $assoc ): void {
		$hash = $args[0] ?? '';
		if ( $hash === '' ) {
			WP_CLI::error( __( 'Give the snapshot hash to roll back to. See: wp branchline log', 'branchline' ) );
		}
		$branch = WPBR_Dolt::current_branch();
		$d      = new WPBR_Dolt( $branch, false );
		if ( ! $d->has_commit( $hash ) ) {
			$d->close();
			/* translators: 1: snapshot hash, 2: branch name */
			WP_CLI::error( sprintf( __( 'Snapshot %1$s is not in the history of %2$s.', 'branchline' ), $hash, $branch ) );
		}
		if ( empty( $assoc['yes'] ) ) {
			/* translators: 1: branch name, 2: snapshot hash */
			WP_CLI::confirm( sprintf( __( 'Roll %1$s back to %2$s? The current head is saved as a tag first.', 'branchline' ), $branch, $hash ) );
		}
		$tag  = $d->rollback_to( $hash );
		$head = $d->head_hash();
		$d->close();
		wp_cache_flush();
		/* translators: 1: branch name, 2: snapshot hash, 3: tag name */
		WP_CLI::success( sprintf( __( '%1$s is now at %2$s. Previous head saved as tag %3$s.', 'branchline' ), $branch, $head, $tag ) );
	}

	public function preview( $args, $assoc ): void {
		if ( empty( $assoc['into'] ) || empty( $assoc['from'] ) ) {
			WP_CLI::error( __( 'Give --into=<branch> and --from=<branch>.', 'branchline' ) );
		}
		$engine = new WPBR_Merge_Engine( $assoc['into'], $assoc['from'] );
		WP_CLI::line( wp_json_encode( $engine->preview(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/** Preview, print, collect decisions, confirm, apply. $applier replaces the plain engine apply for promote, update and release. */
	private function run_merge( WPBR_Merge_Engine $engine, array $assoc, ?callable $applier = null ): void {
		$p = $engine->preview();
		if ( ! empty( $p['up_to_date'] ) ) {
			/* translators: 1: target branch, 2: source branch */
			WP_CLI::success( sprintf( __( 'Nothing to merge: %1$s already contains everything on %2$s.', 'branchline' ), $p['target'], $p['source'] ) );
			return;
		}
		if ( $p['fast_forward'] ) {
			/* translators: 1: source branch, 2: target branch */
			WP_CLI::line( sprintf( __( 'Merging %1$s into %2$s (fast forward)', 'branchline' ), $p['source'], $p['target'] ) );
		} else {
			/* translators: 1: source branch, 2: target branch */
			WP_CLI::line( sprintf( __( 'Merging %1$s into %2$s', 'branchline' ), $p['source'], $p['target'] ) );
		}
		WP_CLI::line( __( 'Incoming:', 'branchline' ) );
		foreach ( $p['incoming'] as $t ) {
			WP_CLI::line( sprintf( '  %-32s +%d ~%d -%d%s', $t['label'], $t['added'], $t['modified'], $t['deleted'], ! empty( $t['approx'] ) ? '  ' . __( '(large table, totals include bookkeeping)', 'branchline' ) : '' ) );
		}
		if ( ! empty( $p['attachments'] ) ) {
			$files = $p['uploads_from'] && $p['uploads_to']
				/* translators: 1: source uploads directory, 2: target uploads directory */
				? sprintf( __( 'files copied from %1$s to %2$s', 'branchline' ), $p['uploads_from'], $p['uploads_to'] )
				: __( 'files NOT copied (set WPBR_UPLOADS_DIRS)', 'branchline' );
			/* translators: 1: number of attachments, 2: what happens to their files */
			WP_CLI::line( sprintf( __( 'Attachments: %1$d; %2$s', 'branchline' ), $p['attachments'], $files ) );
		}
		if ( $p['auto'] ) {
			WP_CLI::line( __( 'Resolved automatically:', 'branchline' ) );
			foreach ( $p['auto'] as $a ) {
				WP_CLI::line( sprintf( '  %-50s %-7s %s', $a['label'], $a['resolution'], $a['reason'] ) );
			}
		}
		if ( ! empty( $p['silent'] ) ) {
			/* translators: %d: number of bookkeeping clashes */
			WP_CLI::line( sprintf( __( 'Bookkeeping clashes resolved without review: %d', 'branchline' ), $p['silent'] ) );
		}
		$decisions = array();
		foreach ( (array) ( $assoc['resolve'] ?? array() ) as $r ) {
			[$k, $v] = array_pad( explode( '=', $r, 2 ), 2, null );
			if ( $k && $v ) {
				$decisions[ $k ] = $v;
			}
		}
		if ( $p['human'] ) {
			WP_CLI::line( __( 'Need a decision:', 'branchline' ) );
			foreach ( $p['human'] as $h ) {
				WP_CLI::line( sprintf( '  %s  [%s]  %s', $h['key'], $h['label'], $h['reason'] ) );
				if ( ! isset( $decisions[ $h['key'] ] ) ) {
					if ( ! empty( $assoc['yes'] ) ) {
						/* translators: %s: conflict key */
						WP_CLI::error( sprintf( __( 'Unresolved conflict and --yes given. Pass --resolve=%s=ours|theirs|merged.', 'branchline' ), $h['key'] ) );
					}
					$decisions[ $h['key'] ] = $this->ask( $h );
				}
			}
		}
		if ( empty( $assoc['yes'] ) ) {
			WP_CLI::confirm( __( 'Apply this merge?', 'branchline' ) );
		}
		$pinned = array(
			'target_hash' => $p['target_hash'],
			'source_hash' => $p['source_hash'],
		);
		$r      = $applier ? $applier( $decisions, $pinned, $assoc['message'] ?? '' ) : $engine->apply( $decisions, $pinned, $assoc['message'] ?? '' );
		$files  = WPBR_Files::describe( $r['files'] ?? array( 'attachments' => 0 ) );
		$extra  = '';
		if ( ! empty( $r['code_hash'] ) ) {
			/* translators: %s: short git commit hash */
			$extra .= ' ' . sprintf( __( 'Code merged at %s.', 'branchline' ), substr( $r['code_hash'], 0, 8 ) );
		}
		if ( ! empty( $r['code_tag'] ) ) {
			$extra .= ' ' . ( isset( $r['deploy'] ) && $r['deploy'] !== null
				/* translators: %s: git tag */
				? sprintf( __( 'Code %s tagged and deployed.', 'branchline' ), $r['code_tag'] )
				/* translators: %s: git tag */
				: sprintf( __( 'Code %s tagged; deploy it with your usual command.', 'branchline' ), $r['code_tag'] ) );
		}
		/* translators: 1: snapshot hash, 2: target branch */
		WP_CLI::success( trim( sprintf( __( 'Merged. New head %1$s on %2$s.', 'branchline' ), $r['hash'], $p['target'] ) . ( $files ? ' ' . $files : '' ) . $extra ) );
	}

	private function ask( array $h ): string {
		$opts = array( 'ours', 'theirs' );
		if ( ! empty( $h['merged'] ) ) {
			$opts[] = 'merged';
		}
		/* translators: %s: the choices, separated by slashes */
		fwrite( STDOUT, sprintf( __( '    choose %s: ', 'branchline' ), implode( '/', $opts ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- interactive terminal prompt, not a file.
		$in = trim( (string) fgets( STDIN ) );
		return in_array( $in, $opts, true ) ? $in : $this->ask( $h );
	}
}
