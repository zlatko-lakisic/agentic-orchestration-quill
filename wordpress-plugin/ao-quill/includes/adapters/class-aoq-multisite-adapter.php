<?php
/**
 * Multisite per language: each site in the network is one language, the main site
 * is the primary language. Nothing links posts across sites today, so translation
 * groups for posts this plugin creates live in a network table. Terms are per site
 * and have no known translations.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Multisite_Adapter implements AOQ_Translation_Adapter {

	/** @var array|null */
	private $languages = null;

	/**
	 * Table holding translation groups.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->base_prefix . 'aoq_translations';
	}

	/**
	 * Language code from a locale: en_US -> en, bs_BA -> bs.
	 *
	 * @param string $locale Locale.
	 * @return string
	 */
	public static function code_from_locale( $locale ) {
		$parts = preg_split( '/[_-]/', strtolower( (string) $locale ) );
		return $parts[0] ? $parts[0] : 'en';
	}

	/**
	 * Clear the per-request cache (settings changes, tests).
	 */
	public function reset() {
		$this->languages = null;
	}

	public function languages() {
		if ( null !== $this->languages ) {
			return $this->languages;
		}
		$excluded = AOQ_Settings::get()['excluded_blog_ids'];
		$main     = (int) get_main_site_id();
		$out      = array();
		$sites    = get_sites(
			array(
				'number'   => 500,
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			)
		);
		foreach ( $sites as $site ) {
			$blog_id = (int) $site->blog_id;
			$locale  = (string) get_blog_option( $blog_id, 'WPLANG', '' );
			if ( '' === $locale ) {
				$locale = (string) get_site_option( 'WPLANG', '' );
			}
			if ( '' === $locale ) {
				$locale = 'en_US';
			}
			$code  = self::code_from_locale( $locale );
			$name  = class_exists( 'Locale' ) ? (string) Locale::getDisplayLanguage( $locale, 'en' ) : $code;
			$out[] = array(
				'code'     => $code,
				'locale'   => $locale,
				'name'     => '' !== $name ? $name : $code,
				'blog_id'  => $blog_id,
				'url'      => get_home_url( $blog_id, '/' ),
				'primary'  => $blog_id === $main,
				'excluded' => $blog_id !== $main && in_array( $blog_id, $excluded, true ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $a['primary'] === $b['primary'] ? $a['blog_id'] <=> $b['blog_id'] : ( $a['primary'] ? -1 : 1 );
			}
		);
		$this->languages = $out;
		return $out;
	}

	/**
	 * Languages the pipeline writes to (not excluded).
	 *
	 * @return array
	 */
	public function active_languages() {
		return array_values(
			array_filter(
				$this->languages(),
				static function ( $l ) {
					return ! $l['excluded'];
				}
			)
		);
	}

	/**
	 * Problems that make the language setup unusable, for example two active
	 * sites resolving to the same language.
	 *
	 * @return string[]
	 */
	public function problems() {
		$problems = array();
		$seen     = array();
		foreach ( $this->active_languages() as $lang ) {
			if ( isset( $seen[ $lang['code'] ] ) ) {
				$problems[] = sprintf(
					/* translators: 1: language code, 2: first site ID, 3: second site ID */
					__( 'Sites %2$d and %3$d both resolve to language "%1$s". Set the site language on one of them or exclude it.', 'ao-quill' ),
					$lang['code'],
					$seen[ $lang['code'] ],
					$lang['blog_id']
				);
			} else {
				$seen[ $lang['code'] ] = $lang['blog_id'];
			}
		}
		return $problems;
	}

	/**
	 * Find a language row by code.
	 *
	 * @param string $code Language code.
	 * @return array|null
	 */
	public function language( $code ) {
		foreach ( $this->active_languages() as $lang ) {
			if ( $lang['code'] === $code ) {
				return $lang;
			}
		}
		return null;
	}

	/**
	 * Language row by site ID.
	 *
	 * @param int $blog_id Site ID.
	 * @return array|null
	 */
	public function language_for_blog( $blog_id ) {
		foreach ( $this->languages() as $lang ) {
			if ( (int) $lang['blog_id'] === (int) $blog_id ) {
				return $lang;
			}
		}
		return null;
	}

	public function primary_language() {
		foreach ( $this->languages() as $lang ) {
			if ( $lang['primary'] ) {
				return $lang['code'];
			}
		}
		return 'en';
	}

	public function post_language( $blog_id, $post_id ) {
		$lang = $this->language_for_blog( $blog_id );
		return $lang ? $lang['code'] : null;
	}

	public function term_translations( $blog_id, $taxonomy, $term_id ) {
		// Terms are per site and the network stores no links between them.
		return array();
	}

	public function translation_group( $blog_id, $post_id ) {
		global $wpdb;
		$group = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT group_id FROM ' . self::table() . ' WHERE blog_id = %d AND post_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$blog_id,
				$post_id
			)
		);
		return $group ? (string) $group : null;
	}

	/**
	 * All translation groups keyed "blog:post" (one query for the inventory).
	 *
	 * @return array<string, string>
	 */
	public function all_groups() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT group_id, blog_id, post_id FROM ' . self::table(), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row['blog_id'] . ':' . $row['post_id'] ] = $row['group_id'];
		}
		return $out;
	}

	public function link_translations( array $posts, $run_id ) {
		global $wpdb;
		$group = wp_generate_uuid4();
		foreach ( $posts as $post ) {
			$ok = $wpdb->insert(
				self::table(),
				array(
					'group_id'   => $group,
					'blog_id'    => (int) $post['blog_id'],
					'post_id'    => (int) $post['post_id'],
					'language'   => (string) $post['language'],
					'run_id'     => (string) $run_id,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%d', '%d', '%s', '%s', '%s' )
			);
			if ( false === $ok ) {
				throw new RuntimeException( 'Could not store translation link: ' . $wpdb->last_error );
			}
		}
		return $group;
	}

	public function unlink_group( $group_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'group_id' => $group_id ), array( '%s' ) );
	}
}
