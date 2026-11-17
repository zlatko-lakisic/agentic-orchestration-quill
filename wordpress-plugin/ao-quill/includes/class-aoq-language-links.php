<?php
/**
 * Language links between the sites of the network (D32): hreflang alternates in every page's
 * head, and the menu's language items point to the same page in the other language instead of
 * the other site's home page.
 *
 * Counterparts: the front page and the posts page of each site; posts, pages and tours linked in
 * the aoq_translations table (AO Quill links its own posts there when it commits them, and other
 * content is linked with link()); taxonomy terms with the same slug.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Language_Links {

	/** @var array|null Per request: language code => URL. */
	private static $current = null;

	/** WP Travel Engine permalink bases, by post type or taxonomy. */
	const WTE_BASES = array(
		'trip'        => 'wp_travel_engine_trip_base',
		'trip_types'  => 'wp_travel_engine_trip_type_base',
		'destination' => 'wp_travel_engine_destination_base',
		'activities'  => 'wp_travel_engine_activity_base',
	);

	/**
	 * Register front-end hooks.
	 */
	public static function init() {
		if ( ! is_multisite() ) {
			return;
		}
		add_action( 'wp_head', array( __CLASS__, 'hreflang' ), 2 );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'switcher' ), 20 );
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ), 10, 2 );
			foreach ( self::ADMIN_TYPES as $type ) {
				add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'column' ) );
				add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column_value' ), 10, 2 );
			}
		}
	}

	/** Post types that show their translations in the admin. */
	const ADMIN_TYPES = array( 'post', 'page', 'trip' );

	/**
	 * A post's translations as a family: the source (the main site's post) and its children (the
	 * same post in each other language), in a box on the edit screen.
	 *
	 * @param string  $type Post type.
	 * @param WP_Post $post Post.
	 */
	public static function meta_boxes( $type, $post ) {
		if ( ! in_array( $type, self::ADMIN_TYPES, true ) || ! $post instanceof WP_Post || ! self::group_posts( get_current_blog_id(), $post->ID ) ) {
			return;
		}
		$here  = get_current_blog_id();
		$title = self::is_source( $here ) ? __( 'Translations', 'ao-quill' ) : __( 'Translated from', 'ao-quill' );
		add_meta_box( 'aoq-translations', $title, array( __CLASS__, 'render_box' ), $type, 'side', 'default' );
	}

	/** Whether a site is the main language (the source of translations). */
	private static function is_source( $blog_id ) {
		$sites = self::sites();
		return $sites && (int) $sites[0]['blog_id'] === (int) $blog_id;
	}

	/**
	 * The family of a post: [code, blog_id, post_id, title, edit, view, is_source] per language.
	 *
	 * @param int $blog_id Site.
	 * @param int $post_id Post.
	 * @return array<int, array<string, mixed>>
	 */
	public static function family( $blog_id, $post_id ) {
		$group = self::group_posts( $blog_id, $post_id );
		$out   = array();
		foreach ( self::sites() as $i => $site ) {
			$b = (int) $site['blog_id'];
			if ( empty( $group[ $b ] ) ) {
				continue;
			}
			switch_to_blog( $b );
			$p = get_post( $group[ $b ] );
			if ( $p ) {
				$out[] = array(
					'code'      => (string) $site['code'],
					'blog_id'   => $b,
					'post_id'   => (int) $p->ID,
					'title'     => get_the_title( $p ),
					'status'    => $p->post_status,
					'edit'      => get_admin_url( $b, 'post.php?post=' . (int) $p->ID . '&action=edit' ),
					'is_source' => 0 === $i,
				);
			}
			restore_current_blog();
		}
		foreach ( $out as &$r ) {
			$r['view'] = (string) self::post_url( $r['blog_id'], $r['post_id'] );
		}
		unset( $r );
		return $out;
	}

	/**
	 * Meta box: on the source, its children; on a translation, its source first, then the others.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_box( $post ) {
		$here = get_current_blog_id();
		$rows = self::family( $here, $post->ID );
		$src  = array_values( array_filter( $rows, static fn( $r ) => $r['is_source'] ) );
		echo '<ul style="margin:0">';
		foreach ( $rows as $r ) {
			if ( $r['blog_id'] === $here ) {
				continue;
			}
			$tag = $r['is_source'] ? ' <em>(' . esc_html__( 'source', 'ao-quill' ) . ')</em>' : '';
			printf(
				'<li><strong>%1$s</strong> <a href="%2$s">%3$s</a>%4$s%5$s%6$s</li>',
				esc_html( strtoupper( $r['code'] ) ),
				esc_url( $r['edit'] ),
				esc_html( $r['title'] ),
				$tag, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup
				'publish' === $r['status'] ? '' : ' (' . esc_html( $r['status'] ) . ')',
				$r['view'] ? ' &middot; <a href="' . esc_url( $r['view'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'view', 'ao-quill' ) . '</a>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped here
			);
		}
		echo '</ul>';
		if ( ! $src || $src[0]['blog_id'] !== $here ) {
			return;
		}
		$have = array_column( $rows, 'code' );
		$miss = array_diff( array_column( self::sites(), 'code' ), $have );
		if ( $miss ) {
			echo '<p class="description">' . esc_html( sprintf( /* translators: %s: language codes */ __( 'Not translated yet: %s', 'ao-quill' ), strtoupper( implode( ', ', $miss ) ) ) ) . '</p>';
		}
	}

	/**
	 * Posts list column.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function column( $cols ) {
		$cols['aoq_translations'] = self::is_source( get_current_blog_id() ) ? __( 'Translations', 'ao-quill' ) : __( 'Translated from', 'ao-quill' );
		return $cols;
	}

	/**
	 * Posts list column value: links to the other languages' posts.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Post.
	 */
	public static function column_value( $col, $post_id ) {
		if ( 'aoq_translations' !== $col ) {
			return;
		}
		$here  = get_current_blog_id();
		$links = array();
		foreach ( self::family( $here, (int) $post_id ) as $r ) {
			if ( $r['blog_id'] !== $here && ( self::is_source( $here ) || $r['is_source'] ) ) {
				$links[] = sprintf( '<a href="%s" title="%s">%s</a>', esc_url( $r['edit'] ), esc_attr( $r['title'] ), esc_html( strtoupper( $r['code'] ) ) );
			}
		}
		echo $links ? implode( ' ', $links ) : '&mdash;'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above
	}

	/**
	 * Sites that are a language of their own: the main site, then each other site whose language
	 * no earlier site has (a site left on the main site's language is not an alternate).
	 *
	 * @return array<int, array{code:string, locale:string, blog_id:int, url:string, primary:bool}>
	 */
	public static function sites() {
		$seen = array();
		$out  = array();
		foreach ( AOQ_Plugin::translation_adapter()->languages() as $lang ) {
			if ( isset( $seen[ $lang['code'] ] ) ) {
				continue;
			}
			$seen[ $lang['code'] ] = true;
			$out[]                 = $lang;
		}
		return $out;
	}

	/**
	 * Link posts across sites as translations of each other (a manual translation group).
	 *
	 * @param array<int, int> $posts Blog ID => post ID.
	 * @return string Group ID.
	 */
	public static function link( array $posts ) {
		global $wpdb;
		$adapter = AOQ_Plugin::translation_adapter();
		$table   = AOQ_Multisite_Adapter::table();
		$group   = null;
		foreach ( $posts as $blog_id => $post_id ) {
			$group = $group ? $group : $adapter->translation_group( $blog_id, $post_id );
		}
		$group = $group ? $group : wp_generate_uuid4();
		foreach ( $posts as $blog_id => $post_id ) {
			$wpdb->delete( $table, array( 'blog_id' => (int) $blog_id, 'post_id' => (int) $post_id ), array( '%d', '%d' ) );
			$wpdb->insert(
				$table,
				array(
					'group_id'   => $group,
					'blog_id'    => (int) $blog_id,
					'post_id'    => (int) $post_id,
					'language'   => (string) $adapter->post_language( $blog_id, $post_id ),
					'run_id'     => 'manual',
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%d', '%d', '%s', '%s', '%s' )
			);
		}
		return $group;
	}

	/**
	 * Every post in the same translation group: blog ID => post ID (the post itself included).
	 *
	 * @param int $blog_id Site.
	 * @param int $post_id Post.
	 * @return array<int, int>
	 */
	public static function group_posts( $blog_id, $post_id ) {
		global $wpdb;
		$group = AOQ_Plugin::translation_adapter()->translation_group( (int) $blog_id, (int) $post_id );
		if ( ! $group ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT blog_id, post_id FROM ' . AOQ_Multisite_Adapter::table() . ' WHERE group_id = %s ORDER BY blog_id', $group ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r->blog_id ] = (int) $r->post_id;
		}
		return $out;
	}

	/**
	 * The translation of a post on another site, or 0.
	 *
	 * @param int $blog_id Site of the post.
	 * @param int $post_id Post.
	 * @param int $target  Site of the translation.
	 * @return int
	 */
	public static function translation_on( $blog_id, $post_id, $target ) {
		$group = self::group_posts( $blog_id, $post_id );
		return (int) ( $group[ (int) $target ] ?? 0 );
	}

	/**
	 * The current request's page in every language that has it: code => URL (the current site
	 * included). Empty when the request has no counterparts (search, 404, ...).
	 *
	 * @return array<string, string>
	 */
	public static function current() {
		if ( null !== self::$current ) {
			return self::$current;
		}
		$here = get_current_blog_id();
		$out  = array();
		foreach ( self::sites() as $site ) {
			$url = self::counterpart( (int) $site['blog_id'], $here );
			if ( $url ) {
				$out[ $site['code'] ] = $url;
			}
		}
		self::$current = count( $out ) > 1 ? $out : array();
		return self::$current;
	}

	/**
	 * URL of the current request's page on another site, or null.
	 *
	 * @param int $blog_id Target site.
	 * @param int $here    Current site.
	 * @return string|null
	 */
	private static function counterpart( $blog_id, $here ) {
		if ( is_front_page() ) {
			return get_home_url( $blog_id, '/' );
		}
		if ( is_home() ) {
			switch_to_blog( $blog_id );
			$page = (int) get_option( 'page_for_posts' );
			$url  = $page ? get_permalink( $page ) : null;
			restore_current_blog();
			return $url ? $url : null;
		}
		if ( is_singular() ) {
			$post_id = (int) get_queried_object_id();
			if ( $blog_id === $here ) {
				return (string) get_permalink( $post_id );
			}
			$group = AOQ_Plugin::translation_adapter()->translation_group( $here, $post_id );
			if ( ! $group ) {
				return null;
			}
			global $wpdb;
			$target = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM ' . AOQ_Multisite_Adapter::table() . ' WHERE group_id = %s AND blog_id = %d', $group, $blog_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $target ? self::post_url( $blog_id, $target ) : null;
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( ! $term instanceof WP_Term ) {
				return null;
			}
			if ( $blog_id === $here ) {
				return (string) get_term_link( $term );
			}
			return self::term_url( $blog_id, $term->taxonomy, $term->slug );
		}
		return null;
	}

	/**
	 * Permalink of a published post on another site. Tours are built from that site's own tour
	 * base: get_permalink() while switched uses the current site's rewrite rules.
	 *
	 * @param int $blog_id Site.
	 * @param int $post_id Post.
	 * @return string|null
	 */
	public static function post_url( $blog_id, $post_id ) {
		switch_to_blog( $blog_id );
		$post = get_post( $post_id );
		$url  = null;
		if ( $post && 'publish' === $post->post_status ) {
			$base = self::wte_base( $post->post_type );
			$url  = $base ? home_url( '/' . $base . '/' . $post->post_name . '/' ) : get_permalink( $post );
		}
		restore_current_blog();
		return $url ? (string) $url : null;
	}

	/**
	 * Archive URL of the term with the same slug on another site.
	 *
	 * @param int    $blog_id  Site.
	 * @param string $taxonomy Taxonomy.
	 * @param string $slug     Term slug.
	 * @return string|null
	 */
	public static function term_url( $blog_id, $taxonomy, $slug ) {
		switch_to_blog( $blog_id );
		$term = get_term_by( 'slug', $slug, $taxonomy );
		$url  = null;
		if ( $term ) {
			$base = self::wte_base( $taxonomy );
			$link = $base ? home_url( '/' . $base . '/' . $term->slug . '/' ) : get_term_link( $term );
			$url  = is_wp_error( $link ) ? null : $link;
		}
		restore_current_blog();
		return $url;
	}

	/**
	 * WP Travel Engine permalink base of the current (possibly switched) site, or null.
	 *
	 * @param string $type Post type or taxonomy.
	 * @return string|null
	 */
	private static function wte_base( $type ) {
		if ( ! isset( self::WTE_BASES[ $type ] ) ) {
			return null;
		}
		$opt  = get_option( 'wp_travel_engine_permalinks' );
		$base = is_array( $opt ) ? trim( (string) ( $opt[ self::WTE_BASES[ $type ] ] ?? '' ), '/' ) : '';
		return '' !== $base ? $base : null;
	}

	/**
	 * hreflang alternates, and x-default (the main site's language).
	 */
	public static function hreflang() {
		$links = self::current();
		if ( ! $links ) {
			return;
		}
		$primary = AOQ_Plugin::translation_adapter()->primary_language();
		foreach ( $links as $code => $url ) {
			echo '<link rel="alternate" hreflang="' . esc_attr( $code ) . '" href="' . esc_url( $url ) . '" />' . "\n";
		}
		if ( isset( $links[ $primary ] ) ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $links[ $primary ] ) . '" />' . "\n";
		}
	}

	/**
	 * Menu language items (custom links to another language site's home page) go to the same page
	 * in that language when it exists.
	 *
	 * @param WP_Post[] $items Menu items.
	 * @return WP_Post[]
	 */
	public static function switcher( $items ) {
		$homes = array();
		foreach ( self::sites() as $site ) {
			$homes[ untrailingslashit( $site['url'] ) ] = $site['code'];
		}
		$links = null;
		foreach ( $items as $item ) {
			$code = $homes[ untrailingslashit( (string) $item->url ) ] ?? null;
			if ( null === $code || (int) get_current_blog_id() === self::blog_of( $code ) ) {
				continue;
			}
			$links = null === $links ? self::current() : $links;
			if ( isset( $links[ $code ] ) ) {
				$item->url = $links[ $code ];
			}
		}
		return $items;
	}

	/**
	 * Blog ID of a language code.
	 *
	 * @param string $code Code.
	 * @return int
	 */
	private static function blog_of( $code ) {
		foreach ( self::sites() as $site ) {
			if ( $site['code'] === $code ) {
				return (int) $site['blog_id'];
			}
		}
		return 0;
	}

	/**
	 * Reset the per-request cache (tests).
	 */
	public static function reset() {
		self::$current = null;
	}
}
