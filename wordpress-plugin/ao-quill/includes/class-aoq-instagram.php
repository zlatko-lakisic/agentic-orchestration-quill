<?php
/**
 * Instagram (D29): the bio link (/ig-latest/ on every site), the connection to an Instagram
 * professional account (Instagram API with Unified Login), and posting AO Quill posts when
 * they go live.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Instagram {

	const OPTION     = 'aoq_instagram';
	const LOG_OPTION = 'aoq_instagram_log';
	const LOG_MAX    = 30;
	const API        = 'https://graph.facebook.com/v21.0';
	const POST_HOOK  = 'aoq_ig_post';
	const REFRESH    = 'aoq_ig_refresh';
	const JOB_OPTION = 'aoq_ig_job';
	const JOBS_LOG   = 'aoq_ig_jobs';
	const JOB_HOOK   = 'aoq_ig_job_post';

	// Post meta on the post's own site.
	const META_CAPTION  = '_aoq_ig_caption';
	const META_HASHTAGS = '_aoq_ig_hashtags';
	const META_MEDIA    = '_aoq_ig_media_id';
	const META_LINK     = '_aoq_ig_permalink';
	const META_POSTS    = '_aoq_ig_posts';
	const LINK_HOOK     = 'aoq_ig_link';
	const META_ERROR    = '_aoq_ig_error';
	const META_QUEUED   = '_aoq_ig_queued';

	/**
	 * Register hooks (every site of the network).
	 */
	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'bio_redirect' ), 0 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( self::POST_HOOK, array( __CLASS__, 'run_queued' ), 10, 2 );
		add_action( self::REFRESH, array( __CLASS__, 'refresh_token' ) );
		add_action( self::JOB_HOOK, array( __CLASS__, 'post_job' ) );
		add_action( self::LINK_HOOK, array( __CLASS__, 'retry_link' ), 10, 4 );
		add_filter( 'manage_post_posts_columns', array( __CLASS__, 'posts_column' ) );
		add_action( 'manage_post_posts_custom_column', array( __CLASS__, 'posts_cell' ), 10, 2 );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'meta_box' ) );
		add_action( 'admin_post_aoq_ig_oauth', array( __CLASS__, 'oauth_callback' ) );
		if ( is_main_site() && ! wp_next_scheduled( self::REFRESH ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::REFRESH );
		}
	}

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'        => false,
			'auto_post'      => true,
			'languages'      => array(),
			'app_id'         => '',
			'app_secret'     => '',
			'config_id'      => '',
			'token'          => '',
			'token_expires'  => 0,
			'token_set_at'   => 0,
			'user_id'        => '',
			'username'       => '',
			'bio_slug'       => 'ig-latest',
			'image_format'   => 'portrait',
			'fixed_hashtags' => 'mostar, bosniaandherzegovina, visitbih',
			'max_hashtags'   => 15,
			'link_hint'      => __( 'Read the full article: link in bio.', 'ao-quill' ),
			'caption_style'  => __( 'Warm and inviting, like a local guide. One short hook line first.', 'ao-quill' ),
		);
	}

	/**
	 * Current settings.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_site_option( self::OPTION, array() );
		$out    = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		if ( ! $out['languages'] ) {
			$out['languages'] = array( AOQ_Plugin::translation_adapter()->primary_language() );
		}
		return $out;
	}

	/**
	 * Save settings (merged over the current ones).
	 *
	 * @param array $values Values to change.
	 */
	public static function save( array $values ) {
		update_site_option( self::OPTION, array_merge( self::get(), $values ) );
	}

	/**
	 * Whether posting can work: enabled and connected.
	 *
	 * @return bool
	 */
	public static function connected() {
		$s = self::get();
		return '' !== $s['token'] && '' !== $s['user_id'];
	}

	/**
	 * What the worker needs to write captions (part of the worker config).
	 *
	 * @return array
	 */
	public static function worker_config() {
		$s = self::get();
		return array(
			'enabled'       => (bool) $s['enabled'],
			'languages'     => array_values( array_map( 'strval', $s['languages'] ) ),
			'max_hashtags'  => (int) $s['max_hashtags'],
			'link_hint'     => (string) $s['link_hint'],
			'caption_style' => (string) $s['caption_style'],
		);
	}

	// ------------------------------------------------------------------ bio link

	/**
	 * /ig-latest/ (the bio link slug) on any site: a temporary redirect to that site's newest
	 * published AO Quill post, or the site's home page when it has none.
	 *
	 * @param WP $wp Request.
	 */
	public static function bio_redirect( $wp ) {
		$slug = trim( (string) self::get()['bio_slug'], '/' );
		if ( '' === $slug || trim( (string) $wp->request, '/' ) !== $slug ) {
			return;
		}
		$target = self::latest_url();
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		wp_safe_redirect( $target ? $target : home_url( '/' ), 302, 'AO Quill' );
		exit;
	}

	/**
	 * Permalink of the newest published AO Quill post on the current site.
	 *
	 * @return string|null
	 */
	public static function latest_url() {
		$q = new WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => 1,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'meta_key'            => AOQ_Marking::META_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'          => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'              => 'ids',
			)
		);
		return $q->posts ? get_permalink( (int) $q->posts[0] ) : null;
	}

	// ------------------------------------------------------------------ connection

	/**
	 * Redirect URI to register in the Meta app.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return get_admin_url( get_main_site_id(), 'admin-post.php?action=aoq_ig_oauth' );
	}

	/**
	 * Whether Connect Instagram can open (Meta app ID, secret and Unified Login config ID).
	 *
	 * @return bool
	 */
	public static function can_connect() {
		$s = self::get();
		return '' !== $s['app_id'] && '' !== $s['app_secret'] && '' !== $s['config_id'];
	}

	/**
	 * Unified Login authorization URL ("Connect Instagram").
	 *
	 * @return string
	 */
	public static function authorize_url() {
		$s     = self::get();
		$state = wp_generate_password( 24, false );
		set_site_transient( 'aoq_ig_state_' . get_current_user_id(), $state, 15 * MINUTE_IN_SECONDS );
		return 'https://www.facebook.com/dialog/oauth?' . http_build_query(
			array(
				'client_id'     => $s['app_id'],
				'config_id'     => $s['config_id'],
				'redirect_uri'  => self::redirect_uri(),
				'response_type' => 'code',
				'prompt'        => 'login',
				'state'         => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * OAuth callback: code to a Business Integration System User token, then the IG account.
	 */
	public static function oauth_callback() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ao-quill' ) );
		}
		$back  = network_admin_url( 'settings.php?page=' . AOQ_Admin::PAGE . '&tab=instagram' );
		$state = get_site_transient( 'aoq_ig_state_' . get_current_user_id() );
		delete_site_transient( 'aoq_ig_state_' . get_current_user_id() );
		$got = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- OAuth state is the check.
		if ( ! $state || ! hash_equals( (string) $state, $got ) ) {
			self::notice( __( 'Instagram connection failed: the request expired or did not come from this page. Try again.', 'ao-quill' ) );
			wp_safe_redirect( $back );
			exit;
		}
		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$why = sanitize_text_field( wp_unslash( $_GET['error_description'] ?? $_GET['error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			self::notice( __( 'Instagram connection was not allowed: ', 'ao-quill' ) . $why );
			wp_safe_redirect( $back );
			exit;
		}
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$s    = self::get();
		$res  = wp_remote_get(
			self::API . '/oauth/access_token?' . http_build_query(
				array(
					'client_id'     => $s['app_id'],
					'client_secret' => $s['app_secret'],
					'redirect_uri'  => self::redirect_uri(),
					'code'          => preg_replace( '/#_$/', '', $code ),
				)
			),
			array( 'timeout' => 30 )
		);
		$data = self::json( $res );
		if ( empty( $data['access_token'] ) ) {
			self::notice( __( 'Instagram connection failed: ', 'ao-quill' ) . self::error_text( $data, $res ) );
			wp_safe_redirect( $back );
			exit;
		}
		$expires_in = isset( $data['expires_in'] ) ? (int) $data['expires_in'] : null;
		$ok         = self::store_token( (string) $data['access_token'], $expires_in );
		self::notice( true === $ok ? __( 'Instagram connected.', 'ao-quill' ) : __( 'Instagram connection failed: ', 'ao-quill' ) . $ok );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Store a Unified Login token and resolve exactly one assigned Instagram account.
	 *
	 * @param string   $token      Access token.
	 * @param int|null $expires_in Seconds until expiry from Meta, or null/0 when non-expiring.
	 * @return true|string True, or why it failed.
	 */
	public static function store_token( $token, $expires_in = null ) {
		$res  = wp_remote_get(
			self::API . '/me/assigned_instagram_accounts?' . http_build_query(
				array(
					'fields'       => 'id,username',
					'access_token' => $token,
				)
			),
			array( 'timeout' => 30 )
		);
		$data = self::json( $res );
		if ( ! empty( $data['error'] ) || is_wp_error( $res ) ) {
			return self::error_text( $data, $res );
		}
		$accounts = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		if ( ! $accounts ) {
			return __( 'No Instagram account was shared. Share exactly one professional account with the app.', 'ao-quill' );
		}
		if ( count( $accounts ) > 1 ) {
			$names = array();
			foreach ( $accounts as $a ) {
				$names[] = '@' . ( $a['username'] ?? $a['id'] ?? '?' );
			}
			return __( 'Share exactly one Instagram account (not several): ', 'ao-quill' ) . implode( ', ', $names );
		}
		$account = $accounts[0];
		if ( empty( $account['id'] ) ) {
			return __( 'Meta did not return an Instagram account ID.', 'ao-quill' );
		}
		$expires = ( null !== $expires_in && (int) $expires_in > 0 ) ? time() + (int) $expires_in : 0;
		self::save(
			array(
				'token'         => $token,
				'token_expires' => $expires,
				'token_set_at'  => time(),
				'user_id'       => (string) $account['id'],
				'username'      => (string) ( $account['username'] ?? '' ),
			)
		);
		return true;
	}

	/**
	 * Forget the token and account.
	 */
	public static function disconnect() {
		self::save(
			array(
				'token'         => '',
				'token_expires' => 0,
				'token_set_at'  => 0,
				'user_id'       => '',
				'username'      => '',
			)
		);
	}

	/**
	 * Daily: when the token has an expiry and ends within 20 days, try to extend it.
	 * Non-expiring Unified Login tokens (token_expires = 0) are left alone.
	 */
	public static function refresh_token() {
		$s = self::get();
		if ( '' === $s['token'] || (int) $s['token_expires'] <= 0 ) {
			return;
		}
		if ( time() - (int) $s['token_set_at'] < DAY_IN_SECONDS || (int) $s['token_expires'] - time() > 20 * DAY_IN_SECONDS ) {
			return;
		}
		$res  = wp_remote_get(
			self::API . '/oauth/access_token?' . http_build_query(
				array(
					'grant_type'        => 'fb_exchange_token',
					'client_id'         => $s['app_id'],
					'client_secret'     => $s['app_secret'],
					'fb_exchange_token' => $s['token'],
				)
			),
			array( 'timeout' => 30 )
		);
		$data = self::json( $res );
		if ( ! empty( $data['access_token'] ) ) {
			$expires = ! empty( $data['expires_in'] ) ? time() + (int) $data['expires_in'] : (int) $s['token_expires'];
			self::save(
				array(
					'token'         => (string) $data['access_token'],
					'token_expires' => $expires,
					'token_set_at'  => time(),
				)
			);
		} else {
			self::log( 0, 0, 'error', __( 'Could not refresh the Instagram token: ', 'ao-quill' ) . self::error_text( $data, $res ) . ' ' . __( 'Reconnect Instagram on the Instagram tab.', 'ao-quill' ) );
		}
	}

	// ------------------------------------------------------------------ posting

	/**
	 * When an AO Quill post goes live, queue it for Instagram (a minute later, off the request).
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public static function on_status( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old || 'post' !== $post->post_type ) {
			return;
		}
		$s = self::get();
		if ( ! $s['enabled'] || ! $s['auto_post'] || ! AOQ_Marking::is_generated( $post->ID ) ) {
			return;
		}
		if ( ! in_array( self::post_language(), $s['languages'], true ) ) {
			return;
		}
		if ( get_post_meta( $post->ID, self::META_MEDIA, true ) || get_post_meta( $post->ID, self::META_QUEUED, true ) ) {
			return;
		}
		update_post_meta( $post->ID, self::META_QUEUED, time() );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::POST_HOOK, array( get_current_blog_id(), (int) $post->ID ) );
	}

	/**
	 * The Instagram link (permalink) of a published media item, asked up to three times.
	 *
	 * @param string $media Media ID.
	 * @return string URL or empty.
	 */
	private static function fetch_link( $media ) {
		for ( $i = 0; $i < 3; $i++ ) {
			$info = self::api( 'GET', '/' . $media, array( 'fields' => 'permalink' ) );
			$link = esc_url_raw( (string) ( $info['permalink'] ?? '' ) );
			if ( '' !== $link ) {
				return $link;
			}
			sleep( 2 );
		}
		return '';
	}

	/**
	 * Cron: fetch a link Instagram did not give at posting time (up to 3 more tries, 5 minutes apart).
	 *
	 * @param int    $blog_id Site.
	 * @param int    $post_id Post.
	 * @param string $media   Media ID.
	 * @param int    $try     Attempt.
	 */
	public static function retry_link( $blog_id, $post_id, $media, $try ) {
		$link = self::fetch_link( (string) $media );
		switch_to_blog( (int) $blog_id );
		if ( '' !== $link ) {
			if ( (string) get_post_meta( $post_id, self::META_MEDIA, true ) === (string) $media ) {
				update_post_meta( $post_id, self::META_LINK, $link );
			}
			$all = (array) get_post_meta( $post_id, self::META_POSTS, true );
			foreach ( $all as &$p ) {
				if ( is_array( $p ) && (string) $p['media_id'] === (string) $media ) {
					$p['permalink'] = $link;
				}
			}
			unset( $p );
			update_post_meta( $post_id, self::META_POSTS, $all );
			self::log( $blog_id, $post_id, 'posted', $link );
			$job = self::job();
			if ( $job && (int) $job['blog_id'] === (int) $blog_id && (int) $job['post_id'] === (int) $post_id && '' === (string) $job['permalink'] ) {
				self::update_job( array( 'permalink' => $link ) ); // the Instagram generation tab shows it too
			}
		} elseif ( $try < 3 ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::LINK_HOOK, array( (int) $blog_id, (int) $post_id, (string) $media, $try + 1 ) );
		}
		restore_current_blog();
	}

	/**
	 * Instagram posts of a post on the current site, newest last (includes posts made before the
	 * list was kept).
	 *
	 * @param int $post_id Post.
	 * @return array<int, array{media_id:string, permalink:string, at:string}>
	 */
	public static function posts_of( $post_id ) {
		$all = array_values( array_filter( (array) get_post_meta( $post_id, self::META_POSTS, true ), 'is_array' ) );
		$one = (string) get_post_meta( $post_id, self::META_MEDIA, true );
		if ( ! $all && '' !== $one ) {
			$all[] = array(
				'media_id'  => $one,
				'permalink' => (string) get_post_meta( $post_id, self::META_LINK, true ),
				'at'        => '',
			);
		}
		return $all;
	}

	/**
	 * Posts list: an Instagram column.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function posts_column( $cols ) {
		$cols['aoq_instagram'] = __( 'Instagram', 'ao-quill' );
		return $cols;
	}

	/**
	 * Posts list cell: the Instagram link, or why posting failed.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Post.
	 */
	public static function posts_cell( $col, $post_id ) {
		if ( 'aoq_instagram' !== $col ) {
			return;
		}
		$posts = self::posts_of( $post_id );
		$last  = end( $posts );
		if ( $last ) {
			echo '' !== $last['permalink']
				? '<a href="' . esc_url( $last['permalink'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'ao-quill' ) . '</a>' . ( count( $posts ) > 1 ? ' <span class="description">(' . (int) count( $posts ) . ')</span>' : '' )
				: esc_html__( 'Posted', 'ao-quill' );
		} elseif ( get_post_meta( $post_id, self::META_QUEUED, true ) ) {
			echo esc_html__( 'Queued', 'ao-quill' );
		} elseif ( get_post_meta( $post_id, self::META_ERROR, true ) ) {
			echo '<span style="color:#d63638" title="' . esc_attr( (string) get_post_meta( $post_id, self::META_ERROR, true ) ) . '">' . esc_html__( 'Failed', 'ao-quill' ) . '</span>';
		} else {
			echo '<span aria-hidden="true">–</span>';
		}
	}

	/**
	 * Post editor: an Instagram box with the post's Instagram links.
	 */
	public static function meta_box() {
		add_meta_box( 'aoq-instagram', __( 'Instagram', 'ao-quill' ), array( __CLASS__, 'render_meta_box' ), 'post', 'side', 'low' );
	}

	/**
	 * Instagram box content.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_meta_box( $post ) {
		$posts = self::posts_of( $post->ID );
		if ( ! $posts ) {
			$err = (string) get_post_meta( $post->ID, self::META_ERROR, true );
			echo '<p>' . esc_html( $err ? __( 'Not on Instagram. Last attempt failed: ', 'ao-quill' ) . $err : __( 'Not on Instagram yet.', 'ao-quill' ) ) . '</p>';
			return;
		}
		echo '<ul style="margin:0">';
		foreach ( array_reverse( $posts ) as $p ) {
			$when = $p['at'] ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $p['at'] ) ), 'Y-m-d H:i' ) : '';
			echo '<li>' . ( '' !== $p['permalink'] ? '<a href="' . esc_url( $p['permalink'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View on Instagram', 'ao-quill' ) . '</a>' : esc_html__( 'Posted (link not available yet)', 'ao-quill' ) ) . ( $when ? ' <span class="description">' . esc_html( $when ) . '</span>' : '' ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * After a commit: queue the posts that went live at once (Publish mode). Posts saved for
	 * review are queued when an editor publishes them (on_status).
	 *
	 * @param array $result commit-post output.
	 */
	public static function queue_committed( array $result ) {
		if ( 'publish' !== ( $result['status'] ?? '' ) ) {
			return;
		}
		foreach ( (array) $result['posts'] as $p ) {
			switch_to_blog( (int) $p['blog_id'] );
			$post = get_post( (int) $p['post_id'] );
			if ( $post ) {
				self::on_status( 'publish', 'new', $post );
			}
			restore_current_blog();
		}
	}

	/**
	 * Cron: post a queued post.
	 *
	 * @param int $blog_id Site.
	 * @param int $post_id Post.
	 */
	public static function run_queued( $blog_id, $post_id ) {
		self::publish( (int) $blog_id, (int) $post_id );
	}

	/**
	 * Post one AO Quill post to Instagram: its featured image, cropped for Instagram, and its caption.
	 *
	 * @param int  $blog_id Site.
	 * @param int  $post_id Post.
	 * @param bool $again   Post even if it was posted before.
	 * @return true|string True, or why it failed.
	 */
	public static function publish( $blog_id, $post_id, $again = false ) {
		$s = self::get();
		switch_to_blog( $blog_id );
		try {
			delete_post_meta( $post_id, self::META_QUEUED );
			if ( ! self::connected() ) {
				return self::fail( $blog_id, $post_id, __( 'Instagram is not connected.', 'ao-quill' ) );
			}
			if ( 'publish' !== get_post_status( $post_id ) ) {
				return self::fail( $blog_id, $post_id, __( 'The post is not published.', 'ao-quill' ) );
			}
			if ( ! $again && get_post_meta( $post_id, self::META_MEDIA, true ) ) {
				return __( 'Already on Instagram.', 'ao-quill' );
			}
			$image = self::image_url( $post_id, $s['image_format'] );
			if ( ! is_string( $image ) || 0 !== strpos( $image, 'https://' ) ) {
				return self::fail( $blog_id, $post_id, is_wp_error( $image ) ? $image->get_error_message() : __( 'The post has no featured image Instagram can load.', 'ao-quill' ) );
			}
			$caption = self::caption( $post_id );
			$made    = self::api( 'POST', '/' . $s['user_id'] . '/media', array( 'image_url' => $image, 'caption' => $caption ) );
			if ( empty( $made['id'] ) ) {
				return self::fail( $blog_id, $post_id, self::error_text( $made ) );
			}
			// Instagram fetches the image before the post can be published.
			$status = '';
			for ( $i = 0; $i < 20; $i++ ) {
				$c      = self::api( 'GET', '/' . $made['id'], array( 'fields' => 'status_code' ) );
				$status = (string) ( $c['status_code'] ?? '' );
				if ( 'FINISHED' === $status || 'ERROR' === $status || 'EXPIRED' === $status ) {
					break;
				}
				sleep( 3 );
			}
			if ( 'FINISHED' !== $status ) {
				/* translators: %s: Instagram container status */
				return self::fail( $blog_id, $post_id, sprintf( __( 'Instagram could not prepare the image (status %s).', 'ao-quill' ), $status ? $status : 'unknown' ) );
			}
			$done = self::api( 'POST', '/' . $s['user_id'] . '/media_publish', array( 'creation_id' => $made['id'] ) );
			if ( empty( $done['id'] ) ) {
				return self::fail( $blog_id, $post_id, self::error_text( $done ) );
			}
			$media = (string) $done['id'];
			$link  = self::fetch_link( $media );
			update_post_meta( $post_id, self::META_MEDIA, $media );
			update_post_meta( $post_id, self::META_LINK, $link );
			delete_post_meta( $post_id, self::META_ERROR );
			// Every Instagram post of this post is kept (Post again adds one, it never replaces).
			$all   = (array) get_post_meta( $post_id, self::META_POSTS, true );
			$all[] = array(
				'media_id'  => $media,
				'permalink' => $link,
				'at'        => gmdate( 'c' ),
			);
			update_post_meta( $post_id, self::META_POSTS, array_values( array_filter( $all, 'is_array' ) ) );
			if ( '' === $link ) {
				// Instagram did not give the link yet: ask again in a few minutes.
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::LINK_HOOK, array( (int) $blog_id, (int) $post_id, $media, 1 ) );
			}
			self::log( $blog_id, $post_id, 'posted', '' !== $link ? $link : __( 'Posted; the link is fetched again shortly.', 'ao-quill' ) );
			return true;
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * The caption: the worker's caption and hashtags, else title, excerpt and the bio link hint;
	 * then the fixed hashtags. At most max_hashtags (Instagram allows 30) and 2200 characters.
	 *
	 * @param int $post_id Post on the current site.
	 * @return string
	 */
	public static function caption( $post_id ) {
		$s    = self::get();
		$text = trim( (string) get_post_meta( $post_id, self::META_CAPTION, true ) );
		if ( '' === $text ) {
			$text = trim( get_the_title( $post_id ) . "\n\n" . wp_strip_all_tags( get_the_excerpt( $post_id ) ) . "\n\n" . $s['link_hint'] );
		}
		$tags = array();
		$all  = array_merge( (array) get_post_meta( $post_id, self::META_HASHTAGS, true ), preg_split( '/[\s,#]+/', (string) $s['fixed_hashtags'] ) );
		foreach ( $all as $tag ) {
			$tag = preg_replace( '/[^\p{L}\p{N}_]/u', '', (string) $tag );
			if ( '' !== $tag && ! in_array( mb_strtolower( $tag ), array_map( 'mb_strtolower', $tags ), true ) ) {
				$tags[] = $tag;
			}
		}
		$tags = array_slice( $tags, 0, max( 0, min( 30, (int) $s['max_hashtags'] ) ) );
		$out  = $text . ( $tags ? "\n\n#" . implode( ' #', $tags ) : '' );
		return mb_substr( $out, 0, 2200 );
	}

	/**
	 * Public URL of the post's featured image cropped for Instagram (4:5 portrait or square,
	 * 1080 px wide, JPEG), made once and kept in uploads/aoq-instagram/.
	 *
	 * @param int    $post_id Post on the current site.
	 * @param string $format  portrait or square.
	 * @return string|WP_Error|null
	 */
	public static function image_url( $post_id, $format ) {
		$att = (int) get_post_thumbnail_id( $post_id );
		if ( ! $att ) {
			return null;
		}
		$file = get_attached_file( $att );
		if ( ! $file || ! is_readable( $file ) ) {
			return null;
		}
		$dir  = wp_upload_dir();
		$name = 'aoq-instagram/' . get_current_blog_id() . '-' . $post_id . '-' . $format . '.jpg';
		$path = trailingslashit( $dir['basedir'] ) . $name;
		if ( ! file_exists( $path ) ) {
			$editor = wp_get_image_editor( $file );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}
			$size   = $editor->get_size();
			$ratio  = 'square' === $format ? 1.0 : 0.8; // width / height
			$w      = (int) $size['width'];
			$h      = (int) $size['height'];
			$crop_w = min( $w, (int) round( $h * $ratio ) );
			$crop_h = min( $h, (int) round( $crop_w / $ratio ) );
			$out_h  = (int) round( 1080 / $ratio );
			$done   = $editor->crop( (int) ( ( $w - $crop_w ) / 2 ), (int) ( ( $h - $crop_h ) / 2 ), $crop_w, $crop_h, 1080, $out_h );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
			$editor->set_quality( 90 );
			wp_mkdir_p( dirname( $path ) );
			$saved = $editor->save( $path, 'image/jpeg' );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}
		return set_url_scheme( trailingslashit( $dir['baseurl'] ) . $name, 'https' );
	}

	/**
	 * Language code of the current site.
	 *
	 * @return string
	 */
	private static function post_language() {
		foreach ( AOQ_Plugin::translation_adapter()->languages() as $lang ) {
			if ( (int) $lang['blog_id'] === get_current_blog_id() ) {
				return (string) $lang['code'];
			}
		}
		return '';
	}

	/**
	 * Recent AO Quill posts on the sites that post to Instagram, newest first, with their status.
	 *
	 * @param int $limit How many.
	 * @return array
	 */
	public static function recent_posts( $limit = 10 ) {
		$s   = self::get();
		$out = array();
		foreach ( AOQ_Plugin::translation_adapter()->languages() as $lang ) {
			if ( ! in_array( $lang['code'], $s['languages'], true ) ) {
				continue;
			}
			switch_to_blog( (int) $lang['blog_id'] );
			$q = new WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => array( 'publish', 'pending' ),
					'posts_per_page' => $limit,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
					'meta_key'       => AOQ_Marking::META_FLAG, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			foreach ( $q->posts as $p ) {
				$out[] = array(
					'blog_id'   => (int) $lang['blog_id'],
					'language'  => $lang['code'],
					'id'        => (int) $p->ID,
					'title'     => get_the_title( $p ),
					'status'    => $p->post_status,
					'date'      => $p->post_date_gmt,
					'edit'      => get_edit_post_link( $p->ID, 'raw' ),
					'view'      => get_permalink( $p ),
					'ig'        => (string) get_post_meta( $p->ID, self::META_LINK, true ),
					'ig_media'  => (string) get_post_meta( $p->ID, self::META_MEDIA, true ),
					'error'     => (string) get_post_meta( $p->ID, self::META_ERROR, true ),
					'queued'    => (bool) get_post_meta( $p->ID, self::META_QUEUED, true ),
					'caption'   => self::caption( $p->ID ),
					'generated' => '' !== (string) get_post_meta( $p->ID, self::META_CAPTION, true ),
				);
			}
			restore_current_blog();
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);
		return array_slice( $out, 0, $limit );
	}

	// ------------------------------------------------------------------ caption jobs (D30)

	/**
	 * The current or last caption job.
	 *
	 * @return array|null
	 */
	public static function job() {
		$job = get_site_option( self::JOB_OPTION, null );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Whether a job is waiting or in progress.
	 *
	 * @return bool
	 */
	public static function job_active() {
		$job = self::job();
		return $job && in_array( $job['status'], array( 'queued', 'running', 'posting' ), true );
	}

	/**
	 * Ask the worker for a caption for one post: test (caption and image preview) or post.
	 *
	 * @param int    $blog_id Site.
	 * @param int    $post_id Post.
	 * @param string $mode    test or post.
	 * @return true|WP_Error
	 */
	public static function request_job( $blog_id, $post_id, $mode ) {
		if ( self::job_active() ) {
			return new WP_Error( 'aoq_ig_busy', __( 'An Instagram generation is already waiting or in progress.', 'ao-quill' ) );
		}
		if ( 'post' === $mode && ! self::connected() ) {
			return new WP_Error( 'aoq_ig_not_connected', __( 'Connect Instagram first, or use Test run.', 'ao-quill' ) );
		}
		switch_to_blog( $blog_id );
		$post  = get_post( $post_id );
		$thumb = $post ? (int) get_post_thumbnail_id( $post_id ) : 0;
		$title = $post ? get_the_title( $post ) : '';
		restore_current_blog();
		if ( ! $post || 'post' !== $post->post_type ) {
			return new WP_Error( 'aoq_ig_no_post', __( 'That post does not exist.', 'ao-quill' ) );
		}
		if ( ! $thumb ) {
			return new WP_Error( 'aoq_ig_no_image', __( 'That post has no featured image, which Instagram needs.', 'ao-quill' ) );
		}
		if ( 'post' === $mode && 'publish' !== $post->post_status ) {
			return new WP_Error( 'aoq_ig_not_published', __( 'Only published posts can be posted; use Test run for others.', 'ao-quill' ) );
		}
		update_site_option(
			self::JOB_OPTION,
			array(
				'job_id'       => wp_generate_uuid4(),
				'blog_id'      => (int) $blog_id,
				'post_id'      => (int) $post_id,
				'title'        => $title,
				'mode'         => 'post' === $mode ? 'post' : 'test',
				'status'       => 'queued',
				'requested_at' => time(),
				'started_at'   => 0,
				'caption_at'   => 0,
				'image_at'     => 0,
				'finished_at'  => 0,
				'caption'      => '',
				'image'        => '',
				'permalink'    => '',
				'error'        => '',
				'user'         => get_current_user_id(),
			)
		);
		return true;
	}

	/**
	 * Update the job and keep finished ones in a short history.
	 *
	 * @param array $values Changes.
	 * @return array The job.
	 */
	private static function update_job( array $values ) {
		$job = array_merge( (array) self::job(), $values );
		update_site_option( self::JOB_OPTION, $job );
		if ( in_array( $job['status'], array( 'done', 'failed' ), true ) ) {
			$log = array_values(
				array_filter(
					(array) get_site_option( self::JOBS_LOG, array() ),
					static function ( $j ) use ( $job ) {
						return ( $j['job_id'] ?? '' ) !== $job['job_id'];
					}
				)
			);
			array_unshift( $log, $job );
			update_site_option( self::JOBS_LOG, array_slice( $log, 0, 15 ) );
		}
		return $job;
	}

	/**
	 * Claim the queued job for the worker (from claim-run), when no post run is active. A job
	 * still "running" at an idle check-in, or for over 15 minutes, lost its worker and fails.
	 *
	 * @param array $input claim-run input.
	 * @return array|null INSTAGRAM_JOB.
	 */
	public static function claim_job( array $input ) {
		$job = self::job();
		if ( ! $job ) {
			return null;
		}
		if ( 'running' === $job['status'] && ( ! empty( $input['idle'] ) || time() - (int) $job['started_at'] > 15 * MINUTE_IN_SECONDS ) ) {
			self::update_job(
				array(
					'status'      => 'failed',
					'finished_at' => time(),
					'error'       => __( 'The worker stopped before the caption was written.', 'ao-quill' ),
				)
			);
			return null;
		}
		if ( 'queued' !== $job['status'] || AOQ_Runs::current() ) {
			return null;
		}
		switch_to_blog( (int) $job['blog_id'] );
		$post = get_post( (int) $job['post_id'] );
		$data = $post ? array(
			'title'   => mb_substr( wp_strip_all_tags( get_the_title( $post ) ), 0, 300 ),
			'excerpt' => mb_substr( wp_strip_all_tags( get_the_excerpt( $post ) ), 0, 2000 ),
			'text'    => mb_substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) ) ), 0, 20000 ),
		) : null;
		restore_current_blog();
		if ( ! $data || '' === $data['title'] ) {
			self::update_job(
				array(
					'status'      => 'failed',
					'finished_at' => time(),
					'error'       => __( 'The post is gone or has no title.', 'ao-quill' ),
				)
			);
			return null;
		}
		self::update_job(
			array(
				'status'     => 'running',
				'started_at' => time(),
			)
		);
		return array_merge(
			array(
				'job_id'   => $job['job_id'],
				'mode'     => $job['mode'],
				'language' => self::language_of( (int) $job['blog_id'] ),
			),
			$data
		);
	}

	/**
	 * The worker's answer (report-instagram): store the caption on the post, make the Instagram
	 * image, and for a post job, post it (WP-Cron, at once).
	 *
	 * @param array $input report-instagram input.
	 * @return true|WP_Error
	 */
	public static function finish_job( array $input ) {
		$job = self::job();
		if ( ! $job || $job['job_id'] !== $input['job_id'] ) {
			return new WP_Error( 'aoq_ig_unknown_job', __( 'Unknown Instagram job.', 'ao-quill' ), array( 'status' => 404 ) );
		}
		if ( 'ok' !== $input['status'] || '' === trim( (string) ( $input['caption'] ?? '' ) ) ) {
			self::update_job(
				array(
					'status'      => 'failed',
					'finished_at' => time(),
					'error'       => (string) ( $input['error'] ?? __( 'No caption came back.', 'ao-quill' ) ),
				)
			);
			return true;
		}
		$s = self::get();
		self::update_job( array( 'caption_at' => time() ) );
		switch_to_blog( (int) $job['blog_id'] );
		update_post_meta( (int) $job['post_id'], self::META_CAPTION, sanitize_textarea_field( $input['caption'] ) );
		update_post_meta( (int) $job['post_id'], self::META_HASHTAGS, array_map( 'sanitize_text_field', (array) ( $input['hashtags'] ?? array() ) ) );
		$caption = self::caption( (int) $job['post_id'] );
		$image   = self::image_url( (int) $job['post_id'], $s['image_format'] );
		restore_current_blog();
		$values = array(
			'caption'  => $caption,
			'image'    => is_string( $image ) ? $image : '',
			'image_at' => time(),
		);
		if ( 'post' === $job['mode'] ) {
			self::update_job( $values + array( 'status' => 'posting' ) );
			wp_schedule_single_event( time(), self::JOB_HOOK );
			spawn_cron();
		} else {
			self::update_job(
				$values + array(
					'status'      => 'done',
					'finished_at' => time(),
				)
			);
		}
		return true;
	}

	/**
	 * Cron: post the job's post to Instagram.
	 */
	public static function post_job() {
		$job = self::job();
		if ( ! $job || 'posting' !== $job['status'] ) {
			return;
		}
		$ok = self::publish( (int) $job['blog_id'], (int) $job['post_id'], true );
		switch_to_blog( (int) $job['blog_id'] );
		$link = (string) get_post_meta( (int) $job['post_id'], self::META_LINK, true );
		restore_current_blog();
		self::update_job(
			array(
				'status'      => true === $ok ? 'done' : 'failed',
				'finished_at' => time(),
				'permalink'   => true === $ok ? $link : '',
				'error'       => true === $ok ? '' : (string) $ok,
			)
		);
	}

	/**
	 * Language code of a site.
	 *
	 * @param int $blog_id Site.
	 * @return string
	 */
	private static function language_of( $blog_id ) {
		foreach ( AOQ_Plugin::translation_adapter()->languages() as $lang ) {
			if ( (int) $lang['blog_id'] === (int) $blog_id ) {
				return (string) $lang['code'];
			}
		}
		return AOQ_Plugin::translation_adapter()->primary_language();
	}

	/**
	 * Published and pending posts (human and AO Quill) on the sites that post to Instagram, for
	 * the Instagram generation picker.
	 *
	 * @param int $limit Per site.
	 * @return array
	 */
	public static function pickable_posts( $limit = 60 ) {
		$s   = self::get();
		$out = array();
		foreach ( AOQ_Plugin::translation_adapter()->languages() as $lang ) {
			if ( ! in_array( $lang['code'], $s['languages'], true ) ) {
				continue;
			}
			switch_to_blog( (int) $lang['blog_id'] );
			$q = new WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => array( 'publish', 'pending' ),
					'posts_per_page' => $limit,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);
			foreach ( $q->posts as $p ) {
				$out[] = array(
					'blog_id'  => (int) $lang['blog_id'],
					'language' => $lang['code'],
					'id'       => (int) $p->ID,
					'title'    => get_the_title( $p ),
					'status'   => $p->post_status,
					'date'     => $p->post_date_gmt,
					'ao'       => AOQ_Marking::is_generated( $p->ID ),
					'on_ig'    => '' !== (string) get_post_meta( $p->ID, self::META_MEDIA, true ),
					'image'    => (bool) get_post_thumbnail_id( $p->ID ),
				);
			}
			restore_current_blog();
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);
		return $out;
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * Call the Instagram Graph API with the stored token.
	 *
	 * @param string $method GET or POST.
	 * @param string $path   Path after the version.
	 * @param array  $params Parameters.
	 * @return array Decoded response (with "error" on failure).
	 */
	private static function api( $method, $path, array $params ) {
		$params['access_token'] = self::get()['token'];
		$url                    = self::API . $path;
		$res                    = 'POST' === $method
			? wp_remote_post( $url, array( 'timeout' => 60, 'body' => $params ) )
			: wp_remote_get( $url . '?' . http_build_query( $params ), array( 'timeout' => 30 ) );
		$data = self::json( $res );
		if ( is_wp_error( $res ) ) {
			$data['error'] = array( 'message' => $res->get_error_message() );
		}
		return $data;
	}

	/**
	 * Decode a JSON response body.
	 *
	 * @param array|WP_Error $res Response.
	 * @return array
	 */
	private static function json( $res ) {
		if ( is_wp_error( $res ) ) {
			return array( 'error' => array( 'message' => $res->get_error_message() ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $data ) ? $data : array( 'error' => array( 'message' => 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
	}

	/**
	 * Readable error from an Instagram response.
	 *
	 * @param array               $data Decoded response.
	 * @param array|WP_Error|null $res  Raw response.
	 * @return string
	 */
	private static function error_text( array $data, $res = null ) {
		if ( isset( $data['error']['message'] ) ) {
			return (string) $data['error']['message'];
		}
		if ( isset( $data['error_message'] ) ) {
			return (string) $data['error_message'];
		}
		return $res && ! is_wp_error( $res ) ? 'HTTP ' . wp_remote_retrieve_response_code( $res ) : __( 'No answer from Instagram.', 'ao-quill' );
	}

	/**
	 * Record a failure on the post and in the log.
	 *
	 * @param int    $blog_id Site.
	 * @param int    $post_id Post (on the current site).
	 * @param string $why     Reason.
	 * @return string The reason.
	 */
	private static function fail( $blog_id, $post_id, $why ) {
		update_post_meta( $post_id, self::META_ERROR, $why );
		self::log( $blog_id, $post_id, 'error', $why );
		return $why;
	}

	/**
	 * Add a log entry (newest first).
	 *
	 * @param int    $blog_id Site.
	 * @param int    $post_id Post.
	 * @param string $kind    posted or error.
	 * @param string $text    Permalink or reason.
	 */
	public static function log( $blog_id, $post_id, $kind, $text ) {
		$log = (array) get_site_option( self::LOG_OPTION, array() );
		array_unshift(
			$log,
			array(
				'at'      => gmdate( 'c' ),
				'blog_id' => (int) $blog_id,
				'post_id' => (int) $post_id,
				'title'   => $post_id ? get_the_title( $post_id ) : '',
				'kind'    => $kind,
				'text'    => $text,
			)
		);
		update_site_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ) );
	}

	/**
	 * Admin notice for the next page load.
	 *
	 * @param string $text Notice.
	 */
	private static function notice( $text ) {
		$key   = 'aoq_notices_' . get_current_user_id();
		$queue = (array) get_site_transient( $key );
		$queue[] = $text;
		set_site_transient( $key, array_filter( $queue ), 5 * MINUTE_IN_SECONDS );
	}
}
