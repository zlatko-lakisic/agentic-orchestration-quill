<?php
/**
 * Site inventory across every active language site, paginated. Posts, terms and
 * media are paged together: page N returns page N of each list.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Inventory {

	const POST_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * One inventory page.
	 *
	 * @param int $page     Page, from 1.
	 * @param int $per_page Items per list.
	 * @return array get-inventory output.
	 */
	public static function page( $page, $per_page ) {
		$adapter  = AOQ_Plugin::translation_adapter();
		$langs    = $adapter->active_languages();
		$offset   = ( $page - 1 ) * $per_page;
		$groups   = $adapter->all_groups();
		$counts   = array(
			'posts' => array(),
			'terms' => array(),
			'media' => array(),
		);
		foreach ( $langs as $lang ) {
			switch_to_blog( $lang['blog_id'] );
			$counts['posts'][ $lang['blog_id'] ] = self::count_posts();
			$counts['terms'][ $lang['blog_id'] ] = (int) wp_count_terms( array( 'taxonomy' => array( 'category', 'post_tag' ), 'hide_empty' => false ) );
			$counts['media'][ $lang['blog_id'] ] = self::count_media();
			restore_current_blog();
		}

		$out = array(
			'page'        => (int) $page,
			'per_page'    => (int) $per_page,
			'total_pages' => 0,
			'languages'   => 1 === (int) $page ? $adapter->languages() : array(),
			'posts'       => array(),
			'terms'       => array(),
			'media'       => array(),
		);
		foreach ( $counts as $section => $by_blog ) {
			$out['total_pages'] = max( $out['total_pages'], (int) ceil( array_sum( $by_blog ) / $per_page ) );
			foreach ( self::slices( $by_blog, $offset, $per_page ) as $blog_id => $slice ) {
				$lang = $adapter->language_for_blog( $blog_id );
				switch_to_blog( $blog_id );
				if ( 'posts' === $section ) {
					$items = self::posts( $lang['code'], $blog_id, $slice[0], $slice[1], $groups );
				} elseif ( 'terms' === $section ) {
					$items = self::terms( $lang['code'], $blog_id, $slice[0], $slice[1], $adapter );
				} else {
					$items = self::media( $lang['code'], $blog_id, $slice[0], $slice[1] );
				}
				restore_current_blog();
				$out[ $section ] = array_merge( $out[ $section ], $items );
			}
		}
		return $out;
	}

	/**
	 * Map a global window [offset, offset+limit) onto per-site windows.
	 *
	 * @param array<int,int> $by_blog Count per site, in order.
	 * @param int            $offset  Global offset.
	 * @param int            $limit   Window size.
	 * @return array<int, array{0:int, 1:int}> Site ID => [local offset, local limit].
	 */
	public static function slices( array $by_blog, $offset, $limit ) {
		$out   = array();
		$start = 0;
		foreach ( $by_blog as $blog_id => $count ) {
			$end = $start + $count;
			if ( $limit > 0 && $offset < $end && $offset + $limit > $start ) {
				$local_offset    = max( 0, $offset - $start );
				$take            = min( $count - $local_offset, $offset + $limit - max( $offset, $start ) );
				$out[ $blog_id ] = array( $local_offset, $take );
			}
			$start = $end;
		}
		return $out;
	}

	/**
	 * Posts of the current site.
	 *
	 * @return int
	 */
	private static function count_posts() {
		$counts = wp_count_posts( 'post' );
		$total  = 0;
		foreach ( self::POST_STATUSES as $status ) {
			$total += isset( $counts->$status ) ? (int) $counts->$status : 0;
		}
		return $total;
	}

	/**
	 * Image attachments of the current site.
	 *
	 * @return int
	 */
	private static function count_media() {
		$q = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return (int) $q->found_posts;
	}

	/**
	 * ISO 8601 GMT date for a post date pair.
	 *
	 * @param string $gmt   GMT date.
	 * @param string $local Local date.
	 * @return string
	 */
	private static function iso( $gmt, $local ) {
		if ( ! $gmt || '0000-00-00 00:00:00' === $gmt ) {
			$gmt = get_gmt_from_date( $local );
		}
		return gmdate( 'Y-m-d\TH:i:s\Z', strtotime( $gmt . ' UTC' ) );
	}

	/**
	 * Post items.
	 */
	private static function posts( $lang, $blog_id, $offset, $limit, array $groups ) {
		$q   = new WP_Query(
			array(
				'post_type'              => 'post',
				'post_status'            => self::POST_STATUSES,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'offset'                 => $offset,
				'posts_per_page'         => $limit,
				'no_found_rows'          => true,
				'update_post_term_cache' => true,
				'update_post_meta_cache' => true,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => true,
			)
		);
		$out = array();
		foreach ( $q->posts as $post ) {
			$excerpt = $post->post_excerpt ? $post->post_excerpt : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 55, '' );
			$out[]   = array(
				'key'               => $blog_id . ':' . $post->ID,
				'blog_id'           => (int) $blog_id,
				'id'                => (int) $post->ID,
				'language'          => $lang,
				'title'             => mb_substr( (string) $post->post_title, 0, 1000 ),
				'excerpt'           => mb_substr( (string) $excerpt, 0, 5000 ),
				'content'           => mb_substr( (string) $post->post_content, 0, 500000 ),
				'url'               => (string) get_permalink( $post ),
				'status'            => (string) $post->post_status,
				'categories'        => self::term_ids( $post, 'category' ),
				'tags'              => self::term_ids( $post, 'post_tag' ),
				'date'              => self::iso( $post->post_date_gmt, $post->post_date ),
				'modified'          => self::iso( $post->post_modified_gmt, $post->post_modified ),
				'ai_generated'      => AOQ_Marking::is_generated( $post->ID ),
				'translation_group' => $groups[ $blog_id . ':' . $post->ID ] ?? null,
			);
		}
		return $out;
	}

	/**
	 * Term IDs of a post.
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $taxonomy Taxonomy.
	 * @return int[]
	 */
	private static function term_ids( $post, $taxonomy ) {
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values( array_map( 'intval', wp_list_pluck( $terms, 'term_id' ) ) );
	}

	/**
	 * Term items.
	 */
	private static function terms( $lang, $blog_id, $offset, $limit, AOQ_Translation_Adapter $adapter ) {
		$terms = get_terms(
			array(
				'taxonomy'   => array( 'category', 'post_tag' ),
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
				'offset'     => $offset,
				'number'     => $limit,
			)
		);
		$out   = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			$out[] = array(
				'blog_id'      => (int) $blog_id,
				'language'     => $lang,
				'taxonomy'     => $term->taxonomy,
				'id'           => (int) $term->term_id,
				'name'         => html_entity_decode( (string) $term->name, ENT_QUOTES ),
				'slug'         => (string) $term->slug,
				'parent'       => (int) $term->parent,
				'count'        => (int) $term->count,
				'translations' => $adapter->term_translations( $blog_id, $term->taxonomy, $term->term_id ),
			);
		}
		return $out;
	}

	/**
	 * Media items (images only).
	 */
	private static function media( $lang, $blog_id, $offset, $limit ) {
		$q   = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'offset'         => $offset,
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
			)
		);
		$out = array();
		foreach ( $q->posts as $att ) {
			$url = wp_get_attachment_url( $att->ID );
			if ( ! $url ) {
				continue;
			}
			$preview = wp_get_attachment_image_src( $att->ID, 'medium_large' );
			$meta    = wp_get_attachment_metadata( $att->ID );
			$file    = get_attached_file( $att->ID );
			$size    = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : ( $file && file_exists( $file ) ? (int) filesize( $file ) : 0 );
			$out[]   = array(
				'key'         => $blog_id . ':' . $att->ID,
				'blog_id'     => (int) $blog_id,
				'id'          => (int) $att->ID,
				'language'    => $lang,
				'url'         => $url,
				'preview_url' => $preview ? $preview[0] : $url,
				'alt'         => mb_substr( (string) get_post_meta( $att->ID, '_wp_attachment_image_alt', true ), 0, 2000 ), // the schema's limits; a paste can be longer
				'caption'     => mb_substr( (string) $att->post_excerpt, 0, 5000 ),
				'title'       => mb_substr( (string) $att->post_title, 0, 1000 ),
				'mime'        => (string) $att->post_mime_type,
				'filesize'    => $size,
				'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
				'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
				'date'        => self::iso( $att->post_date_gmt, $att->post_date ),
			);
		}
		return $out;
	}
}
