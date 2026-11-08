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
		$d->close();
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

	private function run_merge( WPBR_Merge_Engine $engine, array $assoc ): void {
		$p = $engine->preview();
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
		$r     = $engine->apply(
			$decisions,
			array(
				'target_hash' => $p['target_hash'],
				'source_hash' => $p['source_hash'],
			),
			$assoc['message'] ?? ''
		);
		$files = WPBR_Files::describe( $r['files'] ?? array( 'attachments' => 0 ) );
		/* translators: 1: snapshot hash, 2: target branch */
		WP_CLI::success( trim( sprintf( __( 'Merged. New head %1$s on %2$s.', 'branchline' ), $r['hash'], $p['target'] ) . ( $files ? ' ' . $files : '' ) ) );
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
