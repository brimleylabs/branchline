<?php
/**
 * The merge itself: preview, plan, apply.
 *
 * A merge always has a target branch (the one that changes) and a source
 * branch (the one merged in). "Pull" is production -> this branch.
 * "Publish" is this branch -> production. In both cases the rules are the
 * same: production is the source of truth for content, the release branch is
 * the source of truth for structure, and editorial clashes go to a human.
 *
 * Preview runs the real merge inside a transaction, reads the conflicts and
 * constraint violations, computes resolutions, and rolls back. Apply runs it
 * again, applies the same automatic resolutions plus the human decisions, and
 * commits. Both runs are pinned to the branch hashes the preview saw.
 */
defined( 'ABSPATH' ) || exit;

class WPBR_Merge_Engine {

	public const OURS   = 'ours';    // target keeps its row
	public const THEIRS = 'theirs';  // take the source row
	public const MERGED = 'merged';  // column-level or semantic merge
	public const DELETE = 'delete';
	public const HUMAN  = 'human';

	private WPBR_Dolt $t;            // target connection, autocommit off
	private WPBR_Classifier $cls;
	private string $production;

	public function __construct( private string $target, private string $source ) {
		$this->production = WPBR_PRODUCTION_BRANCH;
	}

	public function target(): string {
		return $this->target; }
	public function source(): string {
		return $this->source; }

	/** Is the source branch the production branch (i.e. this is a pull)? */
	private function source_is_production(): bool {
		return $this->source === $this->production; }

	// ------------------------------------------------------------------ preview

	public function preview(): array {
		$this->snapshot_both( 'Snapshot before merge' );
		$this->open();
		try {
			$target_hash = $this->t->head_hash();
			$source_hash = (string) $this->t->var( 'SELECT hash FROM dolt_branches WHERE name = ?', array( $this->source ) );
			$base        = $this->t->merge_base( $this->source );

			$incoming     = $this->summarize_incoming( $base, $source_hash );
			$up_to_date   = $source_hash === $base;          // nothing to bring in
			$fast_forward = ! $up_to_date && $target_hash === $base; // target has not moved: no three-way merge needed
			$attachments  = $up_to_date ? 0 : WPBR_Files::incoming_count( $this->t, $base, $source_hash );

			$plan = array(
				'auto'   => array(),
				'human'  => array(),
				'silent' => array(),
			);
			if ( ! $up_to_date && ! $fast_forward ) {
				// A fast-forward would move the branch ref even inside a rolled-back transaction,
				// so only the real three-way case runs the merge here, and it is always rolled back.
				$this->t->merge_start( $this->source );
				try {
					$plan = $this->build_plan( $base, $source_hash );
				} finally {
					$this->t->abort_merge();
				}
			}

			return array(
				'target'       => $this->target,
				'source'       => $this->source,
				'target_hash'  => $target_hash,
				'source_hash'  => $source_hash,
				'base'         => $base,
				'fast_forward' => $fast_forward,
				'up_to_date'   => $up_to_date,
				'incoming'     => $incoming,
				'attachments'  => $attachments,
				'uploads_from' => WPBR_Files::uploads_dir( $this->source ),
				'uploads_to'   => WPBR_Files::uploads_dir( $this->target ),
				'auto'         => $plan['auto'],
				'human'        => $plan['human'],
				'silent'       => count( $plan['silent'] ),
			);
		} finally {
			$this->close();
		}
	}

	// -------------------------------------------------------------------- apply

	/**
	 * @param array $decisions  key => OURS|THEIRS|MERGED|DELETE from the review form
	 * @param array $pinned     ['target_hash' => ..., 'source_hash' => ...] from the preview
	 */
	public function apply( array $decisions, array $pinned, string $message = '' ): array {
		$this->open();
		try {
			if ( $this->t->head_hash() !== $pinned['target_hash'] || $this->t->var( 'SELECT hash FROM dolt_branches WHERE name = ?', array( $this->source ) ) !== $pinned['source_hash'] ) {
				throw new RuntimeException( 'A branch changed since the preview. Review again before applying.' );
			}
			$base = $this->t->merge_base( $this->source );
			if ( $pinned['source_hash'] === $base ) {
				throw new RuntimeException( 'Nothing to merge: ' . $this->target . ' already contains everything on ' . $this->source . '.' );
			}
			if ( $pinned['target_hash'] === $base ) {
				// Fast-forward: the target has not moved since the split. Just advance it.
				$this->t->merge_start( $this->source );
				$this->t->commit();
				wp_cache_flush();
				return array(
					'hash'         => $this->t->head_hash(),
					'applied'      => array(),
					'fast_forward' => true,
					'files'        => $this->sync_files( $base, $pinned['source_hash'] ),
				);
			}
			$this->t->merge_start( $this->source );
			$plan = $this->build_plan( $base, $pinned['source_hash'] );

			$applied = array();
			foreach ( $plan['silent'] as $item ) {
				$this->resolve( $item, $item['resolution'] );
			}
			foreach ( $plan['auto'] as $item ) {
				$this->resolve( $item, $item['resolution'] );
				$applied[] = $item;
			}
			foreach ( $plan['human'] as $item ) {
				$choice = $decisions[ $item['key'] ] ?? null;
				if ( ! $choice ) {
					throw new RuntimeException( 'No decision given for: ' . $item['label'] );
				}
				if ( $choice === self::MERGED && empty( $item['merged'] ) ) {
					throw new RuntimeException( 'No merged value available for: ' . $item['label'] );
				}
				$this->resolve( $item, $choice );
				$item['resolution'] = $choice;
				$applied[]          = $item;
			}
			$hash = $this->t->finish_merge( $message ?: sprintf( 'Merge %s into %s (Branches)', $this->source, $this->target ) );
			wp_cache_flush();
			return array(
				'hash'         => $hash,
				'applied'      => $applied,
				'silent'       => count( $plan['silent'] ),
				'fast_forward' => false,
				'files'        => $this->sync_files( $base, $pinned['source_hash'] ),
			);
		} catch ( Throwable $e ) {
			$this->t->abort_merge();
			throw $e;
		} finally {
			$this->close();
		}
	}

	// --------------------------------------------------------------- internals

	private function open(): void {
		$this->t   = new WPBR_Dolt( $this->target, false );
		$this->cls = new WPBR_Classifier( $this->t );
	}

	private function close(): void {
		if ( isset( $this->t ) ) {
			$this->t->close();
		}
	}

	/** Record any uncommitted work on both branches so the merge starts clean. */
	private function snapshot_both( string $message ): void {
		foreach ( array( $this->target, $this->source ) as $b ) {
			$c = new WPBR_Dolt( $b, false );
			try {
				$c->snapshot( $message . ' (' . $b . ')' );
			} finally {
				$c->close(); }
		}
	}

	/** What the source branch did since the merge base, grouped by table, with labels. */
	private function summarize_incoming( string $base, string $source_hash ): array {
		return self::summarize( $this->t, $this->cls, $base, $source_hash );
	}

	/** Rows above this many per table are counted from the table statistics instead of one by one. */
	private const CLASSIFY_LIMIT = 5000;

	/**
	 * Meaningful changes between two refs, per table: counts that leave out noise
	 * (transients, cron, locks, sessions) and a few described examples.
	 * Tables with nothing but noise are left out entirely.
	 */
	public static function summarize( WPBR_Dolt $d, WPBR_Classifier $cls, string $from, string $to, int $samples = 8 ): array {
		$out = array();
		foreach ( $d->diff_stat( $from, $to ) as $r ) {
			$table = $r['table_name'];
			if ( ! $r['rows_added'] && ! $r['rows_deleted'] && ! $r['rows_modified'] ) {
				continue;
			}
			if ( $cls->is_noise_table( $table ) ) {
				continue;
			}
			$rows   = $d->diff_rows( $from, $to, $table, self::CLASSIFY_LIMIT + 1 );
			$approx = count( $rows ) > self::CLASSIFY_LIMIT;
			$n      = array(
				'added'    => 0,
				'modified' => 0,
				'removed'  => 0,
			);
			$ex     = array();
			foreach ( $rows as $w ) {
				$row = self::side_of( $w, $w['diff_type'] === 'removed' ? 'from_' : 'to_' );
				if ( $cls->classify( $table, $row ) === WPBR_Classifier::NOISE ) {
					continue;
				}
				$n[ $w['diff_type'] ] = ( $n[ $w['diff_type'] ] ?? 0 ) + 1;
				if ( count( $ex ) < $samples ) {
					$ex[] = array(
						'type' => $w['diff_type'],
						'text' => $cls->describe( $table, $row ),
					);
				}
			}
			if ( $approx ) {
				// Too many rows to classify one by one; report the table totals and say so.
				$n = array(
					'added'    => (int) $r['rows_added'],
					'modified' => (int) $r['rows_modified'],
					'removed'  => (int) $r['rows_deleted'],
				);
			}
			if ( ! $n['added'] && ! $n['modified'] && ! $n['removed'] ) {
				continue;
			}
			$out[] = array(
				'table'    => $table,
				'label'    => $cls->table_label( $table ),
				'added'    => $n['added'],
				'modified' => $n['modified'],
				'deleted'  => $n['removed'],
				'approx'   => $approx,
				'samples'  => $ex,
			);
		}
		return $out;
	}

	/** Sum of meaningful row changes in a summary. */
	public static function total( array $summary ): int {
		$n = 0;
		foreach ( $summary as $t ) {
			$n += $t['added'] + $t['modified'] + $t['deleted'];
		}
		return $n;
	}

	/** Copy attachment files for attachments that came in with this merge. */
	private function sync_files( string $base, string $source_hash ): array {
		try {
			$paths = WPBR_Files::incoming_paths( $this->t, $base, $source_hash );
			$r     = WPBR_Files::copy( $paths, $this->source, $this->target );
			$gone  = WPBR_Files::removed_paths( $this->t, $base, $source_hash );
			return $r + WPBR_Files::trash( $gone, $this->target );
		} catch ( Throwable $e ) {
			return array(
				'attachments' => 0,
				'copied'      => 0,
				'unchanged'   => 0,
				'missing'     => array(),
				'skipped'     => $e->getMessage(),
			);
		}
	}

	/** Pull the base_/our_/their_ (or from_/to_) columns of a wide row into a plain row. */
	private function side( array $wide, string $prefix ): array {
		return self::side_of( $wide, $prefix );
	}

	private static function side_of( array $wide, string $prefix ): array {
		$out = array();
		foreach ( $wide as $k => $v ) {
			if ( ! str_starts_with( $k, $prefix ) ) {
				continue;
			}
			$col = substr( $k, strlen( $prefix ) );
			if ( $col === 'diff_type' || $col === 'commit' || $col === 'commit_date' ) {
				continue; // dolt bookkeeping, not table columns
			}
			$out[ $col ] = $v;
		}
		return $out;
	}

	/** Walk conflicts and constraint violations; decide each one. */
	private function build_plan( string $base, string $source_hash ): array {
		$auto   = array();
		$human  = array();
		$silent = array();
		$place  = function ( array $item ) use ( &$auto, &$human, &$silent ) {
			if ( $item['resolution'] === self::HUMAN ) {
				$human[] = $item;
			} elseif ( $item['class'] === WPBR_Classifier::NOISE ) {
				$silent[] = $item; // resolved, never shown
			} else {
				$auto[] = $item;
			}
		};
		foreach ( $this->t->conflict_tables() as $ct ) {
			$table = $ct['table'];
			$pk    = $this->t->primary_key( $table );
			foreach ( $this->t->conflicts_for( $table ) as $c ) {
				$place( $this->decide_conflict( $table, $pk, $c ) );
			}
		}
		foreach ( $this->t->violation_tables() as $vt ) {
			foreach ( $this->decide_violations( $vt['table'], $source_hash ) as $item ) {
				$place( $item );
			}
		}
		return array(
			'auto'   => $auto,
			'human'  => $human,
			'silent' => $silent,
		);
	}

	private function decide_conflict( string $table, array $pk, array $c ): array {
		$base          = $this->side( $c, 'base_' );
		$ours          = $this->side( $c, 'our_' );
		$theirs        = $this->side( $c, 'their_' );
		$our_type      = $c['our_diff_type'];   // added | modified | removed
		$their_type    = $c['their_diff_type'];
		$row_for_class = $ours['option_name'] ?? null ? $ours : ( $theirs ?: $base );
		$class         = $this->cls->classify( $table, array_filter( $row_for_class, fn( $v ) => $v !== null ) ?: $theirs ?: $base );
		$pk_vals       = array();
		foreach ( $pk as $col ) {
			$pk_vals[ $col ] = $ours[ $col ] ?? $theirs[ $col ] ?? $base[ $col ];
		}
		$item = array(
			'kind'        => 'conflict',
			'table'       => $table,
			'pk'          => $pk_vals,
			'conflict_id' => $c['dolt_conflict_id'],
			'key'         => $this->key( $table, $pk_vals ),
			'class'       => $class,
			'label'       => $this->cls->describe( $table, $ours ?: $theirs ?: $base ),
			'our_type'    => $our_type,
			'their_type'  => $their_type,
			'base'        => $base,
			'ours'        => $ours,
			'theirs'      => $theirs,
			'merged'      => null,
			'reason'      => '',
		);

		// Deleted on one side, changed on the other.
		if ( $our_type === 'removed' || $their_type === 'removed' ) {
			return $this->decide_delete_vs_modify( $item );
		}

		// Both sides added or modified: merge column by column.
		[$merged, $clashes] = $this->merge_columns( $table, $base, $ours, $theirs );
		$item['merged']     = $merged;
		if ( ! $clashes ) {
			$item['resolution'] = self::MERGED;
			$item['reason']     = 'Each side changed different fields; combined.';
			return $item;
		}
		$item['clashes'] = $clashes;
		$policy          = $this->policy_winner( $class );
		if ( $policy === self::HUMAN ) {
			$item['resolution'] = self::HUMAN;
			$item['reason']     = 'Both sides changed: ' . implode( ', ', $clashes ) . '.';
			// Offer a best-effort merged row where the clashing columns take the production side.
			$item['merged'] = $this->fill_clashes( $merged, $clashes, $this->source_is_production() ? $theirs : $ours );
			return $item;
		}
		$item['resolution'] = self::MERGED;
		$item['merged']     = $this->fill_clashes( $merged, $clashes, $policy === self::THEIRS ? $theirs : $ours );
		$item['reason']     = sprintf( '%s: %s wins on %s.', ucfirst( $class ), $policy === self::THEIRS ? $this->source : $this->target, implode( ', ', $clashes ) );
		return $item;
	}

	private function decide_delete_vs_modify( array $item ): array {
		$class                 = $item['class'];
		$deleted_by_us         = $item['our_type'] === 'removed';
		$deleter               = $deleted_by_us ? $this->target : $this->source;
		$deleter_is_production = $deleter === $this->production;
		if ( $class === WPBR_Classifier::NOISE ) {
			$item['resolution'] = self::OURS;
			$item['reason']     = 'Bookkeeping row; kept as is.';
		} elseif ( $class === WPBR_Classifier::EDITORIAL ) {
			$item['resolution'] = self::HUMAN;
			$item['reason']     = sprintf( 'Deleted on %s but changed on the other branch.', $deleter );
		} elseif ( $class === WPBR_Classifier::STRUCTURE ) {
			// Release branch decides structure.
			$release_deleted    = ! $deleter_is_production;
			$item['resolution'] = $release_deleted ? self::DELETE : ( $deleted_by_us ? self::THEIRS : self::OURS );
			$item['reason']     = $release_deleted ? 'Removed by the release; removal stands.' : 'Production removed it, but the release still uses it; kept.';
		} else {
			// Content: production decides.
			$production_deleted = $deleter_is_production;
			$item['resolution'] = $production_deleted ? self::DELETE : ( $deleted_by_us ? self::THEIRS : self::OURS );
			$item['reason']     = $production_deleted ? 'Removed in production; removal stands.' : 'Production still has it; kept.';
		}
		return $item;
	}

	/** Which side wins a same-column clash for this class, or HUMAN. */
	private function policy_winner( string $class ): string {
		return match ( $class ) {
			WPBR_Classifier::NOISE     => self::OURS,
			WPBR_Classifier::STRUCTURE => $this->source_is_production() ? self::OURS : self::THEIRS,
			WPBR_Classifier::CONTENT   => $this->source_is_production() ? self::THEIRS : self::OURS,
			default                    => self::HUMAN,
		};
	}

	/**
	 * Column-level three-way merge. Returns [merged_row, clashing_columns].
	 * Timestamps take the newest, counters take the larger, blobs try a semantic merge.
	 */
	private function merge_columns( string $table, array $base, array $ours, array $theirs ): array {
		$merged     = array();
		$clashes    = array();
		$meaningful = $this->cls->meaningful_columns( $table );
		foreach ( array_keys( $ours ) as $col ) {
			$b = $base[ $col ] ?? null;
			$o = $ours[ $col ];
			$t = $theirs[ $col ] ?? null;
			if ( $o === $t ) {
				$merged[ $col ] = $o;
				continue; }
			if ( $o === $b ) {
				$merged[ $col ] = $t;
				continue; }
			if ( $t === $b ) {
				$merged[ $col ] = $o;
				continue; }
			if ( in_array( $col, WPBR_Classifier::TIMESTAMP_MAX_COLUMNS, true ) ) {
				$merged[ $col ] = max( (string) $o, (string) $t );
				continue; }
			if ( in_array( $col, WPBR_Classifier::COUNTER_COLUMNS, true ) ) {
				$merged[ $col ] = max( (int) $o, (int) $t );
				continue; }
			if ( is_string( $o ) && is_string( $t ) ) {
				$sem = WPBR_Serialized_Merge::try_merge( is_string( $b ) ? $b : null, $o, $t );
				if ( $sem !== null ) {
					$merged[ $col ] = $sem;
					continue; }
			}
			$merged[ $col ] = $o;
			if ( ! $meaningful || in_array( $col, $meaningful, true ) ) {
				$clashes[] = $col;
			}
		}
		return array( $merged, $clashes );
	}

	private function fill_clashes( array $merged, array $clashes, array $winner ): array {
		foreach ( $clashes as $col ) {
			$merged[ $col ] = $winner[ $col ] ?? $merged[ $col ];
		}
		return $merged;
	}

	/** Unique-key collisions: the same thing created separately on both sides. */
	private function decide_violations( string $table, string $source_hash ): array {
		$pk   = $this->t->primary_key( $table );
		$rows = $this->t->violations_for( $table );
		// Group by the violated unique key (violation_info carries the columns).
		$groups = array();
		foreach ( $rows as $v ) {
			$info              = json_decode( (string) ( $v['violation_info'] ?? '{}' ), true ) ?: array();
			$cols              = $info['Columns'] ?? $info['columns'] ?? array();
			$gkey              = implode( '|', array_map( fn( $c ) => (string) ( $v[ $c ] ?? '' ), $cols ) ) ?: ( $v['violation_type'] . '|' . implode( '|', array_map( fn( $c ) => $v[ $c ], $pk ) ) );
			$groups[ $gkey ][] = $v;
		}
		$items = array();
		foreach ( $groups as $gkey => $vrows ) {
			$plain = array_map(
				fn( $v ) => array_diff_key(
					$v,
					array(
						'violation_type'    => 1,
						'violation_info'    => 1,
						'from_root_ish'     => 1,
						'dolt_violation_id' => 1,
					)
				),
				$vrows
			);
			$class = $this->cls->classify( $table, $plain[0] );
			// Which row came from the source branch?
			$from_source = array();
			$from_target = array();
			foreach ( $plain as $row ) {
				$pkv = array_intersect_key( $row, array_flip( $pk ) );
				if ( $this->t->row_exists_at( $source_hash, $table, $pkv ) ) {
					$from_source[] = $row;
				} else {
					$from_target[] = $row;
				}
			}
			$item = array(
				'kind'        => 'violation',
				'table'       => $table,
				'class'       => $class,
				'key'         => $this->key( $table, array( 'uniq' => $gkey ) ),
				'label'       => $this->cls->describe( $table, $plain[0] ) . ' exists on both branches',
				'ours'        => $from_target[0] ?? null,
				'theirs'      => $from_source[0] ?? null,
				'base'        => null,
				'merged'      => null,
				'rows_source' => $from_source,
				'rows_target' => $from_target,
				'pk'          => $pk,
				'reason'      => '',
			);
			if ( count( $plain ) < 2 || ! $from_source || ! $from_target ) {
				$item['resolution'] = self::HUMAN;
				$item['reason']     = 'Duplicate could not be attributed to a branch.';
				$items[]            = $item;
				continue;
			}
			$winner = $this->policy_winner( $class );
			if ( $winner === self::HUMAN ) {
				$item['resolution'] = self::HUMAN;
				$item['reason']     = 'Created separately on both branches.';
			} else {
				$item['resolution'] = $winner;
				$item['reason']     = sprintf( '%s: keeping the %s copy.', ucfirst( $class ), $winner === self::THEIRS ? $this->source : $this->target );
			}
			$items[] = $item;
		}
		return $items;
	}

	/** Apply one decision inside the open merge transaction. */
	private function resolve( array $item, string $choice ): void {
		$table = $item['table'];
		if ( $item['kind'] === 'violation' ) {
			$loser_rows = $choice === self::THEIRS ? $item['rows_target'] : $item['rows_source'];
			foreach ( $loser_rows as $row ) {
				$this->t->delete_row( $table, array_intersect_key( $row, array_flip( $item['pk'] ) ) );
			}
			$this->t->clear_violations( $table );
			return;
		}
		$pk = $item['pk'];
		switch ( $choice ) {
			case self::OURS:
				if ( $item['our_type'] === 'removed' ) {
					$this->t->delete_row( $table, $pk ); } else {
					$this->t->update_row( $table, $pk, $this->without_pk( $item['ours'], $pk ) ); }
				break;
			case self::THEIRS:
				if ( $item['their_type'] === 'removed' ) {
					$this->t->delete_row( $table, $pk ); } elseif ( $item['our_type'] === 'removed' ) {
					$this->t->insert_row( $table, $item['theirs'] ); } else {
						$this->t->update_row( $table, $pk, $this->without_pk( $item['theirs'], $pk ) ); }
				break;
			case self::DELETE:
				$this->t->delete_row( $table, $pk );
				break;
			case self::MERGED:
				if ( $item['our_type'] === 'removed' ) {
					$this->t->insert_row( $table, $item['merged'] ); } else {
					$this->t->update_row( $table, $pk, $this->without_pk( $item['merged'], $pk ) ); }
				break;
			default:
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where displayed.
				throw new RuntimeException( 'Unknown resolution ' . $choice );
		}
		$this->t->clear_conflict( $table, $item['conflict_id'] );
	}

	private function without_pk( array $row, array $pk ): array {
		return array_diff_key( $row, $pk );
	}

	private function key( string $table, array $pk ): string {
		return $table . ':' . md5( wp_json_encode( $pk ) );
	}
}
