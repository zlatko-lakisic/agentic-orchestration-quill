<?php
/**
 * Loads the JSON Schema contracts shipped in /contracts and validates values against them.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Schema {

	/** @var array<string, array> */
	private static $cache = array();

	/**
	 * Load a contract by relative name, for example "abilities/commit-post.input".
	 *
	 * @param string $name Contract name without the .json suffix.
	 * @return array JSON Schema as a PHP array, without the draft keywords WordPress ignores.
	 */
	public static function get( $name ) {
		if ( isset( self::$cache[ $name ] ) ) {
			return self::$cache[ $name ];
		}
		$path = AOQ_DIR . '/contracts/' . $name . '.json';
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'Missing contract ' . $name );
		}
		$schema = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $schema ) ) {
			throw new RuntimeException( 'Invalid contract ' . $name );
		}
		unset( $schema['$schema'], $schema['title'] );
		self::$cache[ $name ] = $schema;
		return $schema;
	}

	/**
	 * Enumerations shared with the worker (agent roles, thresholds, and so on).
	 *
	 * @return array<string, string[]>
	 */
	public static function enums() {
		static $enums = null;
		if ( null === $enums ) {
			$enums = json_decode( (string) file_get_contents( AOQ_DIR . '/contracts/enums.json' ), true );
		}
		return $enums;
	}

	/**
	 * Validate a value against a contract.
	 *
	 * @param mixed  $value Value decoded as associative arrays.
	 * @param string $name  Contract name.
	 * @return true|WP_Error
	 */
	public static function validate( $value, $name ) {
		return rest_validate_value_from_schema( $value, self::get( $name ), $name );
	}
}
