<?php
/**
 * Login sessions shared across stage and every workspace.
 *
 * WordPress keeps session tokens in usermeta. A workspace is a branch of that
 * table, so a session started on stage would not exist in a workspace created
 * before it, and entering the workspace would log the person out. This stores
 * sessions on the staging branch for every request, whichever branch it runs on.
 */
defined( 'ABSPATH' ) || exit;

class WPBR_Session_Tokens extends WP_Session_Tokens {

	private static ?WPBR_Dolt $conn = null;

	private static function db(): WPBR_Dolt {
		if ( self::$conn === null ) {
			self::$conn = new WPBR_Dolt( WPBR_Workspaces::stage_branch() );
		}
		return self::$conn;
	}

	private static function table(): string {
		return self::db()->ident( $GLOBALS['wpdb']->usermeta );
	}

	/** All sessions for this user, from stage. */
	protected function get_sessions() {
		$raw = self::db()->var( 'SELECT meta_value FROM ' . self::table() . " WHERE user_id = ? AND meta_key = 'session_tokens' LIMIT 1", array( (string) $this->user_id ) );
		$all = is_string( $raw ) && $raw !== '' ? maybe_unserialize( $raw ) : array();
		if ( ! is_array( $all ) ) {
			return array();
		}
		$all = array_map( array( $this, 'prepare_session' ), $all );
		return array_filter( $all, array( $this, 'is_still_valid' ) );
	}

	protected function prepare_session( $session ) {
		return is_int( $session ) ? array( 'expiration' => $session ) : $session;
	}

	protected function get_session( $verifier ) {
		$sessions = $this->get_sessions();
		return $sessions[ $verifier ] ?? null;
	}

	protected function update_session( $verifier, $session = null ) {
		$sessions = $this->get_sessions();
		if ( $session ) {
			$sessions[ $verifier ] = $session;
		} else {
			unset( $sessions[ $verifier ] );
		}
		$this->update_sessions( $sessions );
	}

	protected function update_sessions( $sessions ) {
		$db    = self::db();
		$table = self::table();
		$uid   = (string) $this->user_id;
		if ( $sessions ) {
			$value  = serialize( $sessions ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the format WordPress itself uses for this meta key.
			$exists = $db->var( 'SELECT umeta_id FROM ' . $table . " WHERE user_id = ? AND meta_key = 'session_tokens' LIMIT 1", array( $uid ) );
			if ( $exists ) {
				$db->exec( 'UPDATE ' . $table . " SET meta_value = ? WHERE user_id = ? AND meta_key = 'session_tokens'", array( $value, $uid ) );
			} else {
				$db->exec( 'INSERT INTO ' . $table . " (user_id, meta_key, meta_value) VALUES (?, 'session_tokens', ?)", array( $uid, $value ) );
			}
		} else {
			$db->exec( 'DELETE FROM ' . $table . " WHERE user_id = ? AND meta_key = 'session_tokens'", array( $uid ) );
		}
	}

	protected function destroy_other_sessions( $verifier ) {
		$session = $this->get_session( $verifier );
		$this->update_sessions( $session ? array( $verifier => $session ) : array() );
	}

	protected function destroy_all_sessions() {
		$this->update_sessions( array() );
	}

	public static function drop_sessions() {
		self::db()->exec( 'DELETE FROM ' . self::table() . " WHERE meta_key = 'session_tokens'" );
	}
}
