<?php
/**
 * Past runs: each run's live data is kept for good and shown like the Generation tab.
 *
 * @package AOQuill
 */

class Test_AOQ_Past_Runs extends AOQ_TestCase {

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

	private function finished_test_run() {
		AOQ_Runs::request_test_run();
		$run = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		AOQ_Runs::report_stage(
			array(
				'run_id'         => $run['run_id'],
				'stage'          => 'novelty',
				'language'       => null,
				'attempt'        => 1,
				'status'         => 'ok',
				'started_at'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'duration_ms'    => 59000,
				'agents'         => array(),
				'scores'         => array(),
				'revision_count' => 0,
				'failure_reason' => null,
				'detail'         => array( 'chosen' => 'Autumn walks around Blagaj' ),
			)
		);
		AOQ_Runs::report_progress(
			array(
				'run_id' => $run['run_id'],
				'step'   => 5,
				'steps'  => 14,
				'label'  => 'novelty',
				'output' => array( array( 'task' => 'novelty', 'agent' => 'client.aoq_analyst', 'text' => "\n[answer after 59 s]\n{\"chosen_candidate_id\": \"t1\"}\n" ) ),
			)
		);
		$input = $this->finish_input( $run['run_id'] );
		$input['status']         = 'failed';
		$input['failure_reason'] = 'research: nothing usable';
		$this->assertTrue( AOQ_Runs::finish( $input ) );
		return $run['run_id'];
	}

	public function test_finish_archives_the_live_data_and_it_survives_pruning() {
		$run_id = $this->finished_test_run();
		$row    = AOQ_Runs::get( $run_id );
		$this->assertNotEmpty( $row['report'] );
		delete_site_option( AOQ_Runs::LIVE_PREFIX . $run_id );
		$live = AOQ_Runs::history_live( AOQ_Runs::get( $run_id ) );
		$this->assertStringContainsString( '[answer after 59 s]', $live['output'] );
		$this->assertSame( 5, (int) $live['step'] );
	}

	private function render( $method, $args = array() ) {
		require_once AOQ_DIR . '/includes/admin/class-aoq-admin.php';
		$m = new ReflectionMethod( 'AOQ_Admin', $method );
		$m->setAccessible( true );
		ob_start();
		$m->invokeArgs( null, $args );
		return ob_get_clean();
	}

	public function test_past_runs_list_shows_topic_progress_and_result() {
		$this->finished_test_run();
		$html = $this->render( 'render_runs' );
		$this->assertStringContainsString( 'Autumn walks around Blagaj', $html );
		$this->assertStringContainsString( 'Test', $html );
		$this->assertStringContainsString( 'failed', $html );
		$this->assertStringContainsString( 'research: nothing usable', $html );
		$this->assertStringContainsString( 'View', $html );
	}

	public function test_one_past_run_renders_like_the_generation_tab_from_its_report() {
		$run_id = $this->finished_test_run();
		delete_site_option( AOQ_Runs::LIVE_PREFIX . $run_id );
		$html = $this->render( 'render_run', array( $run_id ) );
		$this->assertStringContainsString( 'id="aoq-card"', $html );
		$this->assertStringContainsString( 'data-static="1"', $html );
		$this->assertStringContainsString( 'id="aoq-static"', $html );
		$this->assertSame( 14, substr_count( $html, 'class="aoq-step pending"' ), 'same step list as Generation' );
		$this->assertStringContainsString( '[answer after 59 s]', $html, 'the saved AO output is in the static payload' );
		$this->assertStringContainsString( 'Technical log', $html );
	}

	public function test_generation_tab_is_live_not_static() {
		$this->finished_test_run();
		$html = $this->render( 'render_generation' );
		$this->assertStringContainsString( 'data-static="0"', $html );
		$this->assertStringNotContainsString( 'id="aoq-static"', $html );
	}

	public function test_qa_shows_its_checks_as_sub_step_bars() {
		AOQ_Runs::request_test_run();
		$run = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$out = array();
		foreach ( array( array( 'text_checks:en', '1' ), array( 'qa:grammar:en', '137' ), array( 'qa:facts:en', null ) ) as $call ) {
			$out[] = array( 'task' => $call[0], 'agent' => 'client.aoq_editor', 'text' => "
[started]
" );
			if ( null !== $call[1] ) {
				$out[] = array( 'task' => $call[0], 'agent' => 'client.aoq_editor', 'text' => "
[answer after {$call[1]} s]
{\"score\": 1.0, \"issues\": []}
" );
			}
		}
		AOQ_Runs::report_progress( array( 'run_id' => $run['run_id'], 'step' => 12, 'steps' => 14, 'label' => 'qa', 'output' => $out ) );
		$steps = AOQ_Steps::for_run( AOQ_Runs::get( $run['run_id'] ), AOQ_Runs::live_data( $run['run_id'] ) );
		$qa    = array_values( array_filter( $steps, function ( $s ) { return 'qa' === $s['id']; } ) )[0];
		$this->assertSame( 'running', $qa['status'] );
		$this->assertCount( 1, $qa['substeps'] );
		$bars = array_column( $qa['substeps'][0]['bars'], 'status', 'id' );
		$this->assertSame( array( 'text' => 'done', 'grammar' => 'done', 'facts' => 'running', 'style' => 'pending', 'seo' => 'pending' ), $bars );
		$this->assertSame( 137, $qa['substeps'][0]['bars'][1]['seconds'] );
		$research = array_values( array_filter( $steps, function ( $s ) { return 'research' === $s['id']; } ) )[0];
		$this->assertSame( array( 'read', 'structure', 'verify' ), array_column( $research['substeps'][0]['bars'], 'id' ) );
	}

	public function test_a_revision_starts_a_second_qa_round() {
		AOQ_Runs::request_test_run();
		$run = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$out = array();
		foreach ( array( 'text_checks:en', 'qa:grammar:en', 'qa:facts:en', 'qa:style:en', 'qa:seo:en', 'draft:revise', 'text_checks:en' ) as $task ) {
			$out[] = array( 'task' => $task, 'agent' => 'a', 'text' => "
[started]
" );
			$out[] = array( 'task' => $task, 'agent' => 'a', 'text' => "
[answer after 5 s]
{}
" );
		}
		AOQ_Runs::report_progress( array( 'run_id' => $run['run_id'], 'step' => 12, 'steps' => 14, 'label' => 'qa', 'output' => $out ) );
		$steps = AOQ_Steps::for_run( AOQ_Runs::get( $run['run_id'] ), AOQ_Runs::live_data( $run['run_id'] ) );
		$qa    = array_values( array_filter( $steps, function ( $s ) { return 'qa' === $s['id']; } ) )[0];
		$this->assertCount( 2, $qa['substeps'] );
		$this->assertSame( 'Round 1', $qa['substeps'][0]['label'] );
		$this->assertContains( 'revision', array_column( $qa['substeps'][0]['bars'], 'id' ) );
		$this->assertSame( 'done', $qa['substeps'][1]['bars'][0]['status'] );
		$this->assertSame( 'pending', $qa['substeps'][1]['bars'][1]['status'] );
	}

	public function test_clipped_values_carry_their_full_text() {
		AOQ_Runs::request_test_run();
		$run  = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$html = '<h2>Intro</h2><p>' . str_repeat( 'Autumn in Herzegovina. ', 80 ) . '</p>';
		$text = "
[answer after 75 s]
" . wp_json_encode( array( 'title' => 'Short title', 'content_html' => $html ) ) . "
";
		AOQ_Runs::report_progress( array( 'run_id' => $run['run_id'], 'step' => 9, 'steps' => 14, 'label' => 'draft', 'output' => array( array( 'task' => 'draft', 'agent' => 'client.aoq_writer', 'text' => $text ) ) ) );
		$steps = AOQ_Steps::for_run( AOQ_Runs::get( $run['run_id'] ), AOQ_Runs::live_data( $run['run_id'] ) );
		$draft = array_values( array_filter( $steps, function ( $s ) { return 'draft' === $s['id']; } ) )[0];
		$fields = array_column( $draft['answers'][0]['fields'], null, 0 );
		$this->assertCount( 2, $fields['Title'], 'short values have no full text' );
		$this->assertCount( 3, $fields['Content html'], 'clipped values carry the full text' );
		$this->assertSame( $html, $fields['Content html'][2] );
	}

	public function test_the_modal_is_on_the_run_and_list_pages() {
		$run_id = $this->finished_test_run();
		$this->assertStringContainsString( 'id="aoq-modal"', $this->render( 'render_run', array( $run_id ) ) );
		$this->assertStringContainsString( 'id="aoq-modal"', $this->render( 'render_runs' ) );
	}

	public function test_chosen_images_become_a_gallery() {
		AOQ_Runs::request_test_run();
		$run = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		AOQ_Runs::report_stage(
			array(
				'run_id' => $run['run_id'], 'stage' => 'images', 'language' => 'en', 'attempt' => 1, 'status' => 'ok',
				'started_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'duration_ms' => 138000, 'agents' => array(), 'scores' => array(),
				'revision_count' => 0, 'failure_reason' => null,
				'detail' => array(
					'sources' => array( 'media_library' ),
					'chosen'  => array(
						array( 'key' => 'featured', 'source' => 'media_library', 'preview_url' => 'https://example.org/a-300.jpg', 'full_url' => 'https://example.org/a.jpg', 'alt' => 'Old bridge', 'caption' => 'Stari Most', 'license' => 'Site media library', 'attribution' => '', 'source_page_url' => '' ),
						array( 'key' => 'inline-1', 'source' => 'stock', 'preview_url' => 'javascript:alert(1)', 'full_url' => 'https://images.example.org/b.jpg', 'alt' => 'River', 'caption' => '', 'license' => 'CC BY 4.0', 'attribution' => 'by Sam', 'source_page_url' => 'https://commons.example.org/b' ),
					),
				),
			)
		);
		$steps  = AOQ_Steps::for_run( AOQ_Runs::get( $run['run_id'] ), AOQ_Runs::live_data( $run['run_id'] ) );
		$images = array_values( array_filter( $steps, function ( $s ) { return 'images' === $s['id']; } ) )[0];
		$this->assertCount( 2, $images['gallery'] );
		$this->assertSame( 'https://example.org/a-300.jpg', $images['gallery'][0]['thumb'] );
		$this->assertSame( 'https://images.example.org/b.jpg', $images['gallery'][1]['thumb'], 'unsafe URLs are dropped' );
		$this->assertStringNotContainsString( 'Chosen', implode( ' ', array_filter( $images['summary'], 'is_string' ) ), 'shown as thumbnails, not text' );
	}

	public function test_gallery_falls_back_to_the_checkpoint() {
		AOQ_Runs::request_test_run();
		$run   = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$state = array(
			'images'      => array(
				array( 'key' => 'featured', 'source' => 'media_library', 'url' => null, 'preview_url' => 'https://example.org/a-300.jpg', 'license' => array( 'name' => 'Site media library' ), 'attribution' => '', 'source_page_url' => null ),
			),
			'image_texts' => array( 'en' => array( 'featured' => array( 'alt' => 'Old bridge', 'caption' => 'Stari Most' ) ) ),
		);
		AOQ_Runs::save_checkpoint( array( 'run_id' => $run['run_id'], 'step' => 'images', 'completed' => array( 'images' ), 'state' => wp_json_encode( $state ) ) );
		$steps  = AOQ_Steps::for_run( AOQ_Runs::get( $run['run_id'] ), AOQ_Runs::live_data( $run['run_id'] ) );
		$images = array_values( array_filter( $steps, function ( $s ) { return 'images' === $s['id']; } ) )[0];
		$this->assertCount( 1, $images['gallery'] );
		$this->assertSame( 'Stari Most', $images['gallery'][0]['caption'] );
		$this->assertSame( 'https://example.org/a-300.jpg', $images['gallery'][0]['full'] );
	}

	public function test_qa_rounds_carry_checks_and_the_revision() {
		AOQ_Runs::request_test_run();
		$run = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$ans = function ( $task, $json ) {
			return array( 'task' => $task, 'agent' => 'a', 'text' => "
[started]

[answer after 3 s]
" . wp_json_encode( $json ) . "
" );
		};
		$out = array(
			$ans( 'text_checks:en', array( 'structure' => array( 'score' => 1.0, 'problems' => array() ), 'originality' => array( 'max_overlap' => 0.2 ) ) ),
			$ans( 'qa:grammar:en', array( 'score' => 0.95, 'summary' => 'Clean.', 'issues' => array() ) ),
			$ans( 'qa:facts:en', array( 'score' => 0.5, 'summary' => 'Two sentences go beyond the claims.', 'unsupported_sentences' => array(), 'issues' => array() ) ),
			$ans( 'qa:style:en', array( 'score' => 0.8, 'summary' => 'Fine.', 'issues' => array() ) ),
			$ans( 'qa:seo:en', array( 'score' => 0.8, 'summary' => 'Fine.', 'issues' => array() ) ),
			$ans( 'draft:revise', array( 'title' => 'Revised title', 'excerpt' => 'E', 'content_html' => '<p>Revised {{c:c1}}</p>' ) ),
			array( 'task' => 'text_checks:en', 'agent' => 'a', 'text' => "
[started]
" ),
		);
		AOQ_Runs::report_progress( array( 'run_id' => $run['run_id'], 'step' => 12, 'steps' => 14, 'label' => 'qa', 'output' => $out ) );
		AOQ_Runs::report_stage(
			array(
				'run_id' => $run['run_id'], 'stage' => 'qa', 'language' => 'en', 'attempt' => 1, 'status' => 'failed',
				'started_at' => gmdate( 'Y-m-d\TH:i:s\Z' ), 'duration_ms' => 200000, 'agents' => array(),
				'scores' => array( array( 'check' => 'grammar', 'score' => 0.95, 'threshold' => 0.8, 'passed' => true ), array( 'check' => 'facts', 'score' => 0.5, 'threshold' => 0.6, 'passed' => false ), array( 'check' => 'originality', 'score' => 0.8, 'threshold' => 0.85, 'passed' => false ) ),
				'revision_count' => 0, 'failure_reason' => 'Revising', 'detail' => array(),
			)
		);
		$steps = AOQ_Steps::for_run( AOQ_Runs::get( $run['run_id'] ), AOQ_Runs::live_data( $run['run_id'] ) );
		$qa    = array_values( array_filter( $steps, function ( $s ) { return 'qa' === $s['id']; } ) )[0];
		$this->assertCount( 2, $qa['substeps'] );
		$r1 = $qa['substeps'][0]['detail'];
		$this->assertSame( 'failed', $r1['status'] );
		$this->assertSame( 'generated', $r1['revision']['state'] );
		$this->assertSame( '<p>Revised {{c:c1}}</p>', $r1['revision']['html'] );
		$checks = array_column( $r1['checks'], null, 'id' );
		$this->assertSame( 'failed', $checks['facts']['status'] );
		$this->assertSame( 0.5, $checks['facts']['score'] );
		$this->assertSame( 'Two sentences go beyond the claims.', $checks['facts']['summary'] );
		$this->assertSame( 'passed', $checks['grammar']['status'] );
		$r2 = $qa['substeps'][1];
		$this->assertSame( 'Round 2', $r2['label'] );
		$this->assertSame( 'not_generated', $r2['detail']['revision']['state'] );
		$this->assertSame( 'running', $r2['detail']['status'] );
	}

	public function test_the_preview_shows_the_chosen_images() {
		AOQ_Runs::request_test_run();
		$run   = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$state = array(
			'images'      => array(
				array( 'key' => 'featured', 'source' => 'media_library', 'url' => null, 'preview_url' => 'https://example.org/feat.jpg', 'license' => array( 'name' => 'Site media library' ), 'attribution' => '', 'source_page_url' => null ),
				array( 'key' => 'inline-1', 'source' => 'remote', 'url' => 'https://images.example.org/in1.jpg', 'preview_url' => 'https://images.example.org/in1-t.jpg', 'license' => array( 'name' => 'CC BY 4.0' ), 'attribution' => 'by Sam', 'source_page_url' => null ),
			),
			'image_texts' => array( 'en' => array( 'featured' => array( 'alt' => 'Falls', 'caption' => 'Kravice' ), 'inline-1' => array( 'alt' => 'Thyme', 'caption' => 'Wild thyme' ) ) ),
		);
		AOQ_Runs::save_checkpoint( array( 'run_id' => $run['run_id'], 'step' => 'commit', 'completed' => array( 'commit' ), 'state' => wp_json_encode( $state ) ) );
		AOQ_Runs::finish(
			array(
				'run_id' => $run['run_id'], 'status' => 'succeeded', 'failure_reason' => null,
				'result' => array( 'posts' => array(), 'scores' => array(), 'agents' => array(), 'sources' => array(), 'usage' => null, 'ao' => array( 'engine_version' => null, 'capabilities' => array() ),
					'preview' => array( 'posts' => array( array( 'language' => 'en', 'title' => 'Tea', 'excerpt' => '<p>About tea</p>', 'content' => '<p>Intro</p><!-- aoq:image inline-1 --><p>More</p>' ) ) ) ),
			)
		);
		$html = $this->render( 'render_run', array( $run['run_id'] ) );
		$this->assertStringContainsString( 'src="https://example.org/feat.jpg"', $html, 'featured image above the title' );
		$this->assertStringContainsString( 'src="https://images.example.org/in1.jpg"', $html, 'placeholder replaced by its image' );
		$this->assertStringContainsString( 'Wild thyme', $html );
		$this->assertStringContainsString( 'by Sam', $html );
		$this->assertStringNotContainsString( '&lt;p&gt;About tea', $html, 'excerpt without raw tags' );
	}

	public function test_an_idle_check_in_halts_an_orphaned_run() {
		AOQ_Runs::request_test_run();
		$run = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$this->assertNotNull( AOQ_Runs::current() );
		$idle         = $this->claim_input();
		$idle['idle'] = true;
		AOQ_Runs::claim( $idle, max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$row = AOQ_Runs::get( $run['run_id'] );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringContainsString( 'worker stopped without finishing', $row['failure_reason'] );
	}

	public function test_a_check_in_without_idle_leaves_the_run_alone() {
		AOQ_Runs::request_test_run();
		$run = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$this->assertSame( 'claimed', AOQ_Runs::get( $run['run_id'] )['status'] );
	}

	public function test_a_silent_run_is_halted_as_stalled() {
		AOQ_Runs::request_test_run();
		$run   = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$limit = (int) AOQ_Settings::get()['stage_timeout_seconds'] + 60 * AOQ_Runs::STALL_GRACE_MINUTES;
		AOQ_Runs::halt_stalled( time() + $limit - 60 );
		$this->assertSame( 'claimed', AOQ_Runs::get( $run['run_id'] )['status'], 'not yet' );
		AOQ_Runs::halt_stalled( time() + $limit + 120 );
		$row = AOQ_Runs::get( $run['run_id'] );
		$this->assertSame( 'failed', $row['status'] );
		$this->assertStringContainsString( 'Stalled', $row['failure_reason'] );
	}
}
