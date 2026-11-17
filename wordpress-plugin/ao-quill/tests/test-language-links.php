<?php
/**
 * Language links (D32): counterparts across sites, hreflang and the menu switcher.
 *
 * @package AOQuill
 */

class Test_AOQ_Language_Links extends AOQ_TestCase {

	public function test_linked_pages_point_to_each_other_and_get_hreflang() {
		$en = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'about-us' ) );
		switch_to_blog( $this->bs_blog );
		$bs = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'o-nama' ) );
		$bs_url = get_permalink( $bs );
		restore_current_blog();
		AOQ_Language_Links::link( array( get_main_site_id() => $en, $this->bs_blog => $bs ) );
		$this->go_to( get_permalink( $en ) );
		AOQ_Language_Links::reset();
		$links = AOQ_Language_Links::current();
		$this->assertSame( get_permalink( $en ), $links['en'] );
		$this->assertSame( $bs_url, $links['bs'] );
		ob_start();
		AOQ_Language_Links::hreflang();
		$head = ob_get_clean();
		$this->assertStringContainsString( 'hreflang="bs" href="' . $bs_url . '"', $head );
		$this->assertStringContainsString( 'hreflang="x-default"', $head );
	}

	public function test_the_menu_language_item_goes_to_the_same_page() {
		$en = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		switch_to_blog( $this->bs_blog );
		$bs     = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$bs_url = get_permalink( $bs );
		$home   = home_url( '/' );
		restore_current_blog();
		AOQ_Language_Links::link( array( get_main_site_id() => $en, $this->bs_blog => $bs ) );
		$this->go_to( get_permalink( $en ) );
		AOQ_Language_Links::reset();
		$item  = (object) array( 'url' => $home );
		$other = (object) array( 'url' => 'https://example.org/' );
		$out   = AOQ_Language_Links::switcher( array( $item, $other ) );
		$this->assertSame( $bs_url, $out[0]->url );
		$this->assertSame( 'https://example.org/', $out[1]->url );
	}

	public function test_a_page_without_a_translation_has_no_alternates() {
		$en = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $en ) );
		AOQ_Language_Links::reset();
		$this->assertSame( array(), AOQ_Language_Links::current() );
	}
}
