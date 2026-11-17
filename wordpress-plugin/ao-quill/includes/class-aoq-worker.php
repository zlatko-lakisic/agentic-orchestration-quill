<?php
/**
 * Worker presence. The worker is bundled with the plugin (worker/) but installed and
 * scheduled by hand on a server. WordPress cannot see that server, so it judges the
 * worker by its check-ins: every poll calls claim-run, which records a heartbeat.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

class AOQ_Worker {

	/** The worker polls every minute (cron); 15 quiet minutes means it is not running. */
	const RUNNING_WITHIN_SECONDS = 900;

	/**
	 * Path of the bundled worker, or null when the plugin was installed without it.
	 *
	 * @return string|null
	 */
	public static function bundled_dir() {
		$dir = AOQ_DIR . '/worker';
		return is_file( $dir . '/install.sh' ) ? $dir : null;
	}

	/**
	 * Version of the bundled worker, read from its package.
	 *
	 * @return string|null
	 */
	public static function bundled_version() {
		$dir = self::bundled_dir();
		if ( ! $dir ) {
			return null;
		}
		$init = $dir . '/src/aoq_worker/__init__.py';
		if ( ! is_readable( $init ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( preg_match( '/__version__\s*=\s*"([^"]+)"/', (string) file_get_contents( $init ), $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Worker state from the last heartbeat / busy broadcast.
	 *
	 * @param int|null $now Unix time override (tests).
	 * @return array{state:string, last_seen:?string, age_seconds:?int, version:?string, bundled_version:?string, version_matches:?bool, ao_reachable:?bool, ao_error:?string, busy:?array}
	 */
	public static function state( $now = null ) {
		$now     = null === $now ? time() : (int) $now;
		$hb      = get_site_option( AOQ_Runs::HEARTBEAT_OPTION, array() );
		$bundled = self::bundled_version();
		$busy    = AOQ_Runs::worker_busy( $now );
		if ( empty( $hb['at'] ) && ! $busy && ! (int) get_site_option( AOQ_Runs::CONTACT_OPTION, 0 ) ) {
			return array(
				'state'           => 'not_installed',
				'last_seen'       => null,
				'age_seconds'     => null,
				'version'         => null,
				'bundled_version' => $bundled,
				'version_matches' => null,
				'ao_reachable'    => null,
				'ao_error'        => null,
				'busy'            => null,
			);
		}
		// A long run reports progress but does not poll, so any report counts as a check-in.
		$seen = max(
			(int) ( ! empty( $hb['at'] ) ? strtotime( $hb['at'] ) : 0 ),
			(int) get_site_option( AOQ_Runs::CONTACT_OPTION, 0 )
		);
		$hb['at'] = $seen ? gmdate( 'c', $seen ) : null;
		$age      = $seen ? max( 0, $now - $seen ) : null;
		$version  = (string) ( $hb['worker_version'] ?? '' );
		$alive = null !== $age && $age <= self::RUNNING_WITHIN_SECONDS;
		$state = 'stopped';
		// Busy broadcast is for siblings: this site still shows Running while it has a local post run.
		if ( $alive && $busy && ! AOQ_Runs::current() ) {
			$state = 'busy';
		} elseif ( $alive ) {
			$state = 'running';
		}
		return array(
			'state'           => $state,
			'last_seen'       => $hb['at'],
			'age_seconds'     => $age,
			'version'         => $version,
			'bundled_version' => $bundled,
			'version_matches' => $bundled ? $bundled === $version : null,
			'ao_reachable'    => isset( $hb['ao_reachable'] ) ? (bool) $hb['ao_reachable'] : null,
			'ao_error'        => $hb['ao_error'] ?? null,
			'busy'            => $busy,
		);
	}

	/**
	 * Shell commands to install the bundled worker for this site.
	 *
	 * @return string[]
	 */
	public static function install_commands() {
		$dir = self::bundled_dir();
		$cmd = array(
			'wp --path=' . untrailingslashit( ABSPATH ) . ' user application-password create ' . AOQ_Install::USER_LOGIN . ' "ao-quill worker" --porcelain',
		);
		if ( $dir ) {
			$cmd[] = 'bash ' . $dir . '/install.sh --wp-url ' . untrailingslashit( set_url_scheme( network_home_url(), 'https' ) ) . ' --cron';
			if ( ! AOQ_AO_Connection::certificate_info()['present'] ) {
				$cmd[] = '~/.local/share/ao-quill-worker/run.sh enroll --ca-file /path/to/ao-ca.pem';
			}
		}
		return $cmd;
	}

	/**
	 * Human label for a state.
	 *
	 * @param string $state State id.
	 * @return string
	 */
	public static function label( $state ) {
		switch ( $state ) {
			case 'running':
				return __( 'Running', 'ao-quill' );
			case 'busy':
				return __( 'Busy', 'ao-quill' );
			case 'stopped':
				return __( 'Not checking in', 'ao-quill' );
			default:
				return __( 'Not installed', 'ao-quill' );
		}
	}

	/**
	 * Human detail for a busy state, e.g. "tgme: post run (qa)".
	 *
	 * @param array|null $busy Busy payload from state().
	 * @return string
	 */
	public static function busy_label( $busy ) {
		if ( ! is_array( $busy ) || empty( $busy['site'] ) ) {
			return '';
		}
		$task = (string) ( $busy['task'] ?? '' );
		$det  = trim( (string) ( $busy['detail'] ?? '' ) );
		$core = $det ? $busy['site'] . ': ' . $task . ' (' . $det . ')' : $busy['site'] . ': ' . $task;
		return $core;
	}
}
