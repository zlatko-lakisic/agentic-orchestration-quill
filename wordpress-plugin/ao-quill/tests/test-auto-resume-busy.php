<?php
/**
 * One-shot auto-resume after scheduled QA failure, and shared-worker busy presence.
 *
 * @package AOQuill
 */

class Test_AOQ_Auto_Resume_Busy extends AOQ_TestCase {

	private function checkpoint( $run_id, $completed ) {
		$ok = AOQ_Runs::save_checkpoint(
			array(
				'run_id'    => $run_id,
				'step'      => end( $completed ),
				'completed' => $completed,
				'state'     => wp_json_encode(
					(object) array(
						'topic'    => array( 'title' => 'Culinary Markets' ),
						'taxonomy' => new stdClass(),
						'draft'    => array( 'title' => 'Culinary Markets', 'content_html' => '<h2>A</h2><p>x</p>' ),
					)
				),
			)
		);
		$this->assertTrue( $ok );
	}

	private function finish_qa_fail( $run_id ) {
		return AOQ_Runs::finish(
			array(
				'run_id'         => $run_id,
				'status'         => 'failed',
				'failure_reason' => 'qa: QA below threshold after 3 revision loops: structure has blocking issues.',
				'result'         => array(
					'posts'   => array(),
					'scores'  => array(),
					'agents'  => array(),
					'sources' => array(),
					'usage'   => null,
					'ao'      => array(
						'engine_version' => null,
						'capabilities'   => array(),
					),
				),
			)
		);
	}

	public function test_scheduled_qa_failure_queues_one_auto_resume() {
		$run = $this->claim();
		$this->assertSame( 'scheduled', $run['trigger'] );
		$this->checkpoint(
			$run['run_id'],
			array( 'inventory', 'site_profile', 'style_profile', 'topics', 'novelty', 'research', 'outline', 'draft', 'taxonomy', 'images' )
		);
		$this->assertTrue( $this->finish_qa_fail( $run['run_id'] ) );
		$this->assertTrue( AOQ_Runs::auto_resume_pending_for( $run['run_id'] ) );

		$next = AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 09:00 UTC' ) );
		$this->assertIsArray( $next );
		$this->assertSame( 'manual', $next['trigger'] );
		$this->assertSame( $run['run_id'], $next['resume']['resumed_from'] );
		$this->assertSame( 'qa', $next['resume']['from_step'] );
		$this->assertTrue( AOQ_Runs::was_auto_resumed( $run['run_id'] ) );
		$this->assertFalse( AOQ_Runs::auto_resume_pending_for( $run['run_id'] ) );

		// Second failure of the same run_id must not auto-resume again (already consumed).
		$this->finish( $next['run_id'], 'failed' );
		$this->assertNull( AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 10:00 UTC' ) ) );
	}

	public function test_stall_failure_is_not_auto_resumed() {
		$run = $this->claim();
		$this->checkpoint( $run['run_id'], array( 'inventory', 'site_profile', 'style_profile', 'topics' ) );
		AOQ_Runs::finish(
			array(
				'run_id'         => $run['run_id'],
				'status'         => 'failed',
				'failure_reason' => 'Stalled: the run reported nothing for 12 minutes, longer than one AO call may take. Halted by WordPress.',
				'result'         => array(
					'posts'   => array(),
					'scores'  => array(),
					'agents'  => array(),
					'sources' => array(),
					'usage'   => null,
					'ao'      => array(
						'engine_version' => null,
						'capabilities'   => array(),
					),
				),
			)
		);
		$this->assertFalse( AOQ_Runs::auto_resume_pending_for( $run['run_id'] ) );
		$this->assertNull( AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 09:00 UTC' ) ) );
	}

	public function test_busy_report_sets_busy_state_and_refreshes_contact() {
		AOQ_Runs::heartbeat( $this->claim_input() );
		delete_site_option( AOQ_Runs::BUSY_OPTION );
		$before = (int) get_site_option( AOQ_Runs::CONTACT_OPTION, 0 );
		AOQ_Runs::report_worker_status(
			array(
				'busy' => array(
					'site'       => 'tgme',
					'task'       => 'post_run',
					'detail'     => 'qa',
					'started_at' => gmdate( 'c' ),
					'updated_at' => gmdate( 'c' ),
				),
			)
		);
		$this->assertGreaterThanOrEqual( $before, (int) get_site_option( AOQ_Runs::CONTACT_OPTION, 0 ) );
		$w = AOQ_Worker::state();
		$this->assertSame( 'busy', $w['state'] );
		$this->assertSame( 'tgme', $w['busy']['site'] );
		$this->assertStringContainsString( 'tgme', AOQ_Worker::busy_label( $w['busy'] ) );

		AOQ_Runs::report_worker_status( array( 'busy' => null ) );
		$w2 = AOQ_Worker::state();
		$this->assertSame( 'running', $w2['state'] );
		$this->assertNull( $w2['busy'] );
	}

	private function finish( $run_id, $status ) {
		AOQ_Runs::finish(
			array(
				'run_id'         => $run_id,
				'status'         => $status,
				'failure_reason' => 'failed' === $status ? 'qa: still failing' : null,
				'result'         => array(
					'posts'   => array(),
					'scores'  => array(),
					'agents'  => array(),
					'sources' => array(),
					'usage'   => null,
					'ao'      => array(
						'engine_version' => null,
						'capabilities'   => array(),
					),
				),
			)
		);
	}
}
