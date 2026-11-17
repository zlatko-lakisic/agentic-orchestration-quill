<?php
/**
 * SEO adapter contract.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

interface AOQ_SEO_Adapter {

	/**
	 * Adapter ID shown in the status panel.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Whether the SEO plugin is active on the current site (call inside switch_to_blog).
	 *
	 * @return bool
	 */
	public function is_active();

	/**
	 * Write meta title, description and focus keyword for a post on the current site.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $seo     {title, description, focus_keyword}.
	 * @return void
	 * @throws RuntimeException On failure.
	 */
	public function write( $post_id, array $seo );

	/**
	 * Remove anything write() stored (rollback). Current site.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function remove( $post_id );

	/**
	 * Human-readable note on how the SEO plugin builds sitemaps.
	 *
	 * @return string
	 */
	public function sitemap_note();
}
