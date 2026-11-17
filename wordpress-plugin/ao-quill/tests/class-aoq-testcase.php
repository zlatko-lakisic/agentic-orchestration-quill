<?php
/**
 * Shared fixtures: a two language network (en main site, bs second site), a
 * reported catalog, valid settings, and a claimed run.
 *
 * @package AOQuill
 */

abstract class AOQ_TestCase extends WP_UnitTestCase {

	/** @var int */
	protected $bs_blog;

	/** @var int */
	protected $author;

	/** @var array<string, array{cat:int, tag:int, post:int, media:int}> */
	protected $fx = array();

	public function set_up() {
		parent::set_up();
		global $wpdb;
		foreach ( array( 'aoq_runs', 'aoq_stages', 'aoq_translations', 'aoq_profiles' ) as $t ) {
			$wpdb->query( "DELETE FROM {$wpdb->base_prefix}$t" ); // phpcs:ignore
		}
		delete_site_option( AOQ_Runs::RUN_NOW_OPTION );
		AOQ_Plugin::reset();

		// WordPress keeps the old WPLANG unless the language pack is installed; pretend it is.
		add_filter(
			'get_available_languages',
			static function ( $languages ) {
				return array_merge( (array) $languages, array( 'bs_BA', 'de_DE' ) );
			}
		);
		$this->bs_blog = self::factory()->blog->create( array( 'path' => '/ba/' ) );
		update_blog_option( $this->bs_blog, 'WPLANG', 'bs_BA' );
		$this->author = self::factory()->user->create( array( 'role' => 'editor' ) );

		foreach ( array( 'en' => get_main_site_id(), 'bs' => $this->bs_blog ) as $lang => $blog ) {
			switch_to_blog( $blog );
			$cat   = self::factory()->category->create( array( 'name' => "Travel $lang" ) );
			$tag   = self::factory()->tag->create( array( 'name' => "Bridge $lang" ) );
			$post  = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => "Human post $lang" ) );
			$media = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
			restore_current_blog();
			$this->fx[ $lang ] = compact( 'cat', 'tag', 'post', 'media' );
		}

		$this->report_catalog();
		AOQ_AO_Connection::save_settings( array( 'ao_url' => 'https://ao.example.internal:8765' ) );
		$settings                   = AOQ_Settings::get();
		$settings['author_user_id'] = $this->author;
		$settings['timezone']       = 'UTC';
		$settings['schedule_time']  = '07:00';
		$settings['publish_mode']   = 'pending';
		AOQ_Settings::save( $settings );
	}

	/**
	 * Claim-run input with a catalog that satisfies the default settings.
	 *
	 * @return array
	 */
	protected function claim_input() {
		$agents = array();
		foreach ( AOQ_Settings::defaults()['agents'] as $role => $id ) {
			$agents[ $id ] = array(
				'id'            => $id,
				'family'        => in_array( $id, array( 'client.aoq_editor' ), true ) ? 'gemma' : 'qwen',
				'label'         => $id,
				'deterministic' => in_array( $role, array( 'quote_verifier', 'text_checks' ), true ),
				'vision'        => 'client.aoq_vision' === $id,
			);
		}
		return array(
			'worker' => array(
				'version'          => '0.1.0',
				'ao_reach_version' => '0.19.0',
			),
			'ao'     => array(
				'reachable' => true,
				'error'     => null,
				'catalog'   => array(
					'agents'       => array_values( $agents ),
					'mcps'         => array( 'client.aoq_search', 'client.aoq_fetch', 'client.aoq_images' ),
					'generated_at' => null,
				),
			),
		);
	}

	protected function report_catalog() {
		AOQ_Runs::heartbeat( $this->claim_input() );
	}

	/**
	 * Claim a run now (after the scheduled time).
	 *
	 * @return array
	 */
	protected function claim() {
		// After the 07:00 schedule, and never earlier than now so the lease is still valid.
		$run = AOQ_Runs::claim( $this->claim_input(), max( time(), strtotime( 'today 08:00 UTC' ) ) );
		$this->assertIsArray( $run, 'Expected a claimed run' );
		return $run;
	}

	/**
	 * A valid bundle for the claimed run (media library images only).
	 *
	 * @param string $run_id Run ID.
	 * @return array
	 */
	protected function bundle( $run_id ) {
		$posts = array();
		foreach ( array( 'en', 'bs' ) as $lang ) {
			$posts[] = array(
				'language'     => $lang,
				'title'        => "AI post $lang",
				'slug'         => "ai-post-$lang",
				'excerpt'      => 'Excerpt',
				'content'      => '<p>Intro.</p><!-- aoq:image inline-1 --><h2>Part</h2><p>See <a data-aoq-post="' . $this->fx[ $lang ]['post'] . '">this</a>.</p>',
				'category_ids' => array( $this->fx[ $lang ]['cat'] ),
				'tag_ids'      => array( $this->fx[ $lang ]['tag'] ),
				'seo'          => array(
					'title'         => 'SEO title',
					'description'   => 'SEO description',
					'focus_keyword' => 'bridge',
				),
				'images'       => array(
					array( 'key' => 'featured', 'alt' => "Alt $lang", 'caption' => '' ),
					array( 'key' => 'inline-1', 'alt' => "Inline $lang", 'caption' => "Caption $lang" ),
				),
			);
		}
		$license = array(
			'code'                      => 'site-library',
			'name'                      => 'Site media library',
			'url'                       => null,
			'allows_download_and_reuse' => true,
			'requires_attribution'      => false,
		);
		return array(
			'run_id' => $run_id,
			'posts'  => $posts,
			'images' => array(
				array( 'key' => 'featured', 'source' => 'media_library', 'media' => array( 'blog_id' => get_main_site_id(), 'id' => $this->fx['en']['media'] ), 'url' => null, 'source_page_url' => null, 'license' => $license, 'author' => '', 'attribution' => '' ),
				array( 'key' => 'inline-1', 'source' => 'media_library', 'media' => array( 'blog_id' => get_main_site_id(), 'id' => $this->fx['en']['media'] ), 'url' => null, 'source_page_url' => null, 'license' => $license, 'author' => '', 'attribution' => '' ),
			),
		);
	}

	/**
	 * Count posts and attachments on a site.
	 *
	 * @param int $blog_id Site.
	 * @return array{posts:int, attachments:int}
	 */
	protected function counts( $blog_id ) {
		global $wpdb;
		switch_to_blog( $blog_id );
		$out = array(
			'posts'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post'" ),
			'attachments' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ),
		);
		restore_current_blog();
		return $out;
	}
}
