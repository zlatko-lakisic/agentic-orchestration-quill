<?php
/**
 * commit-post: writes every language or nothing.
 *
 * @package AOQuill
 */

class Test_AOQ_Commit extends AOQ_TestCase {

	public function test_commit_writes_linked_marked_posts_with_server_side_status() {
		$run    = $this->claim();
		$before = $this->counts( $this->bs_blog );
		$result = AOQ_Commit::commit( $this->bundle( $run['run_id'] ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertCount( 2, $result['posts'] );
		$this->assertSame( 'en', $result['posts'][0]['language'] );

		$adapter = AOQ_Plugin::translation_adapter();
		foreach ( $result['posts'] as $p ) {
			switch_to_blog( $p['blog_id'] );
			$post = get_post( $p['post_id'] );
			$this->assertSame( 'pending', $post->post_status );
			$this->assertSame( $this->author, (int) $post->post_author );
			$this->assertTrue( AOQ_Marking::is_generated( $p['post_id'] ) );
			$this->assertSame( $run['run_id'], get_post_meta( $p['post_id'], AOQ_Marking::META_RUN, true ) );
			$this->assertNotEmpty( get_post_thumbnail_id( $p['post_id'] ) );
			$this->assertStringNotContainsString( 'aoq:image', $post->post_content );
			$this->assertStringNotContainsString( 'data-aoq-post', $post->post_content );
			$this->assertStringContainsString( '<figure', $post->post_content );
			restore_current_blog();
			$this->assertSame( $result['translation_group'], $adapter->translation_group( $p['blog_id'], $p['post_id'] ) );
		}

		// The main site reuses its own media item; the bs site gets a copy with license meta.
		$after = $this->counts( $this->bs_blog );
		$this->assertSame( $before['attachments'] + 2, $after['attachments'] );
		$this->assertNotEmpty( $result['attachments'] );
		switch_to_blog( $this->bs_blog );
		$this->assertSame( 'site-library', get_post_meta( $result['attachments'][0]['id'], '_aoq_license_code', true ) );
		restore_current_blog();

		$this->assertSame( 1, (int) AOQ_Runs::get( $run['run_id'] )['committed'] );
		$this->assertWPError( AOQ_Commit::commit( $this->bundle( $run['run_id'] ) ), 'second commit is refused' );
	}

	public function test_publish_mode_is_enforced_by_the_plugin() {
		$s                 = AOQ_Settings::get();
		$s['publish_mode'] = 'publish';
		AOQ_Settings::save( $s );
		$run    = $this->claim();
		$result = AOQ_Commit::commit( $this->bundle( $run['run_id'] ) );
		$this->assertSame( 'publish', $result['status'] );
	}

	public function test_forced_failure_leaves_nothing_behind() {
		$run     = $this->claim();
		$main    = $this->counts( get_main_site_id() );
		$bs      = $this->counts( $this->bs_blog );
		$groups  = count( AOQ_Plugin::translation_adapter()->all_groups() );
		$failing = static function ( $step, $language ) {
			if ( 'post' === $step && 'bs' === $language ) {
				throw new RuntimeException( 'forced failure' );
			}
		};
		add_action( 'aoq_commit_step', $failing, 10, 2 );
		$result = AOQ_Commit::commit( $this->bundle( $run['run_id'] ) );
		remove_action( 'aoq_commit_step', $failing, 10 );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'forced failure', $result->get_error_message() );
		$this->assertSame( $main, $this->counts( get_main_site_id() ) );
		$this->assertSame( $bs, $this->counts( $this->bs_blog ) );
		$this->assertSame( $groups, count( AOQ_Plugin::translation_adapter()->all_groups() ) );
		$this->assertSame( 0, (int) AOQ_Runs::get( $run['run_id'] )['committed'] );
	}

	public function test_failure_at_link_step_also_rolls_back() {
		$run     = $this->claim();
		$bs      = $this->counts( $this->bs_blog );
		$failing = static function ( $step ) {
			if ( 'link' === $step ) {
				throw new RuntimeException( 'link failure' );
			}
		};
		add_action( 'aoq_commit_step', $failing );
		$this->assertWPError( AOQ_Commit::commit( $this->bundle( $run['run_id'] ) ) );
		remove_action( 'aoq_commit_step', $failing );
		$this->assertSame( $bs, $this->counts( $this->bs_blog ) );
		$this->assertSame( array(), AOQ_Plugin::translation_adapter()->all_groups() );
	}

	public function test_unknown_term_is_rejected() {
		$run                                = $this->claim();
		$bundle                             = $this->bundle( $run['run_id'] );
		$bundle['posts'][1]['category_ids'] = array( 999999 );
		$result                             = AOQ_Commit::commit( $bundle );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'Category 999999', $result->get_error_message() );
	}

	public function test_missing_language_is_rejected() {
		$run    = $this->claim();
		$bundle = $this->bundle( $run['run_id'] );
		array_pop( $bundle['posts'] );
		$this->assertWPError( AOQ_Commit::commit( $bundle ) );
	}

	public function test_claim_markers_are_rejected() {
		$run                           = $this->claim();
		$bundle                        = $this->bundle( $run['run_id'] );
		$bundle['posts'][0]['content'] = $bundle['posts'][0]['content'] . '<p>Fact.{{c:c1}}</p>';
		$this->assertWPError( AOQ_Commit::commit( $bundle ) );
	}

	public function test_commit_needs_an_active_run() {
		$this->assertWPError( AOQ_Commit::commit( $this->bundle( wp_generate_uuid4() ) ) );
	}

	public function test_attribution_required_by_license() {
		$run                                                   = $this->claim();
		$bundle                                                = $this->bundle( $run['run_id'] );
		$bundle['images'][1]['source']                         = 'remote';
		$bundle['images'][1]['media']                          = null;
		$bundle['images'][1]['url']                            = 'https://images.example.org/x.jpg';
		$bundle['images'][1]['license']['code']                = 'cc-by';
		$bundle['images'][1]['license']['requires_attribution'] = true;
		$result = AOQ_Commit::commit( $bundle );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'attribution', $result->get_error_message() );
	}
}
