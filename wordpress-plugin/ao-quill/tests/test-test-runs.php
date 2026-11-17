<?php
/**
 * Test runs, live progress and lease renewal.
 *
 * @package AOQuill
 */

class Test_AOQ_Test_Runs extends AOQ_TestCase {

	private function claim_test() {
		AOQ_Runs::request_test_run();
		// Before the 07:00 schedule: only a test run can be claimed.
		$run = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$this->assertIsArray( $run );
		$this->assertSame( 'test', $run['trigger'] );
		return $run;
	}

	public function test_test_run_does_not_count_as_the_days_post() {
		$run = $this->claim_test();
		$this->assertFalse( AOQ_Runs::test_requested() );
		$this->assertNull( AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 08:00 UTC' ) ) ), 'one run at a time' );
		$this->assertTrue( AOQ_Runs::finish( $this->finish_input( $run['run_id'] ) ) );
		$scheduled = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 08:00 UTC' ) ) );
		$this->assertIsArray( $scheduled );
		$this->assertSame( 'scheduled', $scheduled['trigger'] );
	}

	public function test_test_runs_are_never_committed() {
		$run = $this->claim_test();
		$res = AOQ_Commit::commit( $this->bundle( $run['run_id'] ) );
		$this->assertWPError( $res );
		$this->assertSame( 'aoq_test_run', $res->get_error_code() );
		$this->assertSame( 1, $this->counts( get_main_site_id() )['posts'], 'only the fixture post exists' );
	}

	public function test_test_run_succeeds_without_commit_and_keeps_its_preview() {
		$run   = $this->claim_test();
		$input = $this->finish_input( $run['run_id'] );
		$input['result']['preview'] = array(
			'posts' => array(
				array( 'language' => 'en', 'title' => 'Preview title', 'excerpt' => 'E', 'content' => '<p>Body</p>' ),
			),
		);
		$this->assertTrue( AOQ_Runs::finish( $input ) );
		$row = AOQ_Runs::get( $run['run_id'] );
		$this->assertSame( 'succeeded', $row['status'] );
		$this->assertSame( 'Preview title', json_decode( $row['result'], true )['preview']['posts'][0]['title'] );
	}

	public function test_progress_is_stored_and_renews_the_lease() {
		global $wpdb;
		$run = $this->claim_test();
		$wpdb->update( AOQ_Runs::table(), array( 'lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() + 60 ) ), array( 'run_id' => $run['run_id'] ) );
		$ok = AOQ_Runs::report_progress(
			array(
				'run_id' => $run['run_id'],
				'step'   => 3,
				'steps'  => 14,
				'label'  => 'topics',
				'output' => array(
					array( 'task' => 'topics', 'agent' => 'client.aoq_analyst', 'text' => 'Thinking about bridges' ),
					array( 'task' => 'topics', 'agent' => 'client.aoq_analyst', 'text' => ' and rivers.' ),
					array( 'task' => 'novelty', 'agent' => 'client.aoq_analyst', 'text' => 'Novel.' ),
				),
			)
		);
		$this->assertTrue( $ok );
		$live = AOQ_Runs::live_data( $run['run_id'] );
		$this->assertSame( 3, $live['step'] );
		$this->assertSame( "### topics (client.aoq_analyst)\nThinking about bridges and rivers.\n### novelty (client.aoq_analyst)\nNovel.", $live['output'] );
		$row = AOQ_Runs::get( $run['run_id'] );
		$this->assertSame( 'running', $row['status'] );
		$this->assertGreaterThan( time() + 3600, strtotime( $row['lease_expires_at'] . ' UTC' ), 'lease extended to lease_minutes' );
	}

	public function test_live_output_keeps_the_newest_bytes() {
		$run = $this->claim_test();
		$big = str_repeat( 'x', AOQ_Runs::LIVE_MAX_BYTES );
		AOQ_Runs::report_progress( array( 'run_id' => $run['run_id'], 'step' => 1, 'steps' => 2, 'label' => '', 'output' => array( array( 'task' => '', 'agent' => '', 'text' => $big ) ) ) );
		AOQ_Runs::report_progress( array( 'run_id' => $run['run_id'], 'step' => 1, 'steps' => 2, 'label' => '', 'output' => array( array( 'task' => '', 'agent' => '', 'text' => 'END' ) ) ) );
		$live = AOQ_Runs::live_data( $run['run_id'] );
		$this->assertSame( AOQ_Runs::LIVE_MAX_BYTES, strlen( $live['output'] ) );
		$this->assertStringEndsWith( 'END', $live['output'] );
		$this->assertSame( 3, $live['dropped'] );
	}

	public function test_test_tab_renders() {
		require_once AOQ_DIR . '/includes/admin/class-aoq-admin.php';
		$this->claim_test();
		$m = new ReflectionMethod( 'AOQ_Admin', 'render_generation' );
		$m->setAccessible( true );
		ob_start();
		$m->invoke( null );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Generate now', $html );
		$this->assertStringContainsString( 'Test run (writes nothing)', $html );
		$this->assertStringContainsString( 'id="aoq-output"', $html );
		$this->assertStringContainsString( 'id="aoq-card"', $html );
		$this->assertStringContainsString( 'disabled', $html, 'start button is disabled while a run is active' );
		$this->assertSame( 14, substr_count( $html, 'class="aoq-step pending"' ), 'every step listed with its legend' );
		$this->assertStringContainsString( 'Reads sources and collects facts', $html );
		$this->assertStringContainsString( 'Technical log', $html );
	}

	public function test_steps_show_state_and_readable_answers() {
		$run = $this->claim_test();
		AOQ_Runs::report_stage(
			array(
				'run_id' => $run['run_id'], 'stage' => 'inventory', 'language' => null, 'attempt' => 1, 'status' => 'ok',
				'started_at' => gmdate( 'c' ), 'duration_ms' => 1200, 'agents' => array(), 'scores' => array(), 'revision_count' => 0,
				'failure_reason' => null, 'detail' => array( 'languages' => array( 'en', 'bs' ), 'posts' => 120 ),
			)
		);
		$answer = wp_json_encode( array( 'candidates' => array( array( 'id' => 't1', 'title' => 'Kravice at dawn', 'angle' => 'Practical' ) ) ) );
		AOQ_Runs::report_progress(
			array(
				'run_id' => $run['run_id'], 'step' => 4, 'steps' => 14, 'label' => 'topics',
				'output' => array( array( 'step' => 'topics', 'task' => 'topics', 'agent' => 'client.aoq_analyst', 'text' => "thinking...
[answer after 42 s]
" . $answer . "
" ) ),
			)
		);
		$steps = array_column( AOQ_Steps::for_run( AOQ_Runs::get( $run['run_id'] ), AOQ_Runs::live_data( $run['run_id'] ) ), null, 'id' );
		$this->assertCount( 14, $steps );
		$this->assertSame( 'done', $steps['inventory']['status'] );
		$this->assertContains( 'Posts: 120', $steps['inventory']['summary'] );
		$this->assertSame( 'running', $steps['topics']['status'] );
		$this->assertSame( 'pending', $steps['draft']['status'] );
		$this->assertSame( 42, $steps['topics']['answers'][0]['seconds'] );
		$this->assertSame( 'Candidates', $steps['topics']['answers'][0]['fields'][0][0] );
		$this->assertSame( '• Kravice at dawn - Practical', $steps['topics']['answers'][0]['fields'][0][1] );
		$this->assertNotEmpty( $steps['research']['description'] );
	}

	public function test_answers_go_to_their_tasks_step_and_lists_read_well() {
		$run = $this->claim_test();
		$id  = $run['run_id'];
		AOQ_Runs::report_stage(
			array(
				'run_id' => $id, 'stage' => 'connect', 'language' => null, 'attempt' => 1, 'status' => 'ok',
				'started_at' => gmdate( 'c' ), 'duration_ms' => 10, 'agents' => array(), 'scores' => array(), 'revision_count' => 0, 'failure_reason' => null,
				'detail' => array( 'capabilities' => array( array( 'id' => 'mcp_tunnel', 'advertised' => true ), array( 'id' => 'agent:aoq_text_checks', 'advertised' => false ) ), 'languages' => array( 'en', 'bs' ) ),
			)
		);
		// Old-style raw output (no step tags) from before, then an answer flushed after the label moved on.
		update_site_option( AOQ_Runs::LIVE_PREFIX . $id, array_merge( AOQ_Runs::live_data( $id ), array( 'output' => "### site_profile (client.aoq_analyst)
[answer after 780 s]
{\"summary\":\"A guide\"}
" ) ) );
		AOQ_Runs::report_progress(
			array(
				'run_id' => $id, 'step' => 5, 'steps' => 14, 'label' => 'novelty',
				'output' => array( array( 'task' => 'topics:structure', 'agent' => 'client.aoq_structurer', 'text' => "[answer after 267 s]
{\"candidates\":[{\"title\":\"A\"},{\"title\":\"B\"}]}
" ) ),
			)
		);
		$steps = array_column( AOQ_Steps::for_run( AOQ_Runs::get( $id ), AOQ_Runs::live_data( $id ) ), null, 'id' );
		$this->assertSame( 'topics:structure', $steps['topics']['answers'][0]['task'], 'filed under the task step, not the label' );
		$this->assertSame( array(), $steps['novelty']['answers'] );
		$this->assertSame( "• A
• B", $steps['topics']['answers'][0]['fields'][0][1] );
		$this->assertSame( 'site_profile', $steps['site_profile']['answers'][0]['task'], 'raw-log answers are merged in' );
		$summary = $steps['connect']['summary'];
		$this->assertContains( 'Capabilities:', $summary );
		$this->assertContains( '   • mcp_tunnel ✓', $summary );
		$this->assertContains( '   • agent:aoq_text_checks ✗ missing', $summary );
		$this->assertContains( '   • en', $summary );
	}

	public function test_step_start_time_follows_step_changes() {
		global $wpdb;
		$run = $this->claim_test();
		$id  = $run['run_id'];
		$this->assertNotNull( AOQ_Runs::step_started_at( AOQ_Runs::get( $id ), AOQ_Runs::live_data( $id ) ), 'falls back to the claim time' );
		AOQ_Runs::report_progress( array( 'run_id' => $id, 'step' => 2, 'steps' => 14, 'label' => 'site_profile', 'output' => array() ) );
		$first = AOQ_Runs::live_data( $id )['label_since'];
		$this->assertNotNull( $first );
		$live = AOQ_Runs::live_data( $id );
		$live['label_since'] = gmdate( 'c', time() - 300 );
		update_site_option( AOQ_Runs::LIVE_PREFIX . $id, $live );
		AOQ_Runs::report_progress( array( 'run_id' => $id, 'step' => 2, 'steps' => 14, 'label' => 'site_profile', 'output' => array() ) );
		$this->assertSame( gmdate( 'c', time() - 300 ), AOQ_Runs::live_data( $id )['label_since'], 'same step keeps its start' );
		AOQ_Runs::report_progress( array( 'run_id' => $id, 'step' => 3, 'steps' => 14, 'label' => 'style_profile', 'output' => array() ) );
		$this->assertGreaterThan( time() - 5, strtotime( AOQ_Runs::live_data( $id )['label_since'] ), 'a new step restarts the clock' );
	}

	public function test_steps_carry_averages_from_past_runs() {
		$first = $this->claim_test();
		foreach ( array( array( 'topics', 'ok', 400000 ), array( 'research', 'ok', 100000 ), array( 'verify_quotes', 'ok', 20000 ), array( 'draft', 'failed', 999000 ) ) as $r ) {
			AOQ_Runs::report_stage(
				array(
					'run_id' => $first['run_id'], 'stage' => $r[0], 'language' => null, 'attempt' => 1, 'status' => $r[1],
					'started_at' => gmdate( 'c' ), 'duration_ms' => $r[2], 'agents' => array(), 'scores' => array(), 'revision_count' => 0,
					'failure_reason' => 'failed' === $r[1] ? 'x' : null, 'detail' => array(),
				)
			);
		}
		AOQ_Runs::finish( $this->finish_input( $first['run_id'] ) );
		$failed = $this->claim_test();
		foreach ( array( array( 'research', 'ok', 900000 ), array( 'verify_quotes', 'failed', 1000 ) ) as $r ) {
			AOQ_Runs::report_stage(
				array(
					'run_id' => $failed['run_id'], 'stage' => $r[0], 'language' => null, 'attempt' => 1, 'status' => $r[1],
					'started_at' => gmdate( 'c' ), 'duration_ms' => $r[2], 'agents' => array(), 'scores' => array(), 'revision_count' => 0,
					'failure_reason' => 'failed' === $r[1] ? 'x' : null, 'detail' => array(),
				)
			);
		}
		$fin = $this->finish_input( $failed['run_id'] );
		$fin['status'] = 'failed';
		$fin['failure_reason'] = 'verify_quotes: x';
		AOQ_Runs::finish( $fin );
		$second = $this->claim_test();
		$avg    = AOQ_Steps::averages( $second['run_id'] );
		$this->assertSame( 400000, $avg['topics']['avg_ms'] );
		$this->assertSame( 120000, $avg['research']['avg_ms'], 'research includes quote verification' );
		$this->assertArrayNotHasKey( 'outline', $avg );
		$this->assertArrayNotHasKey( 'draft', $avg, 'steps a run did not complete are not averaged' );
		$steps = array_column( AOQ_Steps::for_run( AOQ_Runs::get( $second['run_id'] ), AOQ_Runs::live_data( $second['run_id'] ) ), null, 'id' );
		$this->assertSame( 400000, $steps['topics']['avg_ms'] );
		$this->assertSame( 1, $steps['topics']['avg_runs'] );
		$this->assertNull( $steps['outline']['avg_ms'] );
	}

	public function test_progress_counts_as_a_worker_check_in() {
		$run = $this->claim_test();
		update_site_option( AOQ_Runs::HEARTBEAT_OPTION, array( 'at' => gmdate( 'c', time() - 7200 ), 'worker_version' => '0.1.0' ) );
		$this->assertSame( 'stopped', AOQ_Worker::state()['state'] );
		AOQ_Runs::report_progress( array( 'run_id' => $run['run_id'], 'step' => 2, 'steps' => 14, 'label' => 'site_profile', 'output' => array() ) );
		$this->assertSame( 'running', AOQ_Worker::state()['state'] );
	}

	public function test_generation_tab_follows_real_runs_and_links_their_posts() {
		require_once AOQ_DIR . '/includes/admin/class-aoq-admin.php';
		$run = $this->claim();
		$this->assertSame( $run['run_id'], AOQ_Runs::latest()['run_id'] );
		$res = AOQ_Commit::commit( $this->bundle( $run['run_id'] ) );
		$this->assertIsArray( $res );
		$input                      = $this->finish_input( $run['run_id'] );
		$input['result']['posts']   = $res['posts'];
		$this->assertTrue( AOQ_Runs::finish( $input ) );
		$m = new ReflectionMethod( 'AOQ_Admin', 'render_generation' );
		$m->setAccessible( true );
		ob_start();
		$m->invoke( null );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Posts created (pending review)', $html );
		$this->assertStringContainsString( 'AI post en', $html );
		$this->assertStringContainsString( 'Review and edit', $html );
		$this->assertStringNotContainsString( 'Preview: what would have been published', $html );
	}

	private function finish_input( $run_id ) {
		return array(
			'run_id'         => $run_id,
			'status'         => 'succeeded',
			'failure_reason' => null,
			'result'         => array(
				'posts'   => array(),
				'scores'  => array(),
				'agents'  => array(),
				'sources' => array(),
				'usage'   => null,
				'ao'      => array( 'engine_version' => null, 'capabilities' => array() ),
				'preview' => null,
			),
		);
	}
}
