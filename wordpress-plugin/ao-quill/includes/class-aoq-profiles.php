<?php
/**
 * Cached site and style profiles built by AO, stored with the inventory
 * fingerprint they were built from. Keeps the worker stateless.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Profiles {

	/**
	 * Profiles table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->base_prefix . 'aoq_profiles';
	}

	/**
	 * Read all cached profiles.
	 *
	 * @return array get-profiles output.
	 */
	public static function read() {
		global $wpdb;
		$rows  = (array) $wpdb->get_results( 'SELECT * FROM ' . self::table(), ARRAY_A ); // phpcs:ignore
		$site  = null;
		$style = array();
		foreach ( $rows as $row ) {
			$record = array(
				'fingerprint' => json_decode( $row['fingerprint'], true ),
				'built_at'    => gmdate( 'c', strtotime( $row['built_at'] . ' UTC' ) ),
				'data'        => json_decode( $row['data'], true ),
			);
			if ( 'site' === $row['kind'] ) {
				$site = $record;
			} else {
				$style[] = array_merge( array( 'language' => $row['language'] ), $record );
			}
		}
		$out      = array(
			'site_profile'   => $site,
			'style_profiles' => $style,
		);
		$borrowed = self::borrowed();
		if ( $borrowed ) {
			$out['borrowed_style'] = $borrowed;
		}
		return $out;
	}

	/** Network option with the writing style borrowed from another AO Quill site. */
	const BORROWED_OPTION = 'aoq_borrowed_style';

	/**
	 * The borrowed writing style, or null.
	 *
	 * A site whose posts are all written by AO Quill has no human posts to learn a style from. It can
	 * borrow the style profiles of another site of the same company (built from that site's human
	 * posts); a run uses them for every language with too few human posts of its own.
	 *
	 * @return array|null {source, saved_at, profiles: [{language, data}]}
	 */
	public static function borrowed() {
		$b = get_site_option( self::BORROWED_OPTION, null );
		return is_array( $b ) && ! empty( $b['profiles'] ) ? $b : null;
	}

	/**
	 * This site's own style profiles (from its human posts), ready to give to another site.
	 *
	 * @return array {source, saved_at, profiles}
	 */
	public static function export_style() {
		$profiles = array();
		foreach ( self::read()['style_profiles'] as $p ) {
			$profiles[] = array(
				'language' => $p['language'],
				'data'     => $p['data'],
			);
		}
		return array(
			'source'   => (string) wp_parse_url( network_home_url(), PHP_URL_HOST ),
			'saved_at' => gmdate( 'c' ),
			'profiles' => $profiles,
		);
	}

	/**
	 * Store a borrowed writing style (export_style() of another site); an empty array removes it.
	 *
	 * @param array $style Export.
	 * @return array|WP_Error What was stored.
	 */
	public static function set_borrowed( array $style ) {
		if ( empty( $style['profiles'] ) ) {
			delete_site_option( self::BORROWED_OPTION );
			return array();
		}
		$profiles = array();
		foreach ( (array) $style['profiles'] as $p ) {
			if ( ! empty( $p['language'] ) && preg_match( '/^[a-z]{2,3}$/', (string) $p['language'] ) && is_array( $p['data'] ?? null ) ) {
				$profiles[] = array(
					'language' => (string) $p['language'],
					'data'     => $p['data'],
				);
			}
		}
		if ( ! $profiles ) {
			return new WP_Error( 'aoq_no_profiles', 'No style profiles in the export.' );
		}
		$stored = array(
			'source'   => substr( sanitize_text_field( (string) ( $style['source'] ?? 'another site' ) ), 0, 200 ),
			'saved_at' => gmdate( 'c', strtotime( (string) ( $style['saved_at'] ?? 'now' ) ) ?: time() ),
			'profiles' => $profiles,
		);
		update_site_option( self::BORROWED_OPTION, $stored );
		return $stored;
	}

	/**
	 * Store profiles (upsert by kind and language).
	 *
	 * @param array $input save-profiles input.
	 */
	public static function save( array $input ) {
		if ( ! empty( $input['site_profile'] ) ) {
			self::upsert( 'site', '', $input['site_profile'], $input['run_id'] );
		}
		foreach ( (array) $input['style_profiles'] as $profile ) {
			self::upsert( 'style', $profile['language'], $profile, $input['run_id'] );
		}
	}

	/**
	 * Insert or replace one profile row.
	 *
	 * @param string $kind     site or style.
	 * @param string $language Language code or ''.
	 * @param array  $record   Profile record.
	 * @param string $run_id   Run ID.
	 */
	private static function upsert( $kind, $language, array $record, $run_id ) {
		global $wpdb;
		$wpdb->replace(
			self::table(),
			array(
				'kind'        => $kind,
				'language'    => $language,
				'fingerprint' => wp_json_encode( $record['fingerprint'] ),
				'data'        => wp_json_encode( $record['data'] ),
				'built_at'    => gmdate( 'Y-m-d H:i:s', strtotime( $record['built_at'] ) ),
				'run_id'      => (string) $run_id,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
