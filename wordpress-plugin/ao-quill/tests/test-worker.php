<?php
/**
 * Worker presence from heartbeats.
 *
 * @package AOQuill
 */

class Test_AOQ_Worker extends AOQ_TestCase {

	public function test_never_seen_is_not_installed() {
		delete_site_option( AOQ_Runs::HEARTBEAT_OPTION );
		$w = AOQ_Worker::state();
		$this->assertSame( 'not_installed', $w['state'] );
		$this->assertNull( $w['last_seen'] );
	}

	public function test_recent_heartbeat_is_running_and_old_one_is_stopped() {
		AOQ_Runs::heartbeat( $this->claim_input() );
		$this->assertSame( 'running', AOQ_Worker::state()['state'] );
		$this->assertSame( '0.1.0', AOQ_Worker::state()['version'] );
		$this->assertSame( 'running', AOQ_Worker::state( time() + AOQ_Worker::RUNNING_WITHIN_SECONDS )['state'] );
		$this->assertSame( 'stopped', AOQ_Worker::state( time() + AOQ_Worker::RUNNING_WITHIN_SECONDS + 60 )['state'] );
	}

	public function test_claim_records_a_heartbeat_even_when_no_run_is_due() {
		delete_site_option( AOQ_Runs::HEARTBEAT_OPTION );
		$early = strtotime( 'today 06:00 UTC' );
		$this->assertNull( AOQ_Runs::claim( $this->claim_input(), $early ) );
		$this->assertNotSame( 'not_installed', AOQ_Worker::state()['state'] );
	}

	public function test_install_commands_name_the_worker_user() {
		$cmds = AOQ_Worker::install_commands();
		$this->assertStringContainsString( 'application-password create aoq-worker', $cmds[0] );
	}
}
