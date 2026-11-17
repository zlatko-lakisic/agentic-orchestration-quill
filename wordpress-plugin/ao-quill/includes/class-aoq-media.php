<?php
/**
 * Image handling for commit-post: downloads with WordPress's own functions,
 * checks mime type and size, copies media library images across sites, and
 * stores license metadata on every attachment it creates.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Media {

	const MAX_BYTES     = 15728640; // 15 MiB.
	const ALLOWED_MIMES = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
	);

	/** @var string[] Temporary files to remove at the end. */
	private $temp_files = array();

	/** @var array<int, int[]> Attachments created, by site. */
	public $created = array();

	/** @var array<string, string> Downloaded file per image key. */
	private $downloads = array();

	/**
	 * Load the admin includes that sideloading needs.
	 */
	public static function load_includes() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Validate a local file: real image, allowed mime, size limit.
	 *
	 * @param string $path File path.
	 * @return string Mime type.
	 * @throws RuntimeException When the file is not acceptable.
	 */
	public static function check_file( $path ) {
		$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $size ) {
			throw new RuntimeException( 'Downloaded image is empty.' );
		}
		if ( $size > self::MAX_BYTES ) {
			throw new RuntimeException( sprintf( 'Image is %d bytes; the limit is %d.', $size, self::MAX_BYTES ) );
		}
		$mime = wp_get_image_mime( $path );
		if ( ! $mime || ! isset( self::ALLOWED_MIMES[ $mime ] ) ) {
			throw new RuntimeException( 'Image type is not allowed: ' . ( $mime ? $mime : 'unknown' ) );
		}
		return $mime;
	}

	/**
	 * Attachment ID for an image on the target site. Reuses the source attachment
	 * when it already lives on that site; otherwise creates one.
	 *
	 * @param array  $image   Bundle image.
	 * @param int    $blog_id Target site.
	 * @param string $run_id  Run ID.
	 * @param string $title   Title for a new attachment.
	 * @return array{id:int, created:bool}
	 * @throws RuntimeException On failure.
	 */
	public function attachment_for( array $image, $blog_id, $run_id, $title ) {
		if ( 'media_library' === $image['source'] ) {
			$src_blog = (int) $image['media']['blog_id'];
			$src_id   = (int) $image['media']['id'];
			if ( $src_blog === (int) $blog_id ) {
				return array(
					'id'      => $src_id,
					'created' => false,
				);
			}
			$path = $this->library_copy( $image['key'], $src_blog, $src_id );
		} else {
			$path = $this->download( $image );
		}
		$mime = self::check_file( $path );

		$copy = wp_tempnam( 'aoq-' . $image['key'] );
		if ( ! copy( $path, $copy ) ) {
			throw new RuntimeException( 'Could not copy image to a temporary file.' );
		}
		$this->temp_files[] = $copy;

		$name = sanitize_file_name( sanitize_title( $title ) . '-' . $image['key'] ) . '.' . self::ALLOWED_MIMES[ $mime ];
		switch_to_blog( $blog_id );
		try {
			self::load_includes();
			$id = media_handle_sideload(
				array(
					'name'     => $name,
					'tmp_name' => $copy,
				),
				0,
				$title
			);
			if ( is_wp_error( $id ) ) {
				throw new RuntimeException( 'Sideload failed: ' . $id->get_error_message() );
			}
			$this->created[ (int) $blog_id ][] = (int) $id;
			update_post_meta( $id, '_aoq_run_id', $run_id );
			update_post_meta( $id, '_aoq_license', wp_slash( wp_json_encode( $image['license'] ) ) );
			update_post_meta( $id, '_aoq_license_code', $image['license']['code'] );
			update_post_meta( $id, '_aoq_author', $image['author'] );
			update_post_meta( $id, '_aoq_attribution', $image['attribution'] );
			update_post_meta( $id, '_aoq_source_url', (string) ( $image['url'] ?? '' ) );
			update_post_meta( $id, '_aoq_source_page_url', (string) ( $image['source_page_url'] ?? '' ) );
			if ( 'media_library' === $image['source'] ) {
				update_post_meta( $id, '_aoq_copied_from', $image['media']['blog_id'] . ':' . $image['media']['id'] );
			}
		} finally {
			restore_current_blog();
		}
		return array(
			'id'      => (int) $id,
			'created' => true,
		);
	}

	/**
	 * Download a remote image once per commit.
	 *
	 * @param array $image Bundle image.
	 * @return string Local path.
	 * @throws RuntimeException On failure.
	 */
	private function download( array $image ) {
		if ( isset( $this->downloads[ $image['key'] ] ) ) {
			return $this->downloads[ $image['key'] ];
		}
		$url = (string) $image['url'];
		if ( ! wp_http_validate_url( $url ) || 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			throw new RuntimeException( 'Image URL must be a public https URL.' );
		}
		self::load_includes();
		// Same User-Agent as the worker, which fetched this image for the vision check (AO connection
		// setting, with a contact address). Some hosts refuse WordPress's default one with 403.
		$agent = (string) ( AOQ_AO_Connection::get()['user_agent'] ?? '' );
		$set   = static function ( $args ) use ( $agent ) {
			$args['user-agent'] = $agent;
			return $args;
		};
		if ( '' !== $agent ) {
			add_filter( 'http_request_args', $set );
		}
		$tmp = download_url( $url, 60 );
		if ( '' !== $agent ) {
			remove_filter( 'http_request_args', $set );
		}
		if ( is_wp_error( $tmp ) ) {
			throw new RuntimeException( 'Image download failed (' . wp_parse_url( $url, PHP_URL_HOST ) . '): ' . $tmp->get_error_message() );
		}
		$this->temp_files[]               = $tmp;
		$this->downloads[ $image['key'] ] = $tmp;
		return $tmp;
	}

	/**
	 * Copy a media library file from another site to a temporary file.
	 *
	 * @param string $key      Image key.
	 * @param int    $src_blog Source site.
	 * @param int    $src_id   Source attachment.
	 * @return string Local path.
	 * @throws RuntimeException On failure.
	 */
	private function library_copy( $key, $src_blog, $src_id ) {
		$cache_key = $key . '@' . $src_blog . ':' . $src_id;
		if ( isset( $this->downloads[ $cache_key ] ) ) {
			return $this->downloads[ $cache_key ];
		}
		switch_to_blog( $src_blog );
		$file = get_attached_file( $src_id );
		$ok   = 'attachment' === get_post_type( $src_id ) && wp_attachment_is_image( $src_id );
		restore_current_blog();
		if ( ! $ok || ! $file || ! is_readable( $file ) ) {
			throw new RuntimeException( sprintf( 'Media item %d:%d is not a readable image.', $src_blog, $src_id ) );
		}
		$tmp = wp_tempnam( 'aoq-lib' );
		if ( ! copy( $file, $tmp ) ) {
			throw new RuntimeException( 'Could not read media library file.' );
		}
		$this->temp_files[]            = $tmp;
		$this->downloads[ $cache_key ] = $tmp;
		return $tmp;
	}

	/**
	 * Delete every attachment created by this instance.
	 */
	public function rollback() {
		foreach ( $this->created as $blog_id => $ids ) {
			switch_to_blog( $blog_id );
			foreach ( $ids as $id ) {
				wp_delete_attachment( $id, true );
			}
			restore_current_blog();
		}
		$this->created = array();
	}

	/**
	 * Remove temporary files.
	 */
	public function cleanup() {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$this->temp_files = array();
		$this->downloads  = array();
	}
}
