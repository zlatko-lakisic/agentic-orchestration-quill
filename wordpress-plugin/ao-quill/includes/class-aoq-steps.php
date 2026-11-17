<?php
/**
 * The 14 pipeline steps as people see them in the Generation tab: name, what the step
 * does, its state, duration, results and the AO answers it received, formatted for
 * reading rather than as raw JSON.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

class AOQ_Steps {

	/**
	 * Steps in order. IDs match the worker's progress labels.
	 *
	 * @return array<string, array{title:string, description:string}>
	 */
	public static function definitions() {
		return array(
			'connect'       => array( __( 'Connect to AO', 'ao-quill' ), __( 'Opens the AO session, registers the writing agents and the worker\'s search, page and image tools, and checks that the server checks are installed.', 'ao-quill' ) ),
			'inventory'     => array( __( 'Read the site', 'ao-quill' ), __( 'Lists languages, published posts, categories, tags and media on every site.', 'ao-quill' ) ),
			'site_profile'  => array( __( 'Site profile', 'ao-quill' ), __( 'Summarizes what the site covers and for whom, from the brief and existing posts. Cached and reused for a week.', 'ao-quill' ) ),
			'style_profile' => array( __( 'Writing style', 'ao-quill' ), __( 'Learns voice, tone and structure from human-written posts in each language.', 'ao-quill' ) ),
			'topics'        => array( __( 'Find topics', 'ao-quill' ), __( 'Searches the web for what readers look for and proposes ten candidate topics.', 'ao-quill' ) ),
			'novelty'       => array( __( 'Pick a new topic', 'ao-quill' ), __( 'Compares candidates with every existing post and picks one the site has not covered.', 'ao-quill' ) ),
			'research'      => array( __( 'Research and verify', 'ao-quill' ), __( 'Reads sources and collects facts with exact quotes; AO then checks each quote against its source page.', 'ao-quill' ) ),
			'outline'       => array( __( 'Outline', 'ao-quill' ), __( 'Plans title, sections, which verified facts go where, and links to existing posts.', 'ao-quill' ) ),
			'draft'         => array( __( 'Write the draft', 'ao-quill' ), __( 'Writes the post in the site\'s style, using only verified facts, with SEO title and description.', 'ao-quill' ) ),
			'taxonomy'      => array( __( 'Categories and tags', 'ao-quill' ), __( 'Chooses existing categories and tags for the post.', 'ao-quill' ) ),
			'images'        => array( __( 'Choose images', 'ao-quill' ), __( 'Shortlists media library and stock images, then a vision model checks fit and rejects text, logos, watermarks and faces.', 'ao-quill' ) ),
			'qa'            => array( __( 'Quality checks', 'ao-quill' ), __( 'Grammar, facts, style, SEO, structure and originality checks; the draft is revised until all pass or the limit is reached.', 'ao-quill' ) ),
			'translate'     => array( __( 'Translate', 'ao-quill' ), __( 'Translates into each active language, then checks grammar and meaning by translating back.', 'ao-quill' ) ),
			'commit'        => array( __( 'Publish or preview', 'ao-quill' ), __( 'Saves the posts as pending review and links the translations. A test run builds a preview instead.', 'ao-quill' ) ),
		);
	}

	/**
	 * Step that a reported stage name belongs to.
	 *
	 * @param string $stage Stage name from report-stage or a progress label.
	 * @return string
	 */
	public static function step_for( $stage ) {
		$map = array(
			'verify_quotes'  => 'research',
			'translation_qa' => 'translate',
			'back_translate' => 'translate',
			'fidelity'       => 'translate',
			'text_checks'    => 'qa',
			'preview'        => 'commit',
			'done'           => 'commit',
		);
		$stage = (string) $stage;
		if ( isset( $map[ $stage ] ) ) {
			return $map[ $stage ];
		}
		$base = strtok( $stage, ':' );
		return isset( self::definitions()[ $base ] ) ? $base : $stage;
	}

	/**
	 * Only one active language: translate has nothing to do (older workers recorded no step for it).
	 *
	 * @return bool
	 */
	private static function single_language() {
		static $one = null;
		if ( null === $one ) {
			try {
				$one = count( (array) AOQ_Plugin::translation_adapter()->active_languages() ) <= 1;
			} catch ( Throwable $e ) {
				$one = false;
			}
		}
		return $one;
	}

	/** How many recent runs feed the per-step averages. */
	const AVERAGE_RUNS = 20;

	/**
	 * Average duration of each step over recent runs that completed it, from the
	 * stage log every run already records.
	 *
	 * @param string|null $exclude_run Run left out (the one in progress).
	 * @return array<string, array{avg_ms:int, runs:int}>
	 */
	public static function averages( $exclude_run = null ) {
		global $wpdb;
		$key    = 'aoq_step_avg_' . md5( (string) $exclude_run );
		$cached = get_site_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$runs = (array) $wpdb->get_col(
			$wpdb->prepare(
				'SELECT run_id FROM ' . AOQ_Runs::table() . ' WHERE run_id <> %s ORDER BY id DESC LIMIT %d', // phpcs:ignore
				(string) $exclude_run,
				self::AVERAGE_RUNS * 3
			)
		);
		$totals = array(); // step => [sum_ms, runs]
		foreach ( $runs as $run_id ) {
			$per_step = array();
			$ok       = array();
			foreach ( AOQ_Runs::stages( $run_id ) as $row ) {
				$step                = self::step_for( $row['stage'] );
				$per_step[ $step ]   = ( $per_step[ $step ] ?? 0 ) + (int) $row['duration_ms'];
				$ok[ $step ]         = 'ok' === $row['status']; // the step's last stage decides: a run that stopped inside a step does not count
			}
			foreach ( $per_step as $step => $ms ) {
				if ( empty( $ok[ $step ] ) || ( $totals[ $step ][1] ?? 0 ) >= self::AVERAGE_RUNS ) {
					continue; // only steps the run completed, at most AVERAGE_RUNS samples each
				}
				$totals[ $step ] = array( ( $totals[ $step ][0] ?? 0 ) + $ms, ( $totals[ $step ][1] ?? 0 ) + 1 );
			}
		}
		$out = array();
		foreach ( $totals as $step => $t ) {
			$out[ $step ] = array(
				'avg_ms' => (int) round( $t[0] / $t[1] ),
				'runs'   => $t[1],
			);
		}
		set_site_transient( $key, $out, 300 );
		return $out;
	}

	/**
	 * Every step of a run with state, duration, result lines and formatted AO answers.
	 *
	 * @param array $run  Run row.
	 * @param array $live Live data.
	 * @return array
	 */
	public static function for_run( array $run, array $live ) {
		$rows    = AOQ_Runs::stages( $run['run_id'] );
		$avg     = self::averages( $run['run_id'] );
		$current = self::step_for( (string) $live['label'] );
		$active  = in_array( $run['status'], array( 'claimed', 'running' ), true );
		$out     = array();
		foreach ( self::definitions() as $id => $def ) {
			$out[ $id ] = array(
				'id'          => $id,
				'title'       => $def[0],
				'description' => $def[1],
				'status'      => 'pending',
				'duration_ms' => 0,
				'summary'     => array(),
				'failure'     => '',
				'answers'     => array(),
				'gallery'     => array(),
				'avg_ms'      => $avg[ $id ]['avg_ms'] ?? ( 'translate' === $id && self::single_language() ? 0 : null ),
				'avg_runs'    => $avg[ $id ]['runs'] ?? 0,
			);
		}
		foreach ( $rows as $row ) {
			$id = self::step_for( $row['stage'] );
			if ( ! isset( $out[ $id ] ) ) {
				continue;
			}
			$out[ $id ]['duration_ms'] += (int) $row['duration_ms'];
			$label = $row['stage'] . ( '' !== (string) $row['language'] ? ' [' . $row['language'] . ']' : '' );
			if ( 'failed' === $row['status'] ) {
				$out[ $id ]['failure'] = trim( $label . ': ' . (string) $row['failure_reason'] );
			} elseif ( 'ok' === $row['status'] && 0 === strpos( $out[ $id ]['failure'], $label . ':' ) ) {
				$out[ $id ]['failure'] = ''; // a later attempt of the same stage passed (a revision fixed it)
			}
			self::$clipped = false;
			$detail = json_decode( (string) $row['detail'], true );
			if ( 'images' === $row['stage'] && ! empty( $detail['chosen'] ) && is_array( $detail['chosen'] ) ) {
				$out[ $id ]['gallery'] = self::gallery( $detail['chosen'] );
				unset( $detail['chosen'] ); // shown as thumbnails, not as text
			}
			foreach ( self::readable( $detail, 2 ) as $line ) {
				$out[ $id ]['summary'][] = ( $row['stage'] !== $id ? $label . ' - ' : '' ) . $line;
			}
			if ( self::$clipped ) {
				// A summary item that is an object: a link to the stage's full details.
				$out[ $id ]['summary'][] = array(
					/* translators: %s: stage name */
					'text'  => sprintf( __( 'Full details of %s', 'ao-quill' ), $label ),
					'full'  => self::full( $detail ),
					'title' => $label,
				);
			}
			foreach ( (array) json_decode( (string) $row['scores'], true ) as $s ) {
				/* translators: 1: check, 2: score, 3: threshold */
				$out[ $id ]['summary'][] = sprintf( __( 'Score %1$s: %2$s (needs %3$s)', 'ao-quill' ), $s['check'], number_format( (float) $s['score'], 2 ), null === $s['threshold'] ? '-' : number_format( (float) $s['threshold'], 2 ) ) . ( $s['passed'] ? '' : ' ✗' );
			}
			$out[ $id ]['status'] = ( 'failed' === $out[ $id ]['status'] || ( 'failed' === $row['status'] && ! $active ) ) ? 'failed' : 'done';
		}
		// Per-step log first; then raw-log blocks for tasks it does not hold (output sent
		// before steps were tagged, or by an older worker). Each entry goes to the step its
		// task belongs to, so output flushed just after a step changed is not misplaced.
		$tagged = array();
		$seen   = array();
		foreach ( (array) ( $live['step_log'] ?? array() ) as $logged_step => $list ) {
			foreach ( (array) $list as $e ) {
				$tagged[]                               = array( $logged_step, $e );
				$seen[ $e['task'] . '|' . $e['agent'] ] = true;
			}
		}
		$entries = array(); // untagged (older) output first, so each step keeps time order
		foreach ( self::step_log_from_output( (string) $live['output'] ) as $logged_step => $list ) {
			foreach ( $list as $e ) {
				if ( empty( $seen[ $e['task'] . '|' . $e['agent'] ] ) ) {
					$entries[] = array( $logged_step, $e );
				}
			}
		}
		$entries = array_merge( $entries, $tagged );
		$calls   = array(); // step => ordered [task, answered, seconds], one per AO call
		foreach ( $entries as $pair ) {
			list( $logged_step, $e ) = $pair;
			// Revisions run inside QA, whatever their task name says.
			$id = 0 === strpos( (string) $e['task'], 'draft:revise' ) ? 'qa' : self::step_for( (string) $e['task'] );
			if ( 'qa' === $id && 'translate' === $logged_step ) {
				$id = 'translate'; // a translation's own checks (qa:grammar:bs, text_checks:de) are named like QA's
			}
			if ( ! isset( $out[ $id ] ) ) {
				$id = self::step_for( (string) $logged_step );
			}
			if ( ! isset( $out[ $id ] ) ) {
				continue;
			}
			$answer = self::answer_of( (string) $e['text'] );
			self::track_call( $calls[ $id ], (string) $e['task'], $answer );
			if ( null !== $answer ) {
				$out[ $id ]['answers'][] = array(
					'task'    => (string) $e['task'],
					'agent'   => (string) $e['agent'],
					'seconds' => $answer['seconds'],
					'fields'  => $answer['fields'],
				);
			}
		}
		if ( $active && isset( $out[ $current ] ) ) {
			$out[ $current ]['status'] = 'running';
		} elseif ( ! $active && 'failed' === $run['status'] && isset( $out[ $current ] ) && 'done' !== $out[ $current ]['status'] ) {
			$out[ $current ]['status'] = 'failed';
		}
		if ( isset( $out['images'] ) && ! $out['images']['gallery'] ) {
			// Runs from before the images step listed its choice: the checkpoint has the images.
			$out['images']['gallery'] = self::gallery_from_checkpoint( $run );
		}
		$sub_avg   = self::substep_averages( $run['run_id'] );
		$qa_rounds = array(); // round => [check => score row], with the round's stage status
		foreach ( $rows as $row ) {
			if ( 'qa' === $row['stage'] ) {
				$scores = array();
				foreach ( (array) json_decode( (string) $row['scores'], true ) as $sc ) {
					$scores[ $sc['check'] ] = $sc;
				}
				$qa_rounds[ (int) $row['attempt'] ] = array( 'scores' => $scores, 'status' => $row['status'] );
			}
		}
		$tr_stages = array(); // language => its translate, translation_qa and taxonomy rows, in order
		foreach ( $rows as $row ) {
			if ( '' !== (string) $row['language'] && in_array( $row['stage'], array( 'translate', 'translation_qa', 'taxonomy' ), true ) ) {
				$tr_stages[ (string) $row['language'] ][] = $row;
			}
		}
		foreach ( $out as $id => $step ) {
			if ( 'translate' === $id ) {
				$out[ $id ]['substeps'] = self::translate_rows( $calls[ $id ] ?? array(), $step['status'], $sub_avg[ $id ] ?? array(), $tr_stages );
				continue;
			}
			$out[ $id ]['substeps'] = self::substeps( $id, $calls[ $id ] ?? array(), $step['status'], $sub_avg[ $id ] ?? array(), 'qa' === $id ? $qa_rounds : array() );
		}
		return array_values( $out );
	}

	/**
	 * Steps made of several AO calls, with the calls that make up each sub-step. A task
	 * matches a sub-step when, without its language suffix, it equals or starts with one of
	 * the listed names. QA restarts as a new round after each revision ("draft:revise").
	 *
	 * @return array<string, array<string, array{0:string, 1:string[]}>>
	 */
	public static function substep_definitions() {
		return array(
			'research' => array(
				'read'      => array( __( 'Search and read', 'ao-quill' ), array( 'research', 'research:again', 'research:retry' ) ),
				'structure' => array( __( 'Collect claims', 'ao-quill' ), array() ),
				'verify'    => array( __( 'Verify quotes', 'ao-quill' ), array( 'verify_quotes' ) ),
			),
			'images'   => array(
				'subjects'  => array( __( 'What to show', 'ao-quill' ), array( 'images:subjects' ) ),
				'shortlist' => array( __( 'Media shortlist', 'ao-quill' ), array( 'images:shortlist' ) ),
				'judge'     => array( __( 'Vision check', 'ao-quill' ), array( 'images:judge' ) ),
				'relevance' => array( __( 'Relevance check', 'ao-quill' ), array( 'images:relevance' ) ),
				'stock'     => array( __( 'Stock images', 'ao-quill' ), array( 'images:stock' ) ),
			),
			'qa'       => array(
				'text'     => array( __( 'Structure and originality', 'ao-quill' ), array( 'text_checks' ) ),
				'grammar'  => array( __( 'Grammar', 'ao-quill' ), array( 'qa:grammar' ) ),
				'facts'    => array( __( 'Facts', 'ao-quill' ), array( 'qa:facts' ) ),
				'style'    => array( __( 'Style', 'ao-quill' ), array( 'qa:style' ) ),
				'seo'      => array( __( 'SEO', 'ao-quill' ), array( 'qa:seo' ) ),
				'revision' => array( __( 'Revision', 'ao-quill' ), array( 'draft:plan', 'draft:revise', 'draft:expand', 'draft:fit' ) ),
			),
			'commit'    => array(
				'instagram' => array( __( 'Instagram caption', 'ao-quill' ), array( 'instagram' ) ),
			),
			'translate' => array(
				'translate' => array( __( 'Translate', 'ao-quill' ), array( 'translate' ) ),
				'checks'    => array( __( 'Grammar and style', 'ao-quill' ), array( 'qa:grammar', 'qa:style', 'translation_qa', 'text_checks' ) ),
				'back'      => array( __( 'Back-translation', 'ao-quill' ), array( 'back_translate', 'fidelity' ) ),
			),
		);
	}

	/**
	 * Add one log entry to a step's call sequence. Several entries of one call (streamed
	 * chunks, then the answer) are one call; the same task after its answer is a new call.
	 *
	 * @param array|null $calls  Sequence, by reference.
	 * @param string     $task   Task of the entry.
	 * @param array|null $answer Parsed answer when the entry holds one.
	 */
	private static function track_call( &$calls, $task, $answer ) {
		$calls = (array) $calls;
		$last  = count( $calls ) - 1;
		if ( $last >= 0 && $calls[ $last ][0] === $task && ! $calls[ $last ][1] ) {
			if ( null !== $answer ) {
				$calls[ $last ][1] = true;
				$calls[ $last ][2] = (int) $answer['seconds'];
				$calls[ $last ][3] = $answer;
			}
			return;
		}
		$calls[] = array( $task, null !== $answer, null !== $answer ? (int) $answer['seconds'] : 0, $answer );
	}

	/**
	 * Which sub-step a task belongs to.
	 *
	 * @param string $step Step ID.
	 * @param string $task Task.
	 * @return string|null
	 */
	private static function substep_of( $step, $task ) {
		$task = (string) $task;
		if ( 'research' === $step && preg_match( '/:structure\d*$/', $task ) ) {
			return 'structure'; // research's JSON step is its own sub-step ("Collect claims")
		}
		$task = preg_replace( '/:structure\d*$/', '', $task );             // elsewhere it belongs to the call it structures
		$task = preg_replace( '/:[a-z]{2,3}(:revise)?$/', '$1', $task );    // language suffix
		$best = null;
		$len  = -1;
		foreach ( self::substep_definitions()[ $step ] ?? array() as $id => $def ) {
			foreach ( $def[1] as $name ) {
				if ( 0 === strpos( $task, $name ) && strlen( $name ) > $len ) {
					$best = $id;
					$len  = strlen( $name );
				}
			}
		}
		return $best;
	}

	/**
	 * Rows of sub-step bars for a step: one row, or one per QA round.
	 *
	 * @param string $step   Step ID.
	 * @param array  $calls  The step's call sequence.
	 * @param string $status Step status.
	 * @param array  $avg    Sub-step => average seconds.
	 * @return array<int, array{label:string, bars:array}>
	 */
	private static function substeps( $step, array $calls, $status, array $avg, array $qa_rounds = array() ) {
		$defs = self::substep_definitions()[ $step ] ?? null;
		if ( ! $defs ) {
			return array();
		}
		// Calls run one after another: any call followed by a later one has finished, even
		// when its answer did not reach the log in a recognisable form.
		for ( $i = 0, $n = count( $calls ) - 1; $i < $n; $i++ ) {
			$calls[ $i ][1] = true;
		}
		$rounds = array( array() );
		foreach ( $calls as $c ) {
			$sub = self::substep_of( $step, $c[0] );
			if ( null === $sub ) {
				continue;
			}
			if ( 'qa' === $step && 'revision' === $sub ) {
				$rounds[ count( $rounds ) - 1 ]['revision'][] = $c;
				$rounds[] = array();
				continue;
			}
			$rounds[ count( $rounds ) - 1 ][ $sub ][] = $c;
		}
		$rows = array();
		foreach ( $rounds as $n => $round ) {
			if ( $n > 0 && ! $round && 'running' !== $status ) {
				continue;
			}
			$bars    = array();
			$is_last = count( $rounds ) - 1 === $n;
			foreach ( $defs as $id => $def ) {
				if ( 'revision' === $id && ! isset( $round['revision'] ) ) {
					continue; // only shown when the round led to a revision
				}
				$cs   = $round[ $id ] ?? array();
				$done = $cs && ! in_array( false, array_column( $cs, 1 ), true );
				$open = $cs && ! $done;
				$st   = $done ? 'done' : ( $open ? 'running' : 'pending' );
				if ( 'running' === $st && 'running' !== $status ) {
					$st = 'failed' === $status ? 'failed' : 'done'; // a call that never answered in a finished step
				}
				if ( 'pending' === $st && ! $is_last ) {
					$st = 'skipped';
				}
				$bars[] = array(
					'id'      => $id,
					'label'   => $def[0],
					'status'  => $st,
					'seconds' => array_sum( array_column( $cs, 2 ) ),
					'avg_s'   => $avg[ $id ] ?? null,
				);
			}
			$row = array(
				/* translators: %d: QA round number */
				'label' => ( count( $rounds ) > 1 || 'qa' === $step ) ? sprintf( __( 'Round %d', 'ao-quill' ), $n + 1 ) : '',
				'bars'  => $bars,
			);
			if ( 'qa' === $step ) {
				$row['detail'] = self::qa_round( $round, $bars, $qa_rounds[ $n + 1 ] ?? null, $is_last && 'running' === $status );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * One QA round for the rounds gallery: each check with its score, pass mark and the editor's
	 * answer, and the revision written at the end of the round (or why there is none).
	 *
	 * @param array      $round   Sub-step => calls of this round.
	 * @param array      $bars    The round's bars.
	 * @param array|null $stage   The round's stage row: scores by check and status.
	 * @param bool       $current The round is the one running now.
	 * @return array
	 */
	private static function qa_round( array $round, array $bars, $stage, $current ) {
		$scores = $stage['scores'] ?? array();
		$status = array_column( $bars, 'status', 'id' );
		$checks = array();
		$labels = array(
			'structure'   => array( 'text', __( 'Structure', 'ao-quill' ) ),
			'originality' => array( 'text', __( 'Originality', 'ao-quill' ) ),
			'grammar'     => array( 'grammar', __( 'Grammar', 'ao-quill' ) ),
			'facts'       => array( 'facts', __( 'Facts', 'ao-quill' ) ),
			'style'       => array( 'style', __( 'Style', 'ao-quill' ) ),
			'seo'         => array( 'seo', __( 'SEO', 'ao-quill' ) ),
			'length'      => array( 'length', __( 'Length', 'ao-quill' ) ),
		);
		foreach ( $labels as $check => $def ) {
			list( $sub, $label ) = $def;
			$list   = $round[ $sub ] ?? array();
			$call   = $list ? end( $list ) : null; // the latest call of this check in the round
			$answer = is_array( $call ) ? ( $call[3] ?? null ) : null;
			$json   = is_array( $answer ) ? ( $answer['json'] ?? null ) : null;
			if ( is_array( $json ) && 'text' === $sub ) {
				$json = $json[ $check ] ?? null; // text_checks answers both structure and originality
			}
			$sc       = $scores[ $check ] ?? null;
			$checks[] = array(
				'id'        => $check,
				'label'     => $label,
				'status'    => $sc ? ( $sc['passed'] ? 'passed' : 'failed' ) : ( $status[ $sub ] ?? 'pending' ),
				'score'     => $sc ? (float) $sc['score'] : null,
				'threshold' => $sc ? $sc['threshold'] : null,
				'summary'   => is_array( $json ) && isset( $json['summary'] ) ? (string) $json['summary'] : '',
				'fields'    => is_array( $json ) ? self::fields( $json ) : array(),
				'seconds'   => is_array( $call ) ? (int) $call[2] : 0,
			);
		}
		$revision = array( 'state' => 'not_generated' );
		$rev_list = $round['revision'] ?? array();
		$rev_call = $rev_list ? end( $rev_list ) : null;
		$rev_json = is_array( $rev_call ) && is_array( $rev_call[3] ?? null ) ? ( $rev_call[3]['json'] ?? null ) : null;
		if ( is_array( $rev_json ) && isset( $rev_json['content_html'] ) ) {
			$revision = array(
				'state'   => 'generated',
				'title'   => (string) ( $rev_json['title'] ?? '' ),
				'excerpt' => (string) ( $rev_json['excerpt'] ?? '' ),
				'html'    => (string) $rev_json['content_html'],
				'seconds' => (int) $rev_call[2],
			);
		} elseif ( $rev_call ) {
			$revision = array( 'state' => 'running' );
		} elseif ( $stage && 'ok' === $stage['status'] ) {
			$revision = array( 'state' => 'passed' );
		}
		return array(
			'status'   => $stage ? ( 'ok' === $stage['status'] ? 'passed' : 'failed' ) : ( $current ? 'running' : 'pending' ),
			'checks'   => $checks,
			'revision' => $revision,
		);
	}

	/** Languages written right to left. */
	const RTL = array( 'ar', 'he', 'fa', 'ur' );

	/**
	 * Translate step rows: one per target language in the network's order, each with its own
	 * sub-step bars and, for the rounds gallery, its status, checks, scores and translated text.
	 *
	 * @param array  $calls  The step's call sequence.
	 * @param string $status Step status.
	 * @param array  $avg    Sub-step => average seconds.
	 * @param array  $stages Language => its stage rows (translate, translation_qa, taxonomy).
	 * @return array
	 */
	private static function translate_rows( array $calls, $status, array $avg, array $stages ) {
		$defs = self::substep_definitions()['translate'];
		for ( $i = 0, $n = count( $calls ) - 1; $i < $n; $i++ ) {
			$calls[ $i ][1] = true; // followed by a later call: it has finished
		}
		$by    = array(); // language => sub-step => calls, and '_all' => its calls in order
		$order = array(); // languages in the order the worker started them
		foreach ( $calls as $c ) {
			if ( ! preg_match( '/:([a-z]{2,3})(?::revise)?$/', (string) $c[0], $m ) ) {
				continue;
			}
			$by[ $m[1] ]['_all'][] = $c;
			$order[ $m[1] ]        = true;
			$sub                   = self::substep_of( 'translate', $c[0] );
			if ( null !== $sub ) {
				$by[ $m[1] ][ $sub ][] = $c;
			}
		}
		$names   = array();
		$primary = 'en';
		try {
			foreach ( (array) AOQ_Plugin::translation_adapter()->active_languages() as $l ) {
				if ( ! empty( $l['primary'] ) ) {
					$primary = (string) $l['code'];
				} elseif ( empty( $l['excluded'] ) ) {
					$names[ $l['code'] ] = $l['name'];
				}
			}
		} catch ( Throwable $e ) {
			$names = array();
		}
		$codes = array_keys( $names );
		foreach ( array_merge( array_keys( $order ), array_keys( $stages ) ) as $code ) {
			// The primary language is the article itself: its own category step and quality checks
			// also carry a language ("taxonomy" en) and are not a translation.
			if ( $code !== $primary && ! in_array( $code, $codes, true ) ) {
				$codes[] = $code;
			}
		}
		$current = $order ? array_key_last( $order ) : null;
		$rows    = array();
		foreach ( $codes as $code ) {
			$lc      = $by[ $code ] ?? array();
			$running = 'running' === $status && $code === $current;
			$bars    = array();
			foreach ( $defs as $id => $def ) {
				$cs   = $lc[ $id ] ?? array();
				$done = $cs && ! in_array( false, array_column( $cs, 1 ), true );
				$st   = $done ? 'done' : ( $cs ? 'running' : 'pending' );
				if ( 'running' === $st && ! $running ) {
					$st = 'failed' === $status ? 'failed' : 'done';
				}
				$bars[] = array(
					'id'      => $id,
					'label'   => $def[0],
					'status'  => $st,
					'seconds' => array_sum( array_column( $cs, 2 ) ),
					'avg_s'   => $avg[ $id ] ?? null,
				);
			}
			$name   = $names[ $code ] ?? strtoupper( $code );
			$rows[] = array(
				'label'   => sprintf( '%1$s (%2$s)', $name, $code ),
				'current' => $running,
				'bars'    => $bars,
				'detail'  => self::translate_language( $code, $name, $lc, $stages[ $code ] ?? array(), $running ),
			);
		}
		return $rows;
	}

	/**
	 * One language of the translate step for the rounds gallery: its status, what happened in
	 * order (translation, each check attempt with its scores, revisions, terms), every check with
	 * its score, pass mark and answer, and the latest translated text.
	 *
	 * @param string $code    Language code.
	 * @param string $name    Language name.
	 * @param array  $lc      Sub-step => calls of this language, '_all' => its calls in order.
	 * @param array  $stages  Its stage rows, in order.
	 * @param bool   $running It is the language being translated now.
	 * @return array
	 */
	private static function translate_language( $code, $name, array $lc, array $stages, $running ) {
		$all  = $lc['_all'] ?? array();
		$json = function ( $c ) {
			return is_array( $c ) && is_array( $c[3] ?? null ) ? ( $c[3]['json'] ?? null ) : null;
		};
		$last = function ( $prefix ) use ( $all ) {
			$hit = null;
			foreach ( $all as $c ) {
				if ( 0 === strpos( (string) $c[0], $prefix ) ) {
					$hit = $c;
				}
			}
			return $hit;
		};
		$dur  = function ( $ms ) {
			$s = (int) round( $ms / 1000 );
			/* translators: 1: minutes, 2: seconds */
			return $s >= 60 ? sprintf( __( '%1$d min %2$d s', 'ao-quill' ), intdiv( $s, 60 ), $s % 60 ) : sprintf( __( '%d s', 'ao-quill' ), $s );
		};

		$text = array( 'state' => $running ? 'running' : 'not_generated' );
		foreach ( array_reverse( $all ) as $c ) {
			$j = $json( $c );
			if ( 0 === strpos( (string) $c[0], 'translate:' ) && is_array( $j ) && isset( $j['content_html'] ) ) {
				$text = array(
					'state'   => 'generated',
					'title'   => (string) ( $j['title'] ?? '' ),
					'excerpt' => (string) ( $j['excerpt'] ?? '' ),
					'html'    => (string) $j['content_html'],
					'seconds' => (int) $c[2],
					'revised' => false !== strpos( (string) $c[0], ':revise' ),
				);
				break;
			}
		}
		if ( empty( $text['revised'] ) ) {
			// TranslateGemma answers in plain text: the worker puts its translation in the stage report.
			foreach ( $stages as $r ) {
				$d = json_decode( (string) $r['detail'], true );
				// The first translation, or TranslateGemma's newer one after failed checks (translation_qa).
				if ( in_array( $r['stage'], array( 'translate', 'translation_qa' ), true ) && is_array( $d['translation'] ?? null ) && ! empty( $d['translation']['content_html'] ) ) {
					$text = array(
						'state'      => 'generated',
						'title'      => (string) ( $d['translation']['title'] ?? '' ),
						'excerpt'    => (string) ( $d['translation']['excerpt'] ?? '' ),
						'html'       => (string) $d['translation']['content_html'],
						'seconds'    => (int) round( (int) $r['duration_ms'] / 1000 ),
						'revised'    => 'translation_qa' === $r['stage'],
						'translator' => (string) ( $d['translator'] ?? '' ),
					);
				}
			}
		}

		$qa_rows = array_values(
			array_filter(
				$stages,
				function ( $r ) {
					return 'translation_qa' === $r['stage'];
				}
			)
		);
		$last_qa = $qa_rows ? end( $qa_rows ) : null;
		$scores  = array();
		foreach ( (array) json_decode( (string) ( $last_qa['scores'] ?? '' ), true ) as $sc ) {
			$scores[ $sc['check'] ] = $sc;
		}
		$reused = false; // kept from the run this one resumes: its scores came with it
		foreach ( $stages as $r ) {
			$d = json_decode( (string) $r['detail'], true );
			if ( 'translate' === $r['stage'] && ! empty( $d['reused'] ) ) {
				$reused = true;
				if ( ! $scores ) {
					foreach ( (array) ( $d['scores'] ?? array() ) as $sc ) {
						$scores[ $sc['check'] ] = array( 'check' => $sc['check'], 'score' => (float) $sc['score'], 'threshold' => null, 'passed' => true );
					}
				}
			}
		}
		$labels = array(
			'grammar'     => array( 'qa:grammar:', __( 'Grammar', 'ao-quill' ) ),
			'fidelity'    => array( 'fidelity:', __( 'Meaning (back-translation compared with the original)', 'ao-quill' ) ),
			'style'       => array( 'qa:style:', __( 'Style', 'ao-quill' ) ),
			'structure'   => array( 'text_checks:', __( 'Structure', 'ao-quill' ) ),
			'originality' => array( 'text_checks:', __( 'Originality', 'ao-quill' ) ),
			'back'        => array( 'back_translate:', __( 'Back-translation into the original language', 'ao-quill' ) ),
		);
		// Pass marks from the settings, so a check shows its mark before the attempt's verdict is in.
		$set   = AOQ_Settings::get();
		$th    = (array) ( $set['thresholds'] ?? array() );
		$marks = array(
			'grammar'     => $th['translation_grammar'] ?? null,
			'fidelity'    => $th['translation_fidelity'] ?? null,
			'style'       => $th['translation_style'] ?? null,
			'structure'   => $th['structure'] ?? null,
			'originality' => isset( $set['originality_max_overlap'] ) ? 1 - (float) $set['originality_max_overlap'] : null,
		);
		$checks  = array();
		$holding = array(); // checks below their pass mark
		foreach ( $labels as $check => $def ) {
			$c = $last( $def[0] );
			$j = $json( $c );
			if ( is_array( $j ) && 'text_checks:' === $def[0] ) {
				$j = $j[ $check ] ?? null;
			}
			$sc    = $scores[ $check ] ?? null;
			$mark  = $sc && null !== $sc['threshold'] ? (float) $sc['threshold'] : ( isset( $marks[ $check ] ) ? (float) $marks[ $check ] : null );
			$score = $sc ? (float) $sc['score'] : null;
			if ( null === $score && is_array( $j ) ) {
				if ( is_numeric( $j['score'] ?? null ) ) {
					$score = (float) $j['score'];
				} elseif ( 'originality' === $check && is_numeric( $j['max_overlap'] ?? null ) ) {
					$score = 1 - (float) $j['max_overlap'];
				}
			}
			$final = (bool) $sc; // the attempt's recorded verdict, not the answer alone
			if ( $sc ) {
				$st = $sc['passed'] ? 'passed' : 'failed';
			} elseif ( null !== $score && null !== $mark && $c && $c[1] ) {
				$st = $score >= $mark ? 'passed' : 'failed'; // answered: compared with the mark, not final yet
			} elseif ( $c && $c[1] ) {
				$st = 'passed'; // the back-translation: done
			} else {
				$st = $c && $running ? 'running' : 'pending';
			}
			if ( 'failed' === $st ) {
				$holding[] = sprintf( '%1$s %2$s (needs %3$s)', 'fidelity' === $check ? __( 'Meaning', 'ao-quill' ) : $def[1], null === $score ? '-' : number_format( $score, 2 ), null === $mark ? '-' : number_format( $mark, 2 ) );
			}
			$checks[] = array(
				'id'        => $check,
				'label'     => $def[1],
				'status'    => $st,
				'final'     => $final,
				'score'     => $score,
				'threshold' => 'back' === $check ? null : $mark,
				'summary'   => is_array( $j ) && isset( $j['summary'] ) ? (string) $j['summary'] : '',
				'fields'    => is_array( $j ) ? self::fields( $j ) : array(),
				'seconds'   => is_array( $c ) ? (int) $c[2] : 0,
			);
		}

		$log = array();
		$ok  = false;
		foreach ( $stages as $r ) {
			if ( 'translate' === $r['stage'] && $reused ) {
				$log[] = __( 'Kept: translated and passed its checks in the run this one resumes.', 'ao-quill' );
				$ok    = true;
			} elseif ( 'translate' === $r['stage'] ) {
				$d     = json_decode( (string) $r['detail'], true );
				$by    = is_array( $d ) && ! empty( $d['translator'] ) ? (string) $d['translator'] : '';
				$log[] = '' !== $by
					/* translators: 1: duration, 2: agent */
					? sprintf( __( 'Translated in %1$s by %2$s (TranslateGemma).', 'ao-quill' ), $dur( $r['duration_ms'] ), $by )
					/* translators: %s: duration */
					: sprintf( __( 'Translated in %s.', 'ao-quill' ), $dur( $r['duration_ms'] ) );
				if ( ! empty( $d['unlinked_posts'] ) ) {
					/* translators: %s: post IDs */
					$log[] = sprintf( __( 'Links to posts with no translation yet were kept as plain text: %s.', 'ao-quill' ), implode( ', ', array_map( 'intval', (array) $d['unlinked_posts'] ) ) );
				}
			} elseif ( 'translation_qa' === $r['stage'] ) {
				$parts = array();
				foreach ( (array) json_decode( (string) $r['scores'], true ) as $sc ) {
					$parts[] = $sc['check'] . ' ' . number_format( (float) $sc['score'], 2 ) . ( $sc['passed'] ? '' : ' ✗ (' . sprintf( /* translators: %s: pass mark */ __( 'needs %s', 'ao-quill' ), number_format( (float) $sc['threshold'], 2 ) ) . ')' );
				}
				$ok    = $ok || 'ok' === $r['status'];
				$what  = 'ok' === $r['status'] ? __( 'passed', 'ao-quill' ) : trim( (string) $r['failure_reason'] );
				/* translators: 1: attempt number, 2: result, 3: scores, 4: duration */
				$log[] = sprintf( __( 'Checks, attempt %1$d: %2$s Scores: %3$s. (%4$s)', 'ao-quill' ), (int) $r['attempt'], rtrim( $what, '.' ) . '.', $parts ? implode( ', ', $parts ) : '-', $dur( $r['duration_ms'] ) );
			} elseif ( 'taxonomy' === $r['stage'] ) {
				$terms = (array) json_decode( (string) $r['detail'], true );
				$cats  = implode( ', ', array_map( 'strval', (array) ( $terms['categories'] ?? array() ) ) );
				$tags  = implode( ', ', array_map( 'strval', (array) ( $terms['tags'] ?? array() ) ) );
				/* translators: 1: categories, 2: tags */
				$log[] = sprintf( __( 'Categories: %1$s. Tags: %2$s.', 'ao-quill' ), '' !== $cats ? $cats : '-', '' !== $tags ? $tags : '-' );
			}
		}
		if ( $running ) {
			$log[] = __( 'Working on this language now.', 'ao-quill' );
		}
		if ( $running ) {
			$status = 'running';
		} elseif ( $ok ) {
			$status = 'passed';
		} elseif ( $stages || $all ) {
			$status = 'failed';
		} else {
			$status = 'pending';
		}
		return array(
			'kind'     => 'language',
			'code'     => $code,
			'name'     => $name,
			'dir'      => in_array( $code, self::RTL, true ) ? 'rtl' : 'ltr',
			'status'   => $status,
			'log'      => $log,
			'holding'  => $holding,
			'attempts'     => count( $qa_rows ),
			'max_attempts' => (int) ( $set['max_revision_loops'] ?? 3 ) + 1,
			'checks'   => $checks,
			'revision' => $text,
		);
	}

	/**
	 * Average seconds of each sub-step over recent runs that answered it, from their
	 * saved reports.
	 *
	 * @param string $exclude_run Run left out (the one being shown).
	 * @return array<string, array<string, int>> step => sub-step => seconds
	 */
	public static function substep_averages( $exclude_run ) {
		global $wpdb;
		$cached = get_site_transient( 'aoq_substep_avg' );
		if ( ! is_array( $cached ) ) {
			$sums = array();
			$rows = (array) $wpdb->get_results( 'SELECT run_id, report FROM ' . AOQ_Runs::table() . ' WHERE report IS NOT NULL ORDER BY id DESC LIMIT ' . (int) self::AVERAGE_RUNS, ARRAY_A ); // phpcs:ignore
			foreach ( $rows as $row ) {
				$live = json_decode( (string) $row['report'], true );
				if ( ! is_array( $live ) ) {
					continue;
				}
				foreach ( (array) ( $live['step_log'] ?? array() ) as $list ) {
					foreach ( (array) $list as $e ) {
						$answer = self::answer_of( (string) $e['text'] );
						$step   = 0 === strpos( (string) $e['task'], 'draft:revise' ) ? 'qa' : self::step_for( (string) $e['task'] );
						$sub    = null !== $answer ? self::substep_of( $step, (string) $e['task'] ) : null;
						if ( null !== $sub ) {
							$sums[ $step ][ $sub ][ $row['run_id'] ] = ( $sums[ $step ][ $sub ][ $row['run_id'] ] ?? 0 ) + (int) $answer['seconds'];
						}
					}
				}
			}
			$cached = array();
			foreach ( $sums as $step => $subs ) {
				foreach ( $subs as $sub => $per_run ) {
					$cached[ $step ][ $sub ] = (int) round( array_sum( $per_run ) / count( $per_run ) );
				}
			}
			set_site_transient( 'aoq_substep_avg', $cached, 600 );
		}
		return $cached;
	}

	/**
	 * Rebuild per-step output from the raw log's "### task (agent)" headings, for
	 * output reported by workers that did not tag entries with their step.
	 *
	 * @param string $output Raw log.
	 * @return array<string, array>
	 */
	private static function step_log_from_output( $output ) {
		$log = array();
		foreach ( preg_split( '/^### /m', $output ) as $block ) {
			if ( ! preg_match( '/^(\S+)(?: \(([^)]*)\))?\n/', $block, $m ) ) {
				continue;
			}
			$log[ self::step_for( $m[1] ) ][] = array(
				'task'  => $m[1],
				'agent' => $m[2] ?? '',
				'text'  => substr( $block, strlen( $m[0] ) ),
			);
		}
		return $log;
	}

	/**
	 * The last AO answer in a task's output, decoded into readable fields.
	 *
	 * @param string $text Output of one task.
	 * @return array{seconds:int, fields:array}|null
	 */
	private static function answer_of( $text ) {
		if ( ! preg_match_all( '/\[answer after (\d+) s\]\n/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$last = count( $m[0] ) - 1;
		$body = trim( substr( $text, $m[0][ $last ][1] + strlen( $m[0][ $last ][0] ) ) );
		$json = json_decode( $body, true );
		return array(
			'seconds' => (int) $m[1][ $last ][0],
			'fields'  => is_array( $json ) ? self::fields( $json ) : array( strlen( $body ) > 1500 ? array( '', self::clip( $body, 1500 ), $body ) : array( '', self::clip( $body, 1500 ) ) ),
			'json'    => is_array( $json ) ? $json : null,
		);
	}

	/**
	 * Decoded JSON as label/value rows for people.
	 *
	 * @param array $data Decoded answer.
	 * @return array<int, array{0:string, 1:string}>
	 */
	private static function fields( array $data ) {
		$rows = array();
		foreach ( $data as $key => $value ) {
			self::$clipped = false;
			$text = implode( "\n", self::readable( $value, 1 ) );
			$row  = array( self::humanize( (string) $key ), $text );
			if ( self::$clipped ) {
				$row[] = self::full( $value ); // [label, shown, full]
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Any value as short readable lines.
	 *
	 * @param mixed $value Value.
	 * @param int   $depth How deep nested objects are expanded.
	 * @return string[]
	 */
	private static function readable( $value, $depth ) {
		if ( null === $value || '' === $value || array() === $value ) {
			return array();
		}
		if ( is_bool( $value ) ) {
			return array( $value ? __( 'yes', 'ao-quill' ) : __( 'no', 'ao-quill' ) );
		}
		if ( ! is_array( $value ) ) {
			return array( self::clip( (string) $value, 600 ) );
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		$lines   = array();
		$i       = 0;
		foreach ( $value as $k => $v ) {
			if ( $i++ >= 15 ) {
				self::$clipped = true;
				/* translators: %d: number of further items */
				$lines[] = sprintf( __( '... and %d more', 'ao-quill' ), count( $value ) - 15 );
				break;
			}
			$v_is_list = is_array( $v ) && array_keys( $v ) === range( 0, count( $v ) - 1 );
			if ( $is_list ) {
				$line = is_array( $v ) && ! $v_is_list ? self::headline( $v ) : implode( ', ', self::readable( $v, $depth - 1 ) );
				if ( '' !== $line ) {
					$lines[] = '• ' . $line;
				}
				continue;
			}
			$label = self::humanize( (string) $k );
			if ( $v_is_list && $v ) {
				// A list under a key: the key on its own line, then one item per line.
				$lines[] = $label . ':';
				foreach ( self::readable( $v, $depth - 1 ) as $item ) {
					$lines[] = '   ' . $item;
				}
				continue;
			}
			$line = is_array( $v ) ? self::headline( $v ) : implode( ', ', self::readable( $v, $depth - 1 ) );
			if ( '' !== $line ) {
				$lines[] = $label . ': ' . $line;
			}
		}
		return $lines;
	}

	/**
	 * One line for an object: its most telling field plus a short reason or note.
	 *
	 * @param array $obj Object.
	 * @return string
	 */
	private static function headline( array $obj ) {
		if ( isset( $obj['id'], $obj['advertised'] ) ) {
			return (string) $obj['id'] . ( $obj['advertised'] ? ' ✓' : ' ✗ ' . __( 'missing', 'ao-quill' ) );
		}
		foreach ( array( 'title', 'heading', 'claim', 'name', 'summary', 'point', 'problem', 'query', 'media_key', 'image_key', 'image_id', 'candidate_id', 'claim_id', 'url' ) as $k ) {
			if ( isset( $obj[ $k ] ) && is_scalar( $obj[ $k ] ) && '' !== (string) $obj[ $k ] ) {
				$line = (string) $obj[ $k ];
				foreach ( array( 'novel', 'verified', 'relevant' ) as $flag ) {
					if ( isset( $obj[ $flag ] ) ) {
						$line .= ' (' . $flag . ': ' . ( $obj[ $flag ] ? __( 'yes', 'ao-quill' ) : __( 'no', 'ao-quill' ) ) . ')';
					}
				}
				foreach ( array( 'reason', 'angle', 'note', 'fix' ) as $k2 ) {
					if ( ! empty( $obj[ $k2 ] ) && is_scalar( $obj[ $k2 ] ) ) {
						$line .= ' - ' . (string) $obj[ $k2 ];
						break;
					}
				}
				return self::clip( $line, 400 );
			}
		}
		return self::clip( (string) wp_json_encode( $obj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 300 );
	}

	private static function humanize( $key ) {
		if ( preg_match( '/^[a-z]{2,3}$/', $key ) ) {
			return strtoupper( $key ); // language codes: EN, BS
		}
		return ucfirst( str_replace( '_', ' ', $key ) );
	}

	private static function clip( $text, $max ) {
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		if ( strlen( $text ) > $max ) {
			self::$clipped = true;
			return substr( $text, 0, $max ) . '...';
		}
		return $text;
	}

	/**
	 * Chosen images for the gallery: only http(s) URLs, everything as plain text.
	 *
	 * @param array $chosen Images step detail "chosen".
	 * @return array
	 */
	private static function gallery( array $chosen ) {
		$out = array();
		foreach ( $chosen as $img ) {
			$thumb = esc_url_raw( (string) ( $img['preview_url'] ?? '' ), array( 'http', 'https' ) );
			$full  = esc_url_raw( (string) ( $img['full_url'] ?? '' ), array( 'http', 'https' ) );
			if ( '' === $thumb && '' === $full ) {
				continue;
			}
			$out[] = array(
				'key'         => (string) ( $img['key'] ?? '' ),
				'source'      => (string) ( $img['source'] ?? '' ),
				'thumb'       => $thumb ? $thumb : $full,
				'full'        => $full ? $full : $thumb,
				'alt'         => (string) ( $img['alt'] ?? '' ),
				'caption'     => (string) ( $img['caption'] ?? '' ),
				'license'     => (string) ( $img['license'] ?? '' ),
				'attribution' => (string) ( $img['attribution'] ?? '' ),
				'page'        => esc_url_raw( (string) ( $img['source_page_url'] ?? '' ), array( 'http', 'https' ) ),
				'score'       => isset( $img['score'] ) ? (float) $img['score'] : null,
			);
		}
		return $out;
	}

	/**
	 * Gallery from a run's checkpoint (its images and their alt text and captions).
	 *
	 * @param array $run Run row.
	 * @return array
	 */
	public static function run_gallery( array $run ) {
		$detail = null;
		foreach ( AOQ_Runs::stages( $run['run_id'] ) as $row ) {
			if ( 'images' === $row['stage'] && 'ok' === $row['status'] ) {
				$detail = json_decode( (string) $row['detail'], true );
			}
		}
		if ( ! empty( $detail['chosen'] ) && is_array( $detail['chosen'] ) ) {
			return self::gallery( $detail['chosen'] );
		}
		return self::gallery_from_checkpoint( $run );
	}

	/**
	 * Gallery from a run's checkpoint (its images and their alt text and captions).
	 *
	 * @param array $run Run row.
	 * @return array
	 */
	private static function gallery_from_checkpoint( array $run ) {
		$cp    = AOQ_Runs::checkpoint( $run );
		$state = $cp['state'] ?? array();
		if ( empty( $state['images'] ) || ! is_array( $state['images'] ) ) {
			return array();
		}
		$texts  = is_array( $state['image_texts'] ?? null ) ? reset( $state['image_texts'] ) : array();
		$chosen = array();
		foreach ( $state['images'] as $img ) {
			$key      = (string) ( $img['key'] ?? '' );
			$chosen[] = array(
				'key'             => $key,
				'source'          => $img['source'] ?? '',
				'preview_url'     => $img['preview_url'] ?? ( $img['url'] ?? '' ),
				'full_url'        => $img['url'] ?? ( $img['preview_url'] ?? '' ),
				'alt'             => $texts[ $key ]['alt'] ?? '',
				'caption'         => $texts[ $key ]['caption'] ?? '',
				'license'         => $img['license']['name'] ?? ( $img['license']['code'] ?? '' ),
				'attribution'     => $img['attribution'] ?? '',
				'source_page_url' => $img['source_page_url'] ?? '',
			);
		}
		return self::gallery( $chosen );
	}

	/** Set when clip() or a list limit shortened something, so the full value is sent too. */
	private static $clipped = false;

	/**
	 * The complete value for the "Show full" view: text as is, anything else as pretty JSON.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function full( $value ) {
		return is_string( $value ) ? $value : (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
