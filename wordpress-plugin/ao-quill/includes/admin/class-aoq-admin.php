<?php
/**
 * Network admin screens: Settings, Run log, Status.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Admin {

	const PAGE = 'ao-quill';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'network_admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'worker_notice' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'network_admin_edit_aoq_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'network_admin_edit_aoq_run_now', array( __CLASS__, 'handle_run_now' ) );
		add_action( 'admin_post_aoq_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_aoq_run_now', array( __CLASS__, 'handle_run_now' ) );
		add_action( 'network_admin_edit_aoq_mark_stopped', array( __CLASS__, 'handle_mark_stopped' ) );
		add_action( 'admin_post_aoq_mark_stopped', array( __CLASS__, 'handle_mark_stopped' ) );
		add_action( 'network_admin_edit_aoq_test_run', array( __CLASS__, 'handle_test_run' ) );
		add_action( 'admin_post_aoq_test_run', array( __CLASS__, 'handle_test_run' ) );
		add_action( 'wp_ajax_aoq_live', array( __CLASS__, 'ajax_live' ) );
		add_action( 'network_admin_edit_aoq_resume', array( __CLASS__, 'handle_resume' ) );
		add_action( 'admin_post_aoq_resume', array( __CLASS__, 'handle_resume' ) );
		add_action( 'network_admin_edit_aoq_publish_test', array( __CLASS__, 'handle_publish_test' ) );
		add_action( 'admin_post_aoq_publish_test', array( __CLASS__, 'handle_publish_test' ) );
	}

	/**
	 * Capability needed to manage the plugin.
	 *
	 * @return string
	 */
	private static function cap() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Add Settings → AO Quill (network admin on multisite).
	 */
	public static function menu() {
		if ( is_multisite() !== is_network_admin() ) {
			return;
		}
		$parent = is_multisite() ? 'settings.php' : 'options-general.php';
		add_submenu_page( $parent, AOQ_NAME, AOQ_NAME, self::cap(), self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Page URL.
	 *
	 * @param array $args Query args.
	 * @return string
	 */
	private static function url( array $args = array() ) {
		$base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), $base );
	}

	/**
	 * Form action URL.
	 *
	 * @param string $action Action.
	 * @return string
	 */
	private static function action_url( $action ) {
		return is_multisite() ? network_admin_url( 'edit.php?action=' . $action ) : admin_url( 'admin-post.php?action=' . $action );
	}

	/**
	 * Save settings.
	 */
	public static function handle_save() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ao-quill' ) );
		}
		check_admin_referer( 'aoq_save' );
		$input  = isset( $_POST['aoq'] ) && is_array( $_POST['aoq'] ) ? $_POST['aoq'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in AOQ_Settings::sanitize.
		$result = AOQ_Settings::sanitize( $input );
		AOQ_Settings::save( $result['settings'] );
		$errors = $result['errors'];
		$ao     = isset( $_POST['aoq_ao'] ) && is_array( $_POST['aoq_ao'] ) ? wp_unslash( $_POST['aoq_ao'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in AOQ_AO_Connection.
		if ( $ao ) {
			$errors = array_merge( $errors, AOQ_AO_Connection::save_settings( $ao ) );
			$cert   = trim( (string) ( $ao['cert_pem'] ?? '' ) );
			$key    = trim( (string) ( $ao['key_pem'] ?? '' ) );
			$ca     = trim( (string) ( $ao['ca_pem'] ?? '' ) );
			if ( ! empty( $ao['clear_certificate'] ) ) {
				AOQ_AO_Connection::clear_certificate();
			} elseif ( '' !== $cert || '' !== $key || '' !== $ca ) {
				$saved = ( '' === $cert || '' === $key || '' === $ca )
					? new WP_Error( 'aoq_incomplete', __( 'To replace the AO certificate, paste the client certificate, private key and AO CA together.', 'ao-quill' ) )
					: AOQ_AO_Connection::save_certificate( $cert, $key, $ca );
				if ( is_wp_error( $saved ) ) {
					$errors[] = $saved->get_error_message();
				}
			}
		}
		set_site_transient( 'aoq_notices_' . get_current_user_id(), $errors, 60 );
		wp_safe_redirect( self::url( array( 'tab' => 'settings', 'updated' => 1 ) ) );
		exit;
	}

	/**
	 * Request a run on the next worker poll.
	 */
	public static function handle_run_now() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ao-quill' ) );
		}
		check_admin_referer( 'aoq_run_now' );
		AOQ_Runs::request_run_now();
		wp_safe_redirect( self::url( array( 'tab' => 'generation' ) ) );
		exit;
	}

	/**
	 * Close a run whose worker stopped reporting, so it can be resumed at once.
	 */
	public static function handle_mark_stopped() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ao-quill' ) );
		}
		check_admin_referer( 'aoq_mark_stopped' );
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		AOQ_Runs::mark_stopped( $run_id );
		wp_safe_redirect( self::url( array( 'tab' => 'runs', 'run' => $run_id ) ) );
		exit;
	}

	/**
	 * Request a test run on the next worker poll.
	 */
	/**
	 * Resume a test run from a step (Past runs).
	 */
	public static function handle_resume() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ao-quill' ) );
		}
		check_admin_referer( 'aoq_resume' );
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		$step   = isset( $_POST['from_step'] ) ? sanitize_key( wp_unslash( $_POST['from_step'] ) ) : '';
		$ok     = AOQ_Runs::request_resume( $run_id, $step );
		if ( is_wp_error( $ok ) ) {
			wp_safe_redirect( self::url( array( 'tab' => 'runs', 'run' => $run_id, 'aoq_error' => rawurlencode( $ok->get_error_message() ) ) ) );
			exit;
		}
		wp_safe_redirect( self::url( array( 'tab' => 'generation' ) ) );
		exit;
	}

	/**
	 * Publish a finished test run (Past runs).
	 */
	public static function handle_publish_test() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ao-quill' ) );
		}
		check_admin_referer( 'aoq_publish_test' );
		$run_id = isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '';
		$result = AOQ_Commit::publish_test( $run_id );
		$args   = array( 'tab' => 'runs', 'run' => $run_id );
		if ( is_wp_error( $result ) ) {
			$args['aoq_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['published'] = 1;
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	public static function handle_test_run() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ao-quill' ) );
		}
		check_admin_referer( 'aoq_test_run' );
		AOQ_Runs::request_test_run();
		wp_safe_redirect( self::url( array( 'tab' => 'generation' ) ) );
		exit;
	}

	/**
	 * Live state of a run for the Generation tab. Output is sent from a byte offset so
	 * the page only receives what it has not shown yet.
	 */
	public static function ajax_live() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'aoq_live' );
		AOQ_Runs::halt_stalled();
		$run_id = isset( $_GET['run_id'] ) ? sanitize_text_field( wp_unslash( $_GET['run_id'] ) ) : '';
		$offset = isset( $_GET['offset'] ) ? max( 0, (int) $_GET['offset'] ) : 0;
		$waiting = AOQ_Runs::test_requested() || AOQ_Runs::run_now_requested();
		$latest  = AOQ_Runs::latest();
		// A newer run (another trigger, or the next one) replaces the one the page follows.
		$run = ( '' !== $run_id && ( ! $latest || $latest['run_id'] === $run_id ) ) ? AOQ_Runs::get( $run_id ) : $latest;
		if ( ! $run ) {
			wp_send_json_success( array( 'run_id' => null, 'waiting' => $waiting ) );
		}
		wp_send_json_success( self::run_payload( $run, $offset, $waiting ) );
	}

	/**
	 * Everything the run panel shows for one run: status, steps with results and timings,
	 * and the AO output from a byte offset. Used live (Generation) and for Past runs.
	 *
	 * @param array $run     Run row.
	 * @param int   $offset  Output already shown.
	 * @param bool  $waiting A run was requested and not yet claimed.
	 * @return array
	 */
	private static function run_payload( array $run, $offset = 0, $waiting = false ) {
		$live  = AOQ_Runs::history_live( $run );
		$start = max( 0, $offset - (int) $live['dropped'] );
		$steps = max( 1, (int) $live['steps'] );
		$done  = in_array( $run['status'], array( 'succeeded', 'failed', 'expired' ), true );
		return array(
			'run_id'         => $run['run_id'],
			'status'         => $run['status'],
			'finished'       => $done,
			'step'           => (int) $live['step'],
			'steps'          => $steps,
			'percent'        => $done && 'succeeded' === $run['status'] ? 100 : (int) floor( 100 * (int) $live['step'] / $steps ),
			'label'          => $live['label'],
			'updated_at'     => $live['updated_at'],
			'output'         => (string) substr( $live['output'], $start ),
			'offset'         => (int) $live['dropped'] + strlen( $live['output'] ),
			'failure_reason' => (string) $run['failure_reason'],
			'waiting'        => $waiting,
			'trigger'        => $run['run_trigger'],
			'started_at'     => $run['claimed_at'] ? gmdate( 'c', strtotime( $run['claimed_at'] . ' UTC' ) ) : null,
			'finished_at'    => $run['finished_at'] ? gmdate( 'c', strtotime( $run['finished_at'] . ' UTC' ) ) : null,
			'steps_detail'   => AOQ_Steps::for_run( $run, $live ),
			'step_started'   => AOQ_Runs::step_started_at( $run, $live ),
			'server_now'     => gmdate( 'c' ),
			'silence'        => $done ? null : self::silence_payload( $run ),
		);
	}

	/**
	 * How long a running run's worker has been silent, and when WordPress will close the run.
	 *
	 * @param array $run Run row.
	 * @return array{seconds:int, last:string, closes_at:string, heartbeat:bool}
	 */
	private static function silence_payload( array $run ) {
		$s = AOQ_Runs::silence( $run );
		return array(
			'seconds'   => max( 0, time() - $s['last'] ),
			'last'      => gmdate( 'c', $s['last'] ),
			'closes_at' => gmdate( 'c', $s['last'] + $s['limit'] + 1 ),
			'heartbeat' => $s['heartbeat'],
		);
	}

	/**
	 * Generation tab: follow the current run, whatever started it (schedule, Generate
	 * now, or a test), step by step with a legend, readable results, estimates and the
	 * raw AO log; start a real or a test run; end with links to the posts or a preview.
	 */
	private static function render_generation() {
		$worker  = AOQ_Worker::state();
		$current = AOQ_Runs::current();
		$waiting = AOQ_Runs::test_requested() || AOQ_Runs::run_now_requested();
		$run     = AOQ_Runs::latest();
		$next    = AOQ_Runs::next_scheduled();

		/* translators: %s: date and time of the next scheduled run */
		echo '<p>' . esc_html( sprintf( __( 'Every run shows here as it happens: the daily schedule (next: %s), Generate now, and test runs.', 'ao-quill' ), $next->format( 'Y-m-d H:i T' ) ) ) . '</p>';
		if ( ! in_array( $worker['state'], array( 'running', 'busy' ), true ) && ! $current ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The worker is not checking in, so no run will start until it does. See the Worker and AO status tab.', 'ao-quill' ) . '</p></div>';
		} elseif ( 'busy' === $worker['state'] && ! $current ) {
			$busy_txt = AOQ_Worker::busy_label( $worker['busy'] );
			echo '<div class="notice notice-info inline"><p>' . esc_html(
				$busy_txt
					? sprintf(
						/* translators: %s: site and task, e.g. "tgme: post run (qa)" */
						__( 'The shared worker is busy elsewhere (%s). This site will be polled when that job finishes.', 'ao-quill' ),
						$busy_txt
					)
					: __( 'The shared worker is busy on another site. This site will be polled when that job finishes.', 'ao-quill' )
			) . '</p></div>';
		}
		$disabled = ( $current || $waiting ) ? array( 'disabled' => 'disabled' ) : array();
		echo '<div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;max-width:900px">';
		echo '<form method="post" action="' . esc_url( self::action_url( 'aoq_run_now' ) ) . '">';
		wp_nonce_field( 'aoq_run_now' );
		submit_button( __( 'Generate now', 'ao-quill' ), 'primary', 'submit', false, $disabled );
		echo '<p class="description">' . esc_html__( 'Writes a post now, saved for review. Starts after any run in progress.', 'ao-quill' ) . '</p></form>';
		echo '<form method="post" action="' . esc_url( self::action_url( 'aoq_test_run' ) ) . '">';
		wp_nonce_field( 'aoq_test_run' );
		submit_button( __( 'Test run (writes nothing)', 'ao-quill' ), 'secondary', 'submit', false, $disabled );
		echo '<p class="description">' . esc_html__( 'Every step against AO and the live site, then a preview. Does not count as the day\'s post.', 'ao-quill' ) . '</p></form>';
		echo '</div>';

		$active = $waiting || ( $run && in_array( $run['status'], array( 'claimed', 'running' ), true ) );
		self::render_run_panel( $run, $active );
	}

	/**
	 * One chosen image as it would appear in the post: the image, its caption and credit.
	 *
	 * @param array $img      Gallery entry.
	 * @param bool  $featured The featured image (the theme shows it under the title).
	 * @return string HTML.
	 */
	private static function preview_figure( array $img, $featured ) {
		if ( $featured ) {
			// As the theme shows it: under the title, a wide banner crop, no caption.
			return '<figure style="margin:0 0 16px"><img src="' . esc_url( $img['full'] ) . '" alt="' . esc_attr( $img['alt'] ) . '" style="width:100%;aspect-ratio:1290/540;object-fit:cover;display:block">'
				. '<figcaption class="description" style="font-size:12px;margin-top:4px">' . esc_html__( 'Featured image (the theme shows it here, under the title)', 'ao-quill' ) . '</figcaption></figure>';
		}
		$credit = trim( $img['attribution'] ? $img['attribution'] : ( 'media_library' === $img['source'] ? '' : $img['license'] ) );
		$html   = '<figure style="margin:16px 0">';
		$html  .= '<img src="' . esc_url( $img['full'] ) . '" alt="' . esc_attr( $img['alt'] ) . '" style="max-width:100%;height:auto;display:block">';
		if ( '' !== $img['caption'] || '' !== $credit ) {
			$html .= '<figcaption style="color:#646970;font-size:13px;margin-top:4px">' . esc_html( $img['caption'] ) . ( '' !== $credit ? ' <small>(' . esc_html( $credit ) . ')</small>' : '' ) . '</figcaption>';
		}
		return $html . '</figure>';
	}

	/**
	 * "Show full" modal: any a.aoq-full link opens the complete text. JSON is pretty printed;
	 * text with HTML gets a Preview tab (rendered in a sandboxed frame, scripts off) and a
	 * Source tab. Printed once per page.
	 */
	private static function render_full_text_modal() {
		$t = array(
			'preview' => __( 'Preview', 'ao-quill' ),
			'source'  => __( 'Source', 'ao-quill' ),
			'text'    => __( 'Text', 'ao-quill' ),
			'close'   => __( 'Close', 'ao-quill' ),
			'copy'    => __( 'Copy', 'ao-quill' ),
			'copied'  => __( 'Copied', 'ao-quill' ),
			'showFullR' => __( 'Show full', 'ao-quill' ),
			'gMin'      => __( 'min', 'ao-quill' ),
			'gMax'      => __( 'max', 'ao-quill' ),
			'passMark'  => __( 'pass mark', 'ao-quill' ),
			'alt'         => __( 'Alt text', 'ao-quill' ),
			'caption'     => __( 'Caption', 'ao-quill' ),
			'sourceL'     => __( 'Source', 'ao-quill' ),
			'scoreL'      => __( 'Relevance score', 'ao-quill' ),
			'license'     => __( 'License', 'ao-quill' ),
			'attribution' => __( 'Attribution', 'ao-quill' ),
			'open'        => __( 'Original', 'ao-quill' ),
			'roundOf'     => __( 'Round %1$d of %2$d', 'ao-quill' ),
			'revision'    => __( 'Revision written after this round', 'ao-quill' ),
			'revNot'      => __( 'Not generated yet.', 'ao-quill' ),
			'revRunning'  => __( 'The revision is being written.', 'ao-quill' ),
			'revPassed'   => __( 'No revision: every check passed in this round.', 'ao-quill' ),
			'checks'      => __( 'Checks', 'ao-quill' ),
			'langOf'      => __( 'language %1$d of %2$d', 'ao-quill' ),
			'trText'      => __( 'Translated text', 'ao-quill' ),
			'trRevised'   => __( 'Translated text (newer version, made after the checks)', 'ao-quill' ),
			'trNot'       => __( 'Not translated yet.', 'ao-quill' ),
			'trRunning'   => __( 'The translation is being written.', 'ao-quill' ),
			'updates'     => __( 'Status updates', 'ao-quill' ),
			'trNow'       => __( 'translating now', 'ao-quill' ),
			'pass'        => __( 'PASS', 'ao-quill' ),
			'fail'        => __( 'FAIL', 'ao-quill' ),
			'soFar'       => __( 'so far', 'ao-quill' ),
			'vPass'       => __( 'Passed: every check is at or above its pass mark.', 'ao-quill' ),
			'vFail'       => __( 'Not passing: %1$s.', 'ao-quill' ),
			'vFailRun'    => __( 'Not passing yet, being revised: %1$s.', 'ao-quill' ),
			'vRun'        => __( 'Being checked. Each check is compared with its pass mark as its answer comes in.', 'ao-quill' ),
			'vAttempts'   => __( 'Attempt %1$d of %2$d.', 'ao-quill' ),
			'legend'      => __( 'Each bar runs from 0 to 1. The black line is the pass mark and ▼ is the score: a check passes when ▼ is on the line or to its right.', 'ao-quill' ),
			'needs'       => __( 'needs %1$s', 'ao-quill' ),
			'stPassed'    => __( 'passed', 'ao-quill' ),
			'stFailed'    => __( 'failed', 'ao-quill' ),
			'stRunning'   => __( 'running', 'ao-quill' ),
			'stPending'   => __( 'not run yet', 'ao-quill' ),
			'noAnswer'    => __( 'No details recorded for this check.', 'ao-quill' ),
		);
		?>
<style>
dialog.aoq-modal{width:min(1000px,92vw);height:min(86vh,900px);padding:0;border:1px solid #c3c4c7;border-radius:4px;box-shadow:0 10px 40px rgba(0,0,0,.3)}
dialog.aoq-modal::backdrop{background:rgba(0,0,0,.6)}
.aoq-modal-wrap{display:flex;flex-direction:column;height:100%}
.aoq-modal-head{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid #dcdcde;background:#f6f7f7}
.aoq-modal-head h2{margin:0;font-size:16px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.aoq-modal-tabs .button{margin-left:4px}.aoq-modal-tabs .button.active{background:#2271b1;color:#fff;border-color:#2271b1}
.aoq-modal-body{flex:1;overflow:auto;padding:0}
.aoq-modal-body pre{margin:0;padding:16px;white-space:pre-wrap;word-break:break-word;font-size:13px;line-height:1.5}
.aoq-modal-body iframe{width:100%;height:100%;border:0;background:#fff}
a.aoq-full{white-space:nowrap}
.aoq-gallery{display:flex;gap:8px;flex-wrap:wrap;margin:4px 0 10px}
.aoq-thumb{border:1px solid #c3c4c7;background:#fff;padding:3px;cursor:zoom-in;width:132px;text-align:center}
.aoq-thumb img{display:block;width:124px;height:84px;object-fit:cover}.aoq-thumb span{display:block;font-size:11px;color:#646970;margin-top:2px}
.aoq-thumb:hover,.aoq-thumb:focus{border-color:#2271b1;outline:none;box-shadow:0 0 0 1px #2271b1}
.aoq-gauge{display:inline-flex;align-items:flex-end;gap:8px;vertical-align:middle}
.aoq-gauge .rng{display:flex;flex-direction:column;font-size:10px;line-height:12px;color:#646970;text-align:right;white-space:nowrap}
.aoq-gauge .g{position:relative;width:220px;height:30px}
.aoq-gauge .bar{position:absolute;bottom:2px;left:0;right:0;height:8px;border-radius:4px;background:linear-gradient(90deg,#d63638 0%,#dba617 50%,#00a32a 100%)}
.aoq-gauge .cut{position:absolute;bottom:-1px;height:14px;width:2px;background:#1d2327;transform:translateX(-1px)}
.aoq-gauge .mk{position:absolute;bottom:11px;transform:translateX(-50%);font-size:11px;line-height:11px;text-align:center;color:#1d2327;white-space:nowrap}
.aoq-gauge .mk span{display:block;font-weight:600;font-variant-numeric:tabular-nums}
dialog.aoq-rounds{width:min(1100px,94vw);height:min(90vh,980px);padding:0;border:1px solid #c3c4c7;border-radius:4px}
dialog.aoq-rounds::backdrop{background:rgba(0,0,0,.6)}
.aoq-rd{display:grid;grid-template-rows:auto 1fr;height:100%}
.aoq-rd-head{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid #dcdcde;background:#f6f7f7}
.aoq-rd-head h2{margin:0;font-size:16px;flex:1}
#aoq-rd-prev,#aoq-rd-next{font-size:26px;line-height:1;min-width:44px;height:38px;padding:0 12px;display:inline-flex;align-items:center;justify-content:center}
.aoq-rd-badge{padding:2px 8px;border-radius:10px;font-size:12px;font-weight:600;background:#f0f0f1}
.aoq-rd-badge.passed{background:#edfaef;color:#00a32a}.aoq-rd-badge.failed{background:#fcf0f1;color:#d63638}.aoq-rd-badge.running{background:#f0f6fc;color:#2271b1}
.aoq-rd-body{overflow:auto;padding:14px 18px}
.aoq-rd-body h3{margin:4px 0 8px}
.aoq-rd-rev{border:1px solid #dcdcde;background:#fff;margin-bottom:16px}
.aoq-rd-rev .meta{padding:10px 14px;border-bottom:1px solid #f0f0f1}.aoq-rd-rev .meta strong{display:block;font-size:15px}
.aoq-rd-rev iframe{width:100%;height:420px;border:0;display:block}
.aoq-rd-verdict{padding:10px 14px;margin:0 0 14px;border-left:4px solid;font-weight:600;font-size:14px}
.aoq-rd-verdict.pass{background:#edfaef;border-color:#00a32a;color:#00450c}.aoq-rd-verdict.fail{background:#fcf0f1;border-color:#d63638;color:#8a1f1f}.aoq-rd-verdict.run{background:#f0f6fc;border-color:#2271b1;color:#0a4b78}
.aoq-rd-verdict .att{font-weight:400;color:#50575e}
.aoq-pill{display:inline-block;min-width:38px;text-align:center;padding:1px 6px;border-radius:3px;font-size:11px;font-weight:700;color:#fff}.aoq-pill.pass{background:#00a32a}.aoq-pill.fail{background:#d63638}
.aoq-rd-check.lang>summary{align-items:center;flex-wrap:wrap}.aoq-rd-check.lang>summary strong{width:330px;flex:0 0 330px}
.aoq-rd-check.lang.failed{border-color:#d63638;background:#fffafa}.aoq-rd-check.lang.passed{border-left:3px solid #00a32a}
.aoq-rd-check .lsum{flex-basis:100%;padding-left:48px}
.aoq-rd-legend{margin:-4px 0 8px}
.aoq-rd-log{margin:0 0 16px;padding:8px 12px 8px 30px;background:#fff;border:1px solid #dcdcde;list-style:disc}.aoq-rd-log li{margin:3px 0}
.aoq-rd-empty{padding:14px;color:#646970;font-style:italic;background:#fff;border:1px dashed #c3c4c7;margin-bottom:16px}
.aoq-rd-check{background:#fff;border:1px solid #dcdcde;margin:0 0 6px;padding:6px 10px}
.aoq-rd-check>summary{cursor:pointer;display:flex;gap:10px;align-items:baseline}
.aoq-rd-check .ok{color:#00a32a;font-weight:700}.aoq-rd-check .bad{color:#d63638;font-weight:700}.aoq-rd-check .sc{margin-left:auto;color:#50575e;font-variant-numeric:tabular-nums}
.aoq-rd-check dl{display:grid;grid-template-columns:max-content 1fr;gap:2px 12px;margin:8px 0 2px}.aoq-rd-check dt{font-weight:600}.aoq-rd-check dd{margin:0;white-space:pre-wrap}
dialog.aoq-lightbox{width:min(1200px,94vw);height:min(90vh,950px);padding:0;border:0;background:#1d2327;color:#f0f0f1;border-radius:4px}
dialog.aoq-lightbox::backdrop{background:rgba(0,0,0,.8)}
.aoq-lb{display:grid;grid-template-rows:auto 1fr auto;height:100%}
.aoq-lb-head{display:flex;align-items:center;gap:12px;padding:10px 14px}.aoq-lb-head strong{flex:1}
.aoq-lb-stage{position:relative;display:flex;align-items:center;justify-content:center;min-height:0;padding:0 56px}
.aoq-lb-stage img{max-width:100%;max-height:100%;object-fit:contain}
.aoq-lb-nav{position:absolute;top:50%;transform:translateY(-50%);width:44px;height:44px;border-radius:22px;border:0;background:rgba(255,255,255,.15);color:#fff;font-size:22px;cursor:pointer}
.aoq-lb-nav:hover{background:rgba(255,255,255,.3)}.aoq-lb-prev{left:6px}.aoq-lb-next{right:6px}
.aoq-lb-meta{padding:10px 16px 14px;font-size:13px;line-height:1.5;background:#2c3338}.aoq-lb-meta a{color:#72aee6}
.aoq-lb-meta dl{display:grid;grid-template-columns:max-content 1fr;gap:2px 12px;margin:6px 0 0}.aoq-lb-meta dt{color:#a7aaad}
</style>
<dialog class="aoq-rounds" id="aoq-rounds"><div class="aoq-rd"><div class="aoq-rd-head"><button type="button" class="button" id="aoq-rd-prev">&#8249;</button><h2 id="aoq-rd-title"></h2><span class="aoq-rd-badge" id="aoq-rd-badge"></span><button type="button" class="button" id="aoq-rd-next">&#8250;</button><button type="button" class="button button-primary" id="aoq-rd-close"></button></div><div class="aoq-rd-body" id="aoq-rd-body"></div></div></dialog>
<dialog class="aoq-lightbox" id="aoq-lightbox"><div class="aoq-lb"><div class="aoq-lb-head"><strong id="aoq-lb-title"></strong><span id="aoq-lb-count"></span><button type="button" class="button" id="aoq-lb-close"></button></div><div class="aoq-lb-stage"><button type="button" class="aoq-lb-nav aoq-lb-prev" id="aoq-lb-prev" aria-label="Previous">&#8249;</button><img id="aoq-lb-img" alt=""><button type="button" class="aoq-lb-nav aoq-lb-next" id="aoq-lb-next" aria-label="Next">&#8250;</button></div><div class="aoq-lb-meta" id="aoq-lb-meta"></div></div></dialog>
<dialog class="aoq-modal" id="aoq-modal"><div class="aoq-modal-wrap"><div class="aoq-modal-head"><h2 id="aoq-modal-title"></h2><span class="aoq-modal-tabs" id="aoq-modal-tabs"></span><button type="button" class="button" id="aoq-modal-copy"></button><button type="button" class="button button-primary" id="aoq-modal-close"></button></div><div class="aoq-modal-body" id="aoq-modal-body"></div></div></dialog>
<script>
(function () {
	var T = <?php echo wp_json_encode( $t ); ?>;
	var dlg = document.getElementById('aoq-modal'), body = document.getElementById('aoq-modal-body'), tabs = document.getElementById('aoq-modal-tabs');
	var copyBtn = document.getElementById('aoq-modal-copy'), current = '';
	document.getElementById('aoq-modal-close').textContent = T.close;
	copyBtn.textContent = T.copy;
	document.getElementById('aoq-modal-close').onclick = function () { dlg.close(); };
	dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });
	copyBtn.onclick = function () { if (navigator.clipboard) { navigator.clipboard.writeText(current).then(function () { copyBtn.textContent = T.copied; setTimeout(function () { copyBtn.textContent = T.copy; }, 1500); }); } };
	var HTML = /<\/?(p|h[1-6]|ul|ol|li|a|strong|em|b|i|img|div|span|br|figure|figcaption|table|tr|td|th|blockquote)\b[^>]*>/i;
	function pre(text) { var p = document.createElement('pre'); p.textContent = text; return p; }
	function frame(html) {
		var f = document.createElement('iframe');
		f.setAttribute('sandbox', ''); // no scripts, no forms, no navigation
		f.srcdoc = '<!doctype html><meta charset="utf-8"><style>body{font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:760px;margin:24px auto;padding:0 16px;color:#1d2327}img{max-width:100%;height:auto}h1,h2,h3{line-height:1.3}</style>' + html;
		return f;
	}
	function show(view, text) {
		body.textContent = '';
		body.appendChild(view === 'preview' ? frame(text) : pre(text));
		Array.prototype.forEach.call(tabs.children, function (b) { b.classList.toggle('active', b.dataset.view === view); });
	}
	function open(title, text) {
		var shown = text;
		try { var j = JSON.parse(text); if (j && typeof j === 'object') { shown = JSON.stringify(j, null, 2); } } catch (e) {}
		current = shown;
		document.getElementById('aoq-modal-title').textContent = title || '';
		tabs.textContent = '';
		if (HTML.test(text)) {
			// HTML: rendered preview first; the source a click away.
			[['preview', T.preview, text], ['source', T.source, shown]].forEach(function (v) {
				var b = document.createElement('button'); b.type = 'button'; b.className = 'button'; b.textContent = v[1]; b.dataset.view = v[0];
				b.onclick = function () { show(v[0], v[2]); }; tabs.appendChild(b);
			});
			show('preview', text);
		} else {
			show('text', shown);
		}
		if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', ''); }
	}
	// Score gauge: a red-to-green bar from min to max, a line at the pass mark, a marker at the score.
	window.aoqGauge = function (score, threshold, min, max) {
		min = min === undefined ? 0 : min; max = max === undefined ? 1 : max;
		var pct = function (v) { return Math.max(0, Math.min(100, 100 * (v - min) / (max - min))); };
		var w = document.createElement('span'); w.className = 'aoq-gauge';
		var rng = document.createElement('span'); rng.className = 'rng';
		var mn = document.createElement('span'); mn.textContent = T.gMin + ' ' + min;
		var mx = document.createElement('span'); mx.textContent = T.gMax + ' ' + max;
		rng.appendChild(mx); rng.appendChild(mn); w.appendChild(rng);
		var g = document.createElement('span'); g.className = 'g';
		var bar = document.createElement('span'); bar.className = 'bar'; g.appendChild(bar);
		if (threshold !== null && threshold !== undefined && threshold !== '') {
			var cut = document.createElement('i'); cut.className = 'cut'; cut.style.left = pct(Number(threshold)) + '%';
			cut.title = T.passMark + ' ' + Number(threshold).toFixed(2); g.appendChild(cut);
		}
		var mk = document.createElement('b'); mk.className = 'mk'; mk.style.left = pct(Number(score)) + '%';
		var val = document.createElement('span'); val.textContent = Number(score).toFixed(2);
		mk.appendChild(val); mk.appendChild(document.createTextNode('\u25BC')); g.appendChild(mk);
		w.appendChild(g);
		w.title = Number(score).toFixed(2) + (threshold !== null && threshold !== undefined ? ' / ' + T.passMark + ' ' + Number(threshold).toFixed(2) : '');
		return w;
	};
	function isScore(label, value) { return /^score$/i.test(String(label).trim()) && /^\s*(0(\.\d+)?|1(\.0+)?)\s*$/.test(String(value)); }
	window.aoqIsScore = isScore;
	// QA rounds gallery: the revision each round produced, and every check with its result.
	var rd = document.getElementById('aoq-rounds'), rdRows = [], rdAt = 0, rdStep = '';
	document.getElementById('aoq-rd-close').textContent = T.close;
	document.getElementById('aoq-rd-close').onclick = function () { rd.close(); };
	rd.addEventListener('click', function (e) { if (e.target === rd) { rd.close(); } });
	function rdFmt(s) { var a = Array.prototype.slice.call(arguments, 1); return s.replace(/%(\d)\$[sd]/g, function (m, n) { return a[n - 1]; }); }
	function rdEl(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text !== undefined) { e.textContent = text; } return e; }
	function claimTags(html) { return String(html).replace(/\{\{c:([^}]+)\}\}/g, '<sup style="color:#8c8f94;font-size:11px">[$1]</sup>'); }
	function rdShow(i) {
		rdAt = Math.max(0, Math.min(rdRows.length - 1, i));
		var row = rdRows[rdAt], d = row.detail || {};
		var isLang = d.kind === 'language';
		document.getElementById('aoq-rd-title').textContent = (rdStep ? rdStep + ' - ' : '') + (isLang ? row.label + ' · ' + rdFmt(T.langOf, rdAt + 1, rdRows.length) : rdFmt(T.roundOf, rdAt + 1, rdRows.length));
		var badge = document.getElementById('aoq-rd-badge');
		var st = { passed: T.stPassed, failed: T.stFailed, running: T.stRunning, pending: T.stPending }[d.status] || '';
		badge.textContent = st; badge.className = 'aoq-rd-badge ' + (d.status || '');
		document.getElementById('aoq-rd-prev').disabled = rdAt === 0;
		document.getElementById('aoq-rd-next').disabled = rdAt === rdRows.length - 1;
		var body = document.getElementById('aoq-rd-body'); body.textContent = '';
		var rev = d.revision || { state: 'not_generated' };
		if (isLang) {
			// Translate step: the verdict first, then what happened in order, the text and the checks.
			var hold = d.holding || [], vtext, vcls;
			if (d.status === 'passed') { vtext = T.vPass; vcls = 'pass'; }
			else if (hold.length) { vtext = rdFmt(d.status === 'running' ? T.vFailRun : T.vFail, hold.join('; ')); vcls = 'fail'; }
			else if (d.status === 'running') { vtext = T.vRun; vcls = 'run'; }
			if (vtext) {
				var vb = rdEl('div', 'aoq-rd-verdict ' + vcls, vtext);
				if (d.attempts) { vb.appendChild(rdEl('span', 'att', ' ' + rdFmt(T.vAttempts, d.attempts, d.max_attempts || d.attempts))); }
				body.appendChild(vb);
			}
			body.appendChild(rdEl('h3', '', T.updates));
			var ul = rdEl('ul', 'aoq-rd-log');
			(d.log && d.log.length ? d.log : [T.trNot]).forEach(function (line) { ul.appendChild(rdEl('li', '', line)); });
			body.appendChild(ul);
		}
		body.appendChild(rdEl('h3', '', isLang ? (rev.revised ? T.trRevised : T.trText) : T.revision));
		if (rev.state === 'generated') {
			var box = rdEl('div', 'aoq-rd-rev'), meta = rdEl('div', 'meta');
			meta.appendChild(rdEl('strong', '', rev.title)); if (rev.excerpt) { var ex = rdEl('div'); ex.innerHTML = ''; ex.textContent = rev.excerpt.replace(/<[^>]+>/g, ''); meta.appendChild(ex); }
			box.appendChild(meta);
			var f = document.createElement('iframe'); f.setAttribute('sandbox', '');
			var dir = d.dir === 'rtl' ? 'rtl' : 'ltr';
			if (dir === 'rtl') { meta.setAttribute('dir', 'rtl'); }
			f.srcdoc = '<!doctype html><html dir="' + dir + '"><meta charset="utf-8"><style>body{font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;max-width:760px;margin:16px auto;padding:0 16px;color:#1d2327}</style>' + claimTags(rev.html);
			box.appendChild(f); body.appendChild(box);
		} else if (isLang) {
			body.appendChild(rdEl('div', 'aoq-rd-empty', rev.state === 'running' ? T.trRunning : T.trNot));
		} else {
			body.appendChild(rdEl('div', 'aoq-rd-empty', rev.state === 'running' ? T.revRunning : (rev.state === 'passed' ? T.revPassed : T.revNot)));
		}
		body.appendChild(rdEl('h3', '', T.checks));
		if (isLang) { body.appendChild(rdEl('p', 'description aoq-rd-legend', T.legend)); }
		(d.checks || []).forEach(function (c) {
			var det = rdEl('details', 'aoq-rd-check' + (isLang ? ' lang ' + c.status : '')), sum = rdEl('summary');
			var mark = c.status === 'passed' ? '✓' : (c.status === 'failed' ? '✗' : '○');
			var has = c.score !== null && c.score !== undefined, hasMark = c.threshold !== null && c.threshold !== undefined;
			if (isLang && (c.status === 'passed' || c.status === 'failed') && hasMark) {
				// Translation checks: a PASS or FAIL label and the score on a bar with the pass mark.
				sum.appendChild(rdEl('span', 'aoq-pill ' + (c.status === 'passed' ? 'pass' : 'fail'), c.status === 'passed' ? T.pass : T.fail));
			} else {
				sum.appendChild(rdEl('span', c.status === 'passed' ? 'ok' : (c.status === 'failed' ? 'bad' : ''), mark));
			}
			sum.appendChild(rdEl('strong', '', c.label));
			if (isLang && has && hasMark) {
				sum.appendChild(window.aoqGauge(c.score, c.threshold));
				var nums = rdEl('span', 'sc', c.score.toFixed(2) + ' / ' + rdFmt(T.needs, Number(c.threshold).toFixed(2)) + (c.final ? '' : ' (' + T.soFar + ')'));
				sum.appendChild(nums);
				if (c.summary) { var sd = rdEl('span', 'description lsum', c.summary.length > 140 ? c.summary.slice(0, 140) + '…' : c.summary); sum.appendChild(sd); }
				det.appendChild(sum);
			} else {
			if (c.summary) { sum.appendChild(rdEl('span', 'description', c.summary.length > 90 ? c.summary.slice(0, 90) + '…' : c.summary)); }
			var sc = has ? c.score.toFixed(2) + (hasMark ? ' / ' + rdFmt(T.needs, Number(c.threshold).toFixed(2)) : '') : (hasMark && isLang ? rdFmt(T.needs, Number(c.threshold).toFixed(2)) + ' · ' : '') + ({ running: T.stRunning, pending: T.stPending }[c.status] || '');
			sum.appendChild(rdEl('span', 'sc', sc));
			det.appendChild(sum);
			}
			if (c.fields && c.fields.length) {
				var dl = rdEl('dl');
				c.fields.forEach(function (fl) {
					dl.appendChild(rdEl('dt', '', fl[0]));
					if (isScore(fl[0], fl[1])) {
						var gd = rdEl('dd'); gd.appendChild(window.aoqGauge(parseFloat(fl[1]), c.threshold)); dl.appendChild(gd);
						return;
					}
					var dd = rdEl('dd', '', fl[1]);
					if (fl.length > 2) { var a = rdEl('a', 'aoq-full', T.showFullR || 'Show full'); a.href = '#'; a.dataset.title = c.label; a.dataset.full = fl[2]; dd.appendChild(document.createTextNode(' ')); dd.appendChild(a); }
					dl.appendChild(dd);
				});
				det.appendChild(dl);
			} else { det.appendChild(rdEl('p', 'description', T.noAnswer)); }
			body.appendChild(det);
		});
		body.scrollTop = 0;
	}
	document.getElementById('aoq-rd-prev').onclick = function () { rdShow(rdAt - 1); };
	document.getElementById('aoq-rd-next').onclick = function () { rdShow(rdAt + 1); };
	rd.addEventListener('keydown', function (e) {
		if (e.target && e.target.tagName === 'SUMMARY') { return; }
		if (e.key === 'ArrowLeft') { rdShow(rdAt - 1); e.preventDefault(); }
		if (e.key === 'ArrowRight') { rdShow(rdAt + 1); e.preventDefault(); }
	});
	window.aoqRounds = function (rows, i, stepTitle) {
		rdRows = rows.filter(function (r) { return r.detail; }); rdStep = stepTitle || '';
		rdShow(i || 0);
		if (rd.showModal) { rd.showModal(); } else { rd.setAttribute('open', ''); }
	};
	// Lightbox gallery for chosen images.
	var lb = document.getElementById('aoq-lightbox'), lbList = [], lbAt = 0;
	document.getElementById('aoq-lb-close').textContent = T.close;
	document.getElementById('aoq-lb-close').onclick = function () { lb.close(); };
	lb.addEventListener('click', function (e) { if (e.target === lb) { lb.close(); } });
	function lbShow(i) {
		lbAt = (i + lbList.length) % lbList.length;
		var img = lbList[lbAt];
		var pic = document.getElementById('aoq-lb-img'); pic.src = img.full; pic.alt = img.alt;
		document.getElementById('aoq-lb-title').textContent = img.key + (img.caption ? ' - ' + img.caption : '');
		document.getElementById('aoq-lb-count').textContent = (lbAt + 1) + ' / ' + lbList.length;
		var meta = document.getElementById('aoq-lb-meta'); meta.textContent = '';
		var dl = document.createElement('dl');
		[[T.alt, img.alt], [T.caption, img.caption], [T.sourceL, img.source], [T.scoreL, typeof img.score === 'number' ? img.score + ' / 10' : ''], [T.license, img.license], [T.attribution, img.attribution]].forEach(function (r) {
			if (!r[1]) { return; }
			var dt = document.createElement('dt'); dt.textContent = r[0]; var dd = document.createElement('dd'); dd.style.margin = '0'; dd.textContent = r[1];
			dl.appendChild(dt); dl.appendChild(dd);
		});
		if (img.page || img.full) {
			var dt = document.createElement('dt'); dt.textContent = T.open; var dd = document.createElement('dd'); dd.style.margin = '0';
			var a = document.createElement('a'); a.href = img.page || img.full; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = img.page || img.full;
			dd.appendChild(a); dl.appendChild(dt); dl.appendChild(dd);
		}
		meta.appendChild(dl);
		var many = lbList.length > 1;
		document.getElementById('aoq-lb-prev').style.display = many ? '' : 'none';
		document.getElementById('aoq-lb-next').style.display = many ? '' : 'none';
	}
	document.getElementById('aoq-lb-prev').onclick = function () { lbShow(lbAt - 1); };
	document.getElementById('aoq-lb-next').onclick = function () { lbShow(lbAt + 1); };
	lb.addEventListener('keydown', function (e) {
		if (e.key === 'ArrowLeft') { lbShow(lbAt - 1); e.preventDefault(); }
		if (e.key === 'ArrowRight') { lbShow(lbAt + 1); e.preventDefault(); }
	});
	window.aoqLightbox = function (list, i) {
		lbList = list; lbShow(i || 0);
		if (lb.showModal) { lb.showModal(); } else { lb.setAttribute('open', ''); }
	};
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('a.aoq-full');
		if (!a) { return; }
		e.preventDefault();
		open(a.dataset.title, a.dataset.full);
	});
})();
</script>
		<?php
	}

	/**
	 * The run panel: status card, progress bar, numbered steps with what each did, its time
	 * and AO's readable answers, the raw AO log, and the posts or the test preview.
	 * Live (polls admin-ajax while the run is active) or, for a past run, static from its
	 * saved report.
	 *
	 * @param array|null $run    Run row.
	 * @param bool       $active A run is active or about to be.
	 * @param array|null $static Payload of a past run (no polling).
	 */
	private static function render_run_panel( $run, $active, $static = null ) {
		self::render_full_text_modal();
		echo '<style>
.aoq-card{display:flex;gap:12px;align-items:center;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #72aee6;padding:12px 16px;margin:16px 0;max-width:900px}
.aoq-card.aoq-ok{border-left-color:#00a32a}.aoq-card.aoq-bad{border-left-color:#d63638}.aoq-card.aoq-wait{border-left-color:#dba617}
.aoq-card.aoq-stale{border-left-color:#d63638;background:#fcf0f1}#aoq-stale{margin-top:8px}
.aoq-card h3{margin:0 0 4px;font-size:15px}.aoq-card p{margin:0;color:#50575e}
.aoq-spin{width:18px;height:18px;border:3px solid #c3c4c7;border-top-color:#2271b1;border-radius:50%;animation:aoqspin 1s linear infinite;flex:none}
@keyframes aoqspin{to{transform:rotate(360deg)}}
.aoq-bar{background:#dcdcde;border-radius:3px;height:22px;max-width:900px;position:relative;margin:8px 0 16px}
.aoq-bar>div{background:#2271b1;height:22px;border-radius:3px;width:0%;transition:width .4s}
.aoq-bar>span{position:absolute;left:8px;top:2px;color:#fff;font-weight:600;text-shadow:0 0 2px #000}
.aoq-steps{list-style:none;margin:0;padding:0;max-width:900px}
.aoq-step{background:#fff;border:1px solid #dcdcde;margin:0 0 6px;padding:8px 12px}
.aoq-step>summary{cursor:pointer;display:flex;gap:10px;align-items:baseline;list-style:none}
.aoq-step>summary::-webkit-details-marker{display:none}
.aoq-ico{width:20px;text-align:center;font-weight:700;flex:none}
.aoq-num{min-width:22px;height:22px;line-height:22px;border-radius:11px;background:#f0f0f1;color:#50575e;text-align:center;font-weight:600;font-size:12px;flex:none}
.aoq-step.running .aoq-num{background:#2271b1;color:#fff}.aoq-step.done .aoq-num{background:#edfaef;color:#00a32a}.aoq-step.failed .aoq-num{background:#fcf0f1;color:#d63638}
.aoq-step.done .aoq-ico{color:#00a32a}.aoq-step.failed .aoq-ico{color:#d63638}.aoq-step.pending{opacity:.65}.aoq-step.running{border-color:#2271b1}
.aoq-step .aoq-t{font-weight:600}.aoq-step .aoq-d{color:#646970;flex:1}.aoq-step .aoq-m{color:#646970;white-space:nowrap}
.aoq-step.running .aoq-m{color:#2271b1;font-weight:600;font-variant-numeric:tabular-nums}
.aoq-step.running .aoq-m .aoq-avg{color:#646970;font-weight:400}.aoq-step.running .aoq-m.aoq-over{color:#996800}
#aoq-eta{margin-top:4px;font-weight:600;color:#2271b1}
.aoq-step.running>summary{background:linear-gradient(90deg,#3c434a 0%,#3c434a 42%,#72aee6 50%,#3c434a 58%,#3c434a 100%);background-size:250% 100%;-webkit-background-clip:text;background-clip:text;animation:aoqshimmer 2.6s linear infinite}
.aoq-step.running>summary .aoq-t,.aoq-step.running>summary .aoq-d{color:transparent}
@keyframes aoqshimmer{from{background-position:100% 0}to{background-position:0 0}}
@media (prefers-reduced-motion:reduce){.aoq-step.running>summary{animation:none;background:none}.aoq-step.running>summary .aoq-t{color:#1d2327}.aoq-step.running>summary .aoq-d{color:#646970}}
.aoq-body{margin:8px 0 2px 30px}.aoq-body ul{margin:4px 0 8px 0}.aoq-body li{margin:2px 0}
.aoq-ans{border-top:1px solid #f0f0f1;padding-top:6px;margin-top:6px}.aoq-ans h4{margin:0 0 4px;font-size:13px}
.aoq-ans dl{display:grid;grid-template-columns:max-content 1fr;gap:2px 12px;margin:0}.aoq-ans dt{font-weight:600}.aoq-ans dd{margin:0;white-space:pre-wrap}
.aoq-fail{color:#d63638}
.aoq-subs{margin:2px 0 10px 0;max-width:820px}
.aoq-subrow{display:flex;gap:6px;align-items:flex-start;margin-top:4px}
.aoq-subrow>em{font-size:11px;color:#646970;min-width:54px;font-style:normal;padding-top:0}
.aoq-subrow>em.aoq-now a{font-weight:700}.aoq-now-tag{display:block;color:#2271b1;font-size:10px}
.aoq-chip{display:block;font-size:10px;font-weight:600;max-width:130px;line-height:1.3}.aoq-chip.pass{color:#00a32a}.aoq-chip.fail{color:#d63638}
.aoq-sub{flex:1;min-width:0}
.aoq-sub>i{display:block;height:8px;background:#dcdcde;border-radius:4px;overflow:hidden}
.aoq-sub>i>b{display:block;height:100%;width:0;background:#2271b1;border-radius:4px;transition:width .6s linear}
.aoq-sub.done>i>b{width:100%}
.aoq-sub.failed>i>b{width:100%;background:#d63638}
.aoq-sub.skipped{opacity:.45}
.aoq-sub.running.aoq-indet>i>b{width:35%;animation:aoqslide 1.6s ease-in-out infinite}
@keyframes aoqslide{from{margin-left:-35%}to{margin-left:100%}}
.aoq-sub>small{display:block;font-size:11px;line-height:16px;color:#646970;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.aoq-sub.running>small{color:#2271b1;font-weight:600}
@media (prefers-reduced-motion:reduce){.aoq-sub.running.aoq-indet>i>b{animation:none;width:100%;opacity:.5}}
</style>';

		echo '<div id="aoq-test" data-run="' . esc_attr( $run ? $run['run_id'] : '' ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'aoq_live' ) ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-active="' . esc_attr( $active ? '1' : '0' ) . '" data-static="' . ( null !== $static ? '1' : '0' ) . '">';
		if ( null !== $static ) {
			echo '<script type="application/json" id="aoq-static">' . wp_json_encode( $static, JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>';
		}
		echo '<div id="aoq-card" class="aoq-card"><div id="aoq-spin" class="aoq-spin" style="display:none"></div><div><h3 id="aoq-card-title">' . esc_html( $run ? __( 'Loading...', 'ao-quill' ) : __( 'No run yet', 'ao-quill' ) ) . '</h3><p id="aoq-card-text">' . esc_html( $run ? '' : __( 'Runs show here with every step, what it does, and what AO answered.', 'ao-quill' ) ) . '</p><p id="aoq-eta"></p></div></div>';
		echo '<div class="aoq-bar"><div id="aoq-bar"></div><span id="aoq-pct">0%</span></div>';
		echo '<h2>' . esc_html__( 'Steps', 'ao-quill' ) . '</h2>';
		echo '<ol id="aoq-steps" class="aoq-steps">';
		$n = 0;
		foreach ( AOQ_Steps::definitions() as $id => $def ) {
			++$n;
			echo '<li><details class="aoq-step pending" data-step="' . esc_attr( $id ) . '"><summary><span class="aoq-num">' . (int) $n . '</span><span class="aoq-ico">○</span><span class="aoq-t">' . esc_html( $def[0] ) . '</span><span class="aoq-d">' . esc_html( $def[1] ) . '</span><span class="aoq-m"></span></summary><div class="aoq-body"></div></details></li>';
		}
		echo '</ol>';
		echo '<details style="max-width:900px;margin-top:16px"><summary style="cursor:pointer;font-weight:600">' . esc_html__( 'Technical log (raw AO output)', 'ao-quill' ) . '</summary>';
		echo '<pre id="aoq-output" style="background:#1d2327;color:#f0f0f1;padding:12px;max-height:520px;overflow:auto;white-space:pre-wrap;font-size:12px"></pre></details>';

		if ( $run && 'succeeded' === $run['status'] && 'test' !== $run['run_trigger'] ) {
			$result = json_decode( (string) $run['result'], true );
			echo '<h2>' . esc_html__( 'Posts created (pending review)', 'ao-quill' ) . '</h2><ul>';
			foreach ( (array) ( $result['posts'] ?? array() ) as $post ) {
				switch_to_blog( (int) $post['blog_id'] );
				$title = get_the_title( (int) $post['post_id'] );
				$edit  = get_edit_post_link( (int) $post['post_id'], 'raw' );
				restore_current_blog();
				echo '<li><strong>' . esc_html( strtoupper( $post['language'] ) ) . '</strong> ' . esc_html( $title ) . ' - <a href="' . esc_url( (string) $edit ) . '">' . esc_html__( 'Review and edit', 'ao-quill' ) . '</a> | <a href="' . esc_url( $post['url'] ) . '">' . esc_html__( 'Preview', 'ao-quill' ) . '</a></li>';
			}
			echo '</ul>';
		}
		if ( $run && 'succeeded' === $run['status'] && 'test' === $run['run_trigger'] ) {
			$result  = json_decode( (string) $run['result'], true );
			$preview = $result['preview']['posts'] ?? array();
			echo '<h2>' . esc_html__( 'Preview: what would have been published', 'ao-quill' ) . '</h2>';
			if ( ! $preview ) {
				echo '<p>' . esc_html__( 'No preview was reported.', 'ao-quill' ) . '</p>';
			}
			$images = array_column( AOQ_Steps::run_gallery( $run ), null, 'key' );
			foreach ( $preview as $post ) {
				echo '<div style="border:1px solid #c3c4c7;background:#fff;padding:16px;margin:12px 0;max-width:820px">';
				echo '<p class="description">' . esc_html( strtoupper( $post['language'] ) ) . '</p>';
				// Laid out as the theme shows a post: title, featured image, body. The excerpt only
				// appears in post lists, so it is shown apart.
				echo '<p class="description">' . esc_html__( 'Excerpt (shown in post lists):', 'ao-quill' ) . ' <em>' . esc_html( wp_strip_all_tags( $post['excerpt'] ) ) . '</em></p>';
				echo '<h2 style="margin-top:0">' . esc_html( $post['title'] ) . '</h2>';
				if ( isset( $images['featured'] ) ) {
					echo self::preview_figure( $images['featured'], true ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in preview_figure.
				}
				// Image placeholders become the chosen images, as the commit would place them.
				$content = preg_replace_callback(
					'/<!--\s*aoq:image\s+(featured|inline-[0-9]+)\s*-->/',
					function ( $m ) use ( $images ) {
						return isset( $images[ $m[1] ] ) ? self::preview_figure( $images[ $m[1] ], false ) : '';
					},
					(string) $post['content']
				);
				echo '<div class="aoq-preview-content">' . wp_kses_post( $content ) . '</div>';
				echo '</div>';
			}
		}
		echo '</div>';
		$i18n = array(
			'waiting'   => __( 'Waiting for the worker', 'ao-quill' ),
			'waitingT'  => __( 'The worker picks up the run on its next poll (every minute).', 'ao-quill' ),
			'running'   => __( '%1$s in progress', 'ao-quill' ),
			'kinds'     => array(
				'scheduled' => __( 'Scheduled run', 'ao-quill' ),
				'manual'    => __( 'Manual run', 'ao-quill' ),
				'test'      => __( 'Test run', 'ao-quill' ),
			),
			'stepOf'    => __( 'Step %1$d of %2$d: %3$s', 'ao-quill' ),
			'silentT'   => __( '%1$s: no word from the worker for %2$s', 'ao-quill' ),
			'silentHb'  => __( 'The worker last reported at %1$s and normally reports every minute, so it has probably stopped (a server restart, or it was stopped by hand). If it stays silent, the run is marked failed at %2$s; mark it as stopped now to resume it straight away.', 'ao-quill' ),
			'silentOld' => __( 'The worker last reported at %1$s. A long AO call can be quiet for a while, but this is long: the worker may have stopped. If it stays silent, the run is marked failed at %2$s; mark it as stopped now to resume it straight away.', 'ao-quill' ),
			'stopBtn'   => __( 'Mark as stopped', 'ao-quill' ),
			'stopQ'     => __( 'Mark this run as stopped? If its worker is in fact still working, its results will be refused. You can resume the run from the step where it stopped.', 'ao-quill' ),
			'stopUrl'   => self::action_url( 'aoq_mark_stopped' ),
			'stopNonce' => wp_create_nonce( 'aoq_mark_stopped' ),
			'barRun'    => __( '%1$d%% · step %2$d of %3$d', 'ao-quill' ),
			'barDone'   => __( '%1$d%% · %2$d of %3$d steps', 'ao-quill' ),
			'started'   => __( 'started %1$s (%2$s ago)', 'ao-quill' ),
			'succeeded' => __( '%1$s finished', 'ao-quill' ),
			'succT'     => __( 'Nothing was written to the site. The preview is below.', 'ao-quill' ),
			'succReal'  => __( 'The posts are saved for review. Links are below.', 'ao-quill' ),
			'failed'    => __( '%1$s failed', 'ao-quill' ),
			'expired'   => __( '%1$s expired', 'ao-quill' ),
			'answer'    => __( 'AO answer from %1$s (%2$s s)', 'ao-quill' ),
			'noResult'  => __( 'Not reached yet.', 'ao-quill' ),
			'avg'       => __( '≈ %1$s', 'ao-quill' ),
			'avgTitle'  => __( 'Average of %1$s past runs', 'ao-quill' ),
			'over'      => __( '+%1$s over', 'ao-quill' ),
			'left'      => __( 'About %1$s left (finish ≈ %2$s), estimated from past runs.', 'ao-quill' ),
			'leftMin'   => __( 'At least %1$s left; some steps have no history yet.', 'ao-quill' ),
			'noEta'     => __( 'No estimate yet: estimates come from past runs.', 'ao-quill' ),
			'working'   => __( 'Working on it. Results appear when AO answers.', 'ao-quill' ),
			'instant'   => __( 'Done instantly: reused cached results or no AO call needed.', 'ao-quill' ),
			'ran'       => __( 'Ran %1$s, took %2$s.', 'ao-quill' ),
			'showFull'  => __( 'Show full', 'ao-quill' ),
			'gallery'   => __( 'View chosen images (%1$d)', 'ao-quill' ),
			'trNow'     => __( 'translating now', 'ao-quill' ),
			'chipPass'  => __( '✓ passed', 'ao-quill' ),
		);
		?>
<script>
(function () {
	var box = document.getElementById('aoq-test');
	if (!box) { return; }
	var T = <?php echo wp_json_encode( $i18n ); ?>;
	var runId = box.dataset.run, offset = 0, timer = null, wasActive = box.dataset.active === '1';
	var stepStart = null, skew = 0, tick = null, lastSteps = [], isRunning = false;
	function clock(sec) { sec = Math.max(0, Math.floor(sec)); var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60; return (h ? h + ' h ' : '') + (h || m ? m + ' min ' : '') + s + ' s'; }
	function ring(pct, over) {
		var c = 2 * Math.PI * 15, color = over ? '#dba617' : '#2271b1';
		return '<svg width="18" height="18" viewBox="0 0 36 36" style="vertical-align:-3px" aria-hidden="true">'
			+ '<circle cx="18" cy="18" r="15" fill="none" stroke="#dcdcde" stroke-width="5"/>'
			+ '<circle cx="18" cy="18" r="15" fill="none" stroke="' + color + '" stroke-width="5" stroke-linecap="round" transform="rotate(-90 18 18)"'
			+ ' stroke-dasharray="' + c.toFixed(2) + '" stroke-dashoffset="' + (c * (1 - pct / 100)).toFixed(2) + '"/></svg>';
	}
	function short(sec) { sec = Math.max(0, Math.round(sec)); if (sec < 90) { return sec + ' s'; } var m = Math.round(sec / 60); return m < 90 ? m + ' min' : Math.floor(m / 60) + ' h ' + (m % 60) + ' min'; }
	function tickTimer() {
		var cur = lastSteps.filter(function (s) { return s.status === 'running'; })[0];
		var m = document.querySelector('.aoq-step.running .aoq-m');
		var elapsed = stepStart ? (Date.now() + skew - stepStart) / 1000 : 0;
		if (stepStart) { tickSub(elapsed); }
		if (m && stepStart) {
			if (cur && cur.avg_ms) {
				// With an average: a progress ring in place of the spinner and a percentage on the right.
				// Past the average there is no meaningful percentage: back to the spinner and elapsed time.
				var avg = cur.avg_ms / 1000, over = elapsed > avg;
				var ico = document.querySelector('.aoq-step.running .aoq-ico');
				if (over) {
					if (ico && !ico.querySelector('.aoq-spin')) { ico.innerHTML = '<span class="aoq-spin" style="display:inline-block;width:12px;height:12px;border-width:2px"></span>'; }
					m.textContent = clock(elapsed);
				} else {
					var pct = Math.min(99, Math.floor(100 * elapsed / avg));
					if (ico) { ico.innerHTML = ring(pct, false); }
					m.textContent = pct + '%';
				}
				m.className = 'aoq-m';
				m.title = clock(elapsed) + ' / ' + fmt(T.avg, short(avg)) + (over ? ' (' + fmt(T.over, short(elapsed - avg)) + ')' : '') + ' - ' + fmt(T.avgTitle, cur.avg_runs);
			} else {
				m.textContent = clock(elapsed);
				m.className = 'aoq-m';
				m.title = '';
			}
		}
		var eta = document.getElementById('aoq-eta');
		if (!isRunning) { return; }
		var left = 0, unknown = false, any = false;
		lastSteps.forEach(function (s) {
			// An average of 0 s (a step with nothing to do) is history too: test for a number, not truthiness.
			var has = typeof s.avg_ms === 'number';
			if (s.status === 'pending') { if (has) { left += s.avg_ms / 1000; any = true; } else { unknown = true; } }
			if (s.status === 'running') { if (has) { left += Math.max(0, s.avg_ms / 1000 - elapsed); any = true; } else { unknown = true; } }
		});
		if (!any) { eta.textContent = T.noEta; return; }
		var finish = new Date(Date.now() + left * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
		eta.textContent = unknown ? fmt(T.leftMin, short(left)) : fmt(T.left, short(left), finish);
	}
	var out = document.getElementById('aoq-output');
	var icons = { done: '✓', running: '', failed: '✗', pending: '○' };
	function fmt(s) { var a = Array.prototype.slice.call(arguments, 1); var i = 0; return s.replace(/%(\d)\$[sd]/g, function (m, n) { return a[n - 1]; }).replace(/%%/g, '%'); }
	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text !== undefined) { e.textContent = text; } return e; }
	function ago(iso) { var s = Math.max(0, (Date.now() - Date.parse(iso)) / 1000); return s < 90 ? Math.round(s) + ' s' : (s < 5400 ? Math.round(s / 60) + ' min' : (s / 3600).toFixed(1) + ' h'); }
	function dur(ms) { var s = ms / 1000; return !ms ? '' : (s < 90 ? Math.round(s) + ' s' : Math.round(s / 60) + ' min'); }
	function card(d) {
		var c = document.getElementById('aoq-card'), sp = document.getElementById('aoq-spin');
		var title = document.getElementById('aoq-card-title'), text = document.getElementById('aoq-card-text');
		c.className = 'aoq-card'; sp.style.display = 'none';
		if (!d.run_id || (d.waiting && !(d.status === 'claimed' || d.status === 'running'))) {
			if (d.waiting) { c.className += ' aoq-wait'; sp.style.display = ''; title.textContent = T.waiting; text.textContent = T.waitingT; }
			return;
		}
		var kind = (T.kinds && T.kinds[d.trigger]) || T.kinds.manual;
		var list = d.steps_detail || [], cur = null, curNo = 0;
		list.forEach(function (s, i) { if (s.status === 'running') { cur = s; curNo = i + 1; } });
		var stale = document.getElementById('aoq-stale');
		if (stale) { stale.remove(); }
		if (d.status === 'claimed' || d.status === 'running') {
			sp.style.display = ''; title.textContent = fmt(T.running, kind);
			text.textContent = (cur ? fmt(T.stepOf, curNo, list.length, cur.title) + ', ' : '') + (d.started_at ? fmt(T.started, new Date(d.started_at).toLocaleTimeString(), ago(d.started_at)) : '');
			// The worker reports every minute (heartbeat); older workers only when there is output,
			// which a long AO call can keep quiet for up to its time limit.
			var si = d.silence, quiet = si ? si.seconds : 0;
			if (si && quiet > (si.heartbeat ? 180 : 900)) {
				c.className += ' aoq-stale'; sp.style.display = 'none';
				title.textContent = fmt(T.silentT, kind, ago(si.last));
				text.textContent = fmt(si.heartbeat ? T.silentHb : T.silentOld, new Date(si.last).toLocaleTimeString(), new Date(si.closes_at).toLocaleTimeString());
				var f = el('form'); f.id = 'aoq-stale'; f.method = 'post'; f.action = T.stopUrl;
				f.onsubmit = function () { return confirm(T.stopQ); };
				[['_wpnonce', T.stopNonce], ['run_id', d.run_id]].forEach(function (kv) { var i = el('input'); i.type = 'hidden'; i.name = kv[0]; i.value = kv[1]; f.appendChild(i); });
				var b = el('button', 'button', T.stopBtn); b.type = 'submit'; f.appendChild(b);
				text.parentNode.appendChild(f);
			}
		} else if (d.status === 'succeeded') {
			c.className += ' aoq-ok'; title.textContent = fmt(T.succeeded, kind); text.textContent = d.trigger === 'test' ? T.succT : T.succReal;
		} else {
			c.className += ' aoq-bad'; title.textContent = fmt(d.status === 'expired' ? T.expired : T.failed, kind); text.textContent = d.failure_reason || '';
		}
		var when = document.getElementById('aoq-eta');
		if (d.finished && d.started_at) {
			var took = d.finished_at ? (Date.parse(d.finished_at) - Date.parse(d.started_at)) / 1000 : 0;
			when.textContent = fmt(T.ran, new Date(d.started_at).toLocaleString(), took ? short(took) : '?');
		}
	}
	function steps(list) {
		(list || []).forEach(function (s) {
			var det = document.querySelector('.aoq-step[data-step="' + s.id + '"]');
			if (!det) { return; }
			// Redraw a step only when its data changed: rebuilding every poll restarted spinners and
			// bar animations and reloaded thumbnails every 3 seconds (a visible stutter).
			var sig = JSON.stringify(s);
			if (det.dataset.sig === sig) { return; }
			det.dataset.sig = sig;
			det.className = 'aoq-step ' + s.status;
			var ico = det.querySelector('.aoq-ico');
			ico.textContent = icons[s.status] || '';
			ico.innerHTML = s.status === 'running' ? '<span class="aoq-spin" style="display:inline-block;width:12px;height:12px;border-width:2px"></span>' : ico.textContent;
			var meta = det.querySelector('.aoq-m');
			if (s.status === 'pending') {
				meta.textContent = typeof s.avg_ms === 'number' ? fmt(T.avg, short(s.avg_ms / 1000)) : '';
				meta.title = typeof s.avg_ms === 'number' ? fmt(T.avgTitle, s.avg_runs) + ': ' + short(s.avg_ms / 1000) : '';
			} else if (s.status === 'done' || s.status === 'failed') {
				// A finished step always shows its time; reused cached results took 0 s.
				meta.textContent = s.duration_ms ? dur(s.duration_ms) : '0 s';
				meta.title = s.duration_ms ? '' : T.instant;
			} else {
				meta.textContent = dur(s.duration_ms);
				meta.title = '';
			}
			var body = det.querySelector('.aoq-body');
			body.textContent = '';
			if (s.failure) { body.appendChild(el('p', 'aoq-fail', s.failure)); }
			// Steps whose rounds open in the rounds gallery (QA) show only the round bars here.
			var inRounds = (s.substeps || []).some(function (r) { return r.detail; });
			if (s.summary.length && !inRounds) {
				var ul = el('ul');
				s.summary.forEach(function (l) {
					var li = el('li');
					if (typeof l === 'string') { li.textContent = l; } else { li.appendChild(fullLink(l.text, l.title, l.full)); }
					ul.appendChild(li);
				});
				body.appendChild(ul);
			}
			(inRounds ? [] : s.answers).forEach(function (a) {
				var w = el('div', 'aoq-ans'); w.appendChild(el('h4', '', fmt(T.answer, a.task, a.seconds)));
				var dl = el('dl'); a.fields.forEach(function (f) {
					dl.appendChild(el('dt', '', f[0]));
					if (window.aoqIsScore && window.aoqIsScore(f[0], f[1])) {
						var gd = el('dd'); gd.appendChild(window.aoqGauge(parseFloat(f[1]), null)); dl.appendChild(gd);
						return;
					}
					var dd = el('dd', '', f[1]);
					if (f.length > 2) { dd.appendChild(document.createTextNode(' ')); dd.appendChild(fullLink(T.showFull, (f[0] || a.task) + ' - ' + a.task, f[2])); }
					dl.appendChild(dd);
				});
				w.appendChild(dl); body.appendChild(w);
			});
			if (s.gallery && s.gallery.length) {
				// Chosen images: thumbnails that open the lightbox gallery.
				var g = el('div', 'aoq-gallery');
				s.gallery.forEach(function (img, i) {
					var b = el('button', 'aoq-thumb'); b.type = 'button'; b.title = img.caption || img.alt || img.key;
					var im = el('img'); im.src = img.thumb; im.alt = img.alt; im.loading = 'lazy'; b.appendChild(im);
					b.appendChild(el('span', '', img.key));
					b.onclick = function () { window.aoqLightbox(s.gallery, i); };
					g.appendChild(b);
				});
				var open = el('p'); var link = el('a', 'button button-small', fmt(T.gallery, s.gallery.length)); link.href = '#';
				link.onclick = function (e) { e.preventDefault(); window.aoqLightbox(s.gallery, 0); };
				open.appendChild(link);
				body.insertBefore(g, body.firstChild);
				body.insertBefore(open, g);
			}
			if (!body.childNodes.length) { body.appendChild(el('p', 'description', s.status === 'running' ? T.working : T.noResult)); }
			subs(det, s);
		});
	}
	function fullLink(text, title, full) {
		var a = el('a', 'aoq-full', text); a.href = '#'; a.dataset.title = title || ''; a.dataset.full = full;
		return a;
	}
	// Sub-steps (QA checks, research parts, image passes): one bar each, filling left to right.
	function subs(det, s) {
		var box = det.querySelector('.aoq-subs');
		var rows = s.substeps || [];
		if (!rows.length) { if (box) { box.remove(); } return; }
		// Inside the step's body, so the bars show only when the step is expanded.
		if (!box) { box = el('div', 'aoq-subs'); var bd = det.querySelector('.aoq-body'); bd.insertBefore(box, bd.firstChild); }
		box.textContent = '';
		var doneSec = 0;
		rows.forEach(function (row, ri) {
			var r = el('div', 'aoq-subrow');
			if (row.label && row.detail) {
				// QA rounds and translation languages: the label opens the gallery at this row.
				var em = el('em', row.current ? 'aoq-now' : ''); var a = el('a', '', row.label); a.href = '#';
				a.onclick = function (e) { e.preventDefault(); window.aoqRounds(rows, ri, s.title); };
				em.appendChild(a);
				if (row.current) { em.appendChild(el('span', 'aoq-now-tag', T.trNow)); }
				var dl = row.detail;
				if (dl.kind === 'language' && (dl.status === 'passed' || (dl.holding && dl.holding.length))) {
					// Pass or fail at a glance; the language's view has every score.
					var chip = el('span', 'aoq-chip ' + (dl.status === 'passed' ? 'pass' : 'fail'), dl.status === 'passed' ? T.chipPass : '✗ ' + dl.holding[0]);
					em.appendChild(chip);
				}
				r.appendChild(em);
			} else if (row.label) { r.appendChild(el('em', '', row.label)); }
			row.bars.forEach(function (b) {
				var cls = 'aoq-sub ' + b.status + (b.status === 'running' && !b.avg_s ? ' aoq-indet' : '');
				var w = el('span', cls); w.dataset.avg = b.avg_s || '';
				var track = el('i'); track.appendChild(el('b')); w.appendChild(track);
				var t = b.status === 'done' || b.status === 'failed' ? (b.seconds ? dur(b.seconds * 1000) : '0 s') : (b.status === 'pending' && b.avg_s ? fmt(T.avg, short(b.avg_s)) : '');
				w.appendChild(el('small', '', b.label + (t ? ' · ' + t : '')));
				w.title = b.label + (b.avg_s ? ' - ' + fmt(T.avg, short(b.avg_s)) : '');
				r.appendChild(w);
				if (b.status === 'done') { doneSec += b.seconds; }
			});
			box.appendChild(r);
		});
		box.dataset.done = doneSec;
	}
	function tickSub(elapsed) {
		var box = document.querySelector('.aoq-step.running .aoq-subs');
		var bar = box && box.querySelector('.aoq-sub.running');
		if (!bar) { return; }
		var avg = parseFloat(bar.dataset.avg || '0');
		var since = Math.max(0, elapsed - parseFloat(box.dataset.done || '0'));
		var label = bar.querySelector('small');
		if (!label.dataset.base) { label.dataset.base = label.textContent; }
		if (avg && since <= avg) {
			bar.classList.remove('aoq-indet');
			bar.querySelector('b').style.width = Math.min(99, Math.floor(100 * since / avg)) + '%';
			label.textContent = label.dataset.base + ' · ' + Math.min(99, Math.floor(100 * since / avg)) + '%';
		} else {
			// No history, or past the average: a moving stripe and the time so far.
			bar.classList.add('aoq-indet');
			bar.querySelector('b').style.width = '';
			label.textContent = label.dataset.base + ' · ' + clock(since);
		}
	}
	function poll() {
		if (box.dataset.static === '1') {
			// A past run: render its saved report once; nothing to poll.
			var data = JSON.parse(document.getElementById('aoq-static').textContent);
			wasActive = false;
			render(data);
			return;
		}
		var url = box.dataset.ajax + '?action=aoq_live&_ajax_nonce=' + encodeURIComponent(box.dataset.nonce) + '&offset=' + offset + (runId ? '&run_id=' + encodeURIComponent(runId) : '');
		fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
			if (!res || !res.success) { schedule(); return; }
			render(res.data);
		}).catch(schedule);
	}
	function render(d) {
		{
			card(d);
			if (!d.run_id) { schedule(); return; }
			if (runId !== d.run_id) { runId = d.run_id; offset = 0; out.textContent = ''; }
			var stick = out.scrollTop + out.clientHeight >= out.scrollHeight - 20;
			if (d.output) { out.textContent += d.output; }
			offset = d.offset;
			if (stick) { out.scrollTop = out.scrollHeight; }
			document.getElementById('aoq-bar').style.width = d.percent + '%';
			var running = d.status === 'claimed' || d.status === 'running';
			var curNo = 0; (d.steps_detail || []).forEach(function (s, i) { if (s.status === 'running') { curNo = i + 1; } });
			document.getElementById('aoq-pct').textContent = running && curNo ? fmt(T.barRun, d.percent, curNo, d.steps) : fmt(T.barDone, d.percent, Math.min(d.step, d.steps), d.steps);
			steps(d.steps_detail);
			lastSteps = d.steps_detail || []; isRunning = running;
			if (d.server_now) { skew = Date.parse(d.server_now) - Date.now(); }
			stepStart = running && d.step_started ? Date.parse(d.step_started) : null;
			clearInterval(tick);
			tickTimer();
			if (stepStart) { tick = setInterval(tickTimer, 1000); }
			if (d.finished) {
				if (d.waiting) {
					// A new run was requested and the worker has not picked it up yet (up to a minute):
					// the run shown is the previous, finished one. Keep polling; reloading here looped
					// as fast as the page could load.
					wasActive = true; schedule(); return;
				}
				// Reload once when the followed run finishes, to show its preview or post links;
				// never twice for the same run.
				var key = 'aoq_reloaded_' + d.run_id, done = false;
				try { done = sessionStorage.getItem(key) === '1'; sessionStorage.setItem(key, '1'); } catch (e) {}
				if (wasActive && !done) { window.location.reload(); }
				return;
			}
			wasActive = true;
			schedule();
		}
	}
	function schedule() { clearTimeout(timer); timer = setTimeout(poll, 3000); }
	poll();
})();
</script>
		<?php
	}


	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = array(
			'settings' => __( 'Settings', 'ao-quill' ),
			'generation' => __( 'Post generation', 'ao-quill' ),
			'runs'     => __( 'Past runs', 'ao-quill' ),
			'status'   => __( 'Worker and AO status', 'ao-quill' ),
			'ig_generation' => __( 'Instagram generation', 'ao-quill' ),
			'instagram' => __( 'Instagram', 'ao-quill' ),
		);
		echo '<div class="wrap"><h1>' . esc_html( AOQ_NAME ) . '</h1>';
		$notices = get_site_transient( 'aoq_notices_' . get_current_user_id() );
		if ( $notices ) {
			delete_site_transient( 'aoq_notices_' . get_current_user_id() );
			foreach ( (array) $notices as $notice ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( $notice ) . '</p></div>';
			}
		} elseif ( ! empty( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'ao-quill' ) . '</p></div>';
		}
		// While a run is active or waiting, the Generation tab shimmers with a spinner beside it, on
		// every tab; a light poll keeps it current without reloading the page.
		$busy = (bool) AOQ_Runs::current() || AOQ_Runs::test_requested() || AOQ_Runs::run_now_requested();
		echo '<style>
.nav-tab .aoq-tab-prog{display:none;margin-right:7px;vertical-align:-2px;font-size:12px;font-weight:600;color:#2271b1;font-variant-numeric:tabular-nums}
.nav-tab.aoq-busy .aoq-tab-prog{display:inline-flex;align-items:center;gap:4px}
.nav-tab .aoq-tab-spin{display:inline-block;width:11px;height:11px;border:2px solid #c3c4c7;border-top-color:#2271b1;border-radius:50%;animation:aoqspin 1s linear infinite}
.nav-tab.aoq-busy .aoq-tab-label{background:linear-gradient(90deg,#3c434a 0%,#3c434a 40%,#72aee6 50%,#3c434a 60%,#3c434a 100%);background-size:250% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:aoqshimmer 2.6s linear infinite}
@keyframes aoqspin{to{transform:rotate(360deg)}}
@keyframes aoqshimmer{from{background-position:100% 0}to{background-position:0 0}}
@media (prefers-reduced-motion:reduce){.nav-tab.aoq-busy .aoq-tab-label{animation:none;background:none;color:#2271b1}.nav-tab .aoq-tab-spin{animation-duration:3s}}
</style>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $label ) {
			$ig_busy = 'ig_generation' === $id && AOQ_Instagram::job_active();
			$classes = 'nav-tab' . ( $id === $tab ? ' nav-tab-active' : '' ) . ( ( 'generation' === $id && $busy ) || $ig_busy ? ' aoq-busy' : '' );
			// Left of the label: the run's progress (ring and percentage), or a spinner when there
			// is no reliable estimate (over the estimate with steps left, or steps without history).
			$prog = '';
			$attr = '';
			if ( 'generation' === $id ) {
				$prog = '<span class="aoq-tab-prog" id="aoq-tab-prog" aria-hidden="true"><span class="aoq-tab-spin"></span></span>';
				$attr = ' id="aoq-gen-tab" title="' . esc_attr( $busy ? __( 'A run is in progress', 'ao-quill' ) : '' ) . '"';
			} elseif ( 'ig_generation' === $id ) {
				$prog = '<span class="aoq-tab-prog" id="aoq-ig-tab-prog" aria-hidden="true"><span class="aoq-tab-spin"></span></span>';
				$attr = ' id="aoq-ig-tab" title="' . esc_attr( $ig_busy ? __( 'An Instagram generation is in progress', 'ao-quill' ) : '' ) . '"';
			}
			printf(
				'<a class="%s" href="%s"%s>%s<span class="aoq-tab-label">%s</span></a>',
				esc_attr( $classes ),
				esc_url( self::url( array( 'tab' => $id ) ) ),
				$attr, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
				$prog, // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
				esc_html( $label )
			);
		}
		echo '</nav>';
		?>
<script>
(function () {
	var tab = document.getElementById('aoq-gen-tab');
	if (!tab) { return; }
	var prog = document.getElementById('aoq-tab-prog'), last = null, skew = 0, tick = null;
	function ring(pct) {
		var c = 2 * Math.PI * 15;
		return '<svg width="13" height="13" viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15" fill="none" stroke="#c3c4c7" stroke-width="6"/>'
			+ '<circle cx="18" cy="18" r="15" fill="none" stroke="#2271b1" stroke-width="6" stroke-linecap="round" transform="rotate(-90 18 18)" stroke-dasharray="' + c.toFixed(2) + '" stroke-dashoffset="' + (c * (1 - pct / 100)).toFixed(2) + '"/></svg>';
	}
	// Share of the run's estimated time already spent: done steps (their real time) and the running
	// step (time so far) against that plus the estimated time left. No percentage when the running
	// step is past its average, or a step has no history: then the estimate is not trustworthy.
	function percent(d) {
		var list = d.steps_detail || [], spent = 0, left = 0;
		var started = d.step_started ? Date.parse(d.step_started) : null;
		var elapsed = started ? Math.max(0, (Date.now() + skew - started) / 1000) : 0;
		for (var i = 0; i < list.length; i++) {
			var s = list[i], avg = typeof s.avg_ms === 'number' ? s.avg_ms / 1000 : null;
			if (s.status === 'done' || s.status === 'failed') {
				// A finished step counts at least its usual time: steps a resumed run reused take 0 s.
				spent += Math.max((s.duration_ms || 0) / 1000, avg || 0);
				continue;
			}
			if (avg === null) { return null; }
			if (s.status === 'running') {
				if (elapsed > avg) { return null; } // over the estimate with steps still left
				spent += elapsed; left += avg - elapsed;
			} else { left += avg; }
		}
		return spent + left > 0 ? Math.min(99, Math.floor(100 * spent / (spent + left))) : null;
	}
	function show() {
		var d = last;
		if (!d || !tab.classList.contains('aoq-busy')) { return; }
		var running = d.status === 'claimed' || d.status === 'running';
		var p = running ? percent(d) : null;
		prog.innerHTML = p === null ? '<span class="aoq-tab-spin"></span>' : ring(p) + '<span>' + p + '%</span>';
	}
	var url = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) . '?action=aoq_live&_ajax_nonce=' . wp_create_nonce( 'aoq_live' ) ); ?>;
	var busyTitle = <?php echo wp_json_encode( __( 'A run is in progress', 'ao-quill' ) ); ?>;
	function check() {
		fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
			if (!res || !res.success) { return; }
			var d = res.data, busy = !!d.waiting || d.status === 'claimed' || d.status === 'running';
			tab.classList.toggle('aoq-busy', busy);
			tab.title = busy ? busyTitle : '';
			if (d.server_now) { skew = Date.parse(d.server_now) - Date.now(); }
			last = d;
			show();
			clearInterval(tick);
			if (busy) { tick = setInterval(show, 1000); }
		}).catch(function () {}).then(function () { setTimeout(check, 10000); });
	}
	check();
})();
(function () {
	// Instagram generation tab: the same shimmer, ring and percentage while a job runs.
	var tab = document.getElementById('aoq-ig-tab');
	if (!tab) { return; }
	var prog = document.getElementById('aoq-ig-tab-prog'), last = null, at = 0, tick = null;
	var url = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) . '?action=aoq_ig_job&_ajax_nonce=' . wp_create_nonce( 'aoq_ig_job' ) ); ?>;
	var busyTitle = <?php echo wp_json_encode( __( 'An Instagram generation is in progress', 'ao-quill' ) ); ?>;
	function ring(pct) {
		var c = 2 * Math.PI * 15;
		return '<svg width="13" height="13" viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15" fill="none" stroke="#c3c4c7" stroke-width="6"/>'
			+ '<circle cx="18" cy="18" r="15" fill="none" stroke="#2271b1" stroke-width="6" stroke-linecap="round" transform="rotate(-90 18 18)" stroke-dasharray="' + c.toFixed(2) + '" stroke-dashoffset="' + (c * (1 - pct / 100)).toFixed(2) + '"/></svg>';
	}
	// Share of the job's estimated time spent; none when a step has no history or runs past it.
	function percent(d) {
		var spent = 0, left = 0, extra = (Date.now() - at) / 1000;
		for (var i = 0; i < d.steps.length; i++) {
			var s = d.steps[i];
			if (s.status === 'skipped') { continue; }
			if (s.status === 'done' || s.status === 'failed') { spent += Math.max(s.seconds || 0, s.avg || 0); continue; }
			if (s.avg === null) { return null; }
			if (s.status === 'running') {
				var el = (s.seconds || 0) + extra;
				if (el > s.avg) { return null; }
				spent += el; left += s.avg - el;
			} else { left += s.avg; }
		}
		return spent + left > 0 ? Math.min(99, Math.floor(100 * spent / (spent + left))) : null;
	}
	function show() {
		if (!last || !last.active) { return; }
		var p = percent(last);
		prog.innerHTML = p === null ? '<span class="aoq-tab-spin"></span>' : ring(p) + '<span>' + p + '%</span>';
	}
	function check() {
		fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
			if (!res || !res.success) { return; }
			var d = res.data, busy = !!(d && d.active);
			tab.classList.toggle('aoq-busy', busy);
			tab.title = busy ? busyTitle : '';
			last = d; at = Date.now();
			show();
			clearInterval(tick);
			if (busy) { tick = setInterval(show, 1000); }
			setTimeout(check, busy ? 3000 : 10000);
		}).catch(function () { setTimeout(check, 10000); });
	}
	check();
})();
</script>
		<?php
		if ( 'generation' === $tab || 'test' === $tab ) {
			self::render_generation();
		} elseif ( 'runs' === $tab ) {
			self::render_runs();
		} elseif ( 'status' === $tab ) {
			self::render_status();
		} elseif ( 'instagram' === $tab ) {
			AOQ_Admin_Instagram::render();
		} elseif ( 'ig_generation' === $tab ) {
			AOQ_Admin_Instagram::render_generation();
		} else {
			self::render_settings();
		}
		echo '</div>';
	}

	/**
	 * Settings form.
	 */
	/**
	 * Short description of a setting, shown by its help icon.
	 *
	 * @param string $key Setting.
	 * @return string
	 */
	private static function tip( $key ) {
		$t = array(
			'site_brief' => __( 'What the site is about, its audience, topics to favor and topics to avoid. Every step that plans or writes reads it.', 'ao-quill' ),
			'trend_seeds' => __( 'Places and themes the site writes about. Google search suggestions are gathered for these, and trending searches are kept only when they mention one.', 'ao-quill' ),
			'trend_geos' => __( 'Countries whose Google trending searches are checked, as two-letter codes: the site\'s own country first, then where visitors come from.', 'ao-quill' ),
			'schedule_time' => __( 'When the daily post is written, in the timezone below. The worker checks every minute and starts the run once this time has passed.', 'ao-quill' ),
			'timezone' => __( 'The timezone of the daily run time.', 'ao-quill' ),
			'publish_mode' => __( 'Pending review saves posts for an editor to approve; Publish makes them live at once. Enforced by the plugin; the worker cannot change it.', 'ao-quill' ),
			'author' => __( 'The user shown publicly as the post\'s author. The worker\'s own user is never the author.', 'ao-quill' ),
			'languages' => __( 'Each site in the network is one language. Excluded sites get no translation; posts are written in the main site\'s language.', 'ao-quill' ),
			'agents' => __( 'Which AO agent does each job, with its model and size. The QA agents must be a different model family from the writer, so drafts are never graded by their own family.', 'ao-quill' ),
			'mcp' => __( 'Tool providers AO uses for web search, page fetching and stock images. IDs starting with client. are served by the worker itself.', 'ao-quill' ),
			'threshold' => __( 'The score (0 to 1) this check must reach. Below it the draft is revised, up to the maximum revision loops; after that the run stops.', 'ao-quill' ),
			'originality_max_overlap' => __( 'The highest share of the post\'s 5-word sequences that may also appear in a source or an existing post. Lower is stricter.', 'ao-quill' ),
			'min_words' => __( 'The shortest post allowed. It is a rule for the draft and every revision, and QA\'s length check fails below it.', 'ao-quill' ),
			'max_revision_loops' => __( 'How many times the writer may revise a draft that fails a QA check before the run stops.', 'ao-quill' ),
			'min_verified_claims' => __( 'How many researched facts must pass the quote check before a post can be written.', 'ao-quill' ),
			'min_human_posts_for_style' => __( 'How many human-written posts a language needs before its writing style is learned from them.', 'ao-quill' ),
			'stage_timeout_seconds' => __( 'The longest a single AO call may take before it is stopped.', 'ao-quill' ),
			'lease_minutes' => __( 'How long a run may go without reporting before WordPress treats it as stopped. Every report extends it.', 'ao-quill' ),
			'ao_priority' => __( 'Queue priority of this site\'s AO calls among other AO users.', 'ao-quill' ),
			'inline_image_count' => __( 'How many images go inside the post body, besides the featured image.', 'ao-quill' ),
			'media_candidate_limit' => __( 'How many media library images, best matches first, are offered for the shortlist. The whole library is searched for what the post\'s photos should show; logos and maps are left out.', 'ao-quill' ),
			'image_min_score' => __( 'How well (0 to 10) an image must show the post\'s subject to go in the post body. 5-7 is closely related, 8-10 clearly shows it, 1-4 only the region.', 'ao-quill' ),
			'featured_min_score' => __( 'The score the featured image should reach; it must also be a landscape photo at least 1200 px wide. Stock searches continue until one is found; if none is, the best image below this mark is used.', 'ao-quill' ),
			'fallback_image_id' => __( 'Used as the featured image when no found image relates to the post at all, so a run never fails for lack of images. A media ID from the main site; leave 0 to use the best image found instead.', 'ao-quill' ),
			'image_source' => __( 'Where images may come from, and in which order the sources are tried.', 'ao-quill' ),
		);
		return $t[ $key ] ?? '';
	}

	/**
	 * A question-mark icon that shows a short description on hover or keyboard focus.
	 *
	 * @param string $text Description.
	 * @return string HTML.
	 */
	private static function help( $text ) {
		return ' <span class="dashicons dashicons-editor-help aoq-help" tabindex="0" role="img" aria-label="' . esc_attr( $text ) . '" data-tip="' . esc_attr( $text ) . '"></span>';
	}

	/**
	 * A slider from min to max with the current value beside it.
	 *
	 * @param string    $name  Field name.
	 * @param int|float $value Value.
	 * @param int|float $min   Minimum.
	 * @param int|float $max   Maximum.
	 * @param int|float $step  Step.
	 * @return string HTML.
	 */
	private static function slider( $name, $value, $min, $max, $step ) {
		$dec = $step < 1 ? 2 : 0;
		return '<span class="aoq-slider"><span class="aoq-sl-min">' . esc_html( $min ) . '</span>'
			. '<input type="range" name="' . esc_attr( $name ) . '" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '" step="' . esc_attr( $step ) . '" value="' . esc_attr( $value ) . '" oninput="this.parentNode.querySelector(&quot;output&quot;).value=Number(this.value).toFixed(' . (int) $dec . ')">'
			. '<span class="aoq-sl-max">' . esc_html( $max ) . '</span><output>' . esc_html( number_format( (float) $value, $dec, '.', '' ) ) . '</output></span>';
	}

	/**
	 * Parameter size from a model name: "qwen2.5:14b-instruct" is 14B, "gemma4:e4b" 4B effective.
	 *
	 * @param string $model Model name.
	 * @return string
	 */
	private static function model_size( $model ) {
		$m   = strtolower( (string) $model );
		$tag = (string) preg_replace( '/^[^:]*/', '', $m ); // the part after the colon, if any
		if ( preg_match( '/^:e(\d+(?:\.\d+)?)b\b/', $tag, $x ) ) {
			/* translators: %s: parameter count in billions */
			return sprintf( __( '%sB effective', 'ao-quill' ), $x[1] );
		}
		if ( preg_match( '/(\d+(?:\.\d+)?)b\b/', $tag, $x ) ) {
			return $x[1] . 'B';
		}
		$known = array( 'phi4' => '14B', 'phi3' => '3.8B', 'mistral-nemo' => '12B', 'mistral' => '7B', 'glm4' => '9B', 'olmo2' => '7B', 'granite-code' => '3B', 'moondream' => '1.8B', 'llama3.2' => '3B', 'llama3.1' => '8B', 'lfm2' => '24B' );
		$base  = (string) preg_replace( '/:.*$/', '', $m );
		return $known[ $base ] ?? __( 'size unknown', 'ao-quill' );
	}

	private static function render_settings() {
		$s       = AOQ_Settings::get();
		$adapter = AOQ_Plugin::translation_adapter();
		$catalog = AOQ_Settings::catalog();
		echo '<style>
.aoq-help{color:#8c8f94;cursor:help;position:relative;vertical-align:middle;font-size:17px;width:17px;height:17px}
.aoq-help:hover,.aoq-help:focus{color:#2271b1;outline:none}
.aoq-help:hover::after,.aoq-help:focus::after{content:attr(data-tip);position:absolute;left:24px;top:-6px;z-index:100;width:300px;padding:8px 10px;background:#1d2327;color:#f0f0f1;font:13px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;border-radius:3px;box-shadow:0 2px 8px rgba(0,0,0,.25);white-space:normal;text-align:left;font-weight:400}
.aoq-slider{display:inline-flex;align-items:center;gap:8px}
.aoq-slider input[type=range]{width:280px}
.aoq-slider .aoq-sl-min,.aoq-slider .aoq-sl-max{color:#646970;font-size:12px;font-variant-numeric:tabular-nums;min-width:24px}
.aoq-slider .aoq-sl-min{text-align:right}
.aoq-slider output{font-weight:600;min-width:52px;font-variant-numeric:tabular-nums;background:#f0f0f1;padding:2px 8px;border-radius:3px;text-align:center}
</style>';
		echo '<form method="post" action="' . esc_url( self::action_url( 'aoq_save' ) ) . '">';
		wp_nonce_field( 'aoq_save' );
		echo '<input type="hidden" name="aoq[languages_submitted]" value="1">';

		echo '<h2>' . esc_html__( 'Site brief', 'ao-quill' ) . '' . self::help( self::tip( 'site_brief' ) ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'What the site is about, the audience, topics to favor and topics to avoid.', 'ao-quill' ) . '</p>';
		echo '<textarea name="aoq[site_brief]" rows="8" class="large-text">' . esc_textarea( $s['site_brief'] ) . '</textarea>';

		echo '<h2>' . esc_html__( 'Search interest', 'ao-quill' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Topics take into account what people search right now: Google Trends\' trending searches in these countries (the first is the site\'s own country; elsewhere only searches that touch the seed terms) and Google\'s search suggestions for the seed terms. No account is needed.', 'ao-quill' ) . '</p><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Seed terms', 'ao-quill' ) . '' . self::help( self::tip( 'trend_seeds' ) ) . '</th><td><textarea name="aoq[trend_seeds]" rows="3" class="large-text">' . esc_textarea( implode( ', ', (array) $s['trend_seeds'] ) ) . '</textarea><p class="description">' . esc_html__( 'Comma separated: places and themes the site writes about.', 'ao-quill' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Countries', 'ao-quill' ) . '' . self::help( self::tip( 'trend_geos' ) ) . '</th><td><input type="text" class="regular-text" name="aoq[trend_geos]" value="' . esc_attr( implode( ', ', (array) $s['trend_geos'] ) ) . '"><p class="description">' . esc_html__( 'Two-letter country codes, the site\'s own country first, then where visitors come from.', 'ao-quill' ) . '</p></td></tr></table>';

		echo '<h2>' . esc_html__( 'Schedule and publishing', 'ao-quill' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Daily run time', 'ao-quill' ) . '' . self::help( self::tip( 'schedule_time' ) ) . '</th><td><input type="time" name="aoq[schedule_time]" value="' . esc_attr( $s['schedule_time'] ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Timezone', 'ao-quill' ) . '' . self::help( self::tip( 'timezone' ) ) . '</th><td><select name="aoq[timezone]">';
		foreach ( timezone_identifiers_list() as $tz ) {
			echo '<option value="' . esc_attr( $tz ) . '"' . selected( $s['timezone'], $tz, false ) . '>' . esc_html( $tz ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Publishing mode', 'ao-quill' ) . '' . self::help( self::tip( 'publish_mode' ) ) . '</th><td><select name="aoq[publish_mode]">';
		echo '<option value="pending"' . selected( $s['publish_mode'], 'pending', false ) . '>' . esc_html__( 'Pending review', 'ao-quill' ) . '</option>';
		echo '<option value="publish"' . selected( $s['publish_mode'], 'publish', false ) . '>' . esc_html__( 'Publish', 'ao-quill' ) . '</option>';
		echo '</select><p class="description">' . esc_html__( 'Enforced by the plugin when it writes the post. The worker cannot change it.', 'ao-quill' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Post author', 'ao-quill' ) . '' . self::help( self::tip( 'author' ) ) . '</th><td>';
		wp_dropdown_users(
			array(
				'name'             => 'aoq[author_user_id]',
				'selected'         => $s['author_user_id'],
				'blog_id'          => get_main_site_id(),
				'exclude'          => array( AOQ_Install::worker_user_id() ),
				'show_option_none' => __( 'Choose a user', 'ao-quill' ),
			)
		);
		echo '<p class="description">' . esc_html__( 'Shown publicly as the author. The worker user is never the author.', 'ao-quill' ) . '</p></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Languages', 'ao-quill' ) . '' . self::help( self::tip( 'languages' ) ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Detected from the language setting of each site in the network. The primary language is the main site.', 'ao-quill' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Site', 'ao-quill' ) . '</th><th>' . esc_html__( 'Language', 'ao-quill' ) . '</th><th>' . esc_html__( 'Locale', 'ao-quill' ) . '</th><th>' . esc_html__( 'Exclude', 'ao-quill' ) . '</th></tr></thead><tbody>';
		foreach ( $adapter->languages() as $lang ) {
			echo '<tr><td>' . esc_html( $lang['url'] ) . '</td><td>' . ( $lang['primary'] ? '<strong>' . esc_html( $lang['name'] ) . ' (' . esc_html__( 'primary', 'ao-quill' ) . ')</strong>' : esc_html( $lang['name'] ) ) . '</td><td>' . esc_html( $lang['locale'] ) . '</td><td>';
			if ( ! $lang['primary'] ) {
				echo '<input type="checkbox" name="aoq[excluded_blog_ids][]" value="' . esc_attr( $lang['blog_id'] ) . '"' . checked( $lang['excluded'], true, false ) . '>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		foreach ( $adapter->problems() as $problem ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $problem ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Agents and models', 'ao-quill' ) . '' . self::help( self::tip( 'agents' ) ) . '</h2>';
		if ( empty( $catalog['agents'] ) ) {
			echo '<p>' . esc_html__( 'The worker has not reported the AO catalog yet. Agent IDs can be typed in but are checked once the catalog arrives.', 'ao-quill' ) . '</p>';
		} else {
			/* translators: %s: time */
			echo '<p class="description">' . esc_html( sprintf( __( 'Catalog reported by the worker at %s. QA agents must use a different provider family than the drafting agent.', 'ao-quill' ), (string) $catalog['reported_at'] ) ) . '</p>';
		}
		$role_help = array(
			'site_profile'    => __( 'Summarizes what the site covers and for whom.', 'ao-quill' ),
			'style_profile'   => __( 'Learns the writing style from human-written posts.', 'ao-quill' ),
			'topics'          => __( 'Proposes topic ideas.', 'ao-quill' ),
			'novelty'         => __( 'Picks a topic the site has not covered yet.', 'ao-quill' ),
			'research'        => __( 'Searches the web, reads sources and collects facts with quotes.', 'ao-quill' ),
			'structurer'      => __( 'Turns other agents\' answers into the JSON the worker needs.', 'ao-quill' ),
			'outline'         => __( 'Plans the post: title, sections and which facts go where.', 'ao-quill' ),
			'draft'           => __( 'Writes and revises the post.', 'ao-quill' ),
			'taxonomy'        => __( 'Chooses existing categories and tags.', 'ao-quill' ),
			'image_shortlist' => __( 'Shortlists images and decides whether they show the post\'s subject.', 'ao-quill' ),
			'image_vision'    => __( 'Looks at each image for text, logos, watermarks and faces, and writes its alt text.', 'ao-quill' ),
			'qa_grammar'      => __( 'Grammar check.', 'ao-quill' ),
			'qa_facts'        => __( 'Checks that every factual sentence is backed by a verified fact.', 'ao-quill' ),
			'qa_style'        => __( 'Checks the post against the site\'s writing style.', 'ao-quill' ),
			'qa_seo'          => __( 'Checks the title, description and headings for search.', 'ao-quill' ),
			'translate'       => __( 'Translates the post into the other languages.', 'ao-quill' ),
			'back_translate'  => __( 'Translates back to check that the meaning survived.', 'ao-quill' ),
			'translation_qa'  => __( 'Checks translations for grammar and style.', 'ao-quill' ),
			'quote_verifier'  => __( 'Checks every quote against its source page (code, no model).', 'ao-quill' ),
			'text_checks'     => __( 'Structure and originality checks (code, no model).', 'ao-quill' ),
		);
		echo '<table class="form-table">';
		foreach ( $s['agents'] as $role => $agent_id ) {
			echo '<tr><th>' . esc_html( $role ) . ( isset( $role_help[ $role ] ) ? self::help( $role_help[ $role ] ) : '' ) . '</th><td>';
			if ( empty( $catalog['agents'] ) ) {
				echo '<input type="text" class="regular-text" name="aoq[agents][' . esc_attr( $role ) . ']" value="' . esc_attr( $agent_id ) . '">';
			} else {
				echo '<select name="aoq[agents][' . esc_attr( $role ) . ']">';
				$found = false;
				foreach ( $catalog['agents'] as $agent ) {
					$found = $found || $agent['id'] === $agent_id;
					if ( $agent['deterministic'] ) {
						$what = __( 'deterministic, no model', 'ao-quill' );
					} elseif ( ! empty( $agent['model'] ) ) {
						$what = $agent['model'] . ', ' . self::model_size( $agent['model'] );
					} else {
						$what = $agent['family'] . ', ' . __( 'model not reported yet', 'ao-quill' );
					}
					$label = $agent['id'] . ' (' . $what . ')';
					echo '<option value="' . esc_attr( $agent['id'] ) . '"' . selected( $agent_id, $agent['id'], false ) . '>' . esc_html( $label ) . '</option>';
				}
				if ( ! $found ) {
					echo '<option value="' . esc_attr( $agent_id ) . '" selected>' . esc_html( $agent_id . ' (' . __( 'not in catalog', 'ao-quill' ) . ')' ) . '</option>';
				}
				echo '</select>';
			}
			echo '</td></tr>';
		}
		foreach ( array( 'search', 'fetch', 'stock_images' ) as $key ) {
			echo '<tr><th>' . esc_html( sprintf( /* translators: %s: purpose */ __( 'MCP provider for %s', 'ao-quill' ), str_replace( '_', ' ', $key ) ) ) . '' . self::help( self::tip( 'mcp' ) ) . '</th><td><input type="text" class="regular-text" name="aoq[mcp][' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $s['mcp'][ $key ] ) . '"></td></tr>';
		}
		echo '<tr><th></th><td><p class="description">' . esc_html__( 'IDs starting with client. are served by the worker through the AO Reach tunnel; AO needs AGENTIC_SERVE_MCP_TUNNEL=1.', 'ao-quill' ) . '</p></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Quality checks', 'ao-quill' ) . '</h2><table class="form-table">';
		foreach ( $s['thresholds'] as $key => $value ) {
			echo '<tr><th>' . esc_html( sprintf( /* translators: %s: check */ __( 'Minimum %s score', 'ao-quill' ), str_replace( '_', ' ', $key ) ) ) . self::help( self::tip( 'threshold' ) ) . '</th><td>' . self::slider( 'aoq[thresholds][' . $key . ']', (float) $value, 0, 1, 0.01 ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help and slider.
		}
		// label, min, max, step: the same ranges the settings are clamped to when saved
		$numbers = array(
			'originality_max_overlap'   => array( __( 'Maximum n-gram overlap with any source or post', 'ao-quill' ), 0, 1, 0.01 ),
			'min_words'                 => array( __( 'Minimum word count of a post', 'ao-quill' ), 50, 5000, 10 ),
			'max_revision_loops'        => array( __( 'Maximum revision loops', 'ao-quill' ), 1, 10, 1 ),
			'min_verified_claims'       => array( __( 'Minimum verified claims', 'ao-quill' ), 1, 50, 1 ),
			'min_human_posts_for_style' => array( __( 'Human posts needed for a language style profile', 'ao-quill' ), 1, 100, 1 ),
			'stage_timeout_seconds'     => array( __( 'Timeout per AO call in seconds', 'ao-quill' ), 30, 14400, 30 ),
			'lease_minutes'             => array( __( 'Run lease in minutes', 'ao-quill' ), 30, 1440, 10 ),
		);
		foreach ( $numbers as $key => $def ) {
			echo '<tr><th>' . esc_html( $def[0] ) . self::help( self::tip( $key ) ) . '</th><td>' . self::slider( 'aoq[' . $key . ']', $s[ $key ], $def[1], $def[2], $def[3] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help and slider.
		}
		echo '<tr><th>' . esc_html__( 'AO queue priority', 'ao-quill' ) . '' . self::help( self::tip( 'ao_priority' ) ) . '</th><td><select name="aoq[ao_priority]">';
		foreach ( array( 'background', 'low', 'normal' ) as $p ) {
			echo '<option value="' . esc_attr( $p ) . '"' . selected( $s['ao_priority'], $p, false ) . '>' . esc_html( $p ) . '</option>';
		}
		echo '</select></td></tr></table>';

		echo '<h2>' . esc_html__( 'Images', 'ao-quill' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'In-body images', 'ao-quill' ) . '' . self::help( self::tip( 'inline_image_count' ) ) . '</th><td><input type="number" min="0" max="6" name="aoq[inline_image_count]" value="' . esc_attr( $s['inline_image_count'] ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Media library candidates', 'ao-quill' ) . '' . self::help( self::tip( 'media_candidate_limit' ) ) . '</th><td><input type="number" min="10" max="200" name="aoq[media_candidate_limit]" value="' . esc_attr( $s['media_candidate_limit'] ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Minimum image score', 'ao-quill' ) . self::help( self::tip( 'image_min_score' ) ) . '</th><td>' . self::slider( 'aoq[image_min_score]', (float) $s['image_min_score'], 0, 10, 1 ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help and slider.
		echo '<tr><th>' . esc_html__( 'Minimum featured image score', 'ao-quill' ) . self::help( self::tip( 'featured_min_score' ) ) . '</th><td>' . self::slider( 'aoq[featured_min_score]', (float) $s['featured_min_score'], 0, 10, 1 ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help and slider.
		$fid  = (int) $s['fallback_image_id'];
		$fkey = AOQ_Settings::fallback_image_key( $fid );
		$fimg = '';
		if ( $fkey ) {
			switch_to_blog( get_main_site_id() );
			$fimg = (string) wp_get_attachment_image_url( $fid, 'thumbnail' );
			restore_current_blog();
		}
		echo '<tr><th>' . esc_html__( 'Fallback image (media ID)', 'ao-quill' ) . self::help( self::tip( 'fallback_image_id' ) ) . '</th><td><input type="number" min="0" name="aoq[fallback_image_id]" value="' . esc_attr( $fid ) . '">' // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
			. ( $fimg ? ' <img src="' . esc_url( $fimg ) . '" alt="" style="height:48px;vertical-align:middle;margin-left:8px;border-radius:3px">' : '' )
			. ( $fid > 0 && ! $fkey ? ' <span style="color:#b32d2e">' . esc_html__( 'Not an image in the main site\'s media library.', 'ao-quill' ) . '</span>' : '' )
			. ( 0 === $fid ? ' <span class="description">' . esc_html__( 'None: the best image found is used.', 'ao-quill' ) . '</span>' : '' )
			. '</td></tr>';
		foreach ( $s['image_sources'] as $src ) {
			$cap      = AOQ_Capabilities::get( 'images_' . $src['id'] );
			$disabled = ! $cap || 'shipped' !== $cap['status'];
			echo '<tr><th>' . esc_html( $cap ? $cap['label'] : $src['id'] ) . '' . self::help( self::tip( 'image_source' ) ) . '</th><td>';
			echo '<label><input type="checkbox" name="aoq[image_sources][' . esc_attr( $src['id'] ) . '][enabled]" value="1"' . checked( $src['enabled'], true, false ) . disabled( $disabled, true, false ) . '> ' . esc_html__( 'Enabled', 'ao-quill' ) . '</label> ';
			echo '<label>' . esc_html__( 'Order', 'ao-quill' ) . ' <input type="number" min="1" max="10" class="small-text" name="aoq[image_sources][' . esc_attr( $src['id'] ) . '][order]" value="' . esc_attr( $src['order'] ) . '"></label>';
			if ( $disabled && $cap ) {
				echo '<p class="description">' . esc_html( ucfirst( $cap['status'] ) . ': ' . $cap['reason'] ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		self::render_ao_connection();
		submit_button();
		echo '</form>';
	}

	/**
	 * Run log list and drill down.
	 */
	private static function render_runs() {
		$run_id = isset( $_GET['run'] ) ? sanitize_text_field( wp_unslash( $_GET['run'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $run_id ) {
			self::render_run( $run_id );
			return;
		}
		$page  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$per   = 30;
		$runs  = AOQ_Runs::recent( $per + 1, ( $page - 1 ) * $per );
		$more  = count( $runs ) > $per;
		$runs  = array_slice( $runs, 0, $per );
		$kinds = array(
			'scheduled' => __( 'Scheduled', 'ao-quill' ),
			'manual'    => __( 'Generate now', 'ao-quill' ),
			'test'      => __( 'Test', 'ao-quill' ),
		);
		echo '<p>' . esc_html__( 'Every run with what each step did, its timings and AO\'s answers. Open one to see it the way the Post generation tab showed it.', 'ao-quill' ) . '</p>';
		self::render_full_text_modal();
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Started', 'ao-quill' ), __( 'Kind', 'ao-quill' ), __( 'Status', 'ao-quill' ), __( 'Topic', 'ao-quill' ), __( 'Reached', 'ao-quill' ), __( 'Took', 'ao-quill' ), __( 'Result', 'ao-quill' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $runs ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No runs yet.', 'ao-quill' ) . '</td></tr>';
		}
		$total = count( AOQ_Steps::definitions() );
		foreach ( $runs as $run ) {
			$view    = self::url( array( 'tab' => 'runs', 'run' => $run['run_id'] ) );
			$started = $run['claimed_at'] ? get_date_from_gmt( $run['claimed_at'], 'Y-m-d H:i' ) : $run['run_date'];
			$end     = $run['finished_at'] ? strtotime( $run['finished_at'] . ' UTC' ) : ( in_array( $run['status'], array( 'claimed', 'running' ), true ) ? time() : 0 );
			$took    = $end && $run['claimed_at'] ? human_time_diff( strtotime( $run['claimed_at'] . ' UTC' ), $end ) : '';
			$summary = self::run_summary( $run );
			$status  = $run['status'];
			$color   = 'succeeded' === $status ? '#00a32a' : ( in_array( $status, array( 'failed', 'expired' ), true ) ? '#d63638' : '#2271b1' );
			echo '<tr><td><a href="' . esc_url( $view ) . '"><strong>' . esc_html( $started ) . '</strong></a></td>';
			echo '<td>' . esc_html( $kinds[ $run['run_trigger'] ] ?? $run['run_trigger'] ) . '</td>';
			echo '<td style="color:' . esc_attr( $color ) . ';font-weight:600">' . esc_html( $status ) . '</td>';
			echo '<td>' . esc_html( $summary['topic'] ) . '</td>';
			/* translators: 1: last step reached, 2: number of steps, 3: step name */
			echo '<td>' . esc_html( $summary['step'] ? sprintf( __( '%1$d of %2$d (%3$s)', 'ao-quill' ), $summary['step'], $total, $summary['label'] ) : '' ) . '</td>';
			echo '<td>' . esc_html( $took ) . '</td>';
			if ( (int) $run['committed'] ) {
				$result_html = esc_html__( 'Published:', 'ao-quill' ) . ' ' . self::post_links( $run );
			} elseif ( 'succeeded' === $status ) {
				$result_html = esc_html__( 'Not published', 'ao-quill' );
			} else {
				$reason      = (string) $run['failure_reason'];
				$short       = wp_trim_words( $reason, 24 );
				$result_html = esc_html( $short ) . ( $short !== $reason ? ' <a href="#" class="aoq-full" data-title="' . esc_attr__( 'Failure reason', 'ao-quill' ) . '" data-full="' . esc_attr( $reason ) . '">' . esc_html__( 'Show full', 'ao-quill' ) . '</a>' : '' );
			}
			$actions = array( '<a href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'ao-quill' ) . '</a>' );
			if ( AOQ_Runs::resumable_steps( $run ) ) {
				$actions[] = '<a href="' . esc_url( $view ) . '#aoq-actions">' . esc_html__( 'Resume', 'ao-quill' ) . '</a>';
			}
			if ( self::publishable( $run ) ) {
				$actions[] = '<a href="' . esc_url( $view ) . '#aoq-actions">' . esc_html__( 'Publish', 'ao-quill' ) . '</a>';
			}
			echo '<td>' . $result_html . ' <span style="white-space:nowrap">' . implode( ' | ', $actions ) . '</span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
		echo '</tbody></table>';
		echo '<p>';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'runs', 'paged' => $page - 1 ) ) ) . '">' . esc_html__( 'Newer', 'ao-quill' ) . '</a> ';
		}
		if ( $more ) {
			echo '<a class="button" href="' . esc_url( self::url( array( 'tab' => 'runs', 'paged' => $page + 1 ) ) ) . '">' . esc_html__( 'Older', 'ao-quill' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * The topic a run worked on and how far it got, for the Past runs list.
	 *
	 * @param array $run Run row.
	 * @return array{topic:string, step:int, label:string}
	 */
	private static function run_summary( array $run ) {
		$topic = '';
		foreach ( AOQ_Runs::stages( $run['run_id'] ) as $row ) {
			$detail = json_decode( (string) $row['detail'], true );
			if ( 'draft' === $row['stage'] && ! empty( $detail['title'] ) ) {
				$topic = $detail['title'];
			} elseif ( 'novelty' === $row['stage'] && ! empty( $detail['chosen'] ) && '' === $topic ) {
				$topic = $detail['chosen'];
			}
		}
		$step  = 0;
		$label = '';
		foreach ( AOQ_Steps::for_run( $run, AOQ_Runs::history_live( $run ) ) as $i => $s ) {
			if ( 'pending' !== $s['status'] ) {
				$step  = $i + 1;
				$label = $s['title'];
			}
		}
		return array( 'topic' => $topic, 'step' => $step, 'label' => $label );
	}

	/**
	 * Links to the posts a run created.
	 *
	 * @param array $run Run row.
	 * @return string HTML.
	 */
	private static function post_links( array $run ) {
		$result = json_decode( (string) $run['result'], true );
		$posts  = $result['posts'] ?? ( $result['commit']['posts'] ?? array() );
		$out    = array();
		foreach ( $posts as $p ) {
			switch_to_blog( (int) $p['blog_id'] );
			$edit = get_edit_post_link( (int) $p['post_id'], 'raw' );
			restore_current_blog();
			$out[] = '<a href="' . esc_url( $edit ? $edit : $p['url'] ) . '">' . esc_html( sprintf( /* translators: %s: language code */ __( 'Edit %s in WordPress', 'ao-quill' ), strtoupper( $p['language'] ) ) ) . '</a>';
		}
		return implode( ' ', $out );
	}

	/**
	 * One run with its stages.
	 *
	 * @param string $run_id Run ID.
	 */
	private static function render_run( $run_id ) {
		$run = AOQ_Runs::get( $run_id );
		if ( ! $run ) {
			echo '<p>' . esc_html__( 'Unknown run.', 'ao-quill' ) . '</p>';
			return;
		}
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'runs' ) ) ) . '">' . esc_html__( '← All past runs', 'ao-quill' ) . '</a></p>';
		$active = in_array( $run['status'], array( 'claimed', 'running' ), true );
		if ( $active ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This run is still in progress; the Post generation tab follows it live.', 'ao-quill' ) . '</p></div>';
		}
		if ( isset( $_GET['aoq_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error inline"><p>' . esc_html( sanitize_text_field( wp_unslash( rawurldecode( $_GET['aoq_error'] ) ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( isset( $_GET['published'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Published. The posts are saved with your publish mode.', 'ao-quill' ) . '</p></div>';
		}
		self::render_run_actions( $run );
		if ( AOQ_Runs::auto_resume_pending_for( $run['run_id'] ) ) {
			echo '<p class="description">' . esc_html__( 'Auto-retry queued: the worker will resume this run once on its next idle poll.', 'ao-quill' ) . '</p>';
		} elseif ( AOQ_Runs::was_auto_resumed( $run['run_id'] ) ) {
			echo '<p class="description">' . esc_html__( 'Auto-retried once after a quality-check failure.', 'ao-quill' ) . '</p>';
		}
		if ( $run['resumed_from'] ) {
			echo '<p class="description">' . esc_html__( 'Resumed from', 'ao-quill' ) . ' <a href="' . esc_url( self::url( array( 'tab' => 'runs', 'run' => $run['resumed_from'] ) ) ) . '">' . esc_html( $run['resumed_from'] ) . '</a></p>';
		}
		self::render_run_panel( $run, false, self::run_payload( $run ) );
	}

	/**
	 * A finished test run that saved its post and was not published yet.
	 *
	 * @param array $run Run row.
	 * @return bool
	 */
	private static function publishable( array $run ) {
		if ( 'test' !== $run['run_trigger'] || 'succeeded' !== $run['status'] || (int) $run['committed'] ) {
			return false;
		}
		$cp = AOQ_Runs::checkpoint( $run );
		return isset( $cp['state']['bundle'] );
	}

	/**
	 * Publish, edit and resume actions for one past run.
	 *
	 * @param array $run Run row.
	 */
	private static function render_run_actions( array $run ) {
		$busy  = AOQ_Runs::current() || AOQ_Runs::test_requested() || AOQ_Runs::run_now_requested();
		$steps = AOQ_Runs::resumable_steps( $run );
		$defs  = AOQ_Steps::definitions();
		$nums  = array_flip( array_keys( $defs ) );
		echo '<div id="aoq-actions" style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-end;max-width:900px;margin:12px 0">';
		if ( (int) $run['committed'] ) {
			echo '<div><strong>' . esc_html__( 'Published', 'ao-quill' ) . '</strong><br>' . self::post_links( $run ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in post_links.
		} elseif ( self::publishable( $run ) ) {
			$mode = 'publish' === AOQ_Settings::get()['publish_mode'] ? __( 'published', 'ao-quill' ) : __( 'pending review', 'ao-quill' );
			echo '<form method="post" action="' . esc_url( self::action_url( 'aoq_publish_test' ) ) . '" onsubmit="return confirm(this.dataset.q)" data-q="' . esc_attr__( 'Publish this run\'s post to the site now?', 'ao-quill' ) . '">';
			wp_nonce_field( 'aoq_publish_test' );
			echo '<input type="hidden" name="run_id" value="' . esc_attr( $run['run_id'] ) . '">';
			submit_button( __( 'Publish this run', 'ao-quill' ), 'primary', 'submit', false );
			/* translators: %s: post status, "pending review" or "published" */
			echo '<p class="description">' . esc_html( sprintf( __( 'Commits the post this test built, with every check a real run makes. Posts are saved as %s.', 'ao-quill' ), $mode ) ) . '</p></form>';
		} elseif ( 'test' === $run['run_trigger'] && 'succeeded' === $run['status'] ) {
			echo '<p class="description">' . esc_html__( 'This test run did not save its post (it ran before saving was added), so it can only be previewed.', 'ao-quill' ) . '</p>';
		}
		if ( $steps ) {
			echo '<form method="post" action="' . esc_url( self::action_url( 'aoq_resume' ) ) . '">';
			wp_nonce_field( 'aoq_resume' );
			echo '<input type="hidden" name="run_id" value="' . esc_attr( $run['run_id'] ) . '">';
			echo '<label for="aoq-from"><strong>' . esc_html__( 'Resume from step', 'ao-quill' ) . '</strong></label><br>';
			echo '<select id="aoq-from" name="from_step">';
			foreach ( $steps as $i => $id ) {
				$last = count( $steps ) - 1 === $i;
				printf( '<option value="%s"%s>%d. %s%s</option>', esc_attr( $id ), selected( $last, true, false ), (int) $nums[ $id ] + 1, esc_html( $defs[ $id ][0] ), $last && 'succeeded' !== $run['status'] ? esc_html__( ' (where it stopped)', 'ao-quill' ) : '' );
			}
			echo '</select> ';
			submit_button( __( 'Resume', 'ao-quill' ), 'secondary', 'submit', false, $busy ? array( 'disabled' => 'disabled' ) : array() );
			$what = 'test' === $run['run_trigger']
				? __( 'Starts a new test run that reads the site again, reuses this run\'s results up to the chosen step and continues from there with the current settings and models.', 'ao-quill' )
				: __( 'Starts a new run that reads the site again, reuses this run\'s results up to the chosen step and continues from there with the current settings and models. Its post is saved like any daily post.', 'ao-quill' );
			echo '<p class="description">' . esc_html( $what ) . ( $busy ? ' ' . esc_html__( 'Another run is active or waiting.', 'ao-quill' ) : '' ) . '</p></form>';
		} elseif ( 'test' === $run['run_trigger'] && 'failed' === $run['status'] && ! AOQ_Runs::checkpoint( $run ) ) {
			echo '<p class="description">' . esc_html__( 'This run cannot be resumed: it ran before checkpoints were saved.', 'ao-quill' ) . '</p>';
		}
		echo '</div>';
	}


	/**
	 * AO connection fields. The private key is write-only: it is never printed back.
	 */
	private static function render_ao_connection() {
		$s = AOQ_AO_Connection::get();
		$c = AOQ_AO_Connection::certificate_info();
		echo '<h2>' . esc_html__( 'AO connection', 'ao-quill' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The worker reads these at every poll, so its server only needs the WordPress URL and an Application Password.', 'ao-quill' ) . '</p>';
		echo '<table class="form-table">';
		echo '<tr><th>' . esc_html__( 'AO engine URL', 'ao-quill' ) . '</th><td><input type="url" class="regular-text" name="aoq_ao[ao_url]" placeholder="https://ao.example.internal:8765" value="' . esc_attr( $s['ao_url'] ) . '"></td></tr>';
		echo '<tr><th>' . esc_html__( 'IPv4 only', 'ao-quill' ) . '</th><td><input type="hidden" name="aoq_ao[ao_ipv4_only_submitted]" value="1"><label><input type="checkbox" name="aoq_ao[ao_ipv4_only]" value="1"' . checked( $s['ao_ipv4_only'], true, false ) . '> ' . esc_html__( 'Resolve the AO host to IPv4 addresses only', 'ao-quill' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'SearXNG URL', 'ao-quill' ) . '</th><td><input type="url" class="regular-text" name="aoq_ao[searxng_url]" value="' . esc_attr( $s['searxng_url'] ) . '"><p class="description">' . esc_html__( 'Web search for research. JSON format must be enabled.', 'ao-quill' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'User-Agent', 'ao-quill' ) . '</th><td><input type="text" class="regular-text" name="aoq_ao[user_agent]" value="' . esc_attr( $s['user_agent'] ) . '"><p class="description">' . esc_html__( 'Sent when the worker reads pages and image APIs. Include a contact URL or email.', 'ao-quill' ) . '</p></td></tr>';
		echo '<tr><th>' . esc_html__( 'Client certificate', 'ao-quill' ) . '</th><td>';
		if ( $c['present'] ) {
			/* translators: 1: subject, 2: issuer, 3: date */
			$line = sprintf( __( '%1$s, issued by %2$s, valid until %3$s.', 'ao-quill' ), $c['subject'], $c['issuer'], $c['expires_at'] );
			echo '<p>' . esc_html( $line ) . ( $c['key_readable'] ? '' : ' <strong>' . esc_html__( 'Private key unreadable.', 'ao-quill' ) . '</strong>' ) . '</p>';
			echo '<label><input type="checkbox" name="aoq_ao[clear_certificate]" value="1"> ' . esc_html__( 'Remove the stored certificate and key', 'ao-quill' ) . '</label>';
		} else {
			echo '<p>' . esc_html__( 'None stored. Run "aoq-worker enroll" on the worker server, or paste below.', 'ao-quill' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'To replace it, paste all three (PEM). The private key is encrypted and never shown again.', 'ao-quill' ) . '</p>';
		echo '<textarea name="aoq_ao[cert_pem]" rows="3" class="large-text code" placeholder="-----BEGIN CERTIFICATE-----"></textarea>';
		echo '<textarea name="aoq_ao[key_pem]" rows="3" class="large-text code" autocomplete="off" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>';
		echo '<textarea name="aoq_ao[ca_pem]" rows="3" class="large-text code" placeholder="AO CA: -----BEGIN CERTIFICATE-----"></textarea>';
		echo '</td></tr></table>';
	}

	/**
	 * Network admin notice when the worker was running and stopped checking in.
	 */
	public static function worker_notice() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		$w = AOQ_Worker::state();
		if ( 'stopped' !== $w['state'] ) {
			return;
		}
		$msg = sprintf(
			/* translators: 1: time of last check-in, 2: link to the status tab */
			__( 'AO Quill: the worker has not checked in since %1$s, so no posts are being written. See %2$s.', 'ao-quill' ),
			$w['last_seen'],
			'<a href="' . esc_url( self::url( array( 'tab' => 'status' ) ) ) . '">' . esc_html__( 'Status', 'ao-quill' ) . '</a>'
		);
		echo '<div class="notice notice-warning"><p>' . wp_kses( $msg, array( 'a' => array( 'href' => array() ) ) ) . '</p></div>';
	}

	/**
	 * Worker section of the status tab: state, versions and the manual install step.
	 */
	private static function render_worker() {
		$w     = AOQ_Worker::state();
		$color = array(
			'running'       => '#00a32a',
			'busy'          => '#2271b1',
			'stopped'       => '#d63638',
			'not_installed' => '#996800',
		)[ $w['state'] ];
		echo '<h2>' . esc_html__( 'Worker', 'ao-quill' ) . '</h2>';
		$label = AOQ_Worker::label( $w['state'] );
		if ( 'busy' === $w['state'] ) {
			$busy_txt = AOQ_Worker::busy_label( $w['busy'] );
			if ( $busy_txt ) {
				$label = sprintf(
					/* translators: %s: site and task */
					__( 'Busy — %s', 'ao-quill' ),
					$busy_txt
				);
			}
		}
		echo '<p><strong style="color:' . esc_attr( $color ) . '">' . esc_html( $label ) . '</strong> ';
		if ( 'not_installed' === $w['state'] ) {
			echo esc_html__( 'The worker has never checked in. It ships with this plugin and is installed by hand on a server that can reach WordPress and AO.', 'ao-quill' );
		} else {
			echo esc_html(
				sprintf(
					/* translators: 1: time, 2: minutes */
					__( 'Last check-in %1$s (%2$d minutes ago). It checks in every minute.', 'ao-quill' ),
					$w['last_seen'],
					(int) floor( (int) $w['age_seconds'] / 60 )
				)
			);
		}
		echo '</p>';
		$rows = array(
			__( 'Installed worker version', 'ao-quill' ) => $w['version'] ? $w['version'] : '-',
			__( 'Bundled worker version', 'ao-quill' )   => $w['bundled_version'] ? $w['bundled_version'] : __( 'not bundled in this plugin copy', 'ao-quill' ),
		);
		echo '<table class="form-table">';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</table>';
		if ( false === $w['version_matches'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The installed worker differs from the one bundled with this plugin. Run install.sh again on the worker server to upgrade it; settings and certificates are kept.', 'ao-quill' ) . '</p></div>';
		}
		if ( ! in_array( $w['state'], array( 'running', 'busy' ), true ) ) {
			echo '<p>' . esc_html__( 'Install or repair (as a normal user on the worker server, not root):', 'ao-quill' ) . '</p>';
			echo '<pre style="white-space:pre-wrap">' . esc_html( implode( "
", AOQ_Worker::install_commands() ) ) . '</pre>';
			echo '<p class="description">' . esc_html__( 'install.sh creates a Python environment outside the web root, asks for the Application Password with a hidden prompt, and with --cron runs the worker every minute. The AO URL and certificate come from the AO connection settings; if no certificate is stored, run enroll once with the AO CA file and a one-time token.', 'ao-quill' ) . '</p>';
		}
	}

	/**
	 * Worker and AO status panel.
	 */
	private static function render_status() {
		self::render_worker();
		$hb       = get_site_option( AOQ_Runs::HEARTBEAT_OPTION, array() );
		$last     = AOQ_Runs::last_finished();
		$result   = $last ? json_decode( (string) $last['result'], true ) : array();
		$problems = AOQ_Settings::problems();
		$next     = AOQ_Runs::next_scheduled();

		/* translators: %s: link to the Generation tab */
		echo '<p>' . wp_kses( sprintf( __( 'Start and follow runs on the %s tab.', 'ao-quill' ), '<a href="' . esc_url( self::url( array( 'tab' => 'generation' ) ) ) . '">' . esc_html__( 'Post generation', 'ao-quill' ) . '</a>' ), array( 'a' => array( 'href' => array() ) ) ) . '</p>';

		echo '<table class="form-table">';
		$rows = array(
			__( 'Next scheduled run', 'ao-quill' )     => $next->format( 'Y-m-d H:i T' ),
			__( 'Last worker heartbeat', 'ao-quill' )  => $hb['at'] ?? __( 'never', 'ao-quill' ),
			__( 'Worker version', 'ao-quill' )         => $hb['worker_version'] ?? '',
			__( 'AO Reach version', 'ao-quill' )       => $hb['ao_reach_version'] ?? '',
			__( 'AO reachable at last poll', 'ao-quill' ) => isset( $hb['ao_reachable'] ) ? ( $hb['ao_reachable'] ? __( 'yes', 'ao-quill' ) : __( 'no', 'ao-quill' ) . ': ' . $hb['ao_error'] ) : '',
			__( 'AO engine version on last run', 'ao-quill' ) => (string) ( $result['ao']['engine_version'] ?? '' ),
			__( 'Worker user', 'ao-quill' )            => AOQ_Install::USER_LOGIN . ' (' . __( 'create an Application Password for this user', 'ao-quill' ) . ')',
			__( 'SEO adapter', 'ao-quill' )            => AOQ_Plugin::seo_adapter()->id() . '. ' . AOQ_Plugin::seo_adapter()->sitemap_note(),
		);
		foreach ( $rows as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</table>';

		echo '<h2>' . esc_html__( 'Problems blocking a run', 'ao-quill' ) . '</h2>';
		if ( ! $problems ) {
			echo '<p>' . esc_html__( 'None.', 'ao-quill' ) . '</p>';
		}
		foreach ( $problems as $problem ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $problem ) . '</p></div>';
		}

		$advertised = array();
		foreach ( (array) ( $result['ao']['capabilities'] ?? array() ) as $c ) {
			$advertised[ $c['id'] ] = (bool) $c['advertised'];
		}
		echo '<h2>' . esc_html__( 'AO capabilities this project needs', 'ao-quill' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Capability', 'ao-quill' ) . '</th><th>' . esc_html__( 'Advertised on last run', 'ao-quill' ) . '</th></tr></thead><tbody>';
		if ( ! $advertised ) {
			echo '<tr><td colspan="2">' . esc_html__( 'No run has reported AO capabilities yet.', 'ao-quill' ) . '</td></tr>';
		}
		foreach ( $advertised as $id => $ok ) {
			echo '<tr><td>' . esc_html( $id ) . '</td><td>' . esc_html( $ok ? __( 'yes', 'ao-quill' ) : __( 'no', 'ao-quill' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Capability status', 'ao-quill' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Capability', 'ao-quill' ) . '</th><th>' . esc_html__( 'Status', 'ao-quill' ) . '</th><th>' . esc_html__( 'Notes', 'ao-quill' ) . '</th></tr></thead><tbody>';
		foreach ( AOQ_Capabilities::all() as $cap ) {
			echo '<tr><td>' . esc_html( $cap['label'] ) . '</td><td>' . esc_html( $cap['status'] ) . '</td><td>' . esc_html( $cap['reason'] ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Settings used on the last run', 'ao-quill' ) . '</h2>';
		$snapshot = $last ? json_decode( (string) $last['settings_snapshot'], true ) : null;
		echo $snapshot ? '<pre style="max-height:400px;overflow:auto">' . esc_html( wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ) . '</pre>' : '<p>' . esc_html__( 'No finished run yet.', 'ao-quill' ) . '</p>';
	}
}
