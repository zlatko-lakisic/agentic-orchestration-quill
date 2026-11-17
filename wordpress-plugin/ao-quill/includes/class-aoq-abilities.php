<?php
/**
 * Every operation the worker needs, as WordPress abilities with strict input and
 * output schemas from /contracts and a capability check. Registered on the main
 * site only. Read-only abilities are flagged public for the MCP Adapter; write
 * abilities are not.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Abilities {

	const CATEGORY = 'ao-quill';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Ability category.
	 */
	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => AOQ_NAME,
				'description' => __( 'Operations used by the AO Quill worker.', 'ao-quill' ),
			)
		);
	}

	/**
	 * Definitions: name => [label, readonly, callback].
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			'claim-run'     => array( __( 'Claim a due run', 'ao-quill' ), false, array( __CLASS__, 'claim_run' ) ),
			'get-config'    => array( __( 'Get run config', 'ao-quill' ), true, array( __CLASS__, 'get_config' ) ),
			'get-inventory' => array( __( 'Get site inventory', 'ao-quill' ), true, array( __CLASS__, 'get_inventory' ) ),
			'get-profiles'  => array( __( 'Get cached profiles', 'ao-quill' ), true, array( __CLASS__, 'get_profiles' ) ),
			'save-profiles' => array( __( 'Save profiles', 'ao-quill' ), false, array( __CLASS__, 'save_profiles' ) ),
			'report-stage'  => array( __( 'Report a stage result', 'ao-quill' ), false, array( __CLASS__, 'report_stage' ) ),
			'commit-post'   => array( __( 'Commit the post bundle', 'ao-quill' ), false, array( __CLASS__, 'commit_post' ) ),
			'finish-run'    => array( __( 'Finish a run', 'ao-quill' ), false, array( __CLASS__, 'finish_run' ) ),
			'report-progress' => array( __( 'Report run progress and AO output', 'ao-quill' ), false, array( __CLASS__, 'report_progress' ) ),
			'save-checkpoint' => array( __( 'Save the run state after a step', 'ao-quill' ), false, array( __CLASS__, 'save_checkpoint' ) ),
			'report-instagram' => array( __( 'Report an Instagram caption job', 'ao-quill' ), false, array( __CLASS__, 'report_instagram' ) ),
			'report-worker-status' => array( __( 'Report shared-worker busy status', 'ao-quill' ), false, array( __CLASS__, 'report_worker_status' ) ),
			// These carry the AO client key: never readonly, so never public over MCP and always POST.
			'get-ao-connection'   => array( __( 'Get the AO connection (worker only)', 'ao-quill' ), false, array( __CLASS__, 'get_ao_connection' ) ),
			'save-ao-certificate' => array( __( 'Store the AO client certificate (worker only)', 'ao-quill' ), false, array( __CLASS__, 'save_ao_certificate' ) ),
		);
	}

	/**
	 * Register all abilities.
	 */
	public static function register() {
		if ( is_multisite() && ! is_main_site() ) {
			return;
		}
		foreach ( self::definitions() as $name => $def ) {
			list( $label, $readonly, $callback ) = $def;
			$input = AOQ_Schema::get( "abilities/$name.input" );
			if ( empty( $input['required'] ) ) {
				$input['default'] = array();
			}
			$meta = array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => $readonly,
					'destructive' => false,
					'idempotent'  => $readonly,
				),
			);
			if ( $readonly ) {
				$meta['mcp'] = array( 'public' => true );
			}
			wp_register_ability(
				"aoq/$name",
				array(
					'label'               => $label,
					'description'         => $label,
					'category'            => self::CATEGORY,
					'input_schema'        => $input,
					'output_schema'       => AOQ_Schema::get( "abilities/$name.output" ),
					'execute_callback'    => $callback,
					'permission_callback' => array( __CLASS__, 'can_run' ),
					'meta'                => $meta,
				)
			);
		}
	}

	/**
	 * Only the worker role (and super admins) may call these abilities.
	 *
	 * @return bool
	 */
	public static function can_run() {
		return current_user_can( AOQ_Install::CAP );
	}

	/**
	 * When a run ID is given to a read ability it must be active.
	 *
	 * @param array $input Input.
	 * @return string|null|WP_Error
	 */
	private static function optional_run( $input ) {
		$run_id = is_array( $input ) && ! empty( $input['run_id'] ) ? (string) $input['run_id'] : null;
		if ( null === $run_id ) {
			return null;
		}
		$run = AOQ_Runs::active( $run_id );
		return is_wp_error( $run ) ? $run : $run_id;
	}

	public static function get_ao_connection( $input = array() ) {
		return AOQ_AO_Connection::for_worker();
	}

	public static function save_ao_certificate( $input ) {
		$info = AOQ_AO_Connection::save_certificate( $input['cert_pem'], $input['key_pem'], $input['ca_pem'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		return array(
			'ok'         => true,
			'subject'    => $info['subject'],
			'expires_at' => $info['expires_at'],
		);
	}

	public static function report_progress( $input ) {
		$r = AOQ_Runs::report_progress( $input );
		return is_wp_error( $r ) ? $r : array( 'ok' => true );
	}

	public static function claim_run( $input ) {
		// An Instagram caption job (one short AO call) goes first when no post run is active.
		$job = AOQ_Instagram::claim_job( $input );
		if ( $job ) {
			AOQ_Runs::heartbeat( $input );
			return array( 'run' => null, 'instagram' => $job );
		}
		return array( 'run' => AOQ_Runs::claim( $input ) );
	}

	public static function report_instagram( $input ) {
		$ok = AOQ_Instagram::finish_job( $input );
		return is_wp_error( $ok ) ? $ok : array( 'ok' => true );
	}

	public static function report_worker_status( $input ) {
		AOQ_Runs::report_worker_status( is_array( $input ) ? $input : array() );
		return array( 'ok' => true );
	}

	public static function get_config( $input = array() ) {
		$run_id = self::optional_run( $input );
		if ( is_wp_error( $run_id ) ) {
			return $run_id;
		}
		return AOQ_Settings::worker_config( $run_id );
	}

	public static function get_inventory( $input ) {
		$run_id = self::optional_run( $input );
		if ( is_wp_error( $run_id ) ) {
			return $run_id;
		}
		$per_page = isset( $input['per_page'] ) ? max( 1, min( 200, (int) $input['per_page'] ) ) : 100;
		return AOQ_Inventory::page( max( 1, (int) $input['page'] ), $per_page );
	}

	public static function get_profiles( $input = array() ) {
		$run_id = self::optional_run( $input );
		if ( is_wp_error( $run_id ) ) {
			return $run_id;
		}
		return AOQ_Profiles::read();
	}

	public static function save_profiles( $input ) {
		$run = AOQ_Runs::active( $input['run_id'] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		AOQ_Profiles::save( $input );
		return array( 'ok' => true );
	}

	public static function report_stage( $input ) {
		$ok = AOQ_Runs::report_stage( $input );
		return is_wp_error( $ok ) ? $ok : array( 'ok' => true );
	}

	public static function commit_post( $input ) {
		return AOQ_Commit::commit( $input );
	}

	public static function save_checkpoint( $input ) {
		$ok = AOQ_Runs::save_checkpoint( $input );
		return is_wp_error( $ok ) ? $ok : array( 'ok' => true );
	}

	public static function finish_run( $input ) {
		$ok = AOQ_Runs::finish( $input );
		return is_wp_error( $ok ) ? $ok : array( 'ok' => true );
	}
}
