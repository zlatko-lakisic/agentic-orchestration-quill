<?php
/**
 * Activation: network tables, worker role and worker user.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Install {

	const ROLE        = 'aoq_worker';
	const CAP         = 'aoq_run_worker';
	const USER_LOGIN  = 'aoq-worker';
	const USER_OPTION = 'aoq_worker_user_id';

	/**
	 * Activation hook.
	 *
	 * @param bool $network_wide Whether activated network wide.
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && ! $network_wide ) {
			wp_die( esc_html__( 'AO Quill must be network activated.', 'ao-quill' ) );
		}
		self::install();
	}

	/**
	 * Create or upgrade everything. Safe to run more than once.
	 */
	public static function install() {
		self::create_tables();
		$switched = false;
		if ( is_multisite() && get_current_blog_id() !== get_main_site_id() ) {
			switch_to_blog( get_main_site_id() );
			$switched = true;
		}
		self::ensure_role();
		self::ensure_worker_user();
		if ( $switched ) {
			restore_current_blog();
		}
		update_site_option( 'aoq_db_version', AOQ_DB_VERSION );
	}

	/**
	 * Network tables (base prefix, shared by all sites).
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->base_prefix;

		dbDelta(
			"CREATE TABLE {$p}aoq_runs (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id char(36) NOT NULL,
  run_date date NOT NULL,
  run_trigger varchar(16) NOT NULL,
  status varchar(16) NOT NULL,
  claimed_at datetime NOT NULL,
  lease_expires_at datetime NOT NULL,
  finished_at datetime NULL,
  committed tinyint(1) NOT NULL DEFAULT 0,
  failure_reason text NULL,
  settings_snapshot longtext NULL,
  result longtext NULL,
  report longtext NULL,
  checkpoint longtext NULL,
  resumed_from char(36) NULL,
  worker_version varchar(40) NOT NULL DEFAULT '',
  ao_engine_version varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY run_id (run_id),
  KEY run_date_status (run_date,status)
) $charset;"
		);
		dbDelta(
			"CREATE TABLE {$p}aoq_stages (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id char(36) NOT NULL,
  stage varchar(32) NOT NULL,
  language varchar(8) NOT NULL DEFAULT '',
  attempt int(11) NOT NULL DEFAULT 1,
  status varchar(16) NOT NULL,
  started_at datetime NOT NULL,
  duration_ms bigint(20) NOT NULL DEFAULT 0,
  agents longtext NULL,
  scores longtext NULL,
  revision_count int(11) NOT NULL DEFAULT 0,
  failure_reason text NULL,
  detail longtext NULL,
  PRIMARY KEY  (id),
  KEY run_id (run_id)
) $charset;"
		);
		dbDelta(
			"CREATE TABLE {$p}aoq_translations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  group_id char(36) NOT NULL,
  blog_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  language varchar(8) NOT NULL,
  run_id char(36) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY blog_post (blog_id,post_id),
  KEY group_id (group_id)
) $charset;"
		);
		dbDelta(
			"CREATE TABLE {$p}aoq_profiles (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(16) NOT NULL,
  language varchar(8) NOT NULL DEFAULT '',
  fingerprint longtext NOT NULL,
  data longtext NOT NULL,
  built_at datetime NOT NULL,
  run_id char(36) NOT NULL DEFAULT '',
  PRIMARY KEY  (id),
  UNIQUE KEY kind_language (kind,language)
) $charset;"
		);
	}

	/**
	 * Worker role on the main site: only the capability the abilities check.
	 */
	public static function ensure_role() {
		$role = get_role( self::ROLE );
		if ( ! $role ) {
			add_role( self::ROLE, __( 'AO Quill worker', 'ao-quill' ), array( self::CAP => true ) );
			return;
		}
		foreach ( array_keys( $role->capabilities ) as $cap ) {
			if ( self::CAP !== $cap ) {
				$role->remove_cap( $cap );
			}
		}
		$role->add_cap( self::CAP );
	}

	/**
	 * Dedicated worker user. It never authors posts and has no other role anywhere.
	 *
	 * @return int User ID.
	 */
	public static function ensure_worker_user() {
		$user = get_user_by( 'login', self::USER_LOGIN );
		if ( ! $user ) {
			$user_id = wp_insert_user(
				array(
					'user_login'   => self::USER_LOGIN,
					'user_pass'    => wp_generate_password( 64, true, true ),
					'user_email'   => 'aoq-worker@example.invalid',
					'display_name' => AOQ_NAME . ' worker',
					'role'         => '',
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return 0;
			}
			$user = get_user_by( 'id', $user_id );
		}
		if ( is_multisite() ) {
			add_user_to_blog( get_main_site_id(), $user->ID, self::ROLE );
		}
		$user = new WP_User( $user->ID, '', get_main_site_id() );
		$user->set_role( self::ROLE );
		update_site_option( self::USER_OPTION, (int) $user->ID );
		return (int) $user->ID;
	}

	/**
	 * Worker user ID, or 0.
	 *
	 * @return int
	 */
	public static function worker_user_id() {
		return (int) get_site_option( self::USER_OPTION, 0 );
	}
}
