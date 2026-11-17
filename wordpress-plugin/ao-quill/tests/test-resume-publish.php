<?php
/**
 * Checkpoints, resuming a test run from a step, and publishing a finished test run.
 *
 * @package AOQuill
 */

class Test_AOQ_Resume_Publish extends AOQ_TestCase {

	private function claim_test() {
		AOQ_Runs::request_test_run();
		$run = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$this->assertIsArray( $run );
		return $run;
	}

	private function finish( $run_id, $status ) {
		return AOQ_Runs::finish(
			array(
				'run_id'         => $run_id,
				'status'         => $status,
				'failure_reason' => 'failed' === $status ? 'qa: facts check timed out' : null,
				'result'         => array( 'posts' => array(), 'scores' => array(), 'agents' => array(), 'sources' => array(), 'usage' => null, 'ao' => array( 'engine_version' => null, 'capabilities' => array() ), 'preview' => null ),
			)
		);
	}

	private function checkpoint( $run_id, $completed, $state = array() ) {
		$ok = AOQ_Runs::save_checkpoint( array( 'run_id' => $run_id, 'step' => end( $completed ), 'completed' => $completed, 'state' => wp_json_encode( (object) array_merge( array( 'topic' => array( 'title' => 'Autumn in Blagaj' ), 'taxonomy' => new stdClass() ), $state ) ) ) );
		$this->assertTrue( $ok );
	}

	public function test_a_failed_test_run_can_resume_up_to_where_it_stopped() {
		$run = $this->claim_test();
		$this->checkpoint( $run['run_id'], array( 'inventory', 'site_profile', 'style_profile', 'topics', 'novelty', 'research', 'outline', 'draft', 'taxonomy', 'images' ) );
		$this->finish( $run['run_id'], 'failed' );
		$row   = AOQ_Runs::get( $run['run_id'] );
		$steps = AOQ_Runs::resumable_steps( $row );
		$this->assertSame( 'topics', $steps[0] );
		$this->assertSame( 'qa', end( $steps ), 'the step that failed is the last offered' );
		$this->assertNotContains( 'translate', $steps );

		$this->assertTrue( AOQ_Runs::request_resume( $run['run_id'], 'qa' ) );
		$this->assertTrue( AOQ_Runs::test_requested(), 'the Generation tab shows it as waiting' );
		$next = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 06:00 UTC' ) ) );
		$this->assertSame( 'test', $next['trigger'] );
		$this->assertSame( $run['run_id'], $next['resume']['resumed_from'] );
		$this->assertSame( 'qa', $next['resume']['from_step'] );
		$this->assertSame( 'Autumn in Blagaj', json_decode( $next['resume']['state'], true )['topic']['title'] );
		$this->assertStringContainsString( '"taxonomy":{}', $next['resume']['state'], 'empty objects stay objects' );
		$this->assertTrue( true === AOQ_Schema::validate( json_decode( wp_json_encode( array( 'run' => $next ) ), true ), 'abilities/claim-run.output' ) );
		$this->assertSame( $run['run_id'], AOQ_Runs::get( $next['run_id'] )['resumed_from'] );
	}

	public function test_a_failed_scheduled_run_resumes_as_a_run_that_publishes() {
		$run = AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 08:00 UTC' ) );
		$this->assertSame( 'scheduled', $run['trigger'] );
		$this->checkpoint( $run['run_id'], array( 'inventory', 'site_profile', 'style_profile', 'topics', 'novelty', 'research', 'outline', 'draft', 'taxonomy' ) );
		$this->finish( $run['run_id'], 'failed' );
		$this->assertTrue( AOQ_Runs::request_resume( $run['run_id'], 'images' ) );
		$next = AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 09:00 UTC' ) );
		$this->assertSame( 'manual', $next['trigger'], 'commits its post on its own' );
		$this->assertSame( 'images', $next['resume']['from_step'] );
	}

	public function test_resume_is_refused_past_the_failed_step_and_while_busy() {
		$run = $this->claim_test();
		$this->checkpoint( $run['run_id'], array( 'inventory', 'site_profile', 'style_profile', 'topics' ) );
		$this->finish( $run['run_id'], 'failed' );
		$this->assertWPError( AOQ_Runs::request_resume( $run['run_id'], 'draft' ) );
		AOQ_Runs::request_test_run();
		$this->assertWPError( AOQ_Runs::request_resume( $run['run_id'], 'novelty' ), 'another run is waiting' );
	}

	public function test_a_finished_test_run_is_published_from_its_saved_bundle_once() {
		$run    = $this->claim_test();
		$bundle = $this->bundle( $run['run_id'] );
		$this->checkpoint( $run['run_id'], array( 'inventory', 'commit' ), array( 'bundle' => $bundle ) );
		$this->finish( $run['run_id'], 'succeeded' );
		$result = AOQ_Commit::publish_test( $run['run_id'] );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertNotEmpty( $result['posts'] );
		$this->assertSame( 1, (int) AOQ_Runs::get( $run['run_id'] )['committed'] );
		$this->assertWPError( AOQ_Commit::publish_test( $run['run_id'] ), 'only once' );
	}

	public function test_a_run_without_a_saved_bundle_cannot_be_published() {
		$run = $this->claim_test();
		$this->finish( $run['run_id'], 'succeeded' );
		$this->assertWPError( AOQ_Commit::publish_test( $run['run_id'] ) );
	}

	public function test_past_run_page_offers_resume_and_publish() {
		require_once AOQ_DIR . '/includes/admin/class-aoq-admin.php';
		$failed = $this->claim_test();
		$this->checkpoint( $failed['run_id'], array( 'inventory', 'site_profile', 'style_profile', 'topics', 'novelty' ) );
		$this->finish( $failed['run_id'], 'failed' );
		$m = new ReflectionMethod( 'AOQ_Admin', 'render_run' );
		$m->setAccessible( true );
		ob_start();
		$m->invoke( null, $failed['run_id'] );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="from_step"', $html );
		$this->assertStringContainsString( 'value="research" selected', $html, 'defaults to where it stopped' );
		$this->assertStringContainsString( 'aoq_resume', $html );

		$ok = $this->claim_test();
		$this->checkpoint( $ok['run_id'], array( 'inventory', 'commit' ), array( 'bundle' => $this->bundle( $ok['run_id'] ) ) );
		$this->finish( $ok['run_id'], 'succeeded' );
		ob_start();
		$m->invoke( null, $ok['run_id'] );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Publish this run', $html );
	}
}
