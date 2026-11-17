<?php
/**
 * Bootstrap and shared services.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Plugin {

	/** @var AOQ_Multisite_Adapter|null */
	private static $translation = null;

	/** @var AOQ_SEO_Adapter|null */
	private static $seo = null;

	/**
	 * Register hooks.
	 */
	public static function boot() {
		AOQ_Marking::init();
		AOQ_Abilities::init();
		AOQ_Instagram::init();
		AOQ_Language_Links::init();
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade' ) );
		if ( is_admin() ) {
			require_once AOQ_DIR . '/includes/admin/class-aoq-admin.php';
			AOQ_Admin::init();
			require_once AOQ_DIR . '/includes/admin/class-aoq-admin-instagram.php';
			AOQ_Admin_Instagram::init();
		}
	}

	/**
	 * Re-run install when the stored DB version is old.
	 */
	public static function maybe_upgrade() {
		if ( AOQ_DB_VERSION !== get_site_option( 'aoq_db_version' ) ) {
			AOQ_Install::install();
		}
	}

	/**
	 * Multilingual adapter for this install. Only multisite per language is
	 * implemented (docs/decisions.md D4).
	 *
	 * @return AOQ_Multisite_Adapter
	 */
	public static function translation_adapter() {
		if ( null === self::$translation ) {
			self::$translation = apply_filters( 'aoq_translation_adapter', new AOQ_Multisite_Adapter() );
		}
		return self::$translation;
	}

	/**
	 * SEO adapter. AIOSEO when its code is present, otherwise none. Activity is
	 * checked per site at write time.
	 *
	 * @return AOQ_SEO_Adapter
	 */
	public static function seo_adapter() {
		if ( null === self::$seo ) {
			$installed = file_exists( WP_PLUGIN_DIR . '/all-in-one-seo-pack/all_in_one_seo_pack.php' )
				|| file_exists( WP_PLUGIN_DIR . '/all-in-one-seo-pack-pro/all_in_one_seo_pack.php' );
			$adapter   = $installed ? new AOQ_AIOSEO_Adapter() : new AOQ_Null_SEO_Adapter();
			self::$seo = apply_filters( 'aoq_seo_adapter', $adapter );
		}
		return self::$seo;
	}

	/**
	 * Reset cached services (tests).
	 */
	public static function reset() {
		self::$translation = null;
		self::$seo         = null;
	}
}
