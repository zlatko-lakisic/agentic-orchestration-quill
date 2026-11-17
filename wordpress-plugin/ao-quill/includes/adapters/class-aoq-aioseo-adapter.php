<?php
/**
 * All in One SEO (4.x and later). Writes the per-site `aioseo_posts` row that AIOSEO
 * reads for title, description and focus keyphrase. AIOSEO builds its own sitemap
 * from public post types and taxonomies; `aoq_origin` is not public, so it is never
 * listed.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_AIOSEO_Adapter implements AOQ_SEO_Adapter {

	public function id() {
		return 'aioseo';
	}

	/**
	 * Table for the current site.
	 *
	 * @return string
	 */
	private function table() {
		global $wpdb;
		return $wpdb->prefix . 'aioseo_posts';
	}

	public function is_active() {
		global $wpdb;
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_active = is_plugin_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' )
			|| is_plugin_active( 'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' );
		if ( ! $plugin_active ) {
			return false;
		}
		$table = $this->table();
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public function write( $post_id, array $seo ) {
		global $wpdb;
		$now        = current_time( 'mysql', true );
		$keyphrases = wp_json_encode(
			array(
				'focus'      => array(
					'keyphrase' => (string) $seo['focus_keyword'],
					'score'     => 0,
					'analysis'  => new stdClass(),
				),
				'additional' => array(),
			)
		);
		$ok         = $wpdb->insert(
			$this->table(),
			array(
				'post_id'     => (int) $post_id,
				'title'       => (string) $seo['title'],
				'description' => (string) $seo['description'],
				'keyphrases'  => $keyphrases,
				'created'     => $now,
				'updated'     => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			throw new RuntimeException( 'AIOSEO write failed: ' . $wpdb->last_error );
		}
	}

	public function remove( $post_id ) {
		global $wpdb;
		$wpdb->delete( $this->table(), array( 'post_id' => (int) $post_id ), array( '%d' ) );
	}

	public function sitemap_note() {
		return __( 'All in One SEO builds the sitemap from public post types and taxonomies. AI marking uses a non-public taxonomy, so it is not listed.', 'ao-quill' );
	}
}
