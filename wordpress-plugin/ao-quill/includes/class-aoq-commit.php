<?php
/**
 * aoq/commit-post: writes the whole multilingual bundle in one call, or nothing.
 *
 * Content conventions from the worker:
 * - `<!-- aoq:image inline-1 -->` is replaced by the image figure for that site.
 * - `<a data-aoq-post="123">` gets the permalink of post 123 on the same site.
 * - Claim markers `{{c:...}}` must already be stripped; any left over fails the commit.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Commit {

	const IMAGE_PLACEHOLDER = '/<!--\s*aoq:image\s+(featured|inline-[0-9]+)\s*-->/';
	const POST_LINK         = '/<a\s+data-aoq-post="([0-9]+)"/';

	/** @var array<int, int[]> Posts created, by site. */
	private $posts = array();

	/** @var array<int, int[]> Posts with SEO rows, by site. */
	private $seo_rows = array();

	/** @var string|null */
	private $group = null;

	/** @var AOQ_Media */
	private $media;

	/**
	 * Run a commit.
	 *
	 * @param array $bundle commit-post input (already schema validated).
	 * @return array|WP_Error commit-post output.
	 */
	public static function commit( array $bundle ) {
		$run = AOQ_Runs::active( $bundle['run_id'] );
		if ( ! is_wp_error( $run ) && 'test' === $run['run_trigger'] ) {
			return new WP_Error( 'aoq_test_run', __( 'Test runs are never committed.', 'ao-quill' ), array( 'status' => 409 ) );
		}
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		if ( (int) $run['committed'] ) {
			return new WP_Error( 'aoq_already_committed', __( 'This run already committed a post.', 'ao-quill' ), array( 'status' => 409 ) );
		}
		$problems = self::check( $bundle );
		if ( $problems ) {
			return new WP_Error( 'aoq_invalid_bundle', implode( ' ', $problems ), array( 'status' => 422 ) );
		}
		$commit = new self();
		try {
			$result = $commit->write( $bundle );
		} catch ( Throwable $e ) {
			$commit->rollback();
			return new WP_Error( 'aoq_commit_failed', __( 'Commit failed and was rolled back: ', 'ao-quill' ) . $e->getMessage(), array( 'status' => 500 ) );
		} finally {
			$commit->media->cleanup();
		}
		AOQ_Runs::mark_committed( $bundle['run_id'], $result );
		AOQ_Instagram::queue_committed( $result );
		return $result;
	}

	/**
	 * Publish a test run that finished: commit the bundle it saved, as a real run would
	 * (same checks, publish mode and media handling). Each test run can be published once.
	 *
	 * @param string $run_id Test run.
	 * @return array|WP_Error commit-post output.
	 */
	public static function publish_test( $run_id ) {
		$run = AOQ_Runs::get( $run_id );
		if ( ! $run || 'test' !== $run['run_trigger'] || 'succeeded' !== $run['status'] ) {
			return new WP_Error( 'aoq_not_publishable', __( 'Only a test run that finished can be published.', 'ao-quill' ) );
		}
		if ( (int) $run['committed'] ) {
			return new WP_Error( 'aoq_already_committed', __( 'This run already committed a post.', 'ao-quill' ) );
		}
		$cp     = AOQ_Runs::checkpoint( $run );
		$bundle = $cp['state']['bundle'] ?? null;
		if ( ! is_array( $bundle ) ) {
			return new WP_Error( 'aoq_no_bundle', __( 'This run did not save its post, so it cannot be published. Runs from before this feature are preview only.', 'ao-quill' ) );
		}
		$bundle['run_id'] = $run_id;
		$errors           = AOQ_Schema::validate( $bundle, 'abilities/commit-post.input' );
		$problems         = is_wp_error( $errors ) ? array( $errors->get_error_message() ) : self::check( $bundle );
		if ( $problems ) {
			return new WP_Error( 'aoq_invalid_bundle', implode( ' ', $problems ) );
		}
		$commit = new self();
		try {
			$result = $commit->write( $bundle );
		} catch ( Throwable $e ) {
			$commit->rollback();
			return new WP_Error( 'aoq_commit_failed', __( 'Commit failed and was rolled back: ', 'ao-quill' ) . $e->getMessage() );
		} finally {
			$commit->media->cleanup();
		}
		AOQ_Runs::mark_committed( $run_id, $result );
		AOQ_Instagram::queue_committed( $result );
		return $result;
	}

	public function __construct() {
		$this->media = new AOQ_Media();
	}

	/**
	 * Semantic checks the schema cannot express. Returns a list of problems.
	 *
	 * @param array $bundle Bundle.
	 * @return string[]
	 */
	public static function check( array $bundle ) {
		$adapter  = AOQ_Plugin::translation_adapter();
		$problems = array();

		$expected = wp_list_pluck( $adapter->active_languages(), 'code' );
		$got      = wp_list_pluck( $bundle['posts'], 'language' );
		sort( $expected );
		$sorted = $got;
		sort( $sorted );
		if ( $sorted !== $expected ) {
			$problems[] = sprintf( 'The bundle must contain exactly one post per site language (%s); got %s.', implode( ', ', $expected ), implode( ', ', $got ) );
		}

		$image_keys = wp_list_pluck( $bundle['images'], 'key' );
		if ( count( array_unique( $image_keys ) ) !== count( $image_keys ) ) {
			$problems[] = 'Image keys must be unique.';
		}
		if ( ! in_array( 'featured', $image_keys, true ) ) {
			$problems[] = 'A featured image is required.';
		}
		foreach ( $bundle['images'] as $image ) {
			if ( 'media_library' === $image['source'] ) {
				if ( empty( $image['media'] ) || 'site-library' !== $image['license']['code'] ) {
					$problems[] = sprintf( 'Image %s: media library images need media IDs and the site-library license.', $image['key'] );
				}
			} elseif ( empty( $image['url'] ) || 'site-library' === $image['license']['code'] ) {
				$problems[] = sprintf( 'Image %s: remote images need a URL and an external license.', $image['key'] );
			}
			if ( $image['license']['requires_attribution'] && '' === trim( $image['attribution'] ) ) {
				$problems[] = sprintf( 'Image %s: the license requires attribution text.', $image['key'] );
			}
		}

		foreach ( $bundle['posts'] as $post ) {
			$lang = $adapter->language( $post['language'] );
			if ( ! $lang ) {
				continue;
			}
			$label = $post['language'];
			if ( false !== strpos( $post['content'], '{{c:' ) ) {
				$problems[] = "[$label] Claim markers were not stripped.";
			}
			$post_keys = wp_list_pluck( $post['images'], 'key' );
			sort( $post_keys );
			$all_keys = $image_keys;
			sort( $all_keys );
			if ( $post_keys !== $all_keys ) {
				$problems[] = "[$label] Image text must cover exactly the bundle images.";
			}
			preg_match_all( self::IMAGE_PLACEHOLDER, $post['content'], $m );
			$inline_expected = array_values( array_diff( $image_keys, array( 'featured' ) ) );
			$inline_found    = array_values( array_diff( $m[1], array( 'featured' ) ) );
			sort( $inline_expected );
			sort( $inline_found );
			if ( $inline_found !== $inline_expected ) {
				$problems[] = "[$label] Every inline image must appear exactly once in the content.";
			}

			switch_to_blog( $lang['blog_id'] );
			foreach ( $post['category_ids'] as $id ) {
				if ( ! term_exists( (int) $id, 'category' ) ) {
					$problems[] = "[$label] Category $id does not exist.";
				}
			}
			foreach ( $post['tag_ids'] as $id ) {
				if ( ! term_exists( (int) $id, 'post_tag' ) ) {
					$problems[] = "[$label] Tag $id does not exist.";
				}
			}
			preg_match_all( self::POST_LINK, $post['content'], $links );
			foreach ( $links[1] as $id ) {
				if ( 'post' !== get_post_type( (int) $id ) || 'publish' !== get_post_status( (int) $id ) ) {
					$problems[] = "[$label] Internal link target $id is not a published post on this site.";
				}
			}
			restore_current_blog();
		}
		return $problems;
	}

	/**
	 * Write everything. Throws on any failure; the caller rolls back.
	 *
	 * @param array $bundle Bundle.
	 * @return array
	 * @throws RuntimeException On failure.
	 */
	private function write( array $bundle ) {
		$adapter  = AOQ_Plugin::translation_adapter();
		$settings = AOQ_Settings::get();
		$status   = 'publish' === $settings['publish_mode'] ? 'publish' : 'pending';
		$images   = array();
		foreach ( $bundle['images'] as $image ) {
			$images[ $image['key'] ] = $image;
		}
		$out_posts   = array();
		$attachments = array();
		$primary     = $adapter->primary_language();

		foreach ( $bundle['posts'] as $post ) {
			$lang    = $adapter->language( $post['language'] );
			$blog_id = (int) $lang['blog_id'];
			$texts   = array();
			foreach ( $post['images'] as $text ) {
				$texts[ $text['key'] ] = $text;
			}

			$att_ids = array();
			foreach ( $images as $key => $image ) {
				$att             = $this->media->attachment_for( $image, $blog_id, $bundle['run_id'], $post['title'] );
				$att_ids[ $key ] = $att['id'];
				if ( $att['created'] ) {
					switch_to_blog( $blog_id );
					update_post_meta( $att['id'], '_wp_attachment_image_alt', sanitize_text_field( $texts[ $key ]['alt'] ) );
					wp_update_post(
						array(
							'ID'           => $att['id'],
							'post_excerpt' => sanitize_text_field( $texts[ $key ]['caption'] ),
						)
					);
					restore_current_blog();
					$attachments[] = array(
						'blog_id' => $blog_id,
						'id'      => $att['id'],
						'key'     => $key,
					);
				}
			}
			do_action( 'aoq_commit_step', 'images', $post['language'], $bundle );

			switch_to_blog( $blog_id );
			try {
				$content = $this->render_content( $post['content'], $att_ids, $texts, $images );
				$post_id = wp_insert_post(
					array(
						'post_type'    => 'post',
						'post_status'  => $status,
						'post_title'   => sanitize_text_field( $post['title'] ),
						'post_name'    => $post['slug'],
						'post_excerpt' => sanitize_textarea_field( $post['excerpt'] ),
						'post_content' => $content,
						'post_author'  => (int) $settings['author_user_id'],
					),
					true
				);
				if ( is_wp_error( $post_id ) ) {
					throw new RuntimeException( "[{$post['language']}] " . $post_id->get_error_message() );
				}
				$this->posts[ $blog_id ][] = (int) $post_id;

				$set = wp_set_post_terms( $post_id, array_map( 'intval', $post['category_ids'] ), 'category', false );
				if ( is_wp_error( $set ) ) {
					throw new RuntimeException( $set->get_error_message() );
				}
				$set = wp_set_post_terms( $post_id, array_map( 'intval', $post['tag_ids'] ), 'post_tag', false );
				if ( is_wp_error( $set ) ) {
					throw new RuntimeException( $set->get_error_message() );
				}
				if ( ! set_post_thumbnail( $post_id, $att_ids['featured'] ) ) {
					throw new RuntimeException( "[{$post['language']}] Could not set the featured image." );
				}
				AOQ_Marking::mark( $post_id, $bundle['run_id'] );
				if ( ! empty( $post['instagram']['caption'] ) ) {
					// The worker's Instagram caption and hashtags (D29), used when the post goes live.
					update_post_meta( $post_id, AOQ_Instagram::META_CAPTION, sanitize_textarea_field( $post['instagram']['caption'] ) );
					update_post_meta( $post_id, AOQ_Instagram::META_HASHTAGS, array_map( 'sanitize_text_field', (array) $post['instagram']['hashtags'] ) );
				}
				$seo = AOQ_Plugin::seo_adapter();
				if ( $seo->is_active() ) {
					$seo->write( $post_id, $post['seo'] );
					$this->seo_rows[ $blog_id ][] = (int) $post_id;
				}
				do_action( 'aoq_commit_step', 'post', $post['language'], $bundle );
				$url = (string) get_permalink( $post_id );
			} finally {
				restore_current_blog();
			}
			$out_posts[] = array(
				'language' => $post['language'],
				'blog_id'  => $blog_id,
				'post_id'  => (int) $post_id,
				'url'      => $url,
			);
		}

		usort(
			$out_posts,
			static function ( $a, $b ) use ( $primary ) {
				return ( $a['language'] === $primary ? 0 : 1 ) <=> ( $b['language'] === $primary ? 0 : 1 );
			}
		);
		$this->group = $adapter->link_translations( $out_posts, $bundle['run_id'] );
		do_action( 'aoq_commit_step', 'link', null, $bundle );

		return array(
			'translation_group' => $this->group,
			'status'            => $status,
			'posts'             => $out_posts,
			'attachments'       => $attachments,
		);
	}

	/**
	 * Replace image placeholders and internal link targets for the current site.
	 *
	 * @param string $content Content.
	 * @param array  $att_ids Image key => attachment ID on this site.
	 * @param array  $texts   Image key => {alt, caption}.
	 * @param array  $images  Image key => bundle image.
	 * @return string
	 */
	public function render_content( $content, array $att_ids, array $texts, array $images ) {
		$content = preg_replace_callback(
			self::IMAGE_PLACEHOLDER,
			static function ( $m ) use ( $att_ids, $texts, $images ) {
				$key = $m[1];
				if ( 'featured' === $key ) {
					return '';
				}
				$img     = wp_get_attachment_image( $att_ids[ $key ], 'large', false, array( 'alt' => $texts[ $key ]['alt'] ) );
				$caption = trim( $texts[ $key ]['caption'] );
				if ( 'unchecked' === $images[ $key ]['license']['code'] ) {
					// Web search image (D28): the caption links to the page it was found on.
					$figcaption = '<figcaption>' . esc_html( $caption ) . ( '' !== $caption ? ' ' : '' ) . self::source_link( $images[ $key ] ) . '</figcaption>';
					return '<figure class="wp-block-image size-large">' . $img . $figcaption . '</figure>';
				}
				if ( $images[ $key ]['license']['requires_attribution'] ) {
					// Attribution text stays exactly as the license requires.
					$caption = trim( $caption . ' ' . $images[ $key ]['attribution'] );
				}
				$figcaption = '' !== $caption ? '<figcaption>' . esc_html( $caption ) . '</figcaption>' : '';
				return '<figure class="wp-block-image size-large">' . $img . $figcaption . '</figure>';
			},
			$content
		);
		if ( isset( $images['featured'] ) && 'unchecked' === $images['featured']['license']['code'] ) {
			// The theme shows the featured image without a caption: its source goes under the post.
			$content .= "\n<p class=\"aoq-image-source\"><small>" . esc_html__( 'Featured image', 'ao-quill' ) . ': ' . self::source_link( $images['featured'] ) . '</small></p>';
		}
		return preg_replace_callback(
			self::POST_LINK,
			static function ( $m ) {
				return '<a href="' . esc_url( get_permalink( (int) $m[1] ) ) . '"';
			},
			$content
		);
	}

	/**
	 * Remove everything this commit created.
	 */
	public function rollback() {
		$seo = AOQ_Plugin::seo_adapter();
		foreach ( $this->seo_rows as $blog_id => $ids ) {
			switch_to_blog( $blog_id );
			foreach ( $ids as $id ) {
				$seo->remove( $id );
			}
			restore_current_blog();
		}
		foreach ( $this->posts as $blog_id => $ids ) {
			switch_to_blog( $blog_id );
			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}
			restore_current_blog();
		}
		if ( $this->group ) {
			AOQ_Plugin::translation_adapter()->unlink_group( $this->group );
		}
		$this->media->rollback();
		$this->posts    = array();
		$this->seo_rows = array();
		$this->group    = null;
	}

	/**
	 * "Source: <a>host</a>" for an image found by web search.
	 *
	 * @param array $image Bundle image.
	 * @return string HTML.
	 */
	private static function source_link( array $image ) {
		$page = (string) ( $image['source_page_url'] ?? '' );
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( $page, PHP_URL_HOST ) );
		if ( '' === $page || '' === $host ) {
			return esc_html( (string) $image['attribution'] );
		}
		return esc_html__( 'Source:', 'ao-quill' ) . ' <a href="' . esc_url( $page ) . '" target="_blank" rel="nofollow noopener noreferrer">' . esc_html( $host ) . '</a>';
	}
}
