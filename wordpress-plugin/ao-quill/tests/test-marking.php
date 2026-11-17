<?php
/**
 * AI marking is invisible to anonymous visitors: REST, RSS, sitemaps, post classes.
 *
 * @package AOQuill
 */

class Test_AOQ_Marking extends AOQ_TestCase {

	/** @var int */
	private $post_id;

	public function set_up() {
		parent::set_up();
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Marked post' ) );
		AOQ_Marking::mark( $this->post_id, '3f2b8c1e-9a4d-4c7e-8b1a-2d3e4f5a6b7c' );
		wp_set_current_user( 0 );
	}

	public function test_taxonomy_is_private() {
		$tax = get_taxonomy( AOQ_Marking::TAXONOMY );
		$this->assertFalse( $tax->public );
		$this->assertFalse( $tax->publicly_queryable );
		$this->assertFalse( $tax->show_in_rest );
		$this->assertFalse( $tax->show_in_nav_menus );
		$this->assertFalse( $tax->show_tagcloud );
		$this->assertTrue( $tax->show_ui );
		$this->assertFalse( $tax->rewrite );
		$this->assertFalse( $tax->query_var );
	}

	public function test_post_is_marked() {
		$this->assertTrue( AOQ_Marking::is_generated( $this->post_id ) );
		$this->assertTrue( has_term( AOQ_Marking::TERM, AOQ_Marking::TAXONOMY, $this->post_id ) );
		$this->assertTrue( is_protected_meta( AOQ_Marking::META_FLAG, 'post' ) );
	}

	public function test_not_in_anonymous_rest() {
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->post_id );
		$response = rest_get_server()->dispatch( $request );
		$json     = wp_json_encode( $response->get_data() );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringNotContainsString( 'aoq', $json );
		$this->assertStringNotContainsString( 'ai-generated', $json );

		$list = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
		$this->assertStringNotContainsString( 'aoq', wp_json_encode( $list->get_data() ) );

		$tax = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/taxonomies' ) );
		$this->assertArrayNotHasKey( AOQ_Marking::TAXONOMY, $tax->get_data() );
	}

	public function test_not_in_post_class() {
		$classes = implode( ' ', get_post_class( '', $this->post_id ) );
		$this->assertStringNotContainsString( 'aoq', $classes );
		$this->assertStringNotContainsString( 'ai-generated', $classes );
	}

	public function test_not_in_rss() {
		$this->go_to( get_feed_link() );
		ob_start();
		try {
			@require ABSPATH . 'wp-includes/feed-rss2.php'; // phpcs:ignore
		} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}
		$feed = ob_get_clean();
		$this->assertStringContainsString( 'Marked post', $feed );
		$this->assertStringNotContainsString( 'aoq', $feed );
		$this->assertStringNotContainsString( 'AI generated', $feed );
	}

	public function test_not_in_core_sitemaps() {
		$provider   = wp_sitemaps_get_server()->registry->get_provider( 'taxonomies' );
		$taxonomies = array_keys( $provider->get_object_subtypes() );
		$this->assertNotContains( AOQ_Marking::TAXONOMY, $taxonomies );
		$this->assertSame( array(), $provider->get_url_list( 1, AOQ_Marking::TAXONOMY ) );
	}

	public function test_term_archive_not_reachable() {
		$this->go_to( home_url( '/?aoq_origin=ai-generated' ) );
		$this->assertFalse( is_tax( AOQ_Marking::TAXONOMY ) );
	}
}
