<?php
/**
 * Admin screens load and render (the admin class is only included in wp-admin,
 * so without this a syntax or runtime error there would go unnoticed).
 *
 * @package AOQuill
 */

class Test_AOQ_Admin extends AOQ_TestCase {

	private function render( $method ) {
		require_once AOQ_DIR . '/includes/admin/class-aoq-admin.php';
		$m = new ReflectionMethod( 'AOQ_Admin', $method );
		$m->setAccessible( true );
		ob_start();
		$m->invoke( null );
		return ob_get_clean();
	}

	public function test_settings_page_renders_ao_connection_without_the_key() {
		$html = $this->render( 'render_settings' );
		$this->assertStringContainsString( 'AO connection', $html );
		$this->assertStringContainsString( 'name="aoq_ao[ao_url]"', $html );
		$this->assertStringContainsString( 'https://ao.example.internal:8765', $html );
		$this->assertStringNotContainsString( 'PRIVATE KEY-----' . "\n", $html );
	}

	public function test_status_page_renders_worker_state() {
		delete_site_option( AOQ_Runs::HEARTBEAT_OPTION );
		$html = $this->render( 'render_status' );
		$this->assertStringContainsString( 'Not installed', $html );
		$this->assertStringContainsString( 'application-password create aoq-worker', $html );
	}

	public function test_model_sizes_come_from_the_model_name() {
		require_once AOQ_DIR . '/includes/admin/class-aoq-admin.php';
		$m = new ReflectionMethod( 'AOQ_Admin', 'model_size' );
		$m->setAccessible( true );
		$this->assertSame( '14B', $m->invoke( null, 'qwen2.5:14b-instruct' ) );
		$this->assertSame( '4B effective', $m->invoke( null, 'gemma4:e4b' ) );
		$this->assertSame( '26B', $m->invoke( null, 'gemma4:26b' ) );
		$this->assertSame( '14B', $m->invoke( null, 'phi4:latest' ) );
		$this->assertSame( '12B', $m->invoke( null, 'mistral-nemo:latest' ) );
		$this->assertSame( 'size unknown', $m->invoke( null, 'something:latest' ) );
	}

	public function test_settings_have_sliders_and_help_icons() {
		$html = $this->render( 'render_settings' );
		$this->assertStringContainsString( 'type="range" name="aoq[thresholds][facts]"', $html );
		$this->assertStringContainsString( 'type="range" name="aoq[min_words]" min="50" max="5000"', $html );
		$this->assertGreaterThan( 20, substr_count( $html, 'aoq-help' ) );
		$this->assertStringContainsString( 'The shortest post allowed.', $html );
	}
}
