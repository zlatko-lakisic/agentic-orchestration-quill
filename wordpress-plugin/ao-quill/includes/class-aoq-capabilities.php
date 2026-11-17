<?php
/**
 * Capability status (shipped, partial, planned), read from capabilities.json.
 * docs/capability-status.md mirrors the same file; a test keeps them in sync.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Capabilities {

	/**
	 * All capabilities.
	 *
	 * @return array<int, array{id:string, label:string, status:string, reason:string, ao?:string}>
	 */
	public static function all() {
		static $all = null;
		if ( null === $all ) {
			$all = json_decode( (string) file_get_contents( AOQ_DIR . '/capabilities.json' ), true );
		}
		return $all;
	}

	/**
	 * One capability by ID.
	 *
	 * @param string $id Capability ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $cap ) {
			if ( $cap['id'] === $id ) {
				return $cap;
			}
		}
		return null;
	}
}
