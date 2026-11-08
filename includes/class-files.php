<?php
/**
 * Attachment files follow their database rows.
 *
 * The database merge moves wp_posts rows for attachments between branches, but
 * the files live in each environment's uploads directory. After a merge this
 * copies the files for every attachment that arrived from the source branch.
 *
 * Where each branch keeps its uploads is configured once:
 *
 *   define('WPBR_UPLOADS_DIRS', [
 *       'main'  => '/var/www/prod/wp-content/uploads',
 *       'stage' => '/var/www/stage/wp-content/uploads',
 *   ]);
 *
 * The branch this site runs on defaults to its own uploads directory. Hosts
 * where environments live on different machines or in object storage hook
 * `wpbr_copy_attachment_file` and do the copy their own way.
 */
defined( 'ABSPATH' ) || exit;

class WPBR_Files {

	/** Uploads base directory for a branch, or null when unknown. */
	public static function uploads_dir( string $branch ): ?string {
		$map = defined( 'WPBR_UPLOADS_DIRS' ) && is_array( WPBR_UPLOADS_DIRS ) ? WPBR_UPLOADS_DIRS : array();
		$dir = $map[ $branch ] ?? null;
		if ( ! $dir && $branch === WPBR_Dolt::current_branch() ) {
			$dir = wp_get_upload_dir()['basedir'] ?? null;
		}
		$dir = apply_filters( 'wpbr_uploads_dir', $dir, $branch );
		return $dir ? rtrim( str_replace( '\\', '/', $dir ), '/' ) : null;
	}

	/** How many attachments were added or changed on the source since the merge base. */
	public static function incoming_count( WPBR_Dolt $target, string $base, string $source_hash ): int {
		$prefix = $GLOBALS['wpdb']->prefix;
		return count( $target->diff_rows( $base, $source_hash, $prefix . 'posts', 100000, "to_post_type = 'attachment' AND diff_type IN ('added', 'modified')" ) );
	}

	/**
	 * Relative upload paths (original plus every generated size) for attachments
	 * that were added or changed on the source branch since the merge base.
	 * Read from the target branch after the merge so paths reflect the merged state.
	 */
	public static function incoming_paths( WPBR_Dolt $target, string $base, string $source_hash ): array {
		$prefix = $GLOBALS['wpdb']->prefix;
		$rows   = $target->diff_rows( $base, $source_hash, $prefix . 'posts', 100000, "to_post_type = 'attachment' AND diff_type IN ('added', 'modified')" );
		$paths  = array();
		foreach ( $rows as $r ) {
			$id   = (int) $r['to_ID'];
			$file = (string) $target->var( 'SELECT meta_value FROM ' . $target->ident( $prefix . 'postmeta' ) . " WHERE post_id = ? AND meta_key = '_wp_attached_file' LIMIT 1", array( $id ) );
			if ( $file === '' ) {
				continue;
			}
			$paths[ $id ][] = $file;
			$dir            = dirname( $file );
			$dir            = $dir === '.' ? '' : $dir . '/';
			$meta           = $target->var( 'SELECT meta_value FROM ' . $target->ident( $prefix . 'postmeta' ) . " WHERE post_id = ? AND meta_key = '_wp_attachment_metadata' LIMIT 1", array( $id ) );
			$m              = is_string( $meta ) && is_serialized( $meta ) ? @unserialize( $meta, array( 'allowed_classes' => false ) ) : null;
			if ( is_array( $m ) ) {
				foreach ( ( $m['sizes'] ?? array() ) as $s ) {
					if ( ! empty( $s['file'] ) ) {
						$paths[ $id ][] = $dir . $s['file'];
					}
				}
				if ( ! empty( $m['original_image'] ) ) {
					$paths[ $id ][] = $dir . $m['original_image'];
				}
			}
			$paths[ $id ] = array_values( array_unique( $paths[ $id ] ) );
		}
		return $paths;
	}

	/** Copy the files for the given attachments from one branch's uploads to another's. */
	public static function copy( array $paths_by_id, string $source_branch, string $target_branch ): array {
		$out = array(
			'attachments' => count( $paths_by_id ),
			'copied'      => 0,
			'unchanged'   => 0,
			'missing'     => array(),
			'skipped'     => null,
		);
		if ( ! $paths_by_id ) {
			return $out;
		}
		$src_dir = self::uploads_dir( $source_branch );
		$dst_dir = self::uploads_dir( $target_branch );
		if ( ! $src_dir || ! $dst_dir ) {
			$out['skipped'] = sprintf( 'Uploads directory unknown for %s. Set WPBR_UPLOADS_DIRS to copy attachment files.', ! $src_dir ? $source_branch : $target_branch );
			return $out;
		}
		if ( $src_dir === $dst_dir ) {
			$out['skipped'] = 'Both branches share one uploads directory; nothing to copy.';
			return $out;
		}
		foreach ( $paths_by_id as $id => $rels ) {
			foreach ( $rels as $rel ) {
				$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
				if ( $rel === '' || str_contains( $rel, '..' ) ) {
					continue;
				}
				$src = $src_dir . '/' . $rel;
				$dst = $dst_dir . '/' . $rel;
				// A host can take over the copy (rsync, S3, ...) and return true/false.
				$handled = apply_filters( 'wpbr_copy_attachment_file', null, $src, $dst, $rel, $source_branch, $target_branch, (int) $id );
				if ( $handled !== null ) {
					if ( $handled ) {
						++$out['copied'];
					} else {
						$out['missing'][] = $rel;
					}
					continue;
				}
				if ( ! is_file( $src ) ) {
					$out['missing'][] = $rel;
					continue; }
				if ( is_file( $dst ) && filesize( $dst ) === filesize( $src ) && filemtime( $dst ) >= filemtime( $src ) ) {
					++$out['unchanged'];
					continue; }
				if ( ! is_dir( dirname( $dst ) ) ) {
					wp_mkdir_p( dirname( $dst ) );
				}
				if ( @copy( $src, $dst ) ) {
					++$out['copied'];
				} else {
					$out['missing'][] = $rel; }
			}
		}
		return $out;
	}

	/**
	 * Relative upload paths of attachments the merge removed from the target
	 * (deleted on the source, and the rules let the deletion stand). Read from
	 * the merge base, since the rows are gone from the merged state.
	 */
	public static function removed_paths( WPBR_Dolt $target, string $base, string $source_hash ): array {
		$prefix = $GLOBALS['wpdb']->prefix;
		$rows   = $target->diff_rows( $base, $source_hash, $prefix . 'posts', 100000, "from_post_type = 'attachment' AND diff_type = 'removed'" );
		$paths  = array();
		foreach ( $rows as $r ) {
			$id = (int) $r['from_ID'];
			// Still there after the merge? Then a rule kept it; leave its files alone.
			if ( $target->var( 'SELECT 1 FROM ' . $target->ident( $prefix . 'posts' ) . ' WHERE ID = ?', array( $id ) ) ) {
				continue;
			}
			$lit  = "'" . addslashes( $base ) . "'";
			$meta = $target->q( 'SELECT meta_key, meta_value FROM ' . $target->ident( $prefix . 'postmeta' ) . " AS OF $lit WHERE post_id = ? AND meta_key IN ('_wp_attached_file', '_wp_attachment_metadata')", array( $id ) );
			$file = '';
			$m    = null;
			foreach ( $meta as $row ) {
				if ( $row['meta_key'] === '_wp_attached_file' ) {
					$file = (string) $row['meta_value'];
				} elseif ( is_serialized( $row['meta_value'] ) ) {
					$m = @unserialize( $row['meta_value'], array( 'allowed_classes' => false ) );
				}
			}
			if ( $file === '' ) {
				continue;
			}
			$paths[ $id ][] = $file;
			$dir            = dirname( $file );
			$dir            = $dir === '.' ? '' : $dir . '/';
			if ( is_array( $m ) ) {
				foreach ( ( $m['sizes'] ?? array() ) as $s ) {
					if ( ! empty( $s['file'] ) ) {
						$paths[ $id ][] = $dir . $s['file'];
					}
				}
				if ( ! empty( $m['original_image'] ) ) {
					$paths[ $id ][] = $dir . $m['original_image'];
				}
			}
			$paths[ $id ] = array_values( array_unique( $paths[ $id ] ) );
		}
		return $paths;
	}

	/**
	 * Move files of removed attachments into uploads/wpbr-trash/<timestamp>/ on the
	 * target. Never deletes outright; a rollback can put them back by hand.
	 */
	public static function trash( array $paths_by_id, string $target_branch ): array {
		$out = array(
			'removed'   => count( $paths_by_id ),
			'trashed'   => 0,
			'trash_dir' => null,
		);
		if ( ! $paths_by_id ) {
			return $out;
		}
		$dst_dir = self::uploads_dir( $target_branch );
		if ( ! $dst_dir ) {
			return $out;
		}
		$trash = $dst_dir . '/wpbr-trash/' . gmdate( 'Ymd-His' );
		foreach ( $paths_by_id as $id => $rels ) {
			foreach ( $rels as $rel ) {
				$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
				if ( $rel === '' || str_contains( $rel, '..' ) ) {
					continue;
				}
				$src     = $dst_dir . '/' . $rel;
				$handled = apply_filters( 'wpbr_trash_attachment_file', null, $src, $rel, $target_branch, (int) $id );
				if ( $handled !== null ) {
					if ( $handled ) {
						++$out['trashed'];
					} continue; }
				if ( ! is_file( $src ) ) {
					continue;
				}
				$to = $trash . '/' . $rel;
				if ( ! is_dir( dirname( $to ) ) ) {
					wp_mkdir_p( dirname( $to ) );
				}
				// A move inside the uploads directory of this install; WP_Filesystem has no credentials in a merge request.
				if ( @rename( $src, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
					++$out['trashed'];
					$out['trash_dir'] = $trash; }
			}
		}
		return $out;
	}

	/** One line for a notice or the CLI. */
	public static function describe( array $r ): string {
		$parts = array();
		if ( ! empty( $r['attachments'] ) ) {
			if ( ! empty( $r['skipped'] ) ) {
				$parts[] = $r['attachments'] . ' attachment(s) arrived; files not copied: ' . $r['skipped'];
			} else {
				$s = sprintf( '%d attachment file(s) copied', $r['copied'] );
				if ( ! empty( $r['unchanged'] ) ) {
					$s .= sprintf( ', %d already present', $r['unchanged'] );
				}
				if ( ! empty( $r['missing'] ) ) {
					$s .= sprintf( ', %d missing on the source (%s)', count( $r['missing'] ), implode( ', ', array_slice( $r['missing'], 0, 3 ) ) );
				}
				$parts[] = $s . '.';
			}
		}
		if ( ! empty( $r['trashed'] ) ) {
			$parts[] = sprintf( '%d file(s) of removed attachments moved to %s.', $r['trashed'], str_replace( self::uploads_dir( WPBR_Dolt::current_branch() ) ?? '', 'uploads', (string) $r['trash_dir'] ) );
		}
		return implode( ' ', $parts );
	}
}
