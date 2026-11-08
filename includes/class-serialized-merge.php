<?php
/**
 * Three-way merge for the values WordPress stores as blobs: serialized PHP
 * arrays and JSON. The database sees one changed cell; we see which keys
 * each side changed and combine them. If both sides changed the same leaf
 * differently, the merge fails and the caller falls back to a policy.
 */
defined( 'ABSPATH' ) || exit;

class WPBR_Serialized_Merge {

	public static function try_merge( ?string $base, string $ours, string $theirs ): ?string {
		// Serialized PHP
		if ( is_serialized( $ours ) && is_serialized( $theirs ) && ( $base === null || is_serialized( $base ) ) ) {
			$b = $base === null ? null : @unserialize( $base, array( 'allowed_classes' => false ) );
			$o = @unserialize( $ours, array( 'allowed_classes' => false ) );
			$t = @unserialize( $theirs, array( 'allowed_classes' => false ) );
			if ( $o === false || $t === false ) {
				return null;
			}
			try {
				return serialize( self::merge3( $b, $o, $t ) );
			} catch ( RuntimeException $e ) {
				return null;
			}
		}
		// JSON
		$o = json_decode( $ours, true );
		$t = json_decode( $theirs, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $o ) && is_array( $t ) ) {
			$b = $base === null ? null : json_decode( $base, true );
			if ( $base !== null && ! is_array( $b ) ) {
				return null;
			}
			try {
				return wp_json_encode( self::merge3( $b, $o, $t ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			} catch ( RuntimeException $e ) {
				return null;
			}
		}
		return null;
	}

	public static function merge3( $base, $ours, $theirs ) {
		if ( $ours === $theirs ) {
			return $ours;
		}
		if ( ! is_array( $ours ) || ! is_array( $theirs ) || ( $base !== null && ! is_array( $base ) ) ) {
			if ( $ours === $base ) {
				return $theirs;
			}
			if ( $theirs === $base ) {
				return $ours;
			}
			throw new RuntimeException( 'both sides changed a leaf' );
		}
		$base = $base ?? array();
		$keys = array_values( array_unique( array_merge( array_keys( $base ), array_keys( $ours ), array_keys( $theirs ) ) ) );
		$out  = array();
		foreach ( $keys as $k ) {
			$in_b = array_key_exists( $k, $base );
			$in_o = array_key_exists( $k, $ours );
			$in_t = array_key_exists( $k, $theirs );
			if ( $in_o && $in_t ) {
				$out[ $k ] = self::merge3( $in_b ? $base[ $k ] : null, $ours[ $k ], $theirs[ $k ] );
			} elseif ( $in_o && ! $in_t ) {
				if ( ! $in_b ) {
					// We added it.
					$out[ $k ] = $ours[ $k ];
				} elseif ( $ours[ $k ] !== $base[ $k ] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the merge engine, never shown raw.
					throw new RuntimeException( "they removed '$k', we changed it" );
				}
				// Otherwise they removed it and we did not touch it: drop.
			} elseif ( ! $in_o && $in_t ) {
				if ( ! $in_b ) {
					// They added it.
					$out[ $k ] = $theirs[ $k ];
				} elseif ( $theirs[ $k ] !== $base[ $k ] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- caught by the merge engine, never shown raw.
					throw new RuntimeException( "we removed '$k', they changed it" );
				}
				// Otherwise we removed it and they did not touch it: drop.
			}
		}
		$all_lists = self::is_list( $base ) && self::is_list( $ours ) && self::is_list( $theirs );
		return $all_lists ? array_values( $out ) : $out;
	}

	private static function is_list( array $a ): bool {
		return $a === array() || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}
}
