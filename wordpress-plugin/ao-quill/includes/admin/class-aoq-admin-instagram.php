<?php
/**
 * Instagram tab (D29): connection, settings, bio links and recent posts.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Admin_Instagram {

	/**
	 * Register form handlers.
	 */
	public static function init() {
		add_action( 'wp_ajax_aoq_ig_job', array( __CLASS__, 'ajax_job' ) );
		foreach ( array( 'aoq_ig_save' => 'handle_save', 'aoq_ig_disconnect' => 'handle_disconnect', 'aoq_ig_post' => 'handle_post', 'aoq_ig_generate' => 'handle_generate' ) as $action => $method ) {
			add_action( 'network_admin_edit_' . $action, array( __CLASS__, $method ) );
			add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
		}
	}

	/**
	 * Tab URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	private static function url( array $args = array() ) {
		$base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
		return add_query_arg( array_merge( array( 'page' => AOQ_Admin::PAGE, 'tab' => 'instagram' ), $args ), $base );
	}

	/**
	 * Form action URL.
	 *
	 * @param string $action Action.
	 * @return string
	 */
	private static function action_url( $action ) {
		return is_multisite() ? network_admin_url( 'edit.php?action=' . $action ) : admin_url( 'admin-post.php?action=' . $action );
	}

	/**
	 * Stop unless the user may manage the plugin.
	 *
	 * @param string $nonce Nonce action.
	 */
	private static function guard( $nonce ) {
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ao-quill' ) );
		}
		check_admin_referer( $nonce );
	}

	/**
	 * Queue notices for the next page load.
	 *
	 * @param string[] $texts Notices.
	 */
	private static function notices( array $texts ) {
		set_site_transient( 'aoq_notices_' . get_current_user_id(), array_values( array_filter( $texts ) ), 60 );
	}

	/**
	 * Save settings; a pasted access token replaces the connection.
	 */
	public static function handle_save() {
		self::guard( 'aoq_ig_save' );
		$in    = isset( $_POST['ig'] ) && is_array( $_POST['ig'] ) ? wp_unslash( $_POST['ig'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$codes = array();
		foreach ( AOQ_Plugin::translation_adapter()->languages() as $lang ) {
			$codes[] = $lang['code'];
		}
		$langs = array_values( array_intersect( array_unique( $codes ), array_map( 'sanitize_key', (array) ( $in['languages'] ?? array() ) ) ) );
		$slug  = sanitize_title( (string) ( $in['bio_slug'] ?? '' ) );
		$vals  = array(
			'enabled'        => ! empty( $in['enabled'] ),
			'auto_post'      => ! empty( $in['auto_post'] ),
			'languages'      => $langs,
			'app_id'         => preg_replace( '/[^0-9]/', '', (string) ( $in['app_id'] ?? '' ) ),
			'config_id'      => preg_replace( '/[^0-9]/', '', (string) ( $in['config_id'] ?? '' ) ),
			'bio_slug'       => '' !== $slug ? $slug : 'ig-latest',
			'image_format'   => 'square' === ( $in['image_format'] ?? '' ) ? 'square' : 'portrait',
			'fixed_hashtags' => sanitize_text_field( (string) ( $in['fixed_hashtags'] ?? '' ) ),
			'max_hashtags'   => max( 0, min( 30, (int) ( $in['max_hashtags'] ?? 15 ) ) ),
			'link_hint'      => sanitize_text_field( (string) ( $in['link_hint'] ?? '' ) ),
			'caption_style'  => sanitize_textarea_field( (string) ( $in['caption_style'] ?? '' ) ),
		);
		$secret = trim( (string) ( $in['app_secret'] ?? '' ) );
		if ( '' !== $secret ) {
			$vals['app_secret'] = sanitize_text_field( $secret ); // write-only: never printed back
		}
		AOQ_Instagram::save( $vals );
		$notes = array( __( 'Instagram settings saved.', 'ao-quill' ) );
		$token = trim( (string) ( $in['token'] ?? '' ) );
		if ( '' !== $token ) {
			$ok      = AOQ_Instagram::store_token( sanitize_text_field( $token ) );
			$notes[] = true === $ok ? __( 'Instagram connected with the pasted token.', 'ao-quill' ) : __( 'The pasted token did not work: ', 'ao-quill' ) . $ok;
		}
		if ( $vals['enabled'] && ! AOQ_Instagram::connected() ) {
			$notes[] = __( 'Instagram posting is on but no account is connected yet.', 'ao-quill' );
		}
		self::notices( $notes );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Forget the connected account.
	 */
	public static function handle_disconnect() {
		self::guard( 'aoq_ig_disconnect' );
		AOQ_Instagram::disconnect();
		self::notices( array( __( 'Instagram disconnected.', 'ao-quill' ) ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Post one AO Quill post to Instagram now (or again).
	 */
	public static function handle_post() {
		self::guard( 'aoq_ig_post' );
		$blog = isset( $_POST['blog_id'] ) ? (int) $_POST['blog_id'] : 0;
		$post = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$ok   = AOQ_Instagram::publish( $blog, $post, ! empty( $_POST['again'] ) );
		self::notices( array( true === $ok ? __( 'Posted to Instagram.', 'ao-quill' ) : __( 'Not posted to Instagram: ', 'ao-quill' ) . $ok ) );
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Help icon with a hover description.
	 *
	 * @param string $text Description.
	 * @return string
	 */
	private static function help( $text ) {
		return ' <span class="dashicons dashicons-editor-help aoq-help" tabindex="0" role="img" aria-label="' . esc_attr( $text ) . '" data-tip="' . esc_attr( $text ) . '"></span>';
	}

	/**
	 * Render the tab.
	 */
	public static function render() {
		$s       = AOQ_Instagram::get();
		$adapter = AOQ_Plugin::translation_adapter();
		echo '<style>
.aoq-help{color:#8c8f94;cursor:help;position:relative;vertical-align:middle;font-size:17px;width:17px;height:17px}
.aoq-help:hover,.aoq-help:focus{color:#2271b1;outline:none}
.aoq-help:hover::after,.aoq-help:focus::after{content:attr(data-tip);position:absolute;left:24px;top:-6px;z-index:100;width:300px;padding:8px 10px;background:#1d2327;color:#f0f0f1;font:13px/1.45 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;border-radius:3px;box-shadow:0 2px 8px rgba(0,0,0,.25);white-space:normal;text-align:left;font-weight:400}
.aoq-ig-box{background:#fff;border:1px solid #c3c4c7;padding:12px 16px;max-width:900px;margin:12px 0}
.aoq-ig-cap{white-space:pre-wrap;max-width:420px;font-size:12px;color:#50575e;max-height:7.5em;overflow:auto}
</style>';

		// Connection.
		echo '<h2>' . esc_html__( 'Instagram account', 'ao-quill' ) . '</h2><div class="aoq-ig-box">';
		if ( AOQ_Instagram::connected() ) {
			echo '<p><span class="dashicons dashicons-yes-alt" style="color:#00a32a"></span> ';
			if ( (int) $s['token_expires'] > 0 ) {
				$days = (int) floor( ( (int) $s['token_expires'] - time() ) / DAY_IN_SECONDS );
				/* translators: 1: Instagram username, 2: days */
				echo esc_html( sprintf( __( 'Connected as @%1$s. The access token is valid for %2$d more days and is renewed automatically when it can be.', 'ao-quill' ), $s['username'] ? $s['username'] : $s['user_id'], max( 0, $days ) ) ) . '</p>';
			} else {
				/* translators: %s: Instagram username */
				echo esc_html( sprintf( __( 'Connected as @%s.', 'ao-quill' ), $s['username'] ? $s['username'] : $s['user_id'] ) ) . '</p>';
			}
			echo '<form method="post" action="' . esc_url( self::action_url( 'aoq_ig_disconnect' ) ) . '" onsubmit="return confirm(this.dataset.q)" data-q="' . esc_attr__( 'Disconnect Instagram?', 'ao-quill' ) . '">';
			wp_nonce_field( 'aoq_ig_disconnect' );
			submit_button( __( 'Disconnect', 'ao-quill' ), 'secondary', 'submit', false );
			echo '</form>';
		} else {
			echo '<p><span class="dashicons dashicons-warning" style="color:#dba617"></span> ' . esc_html__( 'Not connected.', 'ao-quill' ) . '</p>';
			if ( AOQ_Instagram::can_connect() ) {
				echo '<p><a class="button button-primary" href="' . esc_url( AOQ_Instagram::authorize_url() ) . '">' . esc_html__( 'Connect Instagram', 'ao-quill' ) . '</a> ';
				echo '<span class="description">' . esc_html__( 'Opens Meta login so you can share the Instagram account AO Quill will publish to.', 'ao-quill' ) . '</span></p>';
			} else {
				echo '<p class="description">' . esc_html__( 'Enter the Meta app ID, app secret and configuration ID below and save; then a Connect Instagram button appears. Or paste an access token.', 'ao-quill' ) . '</p>';
			}
		}
		echo '<details style="margin-top:8px"><summary>' . esc_html__( 'How to set up the Meta app', 'ao-quill' ) . '</summary><ol>';
		echo '<li>' . esc_html__( 'The Instagram account must be a professional account (Business or Creator).', 'ao-quill' ) . '</li>';
		echo '<li>' . esc_html__( 'In Meta for Developers, create a Business app and add the Instagram use case "Manage messaging & content on Instagram", then open API setup with Unified login.', 'ao-quill' ) . '</li>';
		echo '<li>' . esc_html__( 'In the Unified login configuration, allow Instagram accounts and the permissions instagram_basic and instagram_content_publish. Add this redirect URL:', 'ao-quill' ) . ' <code>' . esc_html( AOQ_Instagram::redirect_uri() ) . '</code></li>';
		echo '<li>' . esc_html__( 'Copy the Meta App ID and App secret from App settings → Basic, and the configuration ID from the Unified login setup (config_id in the Login URL), into the fields below and save.', 'ao-quill' ) . '</li>';
		echo '<li>' . esc_html__( 'While the app is in development mode, add the Instagram account under App roles as an Instagram tester and accept the invite in Instagram.', 'ao-quill' ) . '</li>';
		echo '<li>' . esc_html__( 'Press Connect Instagram and share exactly one Instagram account.', 'ao-quill' ) . '</li>';
		echo '</ol></details></div>';

		// Settings.
		echo '<form method="post" action="' . esc_url( self::action_url( 'aoq_ig_save' ) ) . '">';
		wp_nonce_field( 'aoq_ig_save' );
		echo '<h2>' . esc_html__( 'Settings', 'ao-quill' ) . '</h2><table class="form-table">';
		echo '<tr><th>' . esc_html__( 'Post to Instagram', 'ao-quill' ) . self::help( __( 'When on, the worker writes an Instagram caption and hashtags for each post, and posts go to Instagram as set below.', 'ao-quill' ) ) . '</th><td><label><input type="checkbox" name="ig[enabled]" value="1"' . checked( $s['enabled'], true, false ) . '> ' . esc_html__( 'On', 'ao-quill' ) . '</label></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		echo '<tr><th>' . esc_html__( 'Post automatically', 'ao-quill' ) . self::help( __( 'Post to Instagram about a minute after an AO Quill post goes live: at once in Publish mode, or when an editor publishes a post saved for review. Off: post from the list below.', 'ao-quill' ) ) . '</th><td><label><input type="checkbox" name="ig[auto_post]" value="1"' . checked( $s['auto_post'], true, false ) . '> ' . esc_html__( 'When a post goes live', 'ao-quill' ) . '</label></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		echo '<tr><th>' . esc_html__( 'Languages that post', 'ao-quill' ) . self::help( __( 'Posts in these languages go to the connected account. One account usually posts in one language.', 'ao-quill' ) ) . '</th><td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		$shown = array();
		foreach ( $adapter->languages() as $lang ) {
			if ( isset( $shown[ $lang['code'] ] ) ) {
				continue;
			}
			$shown[ $lang['code'] ] = true;
			echo '<label style="margin-right:16px"><input type="checkbox" name="ig[languages][]" value="' . esc_attr( $lang['code'] ) . '"' . checked( in_array( $lang['code'], $s['languages'], true ), true, false ) . '> ' . esc_html( $lang['name'] . ' (' . $lang['code'] . ')' ) . ( $lang['excluded'] ? ' <em>' . esc_html__( 'excluded from AO Quill', 'ao-quill' ) . '</em>' : '' ) . '</label>';
		}
		echo '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Meta app ID', 'ao-quill' ) . self::help( __( 'From Meta App settings → Basic. Used with Unified Login.', 'ao-quill' ) ) . '</th><td><input type="text" class="regular-text" name="ig[app_id]" value="' . esc_attr( $s['app_id'] ) . '" autocomplete="off"></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		echo '<tr><th>' . esc_html__( 'Meta app secret', 'ao-quill' ) . self::help( __( 'From Meta App settings → Basic. Stored and never shown again. Leave empty to keep the saved one.', 'ao-quill' ) ) . '</th><td><input type="password" class="regular-text" name="ig[app_secret]" value="" autocomplete="new-password" placeholder="' . esc_attr( '' !== $s['app_secret'] ? __( 'saved', 'ao-quill' ) : '' ) . '"></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		echo '<tr><th>' . esc_html__( 'Configuration ID', 'ao-quill' ) . self::help( __( 'From Instagram → API setup with Unified login (config_id in the Login URL Meta shows).', 'ao-quill' ) ) . '</th><td><input type="text" class="regular-text" name="ig[config_id]" value="' . esc_attr( $s['config_id'] ) . '" autocomplete="off"></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		echo '<tr><th>' . esc_html__( 'Or paste an access token', 'ao-quill' ) . self::help( __( 'Instead of Connect Instagram: a Unified Login access token from Meta (Generate access tokens → Add account). The token must cover exactly one Instagram account.', 'ao-quill' ) ) . '</th><td><input type="password" class="regular-text" name="ig[token]" value="" autocomplete="new-password"></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		echo '<tr><th>' . esc_html__( 'Image format', 'ao-quill' ) . self::help( __( 'The featured image is cropped from the centre to this shape, 1080 px wide, as a JPEG. Portrait takes the most room in the feed.', 'ao-quill' ) ) . '</th><td><select name="ig[image_format]"><option value="portrait"' . selected( $s['image_format'], 'portrait', false ) . '>' . esc_html__( 'Portrait 4:5 (1080 x 1350)', 'ao-quill' ) . '</option><option value="square"' . selected( $s['image_format'], 'square', false ) . '>' . esc_html__( 'Square 1:1 (1080 x 1080)', 'ao-quill' ) . '</option></select></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		echo '<tr><th>' . esc_html__( 'Caption style', 'ao-quill' ) . self::help( __( 'How the worker writes captions: tone, length, emoji. The caption starts with a hook, sums up the post and ends with the call to action.', 'ao-quill' ) ) . '</th><td><textarea name="ig[caption_style]" rows="2" class="large-text">' . esc_textarea( $s['caption_style'] ) . '</textarea></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		echo '<tr><th>' . esc_html__( 'Call to action', 'ao-quill' ) . self::help( __( 'The last line of every caption. Links in captions do not work on Instagram, so it points to the bio link.', 'ao-quill' ) ) . '</th><td><input type="text" class="large-text" name="ig[link_hint]" value="' . esc_attr( $s['link_hint'] ) . '"></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		echo '<tr><th>' . esc_html__( 'Hashtags on every post', 'ao-quill' ) . self::help( __( 'Added after the post\'s own hashtags, without #, separated by commas.', 'ao-quill' ) ) . '</th><td><input type="text" class="large-text" name="ig[fixed_hashtags]" value="' . esc_attr( $s['fixed_hashtags'] ) . '"></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		echo '<tr><th>' . esc_html__( 'Maximum hashtags', 'ao-quill' ) . self::help( __( 'Total hashtags per post, the post\'s own first. Instagram allows 30.', 'ao-quill' ) ) . '</th><td><input type="number" min="0" max="30" name="ig[max_hashtags]" value="' . esc_attr( $s['max_hashtags'] ) . '"></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		echo '<tr><th>' . esc_html__( 'Bio link path', 'ao-quill' ) . self::help( __( 'On every site this path redirects (temporarily, 302) to the newest published AO Quill post in that site\'s language. Put the main site\'s link in the Instagram bio.', 'ao-quill' ) ) . '</th><td><input type="text" name="ig[bio_slug]" value="' . esc_attr( $s['bio_slug'] ) . '"></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in help.
		echo '</table>';
		submit_button( __( 'Save Instagram settings', 'ao-quill' ) );
		echo '</form>';

		// Bio links.
		echo '<h2>' . esc_html__( 'Bio links', 'ao-quill' ) . '</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Site', 'ao-quill' ) . '</th><th>' . esc_html__( 'Bio link', 'ao-quill' ) . '</th><th>' . esc_html__( 'Goes to now', 'ao-quill' ) . '</th></tr></thead><tbody>';
		foreach ( $adapter->languages() as $lang ) {
			switch_to_blog( (int) $lang['blog_id'] );
			$link   = home_url( '/' . $s['bio_slug'] . '/' );
			$target = AOQ_Instagram::latest_url();
			restore_current_blog();
			echo '<tr><td>' . esc_html( $lang['name'] . ' (' . $lang['code'] . ')' ) . '</td><td><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener"><code>' . esc_html( $link ) . '</code></a></td><td>' . ( $target ? '<a href="' . esc_url( $target ) . '" target="_blank" rel="noopener">' . esc_html( $target ) . '</a>' : '<em>' . esc_html__( 'No AO Quill post yet: the home page', 'ao-quill' ) . '</em>' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// Recent posts.
		echo '<h2>' . esc_html__( 'Recent AO Quill posts', 'ao-quill' ) . '</h2>';
		$posts = AOQ_Instagram::recent_posts( 10 );
		if ( ! $posts ) {
			echo '<p>' . esc_html__( 'No AO Quill posts in the languages that post to Instagram yet.', 'ao-quill' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>' . esc_html__( 'Post', 'ao-quill' ) . '</th><th>' . esc_html__( 'Caption', 'ao-quill' ) . '</th><th>' . esc_html__( 'Instagram', 'ao-quill' ) . '</th></tr></thead><tbody>';
			foreach ( $posts as $p ) {
				echo '<tr><td><a href="' . esc_url( (string) $p['edit'] ) . '">' . esc_html( $p['title'] ) . '</a><br><small>' . esc_html( $p['language'] . ' · ' . ( 'publish' === $p['status'] ? __( 'published', 'ao-quill' ) : __( 'pending review', 'ao-quill' ) ) ) . '</small></td>';
				echo '<td><div class="aoq-ig-cap">' . esc_html( $p['caption'] ) . '</div>' . ( $p['generated'] ? '' : '<small><em>' . esc_html__( 'No worker caption: built from the title and excerpt.', 'ao-quill' ) . '</em></small>' ) . '</td><td>';
				if ( $p['ig'] ) {
					echo '<a href="' . esc_url( $p['ig'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View on Instagram', 'ao-quill' ) . '</a>';
				} elseif ( $p['queued'] ) {
					echo esc_html__( 'Queued', 'ao-quill' );
				} elseif ( $p['error'] ) {
					echo '<span style="color:#b32d2e">' . esc_html( $p['error'] ) . '</span>';
				} else {
					echo '<span class="description">' . esc_html__( 'Not posted', 'ao-quill' ) . '</span>';
				}
				if ( 'publish' === $p['status'] && AOQ_Instagram::connected() ) {
					$again = '' !== $p['ig_media'];
					echo '<form method="post" action="' . esc_url( self::action_url( 'aoq_ig_post' ) ) . '" style="margin-top:6px" onsubmit="return confirm(this.dataset.q)" data-q="' . esc_attr( $again ? __( 'Post this to Instagram again?', 'ao-quill' ) : __( 'Post this to Instagram now?', 'ao-quill' ) ) . '">';
					wp_nonce_field( 'aoq_ig_post' );
					echo '<input type="hidden" name="blog_id" value="' . esc_attr( $p['blog_id'] ) . '"><input type="hidden" name="post_id" value="' . esc_attr( $p['id'] ) . '">' . ( $again ? '<input type="hidden" name="again" value="1">' : '' );
					submit_button( $again ? __( 'Post again', 'ao-quill' ) : __( 'Post to Instagram', 'ao-quill' ), 'small', 'submit', false );
					echo '</form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// Log.
		$log = (array) get_site_option( AOQ_Instagram::LOG_OPTION, array() );
		if ( $log ) {
			echo '<h2>' . esc_html__( 'Instagram log', 'ao-quill' ) . '</h2><table class="widefat striped" style="max-width:1100px"><tbody>';
			foreach ( array_slice( $log, 0, 15 ) as $e ) {
				$text = 'posted' === $e['kind'] && $e['text'] ? '<a href="' . esc_url( $e['text'] ) . '" target="_blank" rel="noopener">' . esc_html( $e['text'] ) . '</a>' : esc_html( $e['text'] );
				echo '<tr><td style="white-space:nowrap">' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $e['at'] ) ), 'Y-m-d H:i' ) ) . '</td><td>' . esc_html( 'posted' === $e['kind'] ? __( 'Posted', 'ao-quill' ) : __( 'Error', 'ao-quill' ) ) . '</td><td>' . esc_html( $e['title'] ) . '</td><td>' . $text . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Start an Instagram generation (test or post) for the chosen post.
	 */
	public static function handle_generate() {
		self::guard( 'aoq_ig_generate' );
		$pick = isset( $_POST['pick'] ) ? sanitize_text_field( wp_unslash( $_POST['pick'] ) ) : '';
		$mode = isset( $_POST['mode'] ) && 'post' === $_POST['mode'] ? 'post' : 'test';
		list( $blog, $post ) = array_map( 'intval', array_pad( explode( ':', $pick ), 2, 0 ) );
		$ok = AOQ_Instagram::request_job( $blog, $post, $mode );
		if ( is_wp_error( $ok ) ) {
			self::notices( array( $ok->get_error_message() ) );
		}
		$base = is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'options-general.php' );
		wp_safe_redirect( add_query_arg( array( 'page' => AOQ_Admin::PAGE, 'tab' => 'ig_generation', 'pick' => $pick ), $base ) );
		exit;
	}

	/**
	 * The current job, for the tab's live panel.
	 */
	public static function ajax_job() {
		check_ajax_referer( 'aoq_ig_job' );
		if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( self::job_view( AOQ_Instagram::job() ) );
	}

	/**
	 * Steps of an Instagram generation: id => [title, what it does].
	 *
	 * @return array
	 */
	private static function step_definitions() {
		return array(
			'queue'   => array( __( 'Wait for the worker', 'ao-quill' ), __( 'The worker picks the job up at its next check-in (every minute), after any post generation in progress.', 'ao-quill' ) ),
			'caption' => array( __( 'Write the caption', 'ao-quill' ), __( 'The writer model writes a hook, a short summary from the post and the call to action, plus hashtags.', 'ao-quill' ) ),
			'image'   => array( __( 'Make the image', 'ao-quill' ), __( 'The featured image is cropped from the centre for Instagram, 1080 px wide, as a JPEG.', 'ao-quill' ) ),
			'post'    => array( __( 'Post to Instagram', 'ao-quill' ), __( 'Instagram loads the image and caption, then the post is published.', 'ao-quill' ) ),
		);
	}

	/**
	 * Average seconds per step from earlier jobs (for the estimate), with defaults.
	 *
	 * @return array<string, int>
	 */
	private static function step_averages() {
		$sum = array( 'queue' => array(), 'caption' => array(), 'image' => array(), 'post' => array() );
		foreach ( (array) get_site_option( AOQ_Instagram::JOBS_LOG, array() ) as $j ) {
			if ( ! empty( $j['started_at'] ) && ! empty( $j['requested_at'] ) ) {
				$sum['queue'][] = $j['started_at'] - $j['requested_at'];
			}
			if ( ! empty( $j['caption_at'] ) && ! empty( $j['started_at'] ) ) {
				$sum['caption'][] = $j['caption_at'] - $j['started_at'];
			}
			if ( ! empty( $j['image_at'] ) && ! empty( $j['caption_at'] ) ) {
				$sum['image'][] = $j['image_at'] - $j['caption_at'];
			}
			if ( 'post' === ( $j['mode'] ?? '' ) && 'done' === ( $j['status'] ?? '' ) && ! empty( $j['image_at'] ) ) {
				$sum['post'][] = $j['finished_at'] - $j['image_at'];
			}
		}
		// [average seconds or null without history, runs it is based on]
		$out = array();
		foreach ( $sum as $k => $list ) {
			$out[ $k ] = $list ? array( (int) round( array_sum( $list ) / count( $list ) ), count( $list ) ) : array( null, 0 );
		}
		return $out;
	}

	/**
	 * A job as the live panel shows it: card, steps with status and timings, and the result.
	 *
	 * @param array|null $job Job.
	 * @return array|null
	 */
	private static function job_view( $job ) {
		if ( ! $job ) {
			return null;
		}
		$job = array_merge( array( 'caption_at' => 0, 'image_at' => 0 ), $job );
		if ( ! $job['caption_at'] && '' !== (string) $job['caption'] ) {
			// Jobs from before step times were recorded: caption and image were done when it finished.
			$job['caption_at'] = $job['finished_at'] ? $job['finished_at'] : time();
			$job['image_at']   = $job['caption_at'];
		}
		$now    = time();
		$st     = $job['status'];
		$test   = 'post' !== $job['mode'];
		$failed = 'failed' === $st;
		$avg    = self::step_averages();
		// Each step: [start, end] times, from the job's own timestamps.
		$times = array(
			'queue'   => array( $job['requested_at'], $job['started_at'] ),
			'caption' => array( $job['started_at'], $job['caption_at'] ),
			'image'   => array( $job['caption_at'], $job['image_at'] ),
			'post'    => array( $job['image_at'], 'done' === $st ? $job['finished_at'] : 0 ),
		);
		$steps   = array();
		$failing = null;
		if ( $failed ) {
			$failing = ! $job['started_at'] ? 'queue' : ( ! $job['caption_at'] ? 'caption' : ( ! $job['image_at'] ? 'image' : 'post' ) );
		}
		foreach ( self::step_definitions() as $id => $def ) {
			list( $from, $to ) = $times[ $id ];
			if ( 'post' === $id && $test ) {
				$state = 'done' === $st ? 'skipped' : 'pending';
			} elseif ( $failing === $id ) {
				$state = 'failed';
			} elseif ( $to ) {
				$state = 'done';
			} elseif ( $from && ! $failed && in_array( $st, array( 'queued', 'running', 'posting' ), true ) ) {
				$state = 'running';
			} elseif ( 'queue' === $id && 'queued' === $st ) {
				$state = 'running';
			} else {
				$state = 'pending';
			}
			$end     = $to ? $to : ( 'failed' === $state ? (int) $job['finished_at'] : $now );
			$steps[] = array(
				'id'      => $id,
				'title'   => $def[0],
				'text'    => 'skipped' === $state ? __( 'Skipped: a test run posts nothing.', 'ao-quill' ) : $def[1],
				'status'  => $state,
				'seconds' => in_array( $state, array( 'done', 'running', 'failed' ), true ) && $from ? max( 0, $end - $from ) : null,
				'avg'     => $avg[ $id ][0],
				'runs'    => $avg[ $id ][1],
			);
		}
		$total = $test ? 3 : 4;
		$done  = count(
			array_filter(
				$steps,
				static function ( $s ) {
					return 'done' === $s['status'];
				}
			)
		);
		$titles = array(
			'queued'  => $test ? __( 'Instagram test run waiting for the worker', 'ao-quill' ) : __( 'Instagram generation waiting for the worker', 'ao-quill' ),
			'running' => $test ? __( 'Instagram test run in progress', 'ao-quill' ) : __( 'Instagram generation in progress', 'ao-quill' ),
			'posting' => __( 'Posting to Instagram', 'ao-quill' ),
			'done'    => $test ? __( 'Test run finished: nothing was posted', 'ao-quill' ) : __( 'Posted to Instagram', 'ao-quill' ),
			'failed'  => $test ? __( 'Instagram test run failed', 'ao-quill' ) : __( 'Instagram generation failed', 'ao-quill' ),
		);
		$left     = 0;
		$defaults = array( 'queue' => 40, 'caption' => 60, 'image' => 2, 'post' => 20 ); // estimate without history
		foreach ( $steps as $s ) {
			$a = null === $s['avg'] ? $defaults[ $s['id'] ] : $s['avg'];
			if ( 'pending' === $s['status'] && ! ( 'post' === $s['id'] && $test ) ) {
				$left += $a;
			} elseif ( 'running' === $s['status'] ) {
				$left += max( 0, $a - (int) $s['seconds'] );
			}
		}
		return array(
			'job_id'    => $job['job_id'],
			'status'    => $st,
			'heading'   => $titles[ $st ] ?? $st,
			'mode'      => $test ? __( 'Test run', 'ao-quill' ) : __( 'Generate and post', 'ao-quill' ),
			'title'     => html_entity_decode( (string) $job['title'], ENT_QUOTES, 'UTF-8' ),
			'active'    => in_array( $st, array( 'queued', 'running', 'posting' ), true ),
			'seconds'   => max( 0, ( (int) $job['finished_at'] ? (int) $job['finished_at'] : $now ) - (int) $job['requested_at'] ),
			'left'      => $left,
			'percent'   => 'done' === $st ? 100 : (int) floor( 100 * $done / $total ),
			'step_no'   => 'done' === $st ? $total : min( $total, $done + 1 ),
			'steps_n'   => $total,
			'steps'     => $steps,
			'caption'   => (string) $job['caption'],
			'image'     => (string) $job['image'],
			'permalink' => (string) $job['permalink'],
			'error'     => (string) $job['error'],
			'when'      => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $job['requested_at'] ), 'Y-m-d H:i' ),
		);
	}

	/**
	 * Instagram generation tab: pick a post, Test run or Generate and post, then follow it the way
	 * the Post generation tab follows a run (status card, progress bar, steps, results).
	 */
	public static function render_generation() {
		$s     = AOQ_Instagram::get();
		$posts = AOQ_Instagram::pickable_posts();
		$job   = AOQ_Instagram::job();
		$busy  = AOQ_Instagram::job_active();
		$pick  = isset( $_GET['pick'] ) ? sanitize_text_field( wp_unslash( $_GET['pick'] ) ) : ( $job ? $job['blog_id'] . ':' . $job['post_id'] : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<style>
.aoq-card{display:flex;gap:12px;align-items:center;background:#fff;border:1px solid #c3c4c7;border-left:4px solid #72aee6;padding:12px 16px;margin:16px 0;max-width:900px}
.aoq-card.aoq-ok{border-left-color:#00a32a}.aoq-card.aoq-bad{border-left-color:#d63638}.aoq-card.aoq-wait{border-left-color:#dba617}
.aoq-card h3{margin:0 0 4px;font-size:15px}.aoq-card p{margin:0;color:#50575e}
.aoq-spin{width:18px;height:18px;border:3px solid #c3c4c7;border-top-color:#2271b1;border-radius:50%;animation:aoqspin 1s linear infinite;flex:none}
@keyframes aoqspin{to{transform:rotate(360deg)}}
.aoq-bar{background:#dcdcde;border-radius:3px;height:22px;max-width:900px;position:relative;margin:8px 0 16px}
.aoq-bar>div{background:#2271b1;height:22px;border-radius:3px;width:0%;transition:width .4s}
.aoq-bar>span{position:absolute;left:8px;top:2px;color:#fff;font-weight:600;text-shadow:0 0 2px #000}
.aoq-steps{list-style:none;margin:0;padding:0;max-width:900px}
.aoq-step{background:#fff;border:1px solid #dcdcde;margin:0 0 6px;padding:8px 12px}
.aoq-step>summary{cursor:pointer;display:flex;gap:10px;align-items:baseline;list-style:none}
.aoq-step>summary::-webkit-details-marker{display:none}
.aoq-ico{width:20px;text-align:center;font-weight:700;flex:none}
.aoq-num{min-width:22px;height:22px;line-height:22px;border-radius:11px;background:#f0f0f1;color:#50575e;text-align:center;font-weight:600;font-size:12px;flex:none}
.aoq-step.running .aoq-num{background:#2271b1;color:#fff}.aoq-step.done .aoq-num{background:#edfaef;color:#00a32a}.aoq-step.failed .aoq-num{background:#fcf0f1;color:#d63638}
.aoq-step.done .aoq-ico{color:#00a32a}.aoq-step.failed .aoq-ico{color:#d63638}.aoq-step.pending,.aoq-step.skipped{opacity:.65}.aoq-step.running{border-color:#2271b1}
.aoq-step .aoq-t{font-weight:600}.aoq-step .aoq-d{color:#646970;flex:1}.aoq-step .aoq-m{color:#646970;white-space:nowrap;font-variant-numeric:tabular-nums}
.aoq-step.running .aoq-m{color:#2271b1;font-weight:600}.aoq-step.running .aoq-m.aoq-over{color:#996800}
#aoq-eta{margin-top:4px;font-weight:600;color:#2271b1}
.aoq-step.running>summary{background:linear-gradient(90deg,#3c434a 0%,#3c434a 42%,#72aee6 50%,#3c434a 58%,#3c434a 100%);background-size:250% 100%;-webkit-background-clip:text;background-clip:text;animation:aoqshimmer 2.6s linear infinite}
.aoq-step.running>summary .aoq-t,.aoq-step.running>summary .aoq-d{color:transparent}
@keyframes aoqshimmer{from{background-position:100% 0}to{background-position:0 0}}
@media (prefers-reduced-motion:reduce){.aoq-step.running>summary{animation:none;background:none}.aoq-step.running>summary .aoq-t{color:#1d2327}.aoq-step.running>summary .aoq-d{color:#646970}}
.aoq-body{margin:8px 0 2px 30px}
.aoq-igg-card{background:#fff;border:1px solid #c3c4c7;padding:14px 16px;margin:12px 0;max-width:900px}
.aoq-igg{display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start}
.aoq-igg-cap{white-space:pre-wrap;font-size:13px;line-height:1.5;max-width:520px;background:#f6f7f7;padding:10px 12px;border-radius:3px}
.aoq-igg-img{width:270px;aspect-ratio:4/5;object-fit:cover;border-radius:3px;border:1px solid #dcdcde;background:#f0f0f1}
</style>';
		echo '<p>' . esc_html__( 'Write an Instagram post for any post on the sites that post to Instagram. Test run writes the caption with AO and makes the cropped image, so you can check them; nothing is posted. Generate and post does the same and then posts it.', 'ao-quill' ) . '</p>';
		if ( ! $s['enabled'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Automatic posting is off on the Instagram tab. Test runs and Generate and post here still work.', 'ao-quill' ) . '</p></div>';
		}
		if ( ! AOQ_Instagram::connected() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Instagram is not connected, so only Test run is available. Connect it on the Instagram tab.', 'ao-quill' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( self::action_url( 'aoq_ig_generate' ) ) . '" class="aoq-igg-card">';
		wp_nonce_field( 'aoq_ig_generate' );
		echo '<p style="margin-top:0"><label for="aoq-igg-pick"><strong>' . esc_html__( 'Post', 'ao-quill' ) . '</strong></label><br><select id="aoq-igg-pick" name="pick" style="max-width:100%;min-width:520px">';
		if ( ! $posts ) {
			echo '<option value="">' . esc_html__( 'No posts in the languages that post to Instagram', 'ao-quill' ) . '</option>';
		}
		foreach ( $posts as $p ) {
			$val   = $p['blog_id'] . ':' . $p['id'];
			$notes = array( $p['ao'] ? 'AO Quill' : __( 'human', 'ao-quill' ) );
			if ( 'publish' !== $p['status'] ) {
				$notes[] = __( 'pending review', 'ao-quill' );
			}
			if ( $p['on_ig'] ) {
				$notes[] = __( 'on Instagram', 'ao-quill' );
			}
			if ( ! $p['image'] ) {
				$notes[] = __( 'no featured image', 'ao-quill' );
			}
			$label = '[' . $p['language'] . '] ' . $p['title'] . ' · ' . mysql2date( 'Y-m-d', $p['date'] ) . ' · ' . implode( ', ', $notes );
			echo '<option value="' . esc_attr( $val ) . '"' . selected( $pick, $val, false ) . disabled( ! $p['image'], true, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p><p style="margin-bottom:0">';
		$off = $busy || ! $posts;
		echo '<button type="submit" name="mode" value="test" class="button button-secondary"' . disabled( $off, true, false ) . '>' . esc_html__( 'Test run', 'ao-quill' ) . '</button> ';
		echo '<button type="submit" name="mode" value="post" class="button button-primary"' . disabled( $off || ! AOQ_Instagram::connected(), true, false ) . ' onclick="return confirm(this.dataset.q)" data-q="' . esc_attr__( 'Write a caption for this post and post it to Instagram?', 'ao-quill' ) . '">' . esc_html__( 'Generate and post', 'ao-quill' ) . '</button>';
		echo ' <span class="description" id="aoq-igg-busy"' . ( $busy ? '' : ' hidden' ) . '>' . esc_html__( 'An Instagram generation is waiting or in progress.', 'ao-quill' ) . '</span>';
		echo '</p></form>';

		// Live panel, laid out like the Post generation tab.
		echo '<div id="aoq-card" class="aoq-card"><div id="aoq-spin" class="aoq-spin" style="display:none"></div><div><h3 id="aoq-card-title">' . esc_html__( 'No Instagram generation yet', 'ao-quill' ) . '</h3><p id="aoq-card-text">' . esc_html__( 'Pick a post and press Test run: the steps, the caption and the image show here.', 'ao-quill' ) . '</p><p id="aoq-eta"></p></div></div>';
		echo '<div class="aoq-bar"><div id="aoq-bar"></div><span id="aoq-pct">0%</span></div>';
		echo '<h2>' . esc_html__( 'Steps', 'ao-quill' ) . '</h2><ol id="aoq-steps" class="aoq-steps">';
		$n = 0;
		foreach ( self::step_definitions() as $id => $def ) {
			++$n;
			echo '<li><details class="aoq-step pending" data-step="' . esc_attr( $id ) . '"><summary><span class="aoq-num">' . (int) $n . '</span><span class="aoq-ico">○</span><span class="aoq-t">' . esc_html( $def[0] ) . '</span><span class="aoq-d">' . esc_html( $def[1] ) . '</span><span class="aoq-m"></span></summary><div class="aoq-body"></div></details></li>';
		}
		echo '</ol>';
		echo '<div id="aoq-igg-result" hidden><h2>' . esc_html__( 'Result', 'ao-quill' ) . '</h2><div class="aoq-igg-card"><div class="aoq-igg"><img id="aoq-igg-img" class="aoq-igg-img" alt="" hidden><div><div id="aoq-igg-cap" class="aoq-igg-cap" hidden></div><p id="aoq-igg-link"></p></div></div></div></div>';

		// History.
		$log = (array) get_site_option( AOQ_Instagram::JOBS_LOG, array() );
		if ( $log ) {
			echo '<h2>' . esc_html__( 'Earlier Instagram generations', 'ao-quill' ) . '</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'When', 'ao-quill' ) . '</th><th>' . esc_html__( 'Post', 'ao-quill' ) . '</th><th>' . esc_html__( 'Kind', 'ao-quill' ) . '</th><th>' . esc_html__( 'Time', 'ao-quill' ) . '</th><th>' . esc_html__( 'Result', 'ao-quill' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 10 ) as $j ) {
				$v = self::job_view( $j );
				echo '<tr><td style="white-space:nowrap">' . esc_html( $v['when'] ) . '</td><td>' . esc_html( $v['title'] ) . '</td><td>' . esc_html( $v['mode'] ) . '</td><td>' . esc_html( $v['seconds'] . ' s' ) . '</td><td>';
				if ( $v['permalink'] ) {
					echo '<a href="' . esc_url( $v['permalink'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'View on Instagram', 'ao-quill' ) . '</a>';
				} elseif ( 'failed' === $v['status'] ) {
					echo '<span style="color:#d63638">' . esc_html( $v['error'] ) . '</span>';
				} else {
					echo esc_html( $v['heading'] );
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		$t = array(
			/* translators: 1: percent, 2: step number, 3: steps */
			'bar'      => __( '%1$d%% · step %2$d of %3$d', 'ao-quill' ),
			/* translators: 1: kind of run, 2: post title, 3: elapsed */
			'cardText' => __( '%1$s for "%2$s" · %3$s', 'ao-quill' ),
			/* translators: %s: time left */
			'eta'      => __( 'About %s left, estimated from earlier runs.', 'ao-quill' ),
			/* translators: %s: average */
			'avg'      => __( 'avg %s', 'ao-quill' ),
			/* translators: %d: number of earlier runs */
			'runs'     => __( 'average of %d earlier runs', 'ao-quill' ),
			'view'     => __( 'View on Instagram', 'ao-quill' ),
			'noImage'  => __( 'The image could not be made from the featured image.', 'ao-quill' ),
			'caption'  => __( 'Caption with hashtags, as it is posted:', 'ao-quill' ),
		);
		?>
<script>
(function () {
	var url = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) . '?action=aoq_ig_job&_ajax_nonce=' . wp_create_nonce( 'aoq_ig_job' ) ); ?>;
	var T = <?php echo wp_json_encode( $t ); ?>;
	var cur = <?php echo wp_json_encode( self::job_view( $job ) ); ?>, wasActive = !!(cur && cur.active), shownAt = Date.now(), lastSig = '';
	var ICO = { pending: '○', running: '', done: '✓', failed: '✕', skipped: '–' };
	function el(id) { return document.getElementById(id); }
	function fmt(s) {
		var a = Array.prototype.slice.call(arguments, 1), i = 0;
		return s.replace(/%(\d)\$[sd]/g, function (m, n) { return a[n - 1]; }).replace(/%[sd]/g, function () { return a[i++]; }).replace(/%%/g, '%');
	}
	function dur(sec) { sec = Math.max(0, Math.round(sec)); return sec < 60 ? sec + ' s' : Math.floor(sec / 60) + ' min ' + (sec % 60) + ' s'; }
	function ring(pct) {
		var c = 2 * Math.PI * 15;
		return '<svg width="18" height="18" viewBox="0 0 36 36" style="vertical-align:-3px" aria-hidden="true">'
			+ '<circle cx="18" cy="18" r="15" fill="none" stroke="#dcdcde" stroke-width="5"/>'
			+ '<circle cx="18" cy="18" r="15" fill="none" stroke="#2271b1" stroke-width="5" stroke-linecap="round" transform="rotate(-90 18 18)"'
			+ ' stroke-dasharray="' + c.toFixed(2) + '" stroke-dashoffset="' + (c * (1 - pct / 100)).toFixed(2) + '"/></svg>';
	}
	var SPIN = '<span class="aoq-spin" style="display:inline-block;width:12px;height:12px;border-width:2px"></span>';
	function runningStep(s, extra) {
		// With an average from earlier runs: a progress ring and a percentage, as on Post generation.
		// Without one, or past it: the spinner and the elapsed time.
		var det = document.querySelector('.aoq-step[data-step="' + s.id + '"]');
		var m = det.querySelector('.aoq-m'), ico = det.querySelector('.aoq-ico');
		var now = (s.seconds || 0) + extra;
		if (s.avg !== null && now <= s.avg) {
			var pct = Math.min(99, Math.floor(100 * now / Math.max(1, s.avg)));
			ico.innerHTML = ring(pct);
			m.textContent = pct + '%';
			m.title = dur(now) + ' / ' + fmt(T.avg, dur(s.avg)) + ' - ' + fmt(T.runs, s.runs);
		} else {
			if (!ico.querySelector('.aoq-spin')) { ico.innerHTML = SPIN; }
			m.textContent = dur(now);
			m.title = s.avg !== null ? fmt(T.avg, dur(s.avg)) + ' - ' + fmt(T.runs, s.runs) : '';
		}
		m.classList.toggle('aoq-over', s.avg !== null && now > s.avg);
	}
	function tick() {
		// Running step and card count up between polls without a reload.
		if (!cur || !cur.active) { return; }
		var extra = (Date.now() - shownAt) / 1000;
		cur.steps.forEach(function (s) { if (s.status === 'running') { runningStep(s, extra); } });
		el('aoq-card-text').textContent = fmt(T.cardText, cur.mode, cur.title, dur(cur.seconds + extra));
		el('aoq-eta').textContent = cur.left - extra > 0 ? fmt(T.eta, dur(cur.left - extra)) : '';
	}
	function draw(j) {
		cur = j; shownAt = Date.now();
		var card = el('aoq-card');
		if (!j) { return; }
		card.className = 'aoq-card' + (j.status === 'done' ? ' aoq-ok' : j.status === 'failed' ? ' aoq-bad' : j.status === 'queued' ? ' aoq-wait' : '');
		el('aoq-spin').style.display = j.active ? '' : 'none';
		el('aoq-card-title').textContent = j.heading;
		el('aoq-card-text').textContent = fmt(T.cardText, j.mode, j.title, dur(j.seconds));
		el('aoq-eta').textContent = j.active && j.left > 0 ? fmt(T.eta, dur(j.left)) : '';
		el('aoq-bar').style.width = j.percent + '%';
		el('aoq-pct').textContent = fmt(T.bar, j.percent, j.step_no, j.steps_n);
		el('aoq-igg-busy').hidden = !j.active;
		j.steps.forEach(function (s) {
			var det = document.querySelector('.aoq-step[data-step="' + s.id + '"]');
			var open = det.open;
			det.className = 'aoq-step ' + s.status;
			det.open = open;
			var ico = det.querySelector('.aoq-ico');
			det.querySelector('.aoq-d').textContent = s.text;
			if (s.status === 'running') { runningStep(s, 0); } else { ico.textContent = ICO[s.status] || '○'; det.querySelector('.aoq-m').textContent = s.seconds === null ? '' : dur(s.seconds); det.querySelector('.aoq-m').title = ''; }
			var body = det.querySelector('.aoq-body');
			var bsig = [s.status, j.caption, j.image, j.permalink, j.error].join('|');
			if (det.dataset.sig === bsig) { return; }
			det.dataset.sig = bsig;
			body.textContent = '';
			if (s.id === 'caption' && j.caption) { var pre = document.createElement('div'); pre.className = 'aoq-igg-cap'; pre.textContent = j.caption; body.appendChild(pre); }
			if (s.id === 'image' && j.image) { var im = document.createElement('img'); im.src = j.image; im.className = 'aoq-igg-img'; im.alt = ''; body.appendChild(im); }
			if (s.id === 'post' && j.permalink) { var a = document.createElement('a'); a.href = j.permalink; a.target = '_blank'; a.rel = 'noopener'; a.textContent = T.view; body.appendChild(a); }
			if (s.status === 'failed' && j.error) { var e = document.createElement('p'); e.style.color = '#d63638'; e.textContent = j.error; body.appendChild(e); det.open = true; }
		});
		var sig = [j.job_id, j.status, j.caption, j.image, j.permalink].join('|');
		if (sig === lastSig) { return; }
		lastSig = sig;
		var res = el('aoq-igg-result');
		res.hidden = !(j.caption || j.image || j.permalink);
		var img = el('aoq-igg-img');
		if (j.image) { img.src = j.image; img.hidden = false; } else { img.hidden = true; }
		var cap = el('aoq-igg-cap');
		if (j.caption) { cap.textContent = j.caption; cap.hidden = false; } else { cap.hidden = true; }
		var link = el('aoq-igg-link'); link.textContent = '';
		if (j.permalink) { var a2 = document.createElement('a'); a2.href = j.permalink; a2.target = '_blank'; a2.rel = 'noopener'; a2.className = 'button button-primary'; a2.textContent = T.view; link.appendChild(a2); }
		else if (j.status === 'done' && !j.image) { link.textContent = T.noImage; }
	}
	function poll() {
		fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
			if (res && res.success && res.data) {
				draw(res.data);
				// The buttons and the history change when a job ends: one reload then.
				if (wasActive && !res.data.active) { location.reload(); return; }
				wasActive = res.data.active;
			}
		}).catch(function () {}).then(function () { setTimeout(poll, wasActive ? 3000 : 15000); });
	}
	draw(cur);
	setInterval(tick, 1000);
	setTimeout(poll, 3000);
})();
</script>
		<?php
	}
}
