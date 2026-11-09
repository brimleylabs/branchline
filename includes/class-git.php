<?php
/**
 * The code side of a workspace: a git branch and a worktree next to the data branch.
 *
 * Only active when WPBR_CODE_ROOT points at a git repository, normally the
 * wp-content directory of the staging site. Everything here shells out to git on
 * the staging server with escaped arguments; nothing here ever runs on production.
 */
defined( 'ABSPATH' ) || exit;

class WPBR_Git {

	/** Is code branching configured and usable? */
	public static function available(): bool {
		static $ok = null;
		if ( $ok !== null ) {
			return $ok;
		}
		$ok = false;
		if ( ! defined( 'WPBR_CODE_ROOT' ) || ! is_dir( WPBR_CODE_ROOT ) ) {
			return false;
		}
		try {
			$ok = self::out( array( 'rev-parse', '--is-inside-work-tree' ) ) === 'true';
		} catch ( Throwable $e ) {
			$ok = false;
		}
		return $ok;
	}

	public static function root(): string {
		return rtrim( str_replace( '\\', '/', (string) WPBR_CODE_ROOT ), '/' );
	}

	/** The branch that holds production code. */
	public static function production_branch(): string {
		return defined( 'WPBR_CODE_BRANCH_PRODUCTION' ) ? WPBR_CODE_BRANCH_PRODUCTION : 'main';
	}

	/** The branch checked out in the staging repository. */
	public static function stage_branch(): string {
		return defined( 'WPBR_CODE_BRANCH_STAGE' ) ? WPBR_CODE_BRANCH_STAGE : self::current_branch();
	}

	/** Run git and return [exit code, stdout, stderr]. */
	public static function run( array $args, ?string $cwd = null ): array {
		$bin = defined( 'WPBR_GIT_BIN' ) ? WPBR_GIT_BIN : 'git';
		$cmd = escapeshellcmd( $bin );
		foreach ( $args as $a ) {
			$cmd .= ' ' . escapeshellarg( (string) $a );
		}
		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		// Git has no PHP API; this runs the git binary with every argument escaped, only when WPBR_CODE_ROOT is set by the site owner.
		$p = proc_open( $cmd, $spec, $pipes, $cwd ?? self::root(), null, array( 'bypass_shell' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, Generic.PHP.ForbiddenFunctions.Found
		if ( ! is_resource( $p ) ) {
			throw new RuntimeException( 'Could not start git.' );
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pipes of a process, not files.
		fclose( $pipes[0] );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		// phpcs:enable
		$code = proc_close( $p );
		return array( $code, trim( $out ), trim( $err ) );
	}

	/** Run git and return stdout, or throw with stderr. */
	public static function out( array $args, ?string $cwd = null ): string {
		[$code, $out, $err] = self::run( $args, $cwd );
		if ( $code !== 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where displayed.
			throw new RuntimeException( 'git ' . implode( ' ', array_map( 'strval', $args ) ) . ' failed: ' . ( $err ?: $out ) );
		}
		return $out;
	}

	public static function current_branch( ?string $cwd = null ): string {
		return self::out( array( 'rev-parse', '--abbrev-ref', 'HEAD' ), $cwd );
	}

	public static function head( string $ref = 'HEAD', ?string $cwd = null ): string {
		return self::out( array( 'rev-parse', $ref ), $cwd );
	}

	public static function branch_exists( string $branch ): bool {
		[$code] = self::run( array( 'rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch ) );
		return $code === 0;
	}

	public static function tag_exists( string $tag ): bool {
		[$code] = self::run( array( 'rev-parse', '--verify', '--quiet', 'refs/tags/' . $tag ) );
		return $code === 0;
	}

	/** No tracked file modified. Untracked files are allowed. */
	public static function is_clean( ?string $cwd = null ): bool {
		return self::out( array( 'status', '--porcelain', '--untracked-files=no' ), $cwd ) === '';
	}

	public static function is_ancestor( string $maybe_ancestor, string $of ): bool {
		[$code] = self::run( array( 'merge-base', '--is-ancestor', $maybe_ancestor, $of ) );
		return $code === 0;
	}

	/** Commits reachable from $to and not from $from. */
	public static function count( string $from, string $to ): int {
		return (int) self::out( array( 'rev-list', '--count', $from . '..' . $to ) );
	}

	public static function worktree_add( string $path, string $branch, string $from ): void {
		if ( self::branch_exists( $branch ) ) {
			self::out( array( 'worktree', 'add', $path, $branch ) );
		} else {
			self::out( array( 'worktree', 'add', '-b', $branch, $path, $from ) );
		}
	}

	public static function worktree_remove( string $path, string $branch ): void {
		if ( is_dir( $path ) ) {
			self::unlink_shared( $path );
			self::out( array( 'worktree', 'remove', '--force', $path ) );
		}
		self::run( array( 'worktree', 'prune' ) );
		if ( self::branch_exists( $branch ) ) {
			self::out( array( 'branch', '-D', $branch ) );
		}
	}

	/**
	 * Does merging $branch into the checkout at $cwd succeed? Runs the merge without
	 * committing and aborts it, so the checkout is untouched either way.
	 */
	public static function can_merge( string $branch, ?string $cwd = null ): array {
		[$code, , $err] = self::run( array( 'merge', '--no-commit', '--no-ff', $branch ), $cwd );
		self::run( array( 'merge', '--abort' ), $cwd );
		return array( $code === 0, $err );
	}

	public static function merge( string $branch, string $message, ?string $cwd = null ): string {
		self::out( array( 'merge', '--no-ff', '-m', $message, $branch ), $cwd );
		return self::head( 'HEAD', $cwd );
	}

	public static function tag( string $name, string $ref, string $message ): void {
		self::out( array( 'tag', '-a', '-m', $message, $name, $ref ) );
	}

	/** Tags matching a prefix, newest first: [name, hash, date]. */
	public static function tags( string $prefix ): array {
		$out = self::out( array( 'for-each-ref', '--sort=-creatordate', '--format=%(refname:short)%09%(objectname:short)%09%(creatordate:iso-strict)', 'refs/tags/' . $prefix . '*' ) );
		if ( $out === '' ) {
			return array();
		}
		return array_map(
			function ( $line ) {
				$p = explode( "\t", $line );
				return array(
					'name' => $p[0],
					'hash' => $p[1] ?? '',
					'date' => $p[2] ?? '',
				);
			},
			explode( "\n", $out )
		);
	}

	/**
	 * Paths under the code root that are not in git but every checkout needs:
	 * uploads always, plus anything in WPBR_WORKSPACE_LINKS (composer-managed
	 * plugins, a vendor directory). Each becomes a link in the worktree.
	 */
	public static function shared_paths(): array {
		$paths = defined( 'WPBR_WORKSPACE_LINKS' ) && is_array( WPBR_WORKSPACE_LINKS ) ? WPBR_WORKSPACE_LINKS : array();
		$paths = array_merge( array( 'uploads' ), $paths );
		$out   = array();
		foreach ( $paths as $p ) {
			$p = trim( str_replace( '\\', '/', (string) $p ), '/' );
			if ( $p !== '' && ! str_contains( $p, '..' ) ) {
				$out[ $p ] = $p;
			}
		}
		return array_values( $out );
	}

	/** Link the shared paths into a worktree. A directory junction on Windows, a symlink elsewhere. */
	public static function link_shared( string $worktree ): void {
		foreach ( self::shared_paths() as $rel ) {
			$real = self::root() . '/' . $rel;
			$link = rtrim( $worktree, '/' ) . '/' . $rel;
			if ( ! file_exists( $real ) || file_exists( $link ) ) {
				continue;
			}
			if ( ! is_dir( dirname( $link ) ) ) {
				wp_mkdir_p( dirname( $link ) );
			}
			if ( DIRECTORY_SEPARATOR === '\\' ) {
				$flag = is_dir( $real ) ? '/J' : '/H';
				$cmd  = 'cmd /c mklink ' . $flag . ' ' . escapeshellarg( str_replace( '/', '\\', $link ) ) . ' ' . escapeshellarg( str_replace( '/', '\\', $real ) );
				exec( $cmd ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- PHP cannot create a Windows directory junction any other way; both paths are escaped.
			} else {
				symlink( $real, $link ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_symlink -- WP_Filesystem has no credentials here and cannot create links.
			}
		}
	}

	/** Remove the links before the worktree is removed, so the targets are never touched. */
	private static function unlink_shared( string $worktree ): void {
		foreach ( self::shared_paths() as $rel ) {
			$link = rtrim( $worktree, '/' ) . '/' . $rel;
			if ( is_link( $link ) ) {
				unlink( $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing a link the plugin made.
			} elseif ( DIRECTORY_SEPARATOR === '\\' && is_dir( $link ) && ! is_dir( self::root() . '/' . $rel . '/.git' ) ) {
				// A junction reads as a directory; rmdir removes the junction and leaves the target alone.
				@rmdir( $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- junction, not a directory of files; a real directory simply stays.
			}
		}
	}
}
