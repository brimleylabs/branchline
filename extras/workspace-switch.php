<?php
/**
 * Workspace switch for the staging environment.
 *
 * Loaded from wp-config.php before DB_NAME is defined. It decides which branch
 * this request runs on: the staging branch, or a workspace chosen by a signed
 * cookie, a signed link, a hostname, or (on the command line) the WPBR_WORKSPACE
 * environment variable.
 *
 *   define('WPBR_WORKSPACE_SECRET', 'a long random string');
 *   require_once __DIR__ . '/wp-content/plugins/branchline/extras/workspace-switch.php';
 *   define('DB_NAME', wpbr_workspace_db_name('wp', 'stage'));
 *
 * Optional, for workspaces that branch code as well as data:
 *
 *   define('WPBR_CODE_ROOT', __DIR__ . '/wp-content');   // a git repository
 *
 * Never load this on production. Production runs one branch and nothing else.
 *
 * This file has no WordPress dependencies; it runs before WordPress loads.
 * wp-load.php and WP-CLI define ABSPATH before they include wp-config.php, so
 * the guard below only stops a direct request for this file.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wpbr_ws_valid_name' ) ) {

	/** Workspace names: lowercase letters, digits and dashes, 1 to 40 characters. */
	function wpbr_ws_valid_name( string $name ): bool {
		return (bool) preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $name );
	}

	/** The Dolt branch a workspace lives on. */
	function wpbr_ws_branch( string $name ): string {
		return 'ws/' . $name;
	}

	/** Signed token carrying a workspace name and an expiry time. */
	function wpbr_ws_sign( string $name, int $expires, string $secret ): string {
		return $name . '.' . $expires . '.' . hash_hmac( 'sha256', $name . '.' . $expires, $secret );
	}

	/** The workspace name a token stands for, or null when the token is invalid or expired. */
	function wpbr_ws_verify( string $token, string $secret, ?int $now = null ): ?string {
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 3 || $secret === '' ) {
			return null;
		}
		[$name, $expires, $sig] = $parts;
		if ( ! wpbr_ws_valid_name( $name ) || ! ctype_digit( $expires ) ) {
			return null;
		}
		if ( (int) $expires < ( $now ?? time() ) ) {
			return null;
		}
		$expected = hash_hmac( 'sha256', $name . '.' . $expires, $secret );
		return hash_equals( $expected, $sig ) ? $name : null;
	}

	function wpbr_ws_cookie_name(): string {
		return 'wpbr_workspace';
	}

	/** Where a workspace's code checkout lives, or null when code is not branched. */
	function wpbr_ws_code_dir( string $name ): ?string {
		if ( ! defined( 'WPBR_CODE_ROOT' ) || ! wpbr_ws_valid_name( $name ) ) {
			return null;
		}
		$base = defined( 'WPBR_WORKSPACE_CODE_DIR' ) ? WPBR_WORKSPACE_CODE_DIR : dirname( rtrim( str_replace( '\\', '/', WPBR_CODE_ROOT ), '/' ) ) . '/wpbr-workspaces';
		return rtrim( str_replace( '\\', '/', $base ), '/' ) . '/' . $name;
	}

	/** The URL that serves a workspace's code checkout. */
	function wpbr_ws_code_url( string $name ): string {
		$base = defined( 'WPBR_WORKSPACE_CODE_URL' ) ? WPBR_WORKSPACE_CODE_URL : null;
		if ( $base === null ) {
			if ( defined( 'WP_HOME' ) ) {
				$home = WP_HOME;
			} else {
				$https = ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off';
				$home  = ( $https ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? 'localhost' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- host of this request, before WordPress loads; used for a URL on the staging host only.
			}
			$base = rtrim( $home, '/' ) . '/wpbr-workspaces';
		}
		return rtrim( $base, '/' ) . '/' . $name;
	}

	/**
	 * Which workspace this request asked for, or null for the staging branch.
	 * Order: the command line environment, a signed link, the signed cookie, the hostname.
	 */
	function wpbr_ws_requested(): ?string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput -- runs before WordPress loads; every value is verified against an HMAC or an allowlist below.
		if ( PHP_SAPI === 'cli' ) {
			$env = getenv( 'WPBR_WORKSPACE' );
			return is_string( $env ) && wpbr_ws_valid_name( $env ) ? $env : null;
		}
		if ( isset( $_GET['wpbr_leave'] ) ) {
			wpbr_ws_set_cookie( '', time() - 3600 );
			return null;
		}
		$secret = defined( 'WPBR_WORKSPACE_SECRET' ) ? (string) WPBR_WORKSPACE_SECRET : '';
		if ( $secret !== '' && isset( $_GET['wpbr_workspace'] ) ) {
			$token = (string) $_GET['wpbr_workspace'];
			$name  = wpbr_ws_verify( $token, $secret );
			if ( $name !== null ) {
				wpbr_ws_set_cookie( $token, (int) explode( '.', $token )[1] );
				return $name;
			}
		}
		if ( $secret !== '' && isset( $_COOKIE[ wpbr_ws_cookie_name() ] ) ) {
			$name = wpbr_ws_verify( (string) $_COOKIE[ wpbr_ws_cookie_name() ], $secret );
			if ( $name !== null ) {
				return $name;
			}
		}
		if ( defined( 'WPBR_WORKSPACE_HOSTS' ) && is_array( WPBR_WORKSPACE_HOSTS ) ) {
			$host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) );
			$name = WPBR_WORKSPACE_HOSTS[ $host ] ?? null;
			if ( is_string( $name ) && wpbr_ws_valid_name( $name ) ) {
				return $name;
			}
		}
		// phpcs:enable
		return null;
	}

	function wpbr_ws_set_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}
		$secure = ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off';
		setcookie(
			wpbr_ws_cookie_name(),
			$value,
			array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ wpbr_ws_cookie_name() ] = $value;
	}

	/**
	 * The DB_NAME for this request: "database/stage" or "database/ws/<name>".
	 * Also defines WPBR_WORKSPACE and, for a workspace with its own code, the
	 * content directory constants so the request runs that checkout.
	 */
	function wpbr_workspace_db_name( string $database, string $stage ): string {
		if ( ! defined( 'WPBR_STAGE_BRANCH' ) ) {
			define( 'WPBR_STAGE_BRANCH', $stage );
		}
		if ( ! defined( 'WPBR_WORKSPACES_ON' ) ) {
			define( 'WPBR_WORKSPACES_ON', true );
		}
		$name = wpbr_ws_requested();
		if ( $name === null ) {
			return $database . '/' . $stage;
		}
		define( 'WPBR_WORKSPACE', $name );
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own cache constants, set per request.
		if ( ! defined( 'WP_CACHE_KEY_SALT' ) ) {
			// One object cache may serve every workspace; keys must not collide.
			define( 'WP_CACHE_KEY_SALT', 'wpbr-ws-' . $name . '-' );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		// phpcs:enable
		$code = wpbr_ws_code_dir( $name );
		if ( $code !== null && is_dir( $code ) && ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', $code );
			if ( ! defined( 'WP_CONTENT_URL' ) ) {
				define( 'WP_CONTENT_URL', wpbr_ws_code_url( $name ) );
			}
		}
		return $database . '/' . wpbr_ws_branch( $name );
	}
}
