<?php
/**
 * Runs: claiming with a lease, stage log, finishing. The plugin owns the schedule;
 * the worker only asks whether a run is due.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Runs {

	const RUN_NOW_OPTION   = 'aoq_run_now';
	const TEST_OPTION      = 'aoq_test_run';
	const RESUME_OPTION    = 'aoq_resume_run';
	const HEARTBEAT_OPTION = 'aoq_worker_heartbeat';
	/** Last time the worker reported anything (a long run reports progress but does not poll). */
	const CONTACT_OPTION = 'aoq_worker_contact';
	/** Shared-worker busy presence broadcast (site + task), for sibling Status tabs. */
	const BUSY_OPTION = 'aoq_worker_busy';
	/** One-shot auto-resume queued after a scheduled QA failure: {run_id, from_step}. */
	const AUTO_RESUME_OPTION = 'aoq_auto_resume';
	/** Run IDs that already consumed their one automatic resume (map run_id => true). */
	const AUTO_RESUMED_OPTION = 'aoq_auto_resumed';
	/** Output kept per step for the readable step view, in bytes. */
	const STEP_LOG_MAX_BYTES = 150000; // room for one whole answer (up to 40,000 characters) plus its streamed copy
	const LIVE_PREFIX      = 'aoq_live_';
	/** Live output kept per run, in bytes (oldest output is dropped first). */
	const LIVE_MAX_BYTES = 300000;

	/**
	 * Runs table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->base_prefix . 'aoq_runs';
	}

	/**
	 * Stages table.
	 *
	 * @return string
	 */
	public static function stages_table() {
		global $wpdb;
		return $wpdb->base_prefix . 'aoq_stages';
	}

	/**
	 * Current time in the configured timezone.
	 *
	 * @param int|null $now Unix time override (tests).
	 * @return DateTimeImmutable
	 */
	public static function local_now( $now = null ) {
		$tz = new DateTimeZone( AOQ_Settings::get()['timezone'] );
		return ( new DateTimeImmutable( '@' . ( null === $now ? time() : (int) $now ) ) )->setTimezone( $tz );
	}

	/**
	 * Next scheduled run time, for the status panel.
	 *
	 * @return DateTimeImmutable
	 */
	public static function next_scheduled( $now = null ) {
		$local = self::local_now( $now );
		list( $h, $m ) = array_map( 'intval', explode( ':', AOQ_Settings::get()['schedule_time'] ) );
		$today = $local->setTime( $h, $m );
		$date = $local->format( 'Y-m-d' );
		if ( $local < $today || ! self::has_scheduled_for_date( $date ) ) {
			return $today;
		}
		return $today->modify( '+1 day' );
	}

	/**
	 * Whether the scheduled run already happened (in any outcome) on a local date.
	 *
	 * @param string $date Y-m-d.
	 * @return bool
	 */
	public static function has_scheduled_for_date( $date ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE run_date = %s AND run_trigger = 'scheduled'", $date ) ); // phpcs:ignore
	}

	/**
	 * Ask for a manual run on the next worker poll.
	 */
	public static function request_run_now() {
		update_site_option( self::RUN_NOW_OPTION, time() );
	}

	/**
	 * Ask for a test run (full pipeline, never committed) on the next worker poll.
	 */
	public static function request_test_run() {
		update_site_option( self::TEST_OPTION, time() );
	}

	/**
	 * Whether a test run was requested and not yet picked up.
	 *
	 * @return bool
	 */
	public static function test_requested() {
		return (int) get_site_option( self::TEST_OPTION, 0 ) > 0 || (bool) get_site_option( self::RESUME_OPTION, false );
	}

	/**
	 * The run currently claimed or running, if any.
	 *
	 * @return array|null
	 */
	public static function current() {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::table() . " WHERE status IN ('claimed','running') ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore
		return $row ? $row : null;
	}

	/**
	 * The run to show on the Generation tab: the active one, else the most recent.
	 *
	 * @return array|null
	 */
	public static function latest() {
		global $wpdb;
		$current = self::current();
		if ( $current ) {
			return $current;
		}
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT 1', ARRAY_A ); // phpcs:ignore
		return $row ? $row : null;
	}

	/**
	 * Whether a manual run was requested and not yet picked up.
	 *
	 * @return bool
	 */
	public static function run_now_requested() {
		return (int) get_site_option( self::RUN_NOW_OPTION, 0 ) > 0;
	}

	/**
	 * Most recent test run.
	 *
	 * @return array|null
	 */
	public static function last_test() {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::table() . " WHERE run_trigger = 'test' ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore
		return $row ? $row : null;
	}

	/**
	 * Store the worker heartbeat and the catalog it reported.
	 *
	 * @param array $input claim-run input.
	 */
	public static function heartbeat( array $input ) {
		update_site_option(
			self::HEARTBEAT_OPTION,
			array(
				'at'               => gmdate( 'c' ),
				'worker_version'   => $input['worker']['version'],
				'ao_reach_version' => $input['worker']['ao_reach_version'],
				'ao_reachable'     => (bool) $input['ao']['reachable'],
				'ao_error'         => $input['ao']['error'],
			)
		);
		if ( ! empty( $input['ao']['catalog'] ) ) {
			$catalog                = $input['ao']['catalog'];
			$catalog['reported_at'] = gmdate( 'c' );
			update_site_option( AOQ_Settings::CATALOG_OPTION, $catalog );
		}
	}

	/**
	 * Claim a run if one is due. Returns the run row or null.
	 *
	 * @param array    $input claim-run input.
	 * @param int|null $now   Unix time override (tests).
	 * @return array|null
	 */
	public static function claim( array $input, $now = null ) {
		global $wpdb;
		self::heartbeat( $input );
		$now = null === $now ? time() : (int) $now;

		if ( ! $wpdb->get_var( "SELECT GET_LOCK('aoq_claim', 10)" ) ) {
			return null;
		}
		try {
			self::expire_leases( $now );

			$settings = AOQ_Settings::get();
			$local    = self::local_now( $now );
			$date     = $local->format( 'Y-m-d' );
			list( $h, $m ) = array_map( 'intval', explode( ':', $settings['schedule_time'] ) );
			$scheduled_at  = $local->setTime( $h, $m );
			$run_now       = (int) get_site_option( self::RUN_NOW_OPTION, 0 ) > 0;

			// One run at a time, whatever its trigger. A worker only asks for a run when none is running
			// on its machine (run.sh holds a lock), so an active run seen here lost its worker: it crashed or
			// was stopped without calling finish-run. Fail it now instead of blocking runs until the lease ends.
			$orphan = self::current();
			if ( $orphan && ! empty( $input['idle'] ) ) {
				$wpdb->update(
					self::table(),
					array(
						'status'         => 'failed',
						'finished_at'    => gmdate( 'Y-m-d H:i:s', $now ),
						'failure_reason' => __( 'The worker stopped without finishing the run (it crashed or was stopped). See the worker log.', 'ao-quill' ),
					),
					array( 'run_id' => $orphan['run_id'] )
				);
				self::archive_live( $orphan['run_id'] );
				$orphan = null;
			}
			if ( $orphan ) {
				return null;
			}

			$trigger = null;
			$resume  = null;
			$request = get_site_option( self::RESUME_OPTION, false );
			$auto    = false;
			if ( ! is_array( $request ) ) {
				// One automatic resume per failed scheduled QA run (heading/structure failsafe).
				$request = self::take_auto_resume();
				$auto    = is_array( $request );
			}
			if ( is_array( $request ) ) {
				// A test run that continues an earlier one from its checkpoint.
				if ( ! $auto ) {
					delete_site_option( self::RESUME_OPTION );
				}
				$from = self::get( (string) $request['run_id'] );
				$cp   = $from ? self::checkpoint( $from ) : null;
				if ( $cp ) {
					// A resumed test run stays a test; a resumed scheduled or manual run is a manual
					// run and commits its post on its own (it must not count as a later day's scheduled run).
					$trigger = 'test' === $from['run_trigger'] ? 'test' : 'manual';
					$resume  = array(
						'resumed_from' => $from['run_id'],
						'from_step'    => (string) $request['from_step'],
						'state'        => (string) $cp['state_json'],
					);
					if ( $auto ) {
						self::mark_auto_resumed( $from['run_id'] );
					}
				}
			}
			if ( null !== $trigger ) {
				delete_site_option( self::TEST_OPTION );
			} elseif ( self::test_requested() ) {
				// Test runs do not count as the day's post and are never committed.
				$trigger = 'test';
				delete_site_option( self::TEST_OPTION );
			} else {
				// No daily cap: "Generate now" always runs, and the scheduled run happens once a day
				// whatever other posts were made that day (2026-11-14: two failed manual runs before
				// 07:00 skipped the scheduled one). A failed scheduled run is not retried, so it cannot loop.
				if ( $run_now ) {
					$trigger = 'manual';
				} elseif ( $local >= $scheduled_at && ! self::has_scheduled_for_date( $date ) ) {
					$trigger = 'scheduled';
				}
				if ( null === $trigger ) {
					return null;
				}
				delete_site_option( self::RUN_NOW_OPTION );
			}
			self::prune_live();

			$run_id   = wp_generate_uuid4();
			$problems = AOQ_Settings::problems();
			$lease    = gmdate( 'Y-m-d H:i:s', $now + 60 * (int) $settings['lease_minutes'] );
			$row      = array(
				'run_id'            => $run_id,
				'run_date'          => $date,
				'run_trigger'       => $trigger,
				'status'            => $problems ? 'failed' : 'claimed',
				'claimed_at'        => gmdate( 'Y-m-d H:i:s', $now ),
				'lease_expires_at'  => $lease,
				'finished_at'       => $problems ? gmdate( 'Y-m-d H:i:s', $now ) : null,
				'failure_reason'    => $problems ? __( 'Configuration problems: ', 'ao-quill' ) . implode( ' ', $problems ) : null,
				'settings_snapshot' => wp_json_encode( AOQ_Settings::worker_config( $run_id ) ),
				'worker_version'    => (string) $input['worker']['version'],
				'resumed_from'      => $resume ? $resume['resumed_from'] : null,
			);
			$wpdb->insert( self::table(), $row );
			if ( $problems ) {
				return null;
			}
			$out = array(
				'run_id'           => $run_id,
				'run_date'         => $date,
				'trigger'          => $trigger,
				'lease_expires_at' => gmdate( 'c', strtotime( $lease . ' UTC' ) ),
			);
			if ( $resume ) {
				$out['resume'] = $resume;
			}
			return $out;
		} finally {
			$wpdb->query( "SELECT RELEASE_LOCK('aoq_claim')" );
		}
	}

	/**
	 * Mark runs whose lease ran out as expired.
	 *
	 * @param int $now Unix time.
	 */
	public static function expire_leases( $now ) {
		global $wpdb;
		self::halt_stalled(); // real time: "nothing reported for N minutes" is about now
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET status = 'expired', finished_at = %s, failure_reason = %s WHERE status IN ('claimed','running') AND lease_expires_at < %s", // phpcs:ignore
				gmdate( 'Y-m-d H:i:s', $now ),
				__( 'The worker lease expired before the run finished.', 'ao-quill' ),
				gmdate( 'Y-m-d H:i:s', $now )
			)
		);
	}

	/**
	 * Run row by ID.
	 *
	 * @param string $run_id Run ID.
	 * @return array|null
	 */
	public static function get( $run_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE run_id = %s', $run_id ), ARRAY_A ); // phpcs:ignore
		return $row ? $row : null;
	}

	/**
	 * Run that the worker may still act on (claimed or running, lease valid).
	 *
	 * @param string $run_id Run ID.
	 * @return array|WP_Error
	 */
	public static function active( $run_id ) {
		$run = self::get( $run_id );
		if ( ! $run ) {
			return new WP_Error( 'aoq_unknown_run', __( 'Unknown run.', 'ao-quill' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $run['status'], array( 'claimed', 'running' ), true ) ) {
			return new WP_Error( 'aoq_run_closed', __( 'The run is not active.', 'ao-quill' ), array( 'status' => 409 ) );
		}
		if ( strtotime( $run['lease_expires_at'] . ' UTC' ) < time() ) {
			self::expire_leases( time() );
			return new WP_Error( 'aoq_lease_expired', __( 'The run lease expired.', 'ao-quill' ), array( 'status' => 409 ) );
		}
		return $run;
	}

	/**
	 * Append a stage result.
	 *
	 * @param array $input report-stage input.
	 * @return true|WP_Error
	 */
	public static function report_stage( array $input ) {
		global $wpdb;
		$run = self::active( $input['run_id'] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		self::renew_lease( $run );
		$wpdb->insert(
			self::stages_table(),
			array(
				'run_id'         => $input['run_id'],
				'stage'          => $input['stage'],
				'language'       => (string) $input['language'],
				'attempt'        => (int) $input['attempt'],
				'status'         => $input['status'],
				'started_at'     => gmdate( 'Y-m-d H:i:s', strtotime( $input['started_at'] ) ),
				'duration_ms'    => (int) $input['duration_ms'],
				'agents'         => wp_json_encode( $input['agents'] ),
				'scores'         => wp_json_encode( $input['scores'] ),
				'revision_count' => (int) $input['revision_count'],
				'failure_reason' => $input['failure_reason'],
				'detail'         => wp_json_encode( $input['detail'] ),
			)
		);
		return true;
	}

	/**
	 * Close a run.
	 *
	 * @param array $input finish-run input.
	 * @return true|WP_Error
	 */
	public static function finish( array $input ) {
		global $wpdb;
		$run = self::active( $input['run_id'] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		if ( 'succeeded' === $input['status'] && ! (int) $run['committed'] && 'test' !== $run['run_trigger'] ) {
			return new WP_Error( 'aoq_not_committed', __( 'A run cannot succeed without a commit.', 'ao-quill' ), array( 'status' => 409 ) );
		}
		$wpdb->update(
			self::table(),
			array(
				'status'            => $input['status'],
				'finished_at'       => gmdate( 'Y-m-d H:i:s' ),
				'failure_reason'    => $input['failure_reason'],
				'result'            => wp_json_encode( $input['result'] ),
				'ao_engine_version' => (string) ( $input['result']['ao']['engine_version'] ?? '' ),
			),
			array( 'run_id' => $input['run_id'] )
		);
		// The worker sends its last progress before finish-run, so the live data is final now.
		self::archive_live( $input['run_id'] );
		if ( 'failed' === $input['status'] ) {
			$finished = self::get( $input['run_id'] );
			self::maybe_queue_auto_resume( $finished );
			// An auto-resume that dies to infra (crash/stall) did not consume the one-shot.
			self::maybe_release_auto_resume_on_infra( $finished );
		}
		return true;
	}

	/**
	 * If this run was an auto-resume and failed because the worker crashed or stalled, allow the
	 * original run_id another automatic attempt (the one-shot was not a real QA retry).
	 *
	 * @param array|null $run Finished run row.
	 */
	public static function maybe_release_auto_resume_on_infra( $run ) {
		if ( ! is_array( $run ) || empty( $run['resumed_from'] ) || 'failed' !== ( $run['status'] ?? '' ) ) {
			return;
		}
		$reason = (string) ( $run['failure_reason'] ?? '' );
		$infra  = ( 0 === strpos( $reason, 'Stalled:' ) )
			|| ( 0 === strpos( $reason, 'Stopped:' ) )
			|| ( false !== strpos( $reason, 'The worker stopped without finishing' ) )
			|| ( 0 === strpos( $reason, 'The worker lease expired' ) );
		if ( ! $infra ) {
			return;
		}
		$done = get_site_option( self::AUTO_RESUMED_OPTION, array() );
		if ( ! is_array( $done ) || empty( $done[ $run['resumed_from'] ] ) ) {
			return;
		}
		unset( $done[ $run['resumed_from'] ] );
		update_site_option( self::AUTO_RESUMED_OPTION, $done );
		$from = self::get( $run['resumed_from'] );
		if ( $from ) {
			self::maybe_queue_auto_resume( $from );
		}
	}

	/**
	 * Whether a finished run's failure is a content/QA miss (eligible for one auto-resume), not
	 * infrastructure (stall, orphan worker, originality fetch errors, config).
	 *
	 * @param array $run Run row.
	 * @return bool
	 */
	public static function is_qa_auto_resume_failure( array $run ) {
		if ( 'scheduled' !== ( $run['run_trigger'] ?? '' ) || 'failed' !== ( $run['status'] ?? '' ) ) {
			return false;
		}
		$reason = (string) ( $run['failure_reason'] ?? '' );
		if ( '' === $reason ) {
			return false;
		}
		$infra = array(
			'Stalled:',
			'Stopped:',
			'The worker stopped without finishing',
			'Sources could not be fetched for the originality check',
			'Configuration problems:',
			'The worker lease expired',
			'AO catalog unreachable',
			'AO does not provide:',
		);
		foreach ( $infra as $prefix ) {
			if ( 0 === strpos( $reason, $prefix ) || false !== strpos( $reason, $prefix ) ) {
				return false;
			}
		}
		// Prefer explicit QA / gate wording; also accept GateFailure-style stage prefixes from the worker.
		return (bool) preg_match( '/QA below threshold|GateFailure|structure|heading/i', $reason )
			|| (bool) preg_match( '/^qa[:\s]/i', $reason );
	}

	/**
	 * Choose the step for an automatic resume: prefer qa, else draft, else the last resumable step.
	 *
	 * @param array $run Failed run row.
	 * @return string|null
	 */
	public static function auto_resume_step( array $run ) {
		$steps = self::resumable_steps( $run );
		if ( ! $steps ) {
			return null;
		}
		foreach ( array( 'qa', 'draft' ) as $want ) {
			if ( in_array( $want, $steps, true ) ) {
				return $want;
			}
		}
		return end( $steps );
	}

	/**
	 * Queue one auto-resume for a failed scheduled QA run, if it has not had one yet.
	 *
	 * @param array|null $run Run row after finish.
	 */
	public static function maybe_queue_auto_resume( $run ) {
		if ( ! is_array( $run ) || ! self::is_qa_auto_resume_failure( $run ) ) {
			return;
		}
		if ( self::was_auto_resumed( $run['run_id'] ) ) {
			return;
		}
		$pending = get_site_option( self::AUTO_RESUME_OPTION, false );
		if ( is_array( $pending ) ) {
			return; // one pending at a time
		}
		$step = self::auto_resume_step( $run );
		if ( ! $step ) {
			return;
		}
		update_site_option(
			self::AUTO_RESUME_OPTION,
			array(
				'run_id'    => $run['run_id'],
				'from_step' => $step,
			)
		);
	}

	/**
	 * Consume a pending auto-resume request, or backfill one for a recent failed scheduled QA run
	 * that finished before this failsafe existed (e.g. TGMe 784cba74).
	 *
	 * @return array{run_id:string, from_step:string}|null
	 */
	public static function take_auto_resume() {
		$pending = get_site_option( self::AUTO_RESUME_OPTION, false );
		if ( is_array( $pending ) && ! empty( $pending['run_id'] ) && ! empty( $pending['from_step'] ) ) {
			delete_site_option( self::AUTO_RESUME_OPTION );
			if ( self::was_auto_resumed( $pending['run_id'] ) ) {
				return null;
			}
			$from = self::get( (string) $pending['run_id'] );
			if ( $from && in_array( (string) $pending['from_step'], self::resumable_steps( $from ), true ) ) {
				return array(
					'run_id'    => (string) $pending['run_id'],
					'from_step' => (string) $pending['from_step'],
				);
			}
		}
		// Backfill: oldest eligible failed scheduled run not yet auto-resumed.
		foreach ( self::recent( 50 ) as $row ) {
			if ( ! self::is_qa_auto_resume_failure( $row ) || self::was_auto_resumed( $row['run_id'] ) ) {
				continue;
			}
			$step = self::auto_resume_step( $row );
			if ( ! $step ) {
				continue;
			}
			return array(
				'run_id'    => $row['run_id'],
				'from_step' => $step,
			);
		}
		return null;
	}

	/**
	 * @param string $run_id Run ID.
	 * @return bool
	 */
	public static function was_auto_resumed( $run_id ) {
		$done = get_site_option( self::AUTO_RESUMED_OPTION, array() );
		return is_array( $done ) && ! empty( $done[ $run_id ] );
	}

	/**
	 * @param string $run_id Failed run that received its one auto-resume.
	 */
	public static function mark_auto_resumed( $run_id ) {
		$done             = get_site_option( self::AUTO_RESUMED_OPTION, array() );
		$done             = is_array( $done ) ? $done : array();
		$done[ $run_id ] = true;
		// Keep the map bounded.
		if ( count( $done ) > 200 ) {
			$done = array_slice( $done, -100, null, true );
		}
		update_site_option( self::AUTO_RESUMED_OPTION, $done );
	}

	/**
	 * Pending auto-resume for a run, if any.
	 *
	 * @param string $run_id Run ID.
	 * @return bool
	 */
	public static function auto_resume_pending_for( $run_id ) {
		$pending = get_site_option( self::AUTO_RESUME_OPTION, false );
		return is_array( $pending ) && (string) ( $pending['run_id'] ?? '' ) === (string) $run_id;
	}

	/**
	 * Store a shared-worker busy report (or clear it). Counts as a check-in.
	 *
	 * @param array $input report-worker-status input.
	 * @return true
	 */
	public static function report_worker_status( array $input ) {
		update_site_option( self::CONTACT_OPTION, time() );
		$busy = $input['busy'] ?? null;
		if ( null === $busy || false === $busy || ( is_array( $busy ) && empty( $busy['site'] ) && empty( $busy['task'] ) ) ) {
			delete_site_option( self::BUSY_OPTION );
			return true;
		}
		update_site_option(
			self::BUSY_OPTION,
			array(
				'site'       => (string) ( $busy['site'] ?? '' ),
				'task'       => (string) ( $busy['task'] ?? '' ),
				'detail'     => (string) ( $busy['detail'] ?? '' ),
				'started_at' => (string) ( $busy['started_at'] ?? gmdate( 'c' ) ),
				'updated_at' => gmdate( 'c' ),
			)
		);
		return true;
	}

	/**
	 * Last busy payload, or null when idle / stale.
	 *
	 * @param int|null $now Unix time.
	 * @return array{site:string, task:string, detail:string, started_at:string, updated_at:string}|null
	 */
	public static function worker_busy( $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$busy = get_site_option( self::BUSY_OPTION, null );
		if ( ! is_array( $busy ) || empty( $busy['updated_at'] ) ) {
			return null;
		}
		$age = $now - (int) strtotime( $busy['updated_at'] );
		if ( $age > AOQ_Worker::RUNNING_WITHIN_SECONDS ) {
			return null;
		}
		return $busy;
	}

	/** Minutes of silence beyond the per-call AO limit after which a run counts as stalled. */
	const STALL_GRACE_MINUTES = 10;

	/** A worker that sends a heartbeat (every minute) and is silent this long has stopped. */
	const HEARTBEAT_STALL_MINUTES = 10;

	/**
	 * When a running run last heard from its worker, and how long silence may last before the run
	 * counts as stalled: 10 minutes for workers that send a heartbeat, one AO call's time limit
	 * plus STALL_GRACE_MINUTES for older ones.
	 *
	 * @param array $run Run row.
	 * @return array{last:int, limit:int, heartbeat:bool}
	 */
	public static function silence( array $run ) {
		$last = (int) strtotime( $run['claimed_at'] . ' UTC' );
		$live = self::live_data( $run['run_id'] );
		if ( ! empty( $live['updated_at'] ) ) {
			$last = max( $last, (int) strtotime( $live['updated_at'] ) );
		}
		foreach ( self::stages( $run['run_id'] ) as $row ) {
			$last = max( $last, (int) strtotime( $row['started_at'] . ' UTC' ) + (int) round( $row['duration_ms'] / 1000 ) );
		}
		$beat = ! empty( $live['heartbeat'] );
		return array(
			'last'      => $last,
			'limit'     => $beat ? 60 * self::HEARTBEAT_STALL_MINUTES : (int) AOQ_Settings::get()['stage_timeout_seconds'] + 60 * self::STALL_GRACE_MINUTES,
			'heartbeat' => $beat,
		);
	}

	/**
	 * Mark a run that lost its worker as failed now (the Post generation tab's "Mark as stopped").
	 *
	 * @param string $run_id Run.
	 * @return bool Whether a running run was closed.
	 */
	public static function mark_stopped( $run_id ) {
		global $wpdb;
		$run = self::get( $run_id );
		if ( ! $run || ! in_array( $run['status'], array( 'claimed', 'running' ), true ) || (int) $run['committed'] ) {
			return false;
		}
		$wpdb->update(
			self::table(),
			array(
				'status'         => 'failed',
				'finished_at'    => gmdate( 'Y-m-d H:i:s' ),
				'failure_reason' => __( 'Stopped: its worker stopped reporting, and the run was marked as stopped from the Post generation tab. Resume it from the step where it stopped.', 'ao-quill' ),
			),
			array( 'run_id' => $run_id )
		);
		self::archive_live( $run_id );
		return true;
	}

	/**
	 * Halt runs that stopped making progress: silent for longer than silence() allows (10 minutes for a
	 * worker that sends a heartbeat, else one AO call's time limit plus STALL_GRACE_MINUTES). Checked on every worker check-in and
	 * every Generation tab refresh, so a stuck run does not block others until its lease ends.
	 *
	 * @param int|null $now Unix time.
	 */
	public static function halt_stalled( $now = null ) {
		global $wpdb;
		$now = null === $now ? time() : (int) $now;
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE status IN ('claimed','running')", ARRAY_A ) as $run ) { // phpcs:ignore
			$s     = self::silence( $run );
			$last  = $s['last'];
			$limit = $s['limit'];
			if ( $now - $last > $limit ) {
				$wpdb->update(
					self::table(),
					array(
						'status'         => 'failed',
						'finished_at'    => gmdate( 'Y-m-d H:i:s', $now ),
						/* translators: %d: minutes */
						'failure_reason' => sprintf( __( 'Stalled: the run reported nothing for %d minutes, longer than one AO call may take. Halted by WordPress.', 'ao-quill' ), (int) round( ( $now - $last ) / 60 ) ),
					),
					array( 'run_id' => $run['run_id'] )
				);
				self::archive_live( $run['run_id'] );
			}
		}
	}

	/** Steps a test run can be resumed from: the ones after reading the site and the profiles. */
	const RESUMABLE_FROM = array( 'topics', 'novelty', 'research', 'outline', 'draft', 'taxonomy', 'images', 'qa', 'translate', 'commit' );

	/**
	 * Store the run's state after a step.
	 *
	 * @param array $input save-checkpoint input.
	 * @return true|WP_Error
	 */
	public static function save_checkpoint( array $input ) {
		global $wpdb;
		$run = self::active( $input['run_id'] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		self::renew_lease( $run );
		$wpdb->update(
			self::table(),
			array(
				'checkpoint' => wp_json_encode(
					array(
						'step'      => $input['step'],
						'completed' => array_values( $input['completed'] ),
						'state_json' => (string) $input['state'], // kept as sent: decoding would turn {} into []
						'saved_at'   => gmdate( 'c' ),
					)
				),
			),
			array( 'run_id' => $input['run_id'] )
		);
		return true;
	}

	/**
	 * A run's saved checkpoint.
	 *
	 * @param array $run Run row.
	 * @return array{step:string, completed:string[], state:array, saved_at:string}|null
	 */
	public static function checkpoint( array $run ) {
		$cp = json_decode( (string) ( $run['checkpoint'] ?? '' ), true );
		if ( ! is_array( $cp ) ) {
			return null;
		}
		if ( isset( $cp['state_json'] ) ) {
			$cp['state'] = json_decode( (string) $cp['state_json'], true );
		} elseif ( isset( $cp['state'] ) ) {
			$cp['state_json'] = wp_json_encode( $cp['state'] ); // saved before state was kept as text
		}
		return is_array( $cp['state'] ?? null ) ? $cp : null;
	}

	/**
	 * Steps a finished test run can be resumed from: from Find topics up to the first step
	 * it did not complete. Empty when it cannot be resumed.
	 *
	 * @param array $run Run row.
	 * @return string[]
	 */
	public static function resumable_steps( array $run ) {
		$cp = self::checkpoint( $run );
		// Test runs, and scheduled or manual runs that did not succeed. A resumed test run stays a
		// test; a resumed scheduled or manual run publishes its post on its own.
		$done = 'test' !== $run['run_trigger'] && 'succeeded' === $run['status'];
		if ( ! $cp || $done || in_array( $run['status'], array( 'claimed', 'running' ), true ) ) {
			return array();
		}
		$out = array();
		foreach ( self::RESUMABLE_FROM as $step ) {
			$out[] = $step;
			if ( ! in_array( $step, $cp['completed'], true ) ) {
				break; // the first step not completed is the last one to offer
			}
		}
		return $out;
	}

	/**
	 * Ask the worker to resume a test run from a step (picked up on its next poll).
	 *
	 * @param string $run_id    Run to resume.
	 * @param string $from_step Step to start from.
	 * @return true|WP_Error
	 */
	public static function request_resume( $run_id, $from_step ) {
		$run = self::get( $run_id );
		if ( ! $run || ! in_array( $from_step, self::resumable_steps( $run ), true ) ) {
			return new WP_Error( 'aoq_not_resumable', __( 'This run cannot be resumed from that step.', 'ao-quill' ) );
		}
		if ( self::current() || self::run_now_requested() || self::test_requested() ) {
			return new WP_Error( 'aoq_busy', __( 'Another run is active or waiting.', 'ao-quill' ) );
		}
		update_site_option( self::RESUME_OPTION, array( 'run_id' => $run_id, 'from_step' => $from_step ) );
		// Manual resume consumes the one-shot auto-resume for this run.
		$pending = get_site_option( self::AUTO_RESUME_OPTION, false );
		if ( is_array( $pending ) && (string) ( $pending['run_id'] ?? '' ) === (string) $run_id ) {
			delete_site_option( self::AUTO_RESUME_OPTION );
		}
		self::mark_auto_resumed( $run_id );
		return true;
	}

	/**
	 * Keep a run's live data (AO output, step log, timings) for good, in its report column.
	 * The live option itself is pruned to recent runs; Past runs reads the archive.
	 *
	 * @param string $run_id Run ID.
	 */
	public static function archive_live( $run_id ) {
		global $wpdb;
		$live = get_site_option( self::LIVE_PREFIX . $run_id, null );
		if ( ! is_array( $live ) || ! $live ) {
			return;
		}
		$wpdb->update( self::table(), array( 'report' => wp_json_encode( $live ) ), array( 'run_id' => $run_id ) );
	}

	/**
	 * Live data of a finished or running run for display: the archived report when there is
	 * one, otherwise the live option (same shape as live_data()).
	 *
	 * @param array $run Run row.
	 * @return array
	 */
	public static function history_live( array $run ) {
		$report = json_decode( (string) ( $run['report'] ?? '' ), true );
		if ( is_array( $report ) && $report ) {
			return array_merge( self::live_data( '' ), $report );
		}
		return self::live_data( $run['run_id'] );
	}

	/**
	 * Extend the lease of an active run and mark it running. Every report from the
	 * worker calls this, so a slow AO never expires a run that is still progressing.
	 *
	 * @param array $run Run row.
	 */
	private static function renew_lease( array $run ) {
		global $wpdb;
		update_site_option( self::CONTACT_OPTION, time() );
		$wpdb->update(
			self::table(),
			array(
				'status'           => 'running',
				'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 * (int) AOQ_Settings::get()['lease_minutes'] ),
			),
			array( 'run_id' => $run['run_id'] )
		);
	}

	/**
	 * Store live progress and AO output for a run.
	 *
	 * @param array $input report-progress input.
	 * @return true|WP_Error
	 */
	public static function report_progress( array $input ) {
		$run = self::active( $input['run_id'] );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		self::renew_lease( $run );
		$live = self::live_data( $input['run_id'] );
		if ( (string) $input['label'] !== $live['label'] || null === $live['label_since'] ) {
			$live['label_since'] = gmdate( 'c' ); // when the current step started, for the elapsed timer
		}
		$live['step']       = (int) $input['step'];
		$live['steps']      = (int) $input['steps'];
		$live['label']      = (string) $input['label'];
		$live['updated_at'] = gmdate( 'c' );
		if ( ! empty( $input['heartbeat'] ) ) {
			$live['heartbeat'] = true; // this worker reports every minute: a long silence means it stopped
		}
		foreach ( $input['output'] as $o ) {
			$step = AOQ_Steps::step_for( (string) ( $o['step'] ?? $input['label'] ) );
			$log  = $live['step_log'][ $step ] ?? array();
			$n    = count( $log );
			if ( $n && $log[ $n - 1 ]['task'] === $o['task'] && $log[ $n - 1 ]['agent'] === $o['agent'] ) {
				$log[ $n - 1 ]['text'] .= (string) $o['text'];
			} else {
				$log[] = array(
					'task'  => (string) $o['task'],
					'agent' => (string) $o['agent'],
					'text'  => (string) $o['text'],
				);
			}
			while ( count( $log ) > 1 && strlen( (string) wp_json_encode( $log ) ) > self::STEP_LOG_MAX_BYTES ) {
				array_shift( $log );
			}
			$last = count( $log ) - 1;
			if ( strlen( $log[ $last ]['text'] ) > self::STEP_LOG_MAX_BYTES ) {
				$log[ $last ]['text'] = substr( $log[ $last ]['text'], -self::STEP_LOG_MAX_BYTES );
			}
			$live['step_log'][ $step ] = $log;
			$head = trim( $o['task'] . ( '' !== $o['agent'] ? ' (' . $o['agent'] . ')' : '' ) );
			if ( '' !== $head && $head !== $live['last_task'] ) {
				$live['output']   .= ( '' === $live['output'] ? '' : "\n" ) . '### ' . $head . "\n";
				$live['last_task'] = $head;
			}
			$live['output'] .= (string) $o['text'];
		}
		$over = strlen( $live['output'] ) - self::LIVE_MAX_BYTES;
		if ( $over > 0 ) {
			$live['output']   = substr( $live['output'], $over );
			$live['dropped'] += $over;
		}
		update_site_option( self::LIVE_PREFIX . $input['run_id'], $live );
		return true;
	}

	/**
	 * Live data of a run (empty defaults when none was reported).
	 *
	 * @param string $run_id Run ID.
	 * @return array{step:int, steps:int, label:string, updated_at:?string, last_task:string, output:string, dropped:int}
	 */
	public static function live_data( $run_id ) {
		$live = get_site_option( self::LIVE_PREFIX . $run_id, array() );
		return array_merge(
			array(
				'step'       => 0,
				'steps'      => 1,
				'label'       => '',
				'label_since' => null,
				'updated_at'  => null,
				'last_task'  => '',
				'output'     => '',
				'dropped'    => 0,
				'step_log'   => array(),
			),
			is_array( $live ) ? $live : array()
		);
	}

	/**
	 * When the current step of a run started: recorded when the step changed, or, for
	 * a step that began before that was recorded, when the last reported stage ended.
	 *
	 * @param array $run  Run row.
	 * @param array $live Live data.
	 * @return string|null ISO 8601 UTC.
	 */
	public static function step_started_at( array $run, array $live ) {
		if ( ! empty( $live['label_since'] ) ) {
			return $live['label_since'];
		}
		$end = 0;
		foreach ( self::stages( $run['run_id'] ) as $row ) {
			$end = max( $end, (int) strtotime( $row['started_at'] . ' UTC' ) + (int) round( $row['duration_ms'] / 1000 ) );
		}
		if ( ! $end && ! empty( $run['claimed_at'] ) ) {
			$end = (int) strtotime( $run['claimed_at'] . ' UTC' );
		}
		return $end ? gmdate( 'c', $end ) : null;
	}

	/**
	 * Keep live output only for the ten most recent runs.
	 */
	private static function prune_live() {
		global $wpdb;
		$keep = (array) $wpdb->get_col( 'SELECT run_id FROM ' . self::table() . ' ORDER BY id DESC LIMIT 10' ); // phpcs:ignore
		$keys = (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like( self::LIVE_PREFIX ) . '%' ) );
		foreach ( $keys as $key ) {
			$run_id = substr( $key, strlen( self::LIVE_PREFIX ) );
			if ( ! in_array( $run_id, $keep, true ) ) {
				// Runs that never called finish-run (expired, or a stopped worker) are archived here.
				$has = $wpdb->get_var( $wpdb->prepare( 'SELECT report IS NOT NULL FROM ' . self::table() . ' WHERE run_id = %s', $run_id ) ); // phpcs:ignore
				if ( ! $has ) {
					self::archive_live( $run_id );
				}
				delete_site_option( $key );
			}
		}
	}

	/**
	 * Mark a run as committed.
	 *
	 * @param string $run_id Run ID.
	 * @param array  $result Commit result.
	 */
	public static function mark_committed( $run_id, array $result ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'committed' => 1,
				'result'    => wp_json_encode( array( 'commit' => $result ) ),
			),
			array( 'run_id' => $run_id )
		);
	}

	/**
	 * Recent runs for the run log.
	 *
	 * @param int $limit  Rows.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function recent( $limit = 30, $offset = 0 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d OFFSET %d', $limit, $offset ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Stage rows of a run.
	 *
	 * @param string $run_id Run ID.
	 * @return array
	 */
	public static function stages( $run_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::stages_table() . ' WHERE run_id = %s ORDER BY id ASC', $run_id ), ARRAY_A ); // phpcs:ignore
	}

	/**
	 * Most recent finished run that reported AO capabilities.
	 *
	 * @return array|null
	 */
	public static function last_finished() {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . self::table() . " WHERE status IN ('succeeded','failed') AND result IS NOT NULL ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore
		return $row ? $row : null;
	}
}
