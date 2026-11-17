<?php
/**
 * Instagram (D29): bio link target, caption assembly, queueing when a post goes live.
 *
 * @package AOQuill
 */

class Test_AOQ_Instagram extends AOQ_TestCase {

	private function generated_post( $status, $date = null ) {
		$id = self::factory()->post->create( array( 'post_status' => $status, 'post_date' => $date ? $date : current_time( 'mysql' ) ) );
		AOQ_Marking::mark( $id, 'run-x' );
		return $id;
	}

	public function test_bio_link_targets_the_newest_published_ao_quill_post() {
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_date' => '2030-01-01 00:00:00' ) ); // human post, newer
		$this->generated_post( 'publish', '2026-01-01 00:00:00' );
		$newest = $this->generated_post( 'publish', '2026-02-01 00:00:00' );
		$this->generated_post( 'pending', '2026-03-01 00:00:00' );
		$this->assertSame( get_permalink( $newest ), AOQ_Instagram::latest_url() );
	}

	public function test_bio_link_is_per_site() {
		switch_to_blog( $this->bs_blog );
		$this->assertNull( AOQ_Instagram::latest_url(), 'no AO Quill post on the Bosnian site yet' );
		$bs = $this->generated_post( 'publish' );
		$this->assertSame( get_permalink( $bs ), AOQ_Instagram::latest_url() );
		restore_current_blog();
	}

	public function test_caption_uses_the_worker_caption_and_limits_hashtags() {
		AOQ_Instagram::save( array( 'fixed_hashtags' => 'mostar, #Bosnia, stonebridge', 'max_hashtags' => 3 ) );
		$id = $this->generated_post( 'publish' );
		update_post_meta( $id, AOQ_Instagram::META_CAPTION, "Hook.\n\nLink in bio." );
		update_post_meta( $id, AOQ_Instagram::META_HASHTAGS, array( 'StoneBridge', 'Neretva' ) );
		$this->assertSame( "Hook.\n\nLink in bio.\n\n#StoneBridge #Neretva #mostar", AOQ_Instagram::caption( $id ) );
	}

	public function test_caption_falls_back_to_title_and_excerpt() {
		AOQ_Instagram::save( array( 'fixed_hashtags' => '', 'link_hint' => 'Link in bio.' ) );
		$id = self::factory()->post->create( array( 'post_title' => 'Old Bridge', 'post_excerpt' => 'At dawn.', 'post_status' => 'publish' ) );
		$this->assertSame( "Old Bridge\n\nAt dawn.\n\nLink in bio.", AOQ_Instagram::caption( $id ) );
	}

	public function test_a_reviewed_post_is_queued_when_published_only_when_on() {
		$id = $this->generated_post( 'pending' );
		wp_publish_post( $id );
		$this->assertEmpty( get_post_meta( $id, AOQ_Instagram::META_QUEUED, true ), 'off by default' );

		AOQ_Instagram::save( array( 'enabled' => true, 'auto_post' => true, 'languages' => array( 'en' ) ) );
		$id = $this->generated_post( 'pending' );
		wp_publish_post( $id );
		$this->assertNotEmpty( get_post_meta( $id, AOQ_Instagram::META_QUEUED, true ) );
		$this->assertNotFalse( wp_next_scheduled( AOQ_Instagram::POST_HOOK, array( get_current_blog_id(), $id ) ) );

		$human = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		wp_publish_post( $human );
		$this->assertEmpty( get_post_meta( $human, AOQ_Instagram::META_QUEUED, true ), 'human posts are never posted' );
	}

	public function test_publishing_without_a_connection_records_why() {
		AOQ_Instagram::save( array( 'enabled' => true, 'token' => '', 'user_id' => '' ) );
		$id = $this->generated_post( 'publish' );
		$this->assertSame( 'Instagram is not connected.', AOQ_Instagram::publish( get_current_blog_id(), $id ) );
		$this->assertSame( 'Instagram is not connected.', get_post_meta( $id, AOQ_Instagram::META_ERROR, true ) );
	}

	public function test_a_test_job_is_claimed_and_its_caption_stored() {
		$id = $this->generated_post( 'publish' );
		set_post_thumbnail( $id, $this->fx['en']['media'] );
		$this->assertTrue( AOQ_Instagram::request_job( get_current_blog_id(), $id, 'test' ) );
		$this->assertWPError( AOQ_Instagram::request_job( get_current_blog_id(), $id, 'test' ), 'one at a time' );
		$job = AOQ_Instagram::claim_job( $this->claim_input() );
		$this->assertSame( 'test', $job['mode'] );
		$this->assertSame( 'en', $job['language'] );
		$this->assertTrue( true === AOQ_Schema::validate( array( 'run' => null, 'instagram' => $job ), 'abilities/claim-run.output' ) );
		$this->assertSame( 'running', AOQ_Instagram::job()['status'] );
		$this->assertTrue( AOQ_Instagram::finish_job( array( 'job_id' => $job['job_id'], 'status' => 'ok', 'caption' => 'Dawn.', 'hashtags' => array( 'Mostar' ) ) ) );
		$this->assertSame( 'done', AOQ_Instagram::job()['status'] );
		$this->assertSame( 'Dawn.', get_post_meta( $id, AOQ_Instagram::META_CAPTION, true ) );
		$this->assertEmpty( get_post_meta( $id, AOQ_Instagram::META_MEDIA, true ), 'a test run posts nothing' );
	}

	public function test_a_job_waits_for_a_post_run_and_fails_when_its_worker_died() {
		$id = $this->generated_post( 'publish' );
		set_post_thumbnail( $id, $this->fx['en']['media'] );
		AOQ_Runs::claim( $this->claim_input(), strtotime( 'today 08:00 UTC' ) );
		AOQ_Instagram::request_job( get_current_blog_id(), $id, 'test' );
		$this->assertNull( AOQ_Instagram::claim_job( $this->claim_input() ), 'a post run is active' );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . AOQ_Runs::table() ); // phpcs:ignore
		$this->assertIsArray( AOQ_Instagram::claim_job( $this->claim_input() ) );
		$this->assertNull( AOQ_Instagram::claim_job( array_merge( $this->claim_input(), array( 'idle' => true ) ) ) );
		$this->assertSame( 'failed', AOQ_Instagram::job()['status'] );
	}

	public function test_posting_needs_a_connection_and_a_published_post() {
		$id = $this->generated_post( 'pending' );
		set_post_thumbnail( $id, $this->fx['en']['media'] );
		$this->assertWPError( AOQ_Instagram::request_job( get_current_blog_id(), $id, 'post' ) );
	}

	public function test_every_instagram_post_of_a_post_is_kept_and_shown() {
		$id = $this->generated_post( 'publish' );
		$this->assertSame( array(), AOQ_Instagram::posts_of( $id ) );
		update_post_meta( $id, AOQ_Instagram::META_MEDIA, '111' );
		update_post_meta( $id, AOQ_Instagram::META_LINK, 'https://www.instagram.com/p/abc/' );
		$this->assertSame( 'https://www.instagram.com/p/abc/', AOQ_Instagram::posts_of( $id )[0]['permalink'], 'posts from before the list was kept' );
		update_post_meta( $id, AOQ_Instagram::META_POSTS, array( array( 'media_id' => '111', 'permalink' => 'https://www.instagram.com/p/abc/', 'at' => '2026-11-14T10:00:00+00:00' ), array( 'media_id' => '222', 'permalink' => 'https://www.instagram.com/p/def/', 'at' => '2026-11-15T10:00:00+00:00' ) ) );
		ob_start();
		AOQ_Instagram::posts_cell( 'aoq_instagram', $id );
		$cell = ob_get_clean();
		$this->assertStringContainsString( 'https://www.instagram.com/p/def/', $cell );
		$this->assertStringContainsString( '(2)', $cell );
	}

	public function test_authorize_url_uses_unified_login() {
		AOQ_Instagram::save( array( 'app_id' => '1125044123653947', 'app_secret' => 'secret', 'config_id' => '1711629783764287' ) );
		$this->assertTrue( AOQ_Instagram::can_connect() );
		$url = AOQ_Instagram::authorize_url();
		$this->assertStringContainsString( 'facebook.com/dialog/oauth', $url );
		$this->assertStringContainsString( 'client_id=1125044123653947', $url );
		$this->assertStringContainsString( 'config_id=1711629783764287', $url );
		$this->assertStringContainsString( 'prompt=login', $url );
		$this->assertStringNotContainsString( 'scope=', $url );
		$this->assertStringNotContainsString( 'instagram.com/oauth', $url );
	}

	public function test_store_token_uses_assigned_instagram_accounts() {
		$bodies = array(
			'zero' => wp_json_encode( array( 'data' => array() ) ),
			'many' => wp_json_encode(
				array(
					'data' => array(
						array( 'id' => '1', 'username' => 'one' ),
						array( 'id' => '2', 'username' => 'two' ),
					),
				)
			),
			'one'  => wp_json_encode( array( 'data' => array( array( 'id' => '17841400000000000', 'username' => 'tgs' ) ) ) ),
		);
		$mode   = 'zero';
		$seen   = array();
		$filter = function ( $pre, $args, $url ) use ( &$mode, &$seen, $bodies ) {
			$seen[] = $url;
			$this->assertStringContainsString( 'graph.facebook.com', $url );
			$this->assertStringContainsString( 'assigned_instagram_accounts', $url );
			return array(
				'headers'  => array(),
				'body'     => $bodies[ $mode ],
				'response' => array( 'code' => 200, 'message' => 'OK' ),
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );

		$mode = 'zero';
		$err  = AOQ_Instagram::store_token( 'tok-zero' );
		$this->assertIsString( $err );
		$this->assertStringContainsString( 'exactly one', $err );

		$mode = 'many';
		$err  = AOQ_Instagram::store_token( 'tok-many' );
		$this->assertIsString( $err );
		$this->assertStringContainsString( '@one', $err );
		$this->assertStringContainsString( '@two', $err );

		$mode = 'one';
		$this->assertTrue( AOQ_Instagram::store_token( 'tok-one', 3600 ) );
		$s = AOQ_Instagram::get();
		$this->assertSame( 'tok-one', $s['token'] );
		$this->assertSame( '17841400000000000', $s['user_id'] );
		$this->assertSame( 'tgs', $s['username'] );
		$this->assertGreaterThan( time(), (int) $s['token_expires'] );

		remove_filter( 'pre_http_request', $filter, 10 );
		$this->assertNotEmpty( $seen );
	}

	public function test_publish_calls_graph_facebook() {
		AOQ_Instagram::save(
			array(
				'enabled' => true,
				'token'   => 'tok-pub',
				'user_id' => '17841400000000000',
			)
		);
		$id = $this->generated_post( 'publish' );
		set_post_thumbnail( $id, $this->fx['en']['media'] );
		$urls   = array();
		$filter = function ( $pre, $args, $url ) use ( &$urls ) {
			$urls[] = $url;
			$this->assertStringContainsString( 'graph.facebook.com', $url );
			$path   = (string) wp_parse_url( $url, PHP_URL_PATH );
			$body   = array( 'error' => array( 'message' => 'unexpected ' . $url ) );
			$code   = 400;
			if ( preg_match( '#/17841400000000000/media_publish$#', $path ) ) {
				$body = array( 'id' => 'media-9' );
				$code = 200;
			} elseif ( preg_match( '#/17841400000000000/media$#', $path ) ) {
				$body = array( 'id' => 'container-1' );
				$code = 200;
			} elseif ( preg_match( '#/container-1$#', $path ) ) {
				$body = array( 'status_code' => 'FINISHED' );
				$code = 200;
			} elseif ( preg_match( '#/media-9$#', $path ) ) {
				$body = array( 'permalink' => 'https://www.instagram.com/p/xyz/' );
				$code = 200;
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => $code, 'message' => 200 === $code ? 'OK' : 'Bad Request' ),
			);
		};
		add_filter( 'pre_http_request', $filter, 10, 3 );
		$this->assertTrue( AOQ_Instagram::publish( get_current_blog_id(), $id ) );
		remove_filter( 'pre_http_request', $filter, 10 );
		$this->assertNotEmpty( $urls );
		$this->assertSame( 'media-9', get_post_meta( $id, AOQ_Instagram::META_MEDIA, true ) );
		$this->assertSame( 'https://www.instagram.com/p/xyz/', get_post_meta( $id, AOQ_Instagram::META_LINK, true ) );
		foreach ( $urls as $u ) {
			$this->assertStringContainsString( 'graph.facebook.com', $u );
			$this->assertStringNotContainsString( 'graph.instagram.com', $u );
		}
	}

	public function tear_down() {
		delete_site_option( AOQ_Instagram::OPTION );
		delete_site_option( AOQ_Instagram::LOG_OPTION );
		delete_site_option( AOQ_Instagram::JOB_OPTION );
		delete_site_option( AOQ_Instagram::JOBS_LOG );
		parent::tear_down();
	}
}
