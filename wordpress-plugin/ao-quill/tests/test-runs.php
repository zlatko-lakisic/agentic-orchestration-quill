<?php
/**
 * Claiming, schedule (one scheduled run a day, no daily cap), lease, stage log.
 *
 * @package AOQuill
 */

class Test_AOQ_Runs extends AOQ_TestCase {

	public function test_nothing_due_before_schedule() {
		$this->assertNull( AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 06:59 UTC' ) ) );
	}

	public function test_due_after_schedule_and_only_once_per_day() {
		$run = $this->claim();
		$this->assertSame( 'scheduled', $run['trigger'] );
		$this->assertNull( AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 09:00 UTC' ) ) );
	}

	public function test_changing_run_time_moves_the_next_run() {
		$s                  = AOQ_Settings::get();
		$s['schedule_time'] = '10:00';
		AOQ_Settings::save( $s );
		$this->assertNull( AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 08:00 UTC' ) ) );
		$this->assertIsArray( AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 10:01 UTC' ) ) );
	}

	public function test_run_now_before_schedule() {
		AOQ_Runs::request_run_now();
		$run = AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 05:00 UTC' ) );
		$this->assertSame( 'manual', $run['trigger'] );
		$this->assertSame( 0, (int) get_site_option( AOQ_Runs::RUN_NOW_OPTION, 0 ) );
	}

	public function test_run_now_waits_for_the_active_run_and_has_no_daily_cap() {
		$run = $this->claim();
		AOQ_Runs::request_run_now();
		// One run at a time: the request waits while the scheduled run is active.
		$this->assertNull( AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 09:00 UTC' ) ) );
		$this->finish( $run['run_id'], 'succeeded' );
		$again = AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 09:01 UTC' ) );
		$this->assertSame( 'manual', $again['trigger'] );
	}

	public function test_scheduled_run_happens_whatever_ran_earlier_that_day() {
		foreach ( array( 'failed', 'succeeded' ) as $i => $status ) {
			AOQ_Runs::request_run_now();
			$manual = AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 05:0' . $i . ' UTC' ) );
			$this->assertSame( 'manual', $manual['trigger'] );
			$this->finish( $manual['run_id'], $status );
		}
		$this->assertSame( 'scheduled', $this->claim()['trigger'] );
	}

	private function finish( $run_id, $status ) {
		AOQ_Runs::finish(
			array(
				'run_id'         => $run_id,
				'status'         => $status,
				'failure_reason' => 'failed' === $status ? 'test' : null,
				'result'         => array( 'posts' => array(), 'scores' => array(), 'agents' => array(), 'sources' => array(), 'usage' => null, 'ao' => array( 'engine_version' => null, 'capabilities' => array() ) ),
			)
		);
	}

	public function test_failed_scheduled_run_is_not_retried_automatically_but_run_now_works() {
		$run = $this->claim();
		AOQ_Runs::finish(
			array(
				'run_id'         => $run['run_id'],
				'status'         => 'failed',
				'failure_reason' => 'test',
				'result'         => array( 'posts' => array(), 'scores' => array(), 'agents' => array(), 'sources' => array(), 'usage' => null, 'ao' => array( 'engine_version' => null, 'capabilities' => array() ) ),
			)
		);
		$this->assertNull( AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 09:00 UTC' ) ) );
		AOQ_Runs::request_run_now();
		$this->assertIsArray( AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 09:01 UTC' ) ) );
	}

	public function test_config_problems_fail_the_run_with_a_reason() {
		$s                   = AOQ_Settings::get();
		$s['author_user_id'] = 0;
		AOQ_Settings::save( $s );
		$this->assertNull( AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 08:00 UTC' ) ) );
		$runs = AOQ_Runs::recent();
		$this->assertSame( 'failed', $runs[0]['status'] );
		$this->assertStringContainsString( 'post author', $runs[0]['failure_reason'] );
	}

	public function test_lease_expiry() {
		$run = $this->claim();
		AOQ_Runs::expire_leases( time() + DAY_IN_SECONDS );
		$this->assertSame( 'expired', AOQ_Runs::get( $run['run_id'] )['status'] );
	}

	public function test_report_stage_and_cannot_succeed_without_commit() {
		$run = $this->claim();
		$ok  = AOQ_Runs::report_stage(
			array(
				'run_id'         => $run['run_id'],
				'stage'          => 'inventory',
				'language'       => null,
				'attempt'        => 1,
				'status'         => 'ok',
				'started_at'     => gmdate( 'c' ),
				'duration_ms'    => 10,
				'agents'         => array(),
				'scores'         => array(),
				'revision_count' => 0,
				'failure_reason' => null,
				'detail'         => array(),
			)
		);
		$this->assertTrue( $ok );
		$this->assertSame( 'running', AOQ_Runs::get( $run['run_id'] )['status'] );
		$this->assertCount( 1, AOQ_Runs::stages( $run['run_id'] ) );
		$res = AOQ_Runs::finish(
			array(
				'run_id'         => $run['run_id'],
				'status'         => 'succeeded',
				'failure_reason' => null,
				'result'         => array( 'posts' => array(), 'scores' => array(), 'agents' => array(), 'sources' => array(), 'usage' => null, 'ao' => array( 'engine_version' => null, 'capabilities' => array() ) ),
			)
		);
		$this->assertWPError( $res );
	}

	public function test_qa_agent_must_differ_in_family_from_drafter() {
		$agents               = AOQ_Settings::get()['agents'];
		$agents['qa_grammar'] = 'client.aoq_writer';
		$problems             = AOQ_Settings::agent_problems( $agents );
		$this->assertNotEmpty( preg_grep( '/different family/', $problems ) );
	}

	public function test_deterministic_roles_need_deterministic_agents() {
		$agents                   = AOQ_Settings::get()['agents'];
		$agents['quote_verifier'] = 'client.aoq_analyst';
		$this->assertNotEmpty( preg_grep( '/deterministic/', AOQ_Settings::agent_problems( $agents ) ) );
	}

	public function test_planned_image_sources_cannot_be_enabled() {
		$r = AOQ_Settings::sanitize(
			array(
				'image_sources' => array(
					'media_library' => array( 'enabled' => '1', 'order' => 1 ),
					'stock'         => array( 'enabled' => '1', 'order' => 2 ),
					'ai_generated'  => array( 'enabled' => '1', 'order' => 3 ),
				),
			)
		);
		foreach ( $r['settings']['image_sources'] as $src ) {
			// Stock search is shipped (worker-hosted MCP, D14); AI generation is still planned.
			$this->assertSame( 'ai_generated' !== $src['id'], $src['enabled'], $src['id'] );
		}
	}

	public function test_stock_images_need_the_image_mcp_in_the_catalog() {
		$settings = AOQ_Settings::get();
		foreach ( $settings['image_sources'] as $i => $src ) {
			if ( 'stock' === $src['id'] ) {
				$settings['image_sources'][ $i ]['enabled'] = true;
			}
		}
		$settings['mcp']['stock_images'] = 'client.not_reported';
		AOQ_Settings::save( $settings );
		$this->assertNotEmpty( preg_grep( '/stock_images/', AOQ_Settings::problems() ) );

		$settings['mcp']['stock_images'] = 'client.aoq_images';
		AOQ_Settings::save( $settings );
		$this->assertEmpty( preg_grep( '/MCP provider/', AOQ_Settings::problems() ) );
		$config = AOQ_Settings::worker_config( $this->claim()['run_id'] );
		$this->assertSame( 'client.aoq_images', $config['mcp']['stock_images'] );
	}

	public function test_stock_image_commit_keeps_license_and_attribution() {
		$run    = $this->claim();
		$bundle = $this->bundle( $run['run_id'] );
		$bundle['images'][1] = array(
			'key'             => 'inline-1',
			'source'          => 'remote',
			'media'           => null,
			'url'             => 'https://images.example.org/4401.jpg',
			'source_page_url' => 'https://images.example.org/page/4401',
			'license'         => array(
				'code'                      => 'cc-by-sa',
				'name'                      => 'CC BY-SA 4.0',
				'url'                       => 'https://creativecommons.org/licenses/by-sa/4.0/',
				'allows_download_and_reuse' => true,
				'requires_attribution'      => true,
			),
			'author'          => 'Jane Example',
			'attribution'     => '',
		);
		$this->assertNotEmpty( preg_grep( '/attribution/', AOQ_Commit::check( $bundle ) ) );
	}
}
