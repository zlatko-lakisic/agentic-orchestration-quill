<?php
/**
 * Used when no supported SEO plugin is active: writes nothing.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Null_SEO_Adapter implements AOQ_SEO_Adapter {

	public function id() {
		return 'none';
	}

	public function is_active() {
		return false;
	}

	public function write( $post_id, array $seo ) {
	}

	public function remove( $post_id ) {
	}

	public function sitemap_note() {
		return __( 'No SEO plugin found. WordPress core sitemaps apply if enabled.', 'ao-quill' );
	}
}
