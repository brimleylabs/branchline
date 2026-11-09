<?php
/**
 * A release moves stage to production as one named, coupled step: code first,
 * then data, both tagged with the same name, so a rollback reverts both.
 *
 * Data is the existing publish merge. Code is whatever the site's deploy command
 * does; Branchline tags the commit and runs the command with the tag in the
 * environment:
 *
 *   define('WPBR_RELEASE_COMMAND', '/usr/local/bin/deploy-prod.sh');
 *
 * The command receives WPBR_RELEASE (the name) and WPBR_RELEASE_REF (the git tag
 * to deploy). On rollback it is run again with the previous release's tag.
 */
defined( 'ABSPATH' ) || exit;

class WPBR_Release {

	public const TAG    = 'release-';
	public const BEFORE = 'pre-release-';

	public static function valid_name( string $name ): bool {
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,60}$/', $name );
	}

	/** Today's date plus a counter, e.g. 2026-11-08-2. */
	public static function default_name(): string {
		$day = gmdate( 'Y-m-d' );
		$n   = 1;
		try {
			foreach ( self::all() as $r ) {
				if ( str_starts_with( $r['name'], $day . '-' ) ) {
					$n = max( $n, (int) substr( $r['name'], strlen( $day ) + 1 ) + 1 );
				}
			}
		} catch ( Throwable $e ) {
			$n = 1;
		}
		return $day . '-' . $n;
	}

	public static function has_deploy(): bool {
		return defined( 'WPBR_RELEASE_COMMAND' ) && WPBR_RELEASE_COMMAND !== '';
	}

	/** Releases so far, newest first, from the tags on production. */
	public static function all(): array {
		$d = new WPBR_Dolt( WPBR_PRODUCTION_BRANCH );
		try {
			$tags = $d->tags();
		} finally {
			$d->close();
		}
		$out = array();
		foreach ( $tags as $t ) {
			if ( str_starts_with( $t['tag_name'], self::TAG ) ) {
				$out[] = array(
					'name'    => substr( $t['tag_name'], strlen( self::TAG ) ),
					'hash'    => $t['tag_hash'],
					'date'    => $t['date'],
					'message' => (string) $t['message'],
				);
			}
		}
		return $out;
	}

	/**
	 * Release stage to production. $decisions and $pinned come from a reviewed publish preview.
	 * Returns the merge result plus 'code_tag' and 'deploy' (command output) when code is involved.
	 */
	public static function run( string $name, array $decisions, array $pinned, bool $deploy = true, ?callable $log = null ): array {
		if ( ! self::valid_name( $name ) ) {
			throw new RuntimeException( esc_html__( 'A release name is letters, digits, dots, dashes and underscores.', 'branchline' ) );
		}
		$log   = $log ?? static function ( string $s ): void {};
		$stage = WPBR_Workspaces::stage_branch();
		$prod  = new WPBR_Dolt( WPBR_PRODUCTION_BRANCH, false );
		try {
			foreach ( $prod->tags() as $t ) {
				if ( $t['tag_name'] === self::TAG . $name ) {
					/* translators: %s: release name */
					throw new RuntimeException( sprintf( esc_html__( 'Release %s already exists.', 'branchline' ), $name ) );
				}
			}
			// Where production was, so a rollback has a target.
			$prod->snapshot( sprintf( 'Snapshot before release %s', $name ) );
			/* translators: %s: release name */
			$prod->tag( self::BEFORE . $name, $prod->head_hash(), sprintf( __( 'Production before release %s', 'branchline' ), $name ) );
		} finally {
			$prod->close();
		}
		$out = array(
			'code_tag' => null,
			'deploy'   => null,
		);
		if ( WPBR_Git::available() ) {
			if ( WPBR_Git::tag_exists( self::TAG . $name ) ) {
				/* translators: %s: tag name */
				throw new RuntimeException( sprintf( esc_html__( 'Git tag %s already exists.', 'branchline' ), esc_html( self::TAG . $name ) ) );
			}
			$ref = WPBR_Git::stage_branch();
			WPBR_Git::tag( self::TAG . $name, $ref, sprintf( 'Release %s', $name ) );
			$out['code_tag'] = self::TAG . $name;
			$log( sprintf( 'Tagged code %s at %s', self::TAG . $name, substr( WPBR_Git::head( $ref ), 0, 8 ) ) );
			if ( $deploy && self::has_deploy() ) {
				// Code first: new code can read old data for the seconds in between; old code cannot read new data.
				$out['deploy'] = self::deploy( $name, self::TAG . $name );
				$log( 'Deploy: ' . trim( $out['deploy'] ) );
			}
		}
		$engine = new WPBR_Merge_Engine( WPBR_PRODUCTION_BRANCH, $stage );
		$r      = $engine->apply( $decisions, $pinned, sprintf( 'Release %s', $name ) );
		$prod   = new WPBR_Dolt( WPBR_PRODUCTION_BRANCH, false );
		try {
			/* translators: %s: release name */
			$prod->tag( self::TAG . $name, $r['hash'], sprintf( __( 'Release %s', 'branchline' ), $name ) );
		} finally {
			$prod->close();
		}
		$log( sprintf( 'Tagged data %s at %s', self::TAG . $name, substr( $r['hash'], 0, 8 ) ) );
		return $r + $out;
	}

	/**
	 * Undo a release: production data back to where it was before, and the previous
	 * release's code deployed again. The head you leave is saved as a tag, as always.
	 */
	public static function rollback( string $name, bool $deploy = true ): array {
		if ( ! self::valid_name( $name ) ) {
			throw new RuntimeException( esc_html__( 'Unknown release.', 'branchline' ) );
		}
		$prod = new WPBR_Dolt( WPBR_PRODUCTION_BRANCH, false );
		try {
			$before = null;
			foreach ( $prod->tags() as $t ) {
				if ( $t['tag_name'] === self::BEFORE . $name ) {
					$before = $t['tag_hash'];
				}
			}
			if ( ! $before ) {
				/* translators: %s: release name */
				throw new RuntimeException( sprintf( esc_html__( 'No saved point before release %s.', 'branchline' ), $name ) );
			}
			$saved = $prod->rollback_to( $before );
			$head  = $prod->head_hash();
		} finally {
			$prod->close();
		}
		wp_cache_flush();
		$out = array(
			'hash'     => $head,
			'saved_as' => $saved,
			'code_ref' => null,
			'deploy'   => null,
		);
		if ( $deploy && WPBR_Git::available() ) {
			$previous = null;
			$tags     = WPBR_Git::tags( self::TAG );
			foreach ( $tags as $i => $t ) {
				if ( $t['name'] === self::TAG . $name ) {
					$previous = $tags[ $i + 1 ]['name'] ?? null;
				}
			}
			if ( $previous && self::has_deploy() ) {
				$out['code_ref'] = $previous;
				$out['deploy']   = self::deploy( $name, $previous );
			} else {
				$out['code_ref'] = $previous;
			}
		}
		return $out;
	}

	/** Run the site's deploy command with the release in its environment. Returns its output. */
	public static function deploy( string $name, string $ref ): string {
		if ( ! self::has_deploy() ) {
			return '';
		}
		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$env  = array_merge(
			self::environment(),
			array(
				'WPBR_RELEASE'     => $name,
				'WPBR_RELEASE_REF' => $ref,
			)
		);
		// The deploy command is set by the site owner in wp-config.php, never from a request. Deploying code has no PHP API.
		$p = proc_open( WPBR_RELEASE_COMMAND, $spec, $pipes, WPBR_Git::available() ? WPBR_Git::root() : ABSPATH, $env ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, Generic.PHP.ForbiddenFunctions.Found
		if ( ! is_resource( $p ) ) {
			throw new RuntimeException( esc_html__( 'Could not start the deploy command.', 'branchline' ) );
		}
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pipes of a process, not files.
		fclose( $pipes[0] );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		// phpcs:enable
		$code = proc_close( $p );
		if ( $code !== 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where displayed.
			throw new RuntimeException( sprintf( 'Deploy command failed (exit %d): %s', $code, trim( $err ?: $out ) ) );
		}
		return $out . ( $err ? "\n" . $err : '' );
	}

	/** The current environment, so the deploy command finds its PATH and HOME. */
	private static function environment(): array {
		$env = array();
		foreach ( array( 'PATH', 'Path', 'HOME', 'USERPROFILE', 'SYSTEMROOT', 'SystemRoot', 'TEMP', 'TMP', 'COMSPEC', 'ComSpec', 'LANG', 'SSH_AUTH_SOCK' ) as $k ) {
			$v = getenv( $k );
			if ( is_string( $v ) && $v !== '' ) {
				$env[ $k ] = $v;
			}
		}
		return $env;
	}
}
