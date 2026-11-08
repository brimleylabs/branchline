<?php
/**
 * Thin wrapper over a dedicated mysqli connection to one branch of the Dolt database.
 *
 * WordPress's own $wpdb runs with autocommit and keeps writing options and
 * transients while an admin page renders. A merge has to happen inside one
 * transaction on one session, so every merge step runs on its own connection
 * with autocommit off and never touches $wpdb.
 */
defined( 'ABSPATH' ) || exit;

class WPBR_Dolt {

	private mysqli $db;
	public string $database;   // base database name, e.g. "wp"
	public string $branch;     // branch this connection is on

	public function __construct( string $branch, bool $autocommit = true ) {
		$this->database         = self::base_database();
		$this->branch           = $branch;
		[$host, $port, $socket] = self::host_parts();
		// A branch is a separate database name on the same server. $wpdb is bound to this site's
		// branch, so every other branch needs its own connection.
		$this->db = mysqli_init(); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_init
		$this->db->options( MYSQLI_OPT_CONNECT_TIMEOUT, 10 );
		$dbname = $this->database . '/' . $branch;
		if ( ! @$this->db->real_connect( $host, DB_USER, DB_PASSWORD, $dbname, $port, $socket ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where displayed.
			throw new RuntimeException( "Could not connect to branch '$branch': " . $this->db->connect_error );
		}
		$this->db->set_charset( defined( 'DB_CHARSET' ) && DB_CHARSET ? DB_CHARSET : 'utf8mb4' );
		$this->db->autocommit( $autocommit );
	}

	/** "wp/stage" -> "wp"; "wp" -> "wp" */
	public static function base_database(): string {
		return explode( '/', DB_NAME, 2 )[0];
	}

	/** Branch of the WordPress site we are running in, from DB_NAME, else the server's active branch. */
	public static function current_branch(): string {
		$parts = explode( '/', DB_NAME, 2 );
		if ( count( $parts ) === 2 && $parts[1] !== '' ) {
			return $parts[1];
		}
		global $wpdb;
		$b = $wpdb->get_var( 'SELECT active_branch()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- server function, no table, no cache possible.
		return $b ?: 'main';
	}

	public static function is_dolt(): bool {
		global $wpdb;
		$v = $wpdb->get_var( 'SELECT dolt_version()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- server function, no table, no cache possible.
		return (bool) $v;
	}

	private static function host_parts(): array {
		$host   = DB_HOST;
		$port   = 3306;
		$socket = null;
		if ( str_contains( $host, ':' ) ) {
			[$h, $p] = explode( ':', $host, 2 );
			$host    = $h;
			if ( is_numeric( $p ) ) {
				$port = (int) $p;
			} else {
				$socket = $p; }
		}
		return array( $host, $port, $socket );
	}

	// ------------------------------------------------------------------ queries

	public function q( string $sql, array $params = array() ): array {
		$stmt = $this->prepare( $sql, $params );
		$stmt->execute();
		$res  = $stmt->get_result();
		$rows = $res ? $res->fetch_all( MYSQLI_ASSOC ) : array();
		$stmt->close();
		return $rows;
	}

	public function exec( string $sql, array $params = array() ): int {
		$stmt = $this->prepare( $sql, $params );
		$stmt->execute();
		$n = $stmt->affected_rows;
		$stmt->close();
		return $n;
	}

	public function one( string $sql, array $params = array() ): ?array {
		$r = $this->q( $sql, $params );
		return $r[0] ?? null;
	}

	public function var( string $sql, array $params = array() ) {
		$r = $this->one( $sql, $params );
		return $r ? array_values( $r )[0] : null;
	}

	private function prepare( string $sql, array $params ): mysqli_stmt {
		$stmt = $this->db->prepare( $sql );
		if ( ! $stmt ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where displayed.
			throw new RuntimeException( "Dolt query failed to prepare: {$this->db->error} :: $sql" );
		}
		if ( $params ) {
			$types = str_repeat( 's', count( $params ) );
			$stmt->bind_param( $types, ...array_values( $params ) );
		}
		return $stmt;
	}

	public function ident( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	public function commit(): void {
		$this->db->commit(); }
	public function rollback(): void {
		$this->db->rollback(); }
	public function close(): void {
		@$this->db->close(); }

	// ---------------------------------------------------------------- dolt ops

	public function head_hash(): string {
		return (string) $this->var( 'SELECT hash FROM dolt_branches WHERE name = ?', array( $this->branch ) );
	}

	public function branches(): array {
		return $this->q( 'SELECT name, hash, latest_committer, latest_commit_date, latest_commit_message FROM dolt_branches ORDER BY name' );
	}

	public function log( int $limit = 10 ): array {
		return $this->q( "SELECT commit_hash, committer, date, message FROM dolt_log LIMIT $limit" );
	}

	/** Uncommitted changes on this branch, grouped by table. */
	public function status(): array {
		return $this->q( 'SELECT table_name, staged, status FROM dolt_status' );
	}

	public function is_dirty(): bool {
		return (int) $this->var( 'SELECT COUNT(*) FROM dolt_status' ) > 0;
	}

	/** Record the working set as a commit. Returns the new hash or null when nothing changed. */
	public function snapshot( string $message ): ?string {
		if ( ! $this->is_dirty() ) {
			return null;
		}
		$this->exec( 'CALL dolt_commit(?, ?, ?, ?)', array( '-A', '-m', $message, '--skip-empty' ) );
		$this->commit();
		return $this->head_hash();
	}

	public function merge_base( string $other ): string {
		return (string) $this->var( 'SELECT dolt_merge_base(?, ?)', array( $this->branch, $other ) );
	}

	/** Per-table counts between two refs (hashes or branch names). */
	public function diff_stat( string $from, string $to ): array {
		return $this->q( 'SELECT * FROM dolt_diff_stat(?, ?)', array( $from, $to ) );
	}

	/** Row diff for one table between two refs. Columns come back as from_* / to_* plus diff_type. */
	public function diff_rows( string $from, string $to, string $table, int $limit = 500, string $where = '' ): array {
		// dolt_diff() wants literal arguments, not placeholders. $where is trusted SQL from the engine, never user input.
		$lit = fn( string $s ) => "'" . $this->db->real_escape_string( $s ) . "'";
		return $this->q( 'SELECT * FROM dolt_diff(' . $lit( $from ) . ', ' . $lit( $to ) . ', ' . $lit( $table ) . ')' . ( $where ? ' WHERE ' . $where : '' ) . ' LIMIT ' . (int) $limit );
	}

	public function primary_key( string $table ): array {
		// Filter in PHP: Dolt returns every index here regardless of the WHERE clause.
		$rows = array_values( array_filter( $this->q( 'SHOW KEYS FROM ' . $this->ident( $table ) ), fn( $r ) => ( $r['Key_name'] ?? '' ) === 'PRIMARY' ) );
		usort( $rows, fn( $a, $b ) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
		return array_map( fn( $r ) => $r['Column_name'], $rows );
	}

	public function columns( string $table ): array {
		return array_map( fn( $r ) => $r['Field'], $this->q( 'SHOW COLUMNS FROM ' . $this->ident( $table ) ) );
	}

	/** Start a merge of $source into this branch inside the open transaction. Returns the dolt_merge row. */
	public function merge_start( string $source ): array {
		$this->exec( 'SET @@dolt_allow_commit_conflicts = 1' );
		$this->exec( 'SET @@dolt_force_transaction_commit = 1' );
		// --no-commit leaves the merged working set staged so the caller decides whether to
		// commit (apply) or roll back (preview). Without it a clean merge commits by itself.
		$rows = $this->q( 'CALL dolt_merge(?, ?)', array( '--no-commit', $source ) );
		return $rows[0] ?? array();
	}

	public function conflict_tables(): array {
		return $this->q( 'SELECT `table`, num_conflicts FROM dolt_conflicts' );
	}

	public function violation_tables(): array {
		return $this->q( 'SELECT `table`, num_violations FROM dolt_constraint_violations' );
	}

	public function conflicts_for( string $table ): array {
		return $this->q( 'SELECT * FROM ' . $this->ident( 'dolt_conflicts_' . $table ) );
	}

	public function violations_for( string $table ): array {
		return $this->q( 'SELECT * FROM ' . $this->ident( 'dolt_constraint_violations_' . $table ) );
	}

	public function clear_conflict( string $table, string $conflict_id ): void {
		$this->exec( 'DELETE FROM ' . $this->ident( 'dolt_conflicts_' . $table ) . ' WHERE dolt_conflict_id = ?', array( $conflict_id ) );
	}

	public function clear_violations( string $table ): void {
		$this->exec( 'DELETE FROM ' . $this->ident( 'dolt_constraint_violations_' . $table ) );
	}

	/** Does a row with this primary key exist in the table as of the given ref? */
	public function row_exists_at( string $ref, string $table, array $pk ): bool {
		// AS OF takes a literal, not a placeholder.
		$where = implode( ' AND ', array_map( fn( $c ) => $this->ident( $c ) . ' = ?', array_keys( $pk ) ) );
		$sql   = 'SELECT 1 FROM ' . $this->ident( $table ) . " AS OF '" . $this->db->real_escape_string( $ref ) . "' WHERE " . $where . ' LIMIT 1';
		return (bool) $this->one( $sql, array_values( $pk ) );
	}

	public function update_row( string $table, array $pk, array $values ): void {
		if ( ! $values ) {
			return;
		}
		$set   = implode( ', ', array_map( fn( $c ) => $this->ident( $c ) . ' = ?', array_keys( $values ) ) );
		$where = implode( ' AND ', array_map( fn( $c ) => $this->ident( $c ) . ' = ?', array_keys( $pk ) ) );
		$this->exec( 'UPDATE ' . $this->ident( $table ) . " SET $set WHERE $where", array_merge( array_values( $values ), array_values( $pk ) ) );
	}

	public function insert_row( string $table, array $values ): void {
		$cols = implode( ', ', array_map( array( $this, 'ident' ), array_keys( $values ) ) );
		$ph   = implode( ', ', array_fill( 0, count( $values ), '?' ) );
		$this->exec( 'INSERT INTO ' . $this->ident( $table ) . " ($cols) VALUES ($ph)", array_values( $values ) );
	}

	public function delete_row( string $table, array $pk ): void {
		$where = implode( ' AND ', array_map( fn( $c ) => $this->ident( $c ) . ' = ?', array_keys( $pk ) ) );
		$this->exec( 'DELETE FROM ' . $this->ident( $table ) . " WHERE $where", array_values( $pk ) );
	}

	public function finish_merge( string $message ): string {
		$left = (int) $this->var( 'SELECT COUNT(*) FROM dolt_conflicts' );
		$viol = (int) $this->var( 'SELECT COUNT(*) FROM dolt_constraint_violations' );
		if ( $left || $viol ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where displayed.
			throw new RuntimeException( "Merge still has $left conflicted tables and $viol tables with constraint violations." );
		}
		$this->exec( 'CALL dolt_commit(?, ?, ?)', array( '-A', '-m', $message ) );
		$this->commit();
		return $this->head_hash();
	}

	public function abort_merge(): void {
		try {
			$this->rollback();
		} catch ( Throwable $e ) {
			// Nothing to abort: the connection may already be out of the transaction.
			return;
		}
	}

	// ---------------------------------------------------------------- rollback

	/** Is this commit in the history of this branch? */
	public function has_commit( string $hash ): bool {
		return (bool) $this->var( 'SELECT 1 FROM dolt_log WHERE commit_hash = ? LIMIT 1', array( $hash ) );
	}

	public function tags(): array {
		return $this->q( 'SELECT tag_name, tag_hash, date, message FROM dolt_tags ORDER BY date DESC' );
	}

	public function tag( string $name, string $ref, string $message = '' ): void {
		$this->exec( 'CALL dolt_tag(?, ?, ?, ?)', array( '-m', $message ?: $name, $name, $ref ) );
		$this->commit();
	}

	/**
	 * Move this branch back to an earlier snapshot. The current head is first saved as a
	 * tag so nothing becomes unreachable. Returns the tag name.
	 */
	public function rollback_to( string $hash ): string {
		if ( ! $this->has_commit( $hash ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- escaped where displayed.
			throw new RuntimeException( "Snapshot $hash is not in the history of " . $this->branch . '.' );
		}
		$this->snapshot( 'Unsaved changes before rollback (' . $this->branch . ')' );
		$tag = 'before-rollback-' . $this->branch . '-' . gmdate( 'Ymd-His' );
		$this->tag( $tag, $this->head_hash(), 'Head of ' . $this->branch . ' before rolling back to ' . substr( $hash, 0, 8 ) );
		$this->exec( 'CALL dolt_reset(?, ?)', array( '--hard', $hash ) );
		$this->commit();
		return $tag;
	}

	// ----------------------------------------------------------------- remotes
	// A remote is another Dolt server, DoltHub, DoltLab, a bucket or a directory.
	// Developers clone it to their laptops and push branches back, like git.

	public function remotes(): array {
		return $this->q( 'SELECT name, url FROM dolt_remotes ORDER BY name' );
	}

	public function remote_add( string $name, string $url ): void {
		$this->exec( 'CALL dolt_remote(?, ?, ?)', array( 'add', $name, $url ) );
		$this->commit();
	}

	public function remote_remove( string $name ): void {
		$this->exec( 'CALL dolt_remote(?, ?)', array( 'remove', $name ) );
		$this->commit();
	}

	/** Branches known on remotes after the last fetch, as "remotes/<remote>/<branch>". */
	public function remote_branches(): array {
		return $this->q( 'SELECT name, hash, latest_commit_message FROM dolt_remote_branches ORDER BY name' );
	}

	public function fetch( string $remote ): void {
		$this->q( 'CALL dolt_fetch(?)', array( $remote ) );
		$this->commit();
	}

	/** Push one branch; returns Dolt's message. */
	public function push( string $remote, string $branch ): string {
		$r = $this->q( 'CALL dolt_push(?, ?)', array( $remote, $branch ) );
		$this->commit();
		return (string) ( $r[0]['message'] ?? '' );
	}

	public function branch_exists( string $name ): bool {
		return (bool) $this->var( 'SELECT 1 FROM dolt_branches WHERE name = ?', array( $name ) );
	}

	/** Create a local branch from any ref ("origin/feature", a hash, another branch). */
	public function branch_from( string $name, string $ref ): void {
		$this->exec( 'CALL dolt_branch(?, ?)', array( $name, $ref ) );
		$this->commit();
	}

	/** Fast-forward this connection's branch to a remote branch (pull on an unchanged local branch). */
	public function pull_remote( string $remote, string $branch ): string {
		$r = $this->q( 'CALL dolt_pull(?, ?)', array( $remote, $branch ) );
		$this->commit();
		return (string) ( $r[0]['message'] ?? '' );
	}
}
