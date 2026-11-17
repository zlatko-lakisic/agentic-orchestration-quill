<?php
/**
 * Language site tool (docs/plans/new-language-site.md), run by the worker's `language-site`
 * command through WP-CLI:
 *
 *   wp --url=<target site> eval-file language-site.php <action> <in.json> <out.json>
 *
 * Actions (in.json holds the options, out.json receives the result):
 *   check   what the target and the base site hold
 *   export  every base-site text to translate: [{key, text, html}]
 *   apply   copy everything from the base site and apply the translations (idempotent: what it
 *           creates carries _aoq_source = "<base blog>:<id>")
 *   post    copy the given base posts (post_ids) now, with their categories and language link
 *   link    language links (D32) and the language menus of every language site
 *   mo      write plugin language files from translated interface words
 *   pages   the URLs to compare in the verify step: [{base, target}]
 *
 * Nothing here is shown in WordPress; it only runs from the command line.
 *
 * @package AOQuill
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

list( $aoq_action, $aoq_in, $aoq_out ) = array_pad( $args, 3, '' );
$aoq_opt = $aoq_in && is_readable( $aoq_in ) ? (array) json_decode( (string) file_get_contents( $aoq_in ), true ) : array();
$aoq_res = ( new AOQ_Language_Site( $aoq_opt ) )->run( $aoq_action );
file_put_contents( $aoq_out, wp_json_encode( $aoq_res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ) );

final class AOQ_Language_Site {

	const WTE_TAXONOMIES = array( 'destination', 'activities', 'trip_types', 'difficulty', 'trip_tag' );
	// Text fields of theme settings (top level and inside list settings) that are translated.
	const TEXT_KEYS = array( 'title', 'name', 'desc', 'description', 'content', 'label', 'readmore', 'subtitle' );
	// Theme settings that are the site's own or not text.
	const MOD_SKIP = array( 'nav_menu_locations', 'custom_css_post_id', 'sidebars_widgets', 'google_analytics_ad_code', 'external_header_video', 'child_additional_support', 'header_layout', 'pagination_type', 'home_sort', 'color_scheme', '0' );
	// Interface words of WP Travel Engine shown on the site (msgid, or context\x04msgid).
	const UI_DOMAIN = 'wp-travel-engine';
	const UI_WORDS  = array( 'Days', 'Day', 'Hours', 'Hour', 'Overview', 'Duration', 'Nights', 'Night', 'Price', 'Message', 'Destination', 'Destinations', 'Activities', 'Check Availability', 'Trip Type', 'Trip Types', 'Send Email', 'Search', 'Adult', 'Enquiry', 'Continue', 'Child', 'Book Now', 'From', 'Group Discounts', 'Itinerary', 'Cost', 'Dates', 'FAQs', 'Map', 'Your Name', 'Your Email', 'Email', 'Name', 'Country', 'Contact Number', 'Number of Adults', 'Number of Children', 'Subject', 'Total', 'Select Date', 'Travellers', 'Includes', 'Excludes', 'View Details', 'Explore', 'per person', 'Reviews', 'Languages', "taxonomy general name\x04Trip Type", "taxonomy singular name\x04Trip Type", "taxonomy general name\x04Activities", "taxonomy singular name\x04Activities", "taxonomy general name\x04Destination", "taxonomy singular name\x04Destination", "taxonomy general name\x04Destinations" );

	private $o;
	private $base;
	private $target;
	private $tr      = array();
	private $log     = array();
	private $trips   = array();
	private $pages   = array();
	private $termmap = array();
	private $queue   = null;
	private $skipped = 0;

	public function __construct( array $o ) {
		$this->o      = $o;
		$this->base   = (int) ( $o['base_blog'] ?? get_main_site_id() );
		$this->target = get_current_blog_id();
		$this->tr     = (array) ( $o['translations'] ?? array() );
	}

	public function run( $action ) {
		if ( $this->target === $this->base && 'pages' !== $action ) {
			return array( 'error' => 'The target is the base site.' );
		}
		switch ( $action ) {
			case 'check':
				return $this->check();
			case 'export':
				return $this->export();
			case 'apply':
				$this->apply();
				return array( 'log' => $this->log );
			case 'post':
				return $this->posts_now();
			case 'link':
				return $this->link();
			case 'mo':
				return $this->mo();
			case 'pages':
				return $this->pages();
		}
		return array( 'error' => "Unknown action $action" );
	}

	// ------------------------------------------------------------------ helpers

	/** Translation of a key, or the source text. */
	private function t( $key, $source ) {
		$v = $this->tr[ $key ] ?? null;
		return is_string( $v ) && '' !== $v ? $v : $source;
	}

	/**
	 * The blog posts this run translates: the base site's newest published posts that have no
	 * translation on the target yet (linked in the translation table, by AO Quill's own pipeline
	 * or an earlier run, or copied with _aoq_source), up to the --posts number.
	 */
	private function post_queue() {
		if ( null !== $this->queue ) {
			return $this->queue;
		}
		$want        = (int) ( $this->o['posts'] ?? 0 );
		$this->queue = array();
		for ( $offset = 0; $want > 0 && count( $this->queue ) < $want; $offset += 100 ) {
			$batch = $this->base_do(
				static function () use ( $offset ) {
					return get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 100, 'offset' => $offset, 'orderby' => 'date', 'order' => 'DESC' ) );
				}
			);
			if ( ! $batch ) {
				break;
			}
			foreach ( $batch as $p ) {
				if ( AOQ_Language_Links::translation_on( $this->base, $p->ID, $this->target ) || $this->find( 'post', $p->ID ) ) {
					++$this->skipped;
					continue;
				}
				$this->queue[] = $p;
				if ( count( $this->queue ) >= $want ) {
					break;
				}
			}
		}
		return $this->queue;
	}

	private function base_do( callable $fn ) {
		switch_to_blog( $this->base );
		try {
			return $fn();
		} finally {
			restore_current_blog();
		}
	}

	private function counts() {
		$out = array();
		foreach ( array( 'post', 'page', 'trip', 'tap_testimonial', 'trip-packages' ) as $t ) {
			$c         = wp_count_posts( $t );
			$out[ $t ] = (int) ( $c->publish ?? 0 );
		}
		$out['menus']        = count( wp_get_nav_menus() );
		$out['theme_mods']   = count( (array) get_theme_mods() );
		$out['from_base']    = count( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_aoq_source', 'fields' => 'ids' ) ) );
		return $out;
	}

	private function find( $type, $base_id ) {
		$q = get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'numberposts' => 1, 'meta_key' => '_aoq_source', 'meta_value' => $this->base . ':' . $base_id, 'fields' => 'ids' ) );
		if ( ! $q ) {  // made by the first, hand-written Bosnian migration
			$q = get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'numberposts' => 1, 'meta_key' => '_tgm_from', 'meta_value' => (string) $base_id, 'fields' => 'ids' ) );
		}
		return $q ? (int) $q[0] : 0;
	}

	private function mark( $id, $base_id ) {
		update_post_meta( $id, '_aoq_source', $this->base . ':' . $base_id );
	}

	/** Copy a base-site attachment into this site's media library (once). */
	private function media( $base_id ) {
		$base_id = (int) $base_id;
		if ( ! $base_id ) {
			return 0;
		}
		$have = $this->find( 'attachment', $base_id );
		if ( $have ) {
			return $have;
		}
		list( $file, $title, $alt ) = $this->base_do(
			static function () use ( $base_id ) {
				return array( get_attached_file( $base_id ), get_the_title( $base_id ), get_post_meta( $base_id, '_wp_attachment_image_alt', true ) );
			}
		);
		if ( ! $file || ! is_readable( $file ) ) {
			$this->log[] = "media $base_id: no file";
			return 0;
		}
		$tmp = wp_tempnam( basename( $file ) );
		copy( $file, $tmp );
		$id = media_handle_sideload( array( 'name' => basename( $file ), 'tmp_name' => $tmp ), 0, $title );
		if ( is_wp_error( $id ) ) {
			$this->log[] = "media $base_id: " . $id->get_error_message();
			@unlink( $tmp ); // phpcs:ignore
			return 0;
		}
		$this->mark( $id, $base_id );
		if ( $alt ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $this->t( "media:$base_id:alt", $alt ) );
		}
		return (int) $id;
	}

	/** Base-site URLs point to this site instead. */
	private function local_url( $url ) {
		$from = untrailingslashit( get_home_url( $this->base ) );
		$to   = untrailingslashit( home_url() );
		return is_string( $url ) && 0 === strpos( $url, $from . '/' ) && 0 !== strpos( $url, $to . '/' ) && ! preg_match( '#/wp-content/#', $url ) ? $to . substr( $url, strlen( $from ) ) : $url;
	}

	/** Remove pasted chat markup (data-start/data-end attributes and conversation wrappers). */
	private static function clean_html( $html ) {
		$html = preg_replace( '/\s+data-(start|end|testid|scroll-anchor|message-[a-z-]+)="[^"]*"/', '', (string) $html );
		return preg_replace( '/<p class="">/', '<p>', $html );
	}

	private static function has_words( $s ) {
		return is_string( $s ) && preg_match( '/\p{L}{2,}/u', wp_strip_all_tags( $s ) );
	}

	// ------------------------------------------------------------------ check

	private function check() {
		$lang = get_option( 'WPLANG' );
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		$all  = wp_get_available_translations();
		$want = (string) ( $this->o['locale'] ?? '' );
		return array(
			'language_name' => $all[ $want ]['english_name'] ?? ( 'en_US' === $want ? 'English (United States)' : $want ),
			'native_name'   => $all[ $want ]['native_name'] ?? $want,
			'target_blog' => $this->target,
			'base_blog'   => $this->base,
			'target_url'  => home_url( '/' ),
			'base_url'    => get_home_url( $this->base, '/' ),
			'locale'      => $lang ? $lang : 'en_US',
			'target'      => $this->counts(),
			'base'        => $this->base_do( array( $this, 'counts' ) ),
		);
	}

	// ------------------------------------------------------------------ export

	/** Everything to translate: [{key, text, html}]. */
	private function export() {
		$o     = $this->o;
		$queue = $this->post_queue();
		$res   = $this->base_do(
			function () use ( $o, $queue ) {
				$items = array();
				$add   = static function ( $key, $text, $html = false ) use ( &$items ) {
					if ( self::has_words( $text ) ) {
						$items[] = array( 'key' => $key, 'text' => (string) $text, 'html' => (bool) $html );
					}
				};
				$add( 'site:blogname', get_option( 'blogname' ) );
				$add( 'site:blogdescription', get_option( 'blogdescription' ) );
				foreach ( (array) get_theme_mods() as $k => $v ) {
					if ( in_array( (string) $k, self::MOD_SKIP, true ) ) {
						continue;
					}
					if ( is_string( $v ) && ! preg_match( '#^(https?:|\#|/)#', $v ) && ! is_numeric( $v ) ) {
						$add( "mod:$k", $v, (bool) preg_match( '/<[a-z]/i', $v ) );
					} elseif ( is_array( $v ) ) {
						foreach ( $v as $i => $row ) {
							foreach ( is_array( $row ) ? $row : array() as $f => $x ) {
								if ( in_array( (string) $f, self::TEXT_KEYS, true ) && is_string( $x ) ) {
									$add( "mod:$k:$i:$f", $x );
								}
							}
						}
					}
				}
				foreach ( get_posts( array( 'post_type' => 'trip', 'post_status' => 'publish', 'numberposts' => -1 ) ) as $p ) {
					$add( "trip:{$p->ID}:title", $p->post_title );
					$add( "trip:{$p->ID}:content", self::clean_html( $p->post_content ), true );
					$s = get_post_meta( $p->ID, 'wp_travel_engine_setting', true );
					if ( is_array( $s ) ) {
						foreach ( (array) ( $s['tab_content'] ?? array() ) as $tk => $tv ) {
							$add( "trip:{$p->ID}:tab:$tk", self::clean_html( $tv ), true );
						}
						foreach ( array( 'includes_title', 'cost_includes', 'excludes_title', 'cost_excludes' ) as $ck ) {
							$add( "trip:{$p->ID}:cost:$ck", $s['cost'][ $ck ] ?? '' );
						}
						$add( "trip:{$p->ID}:itinerary_title", $s['trip_itinerary_title'] ?? '' );
						foreach ( (array) ( $s['itinerary']['itinerary_title'] ?? array() ) as $ik => $iv ) {
							$add( "trip:{$p->ID}:it_title:$ik", $iv );
						}
						foreach ( (array) ( $s['itinerary']['itinerary_content'] ?? array() ) as $ik => $iv ) {
							$add( "trip:{$p->ID}:it_content:$ik", $iv, true );
						}
						foreach ( (array) ( $s['faq']['faq_title'] ?? array() ) as $ik => $iv ) {
							$add( "trip:{$p->ID}:faq_title:$ik", $iv );
						}
						foreach ( (array) ( $s['faq']['faq_content'] ?? array() ) as $ik => $iv ) {
							$add( "trip:{$p->ID}:faq_content:$ik", $iv, true );
						}
					}
				}
				foreach ( get_pages( array( 'post_status' => 'publish' ) ) as $p ) {
					$add( "page:{$p->ID}:title", $p->post_title );
					$add( "page:{$p->ID}:content", self::clean_html( $p->post_content ), true );
				}
				foreach ( get_posts( array( 'post_type' => 'tap_testimonial', 'post_status' => 'publish', 'numberposts' => -1 ) ) as $p ) {
					$add( "testimonial:{$p->ID}:content", self::clean_html( $p->post_content ), true );
					$add( "testimonial:{$p->ID}:trip", get_post_meta( $p->ID, '_tap_testimonail_visited_trip', true ) );
					$add( "testimonial:{$p->ID}:date", get_post_meta( $p->ID, '_tap_testimonail_trip_date', true ) );
				}
				$taxes = self::WTE_TAXONOMIES;
				if ( ! empty( $o['posts'] ) ) {
					$taxes[] = 'category';
				}
				foreach ( $taxes as $tax ) {
					foreach ( (array) get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ) as $t ) {
						$add( "term:$tax:{$t->slug}:name", html_entity_decode( $t->name ) );
						$add( "term:$tax:{$t->slug}:description", $t->description );
					}
				}
				$cats = get_terms( array( 'taxonomy' => 'trip-packages-categories', 'hide_empty' => false ) );
				foreach ( is_array( $cats ) ? $cats : array() as $t ) {
					$add( "pkgcat:{$t->slug}", $t->name );
				}
				foreach ( $this->widgets() as $sidebar => $ws ) {
					foreach ( $ws as $wid => $w ) {
						foreach ( array( 'title', 'text', 'content', 'readmore' ) as $f ) {
							if ( isset( $w[ $f ] ) && is_string( $w[ $f ] ) ) {
								$add( "widget:$wid:$f", self::clean_html( $w[ $f ] ), 'title' !== $f );
							}
						}
					}
				}
				$menu = $this->primary_menu();
				foreach ( $menu ? (array) wp_get_nav_menu_items( $menu ) : array() as $it ) {
					$add( "menu:{$it->ID}", $it->title );
				}
				$w = get_option( 'wp_travel_engine_settings' );
				if ( is_array( $w ) ) {
					foreach ( (array) ( $w['trip_tabs']['name'] ?? array() ) as $i => $n ) {
						$add( "wte:tab:$i", $n );
					}
					foreach ( (array) ( $w['trip_facts']['field_id'] ?? array() ) as $i => $n ) {
						$add( "wte:fact:$i", $n );
					}
					foreach ( (array) ( $w['default_trip_facts'] ?? array() ) as $i => $f ) {
						$add( "wte:dfact:$i", $f['field_id'] ?? '' );
					}
					$add( 'wte:related', $w['related_trips_section_title'] ?? '' );
					$add( 'wte:query_subject', $w['query_subject'] ?? '' );
				}
				foreach ( self::UI_WORDS as $word ) {
					$parts = explode( "\x04", $word );
					$add( 'ui:' . $word, end( $parts ) );
				}
				foreach ( $queue as $p ) {
					$add( "post:{$p->ID}:title", $p->post_title );
					$add( "post:{$p->ID}:excerpt", $p->post_excerpt );
					$add( "post:{$p->ID}:content", self::clean_html( $p->post_content ), true );
				}
				return array( 'items' => $items, 'base_locale' => get_locale() );
			}
		);
		$res['posts']         = count( $queue );
		$res['posts_skipped'] = $this->skipped;
		return $res;
	}

	/** Widgets of the current site: sidebar => [widget id => settings]. */
	private function widgets() {
		$out = array();
		foreach ( (array) get_option( 'sidebars_widgets' ) as $sidebar => $ids ) {
			if ( ! is_array( $ids ) || 'wp_inactive_widgets' === $sidebar || 0 === strpos( (string) $sidebar, 'array_version' ) ) {
				continue;
			}
			foreach ( $ids as $wid ) {
				if ( preg_match( '/^(.+)-(\d+)$/', $wid, $m ) ) {
					$all = get_option( 'widget_' . $m[1] );
					if ( isset( $all[ (int) $m[2] ] ) && is_array( $all[ (int) $m[2] ] ) ) {
						$out[ $sidebar ][ $wid ] = $all[ (int) $m[2] ];
					}
				}
			}
		}
		return $out;
	}

	private function primary_menu() {
		$loc = get_nav_menu_locations();
		return (int) ( $loc['primary'] ?? 0 );
	}

	// ------------------------------------------------------------------ apply

	private function apply() {
		// A new site starts on the default theme; theme settings are saved per theme, so the base
		// site's theme must be active before they are written.
		$theme = $this->base_do( 'get_stylesheet' );
		if ( get_stylesheet() !== $theme ) {
			switch_theme( $theme );
		}
		$this->terms();
		$this->pages_copy();
		$this->trips_copy();
		$this->testimonials();
		$this->posts_copy();
		$this->theme_mods();
		$this->site_options();
		$this->wte_settings();
		$this->widgets_copy();
		$this->menu();
	}

	private function terms() {
		$taxes = self::WTE_TAXONOMIES;
		if ( ! empty( $this->o['posts'] ) ) {
			$taxes[] = 'category';
		}
		foreach ( $taxes as $tax ) {
			$terms = $this->base_do(
				static function () use ( $tax ) {
					$t = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
					return is_array( $t ) ? $t : array();
				}
			);
			foreach ( $terms as $t ) {
				$name = $this->t( "term:$tax:{$t->slug}:name", html_entity_decode( $t->name ) );
				$desc = $this->t( "term:$tax:{$t->slug}:description", $t->description );
				$have = get_term_by( 'slug', $t->slug, $tax );
				if ( $have ) {
					wp_update_term( $have->term_id, $tax, array( 'name' => $name, 'description' => $desc ) );
					$this->termmap[ $t->term_id ] = $have->term_id;
				} else {
					$r = wp_insert_term( $name, $tax, array( 'slug' => $t->slug, 'description' => $desc ) );
					if ( ! is_wp_error( $r ) ) {
						$this->termmap[ $t->term_id ] = (int) $r['term_id'];
					} else {
						$this->log[] = "term $tax/{$t->slug}: " . $r->get_error_message();
					}
				}
			}
			// Empty terms of this site that the base site does not have under the same slug and
			// that carry one of the base site's names are duplicates from an earlier copy.
			$names = array_map(
				static function ( $t ) {
					return strtolower( html_entity_decode( $t->name ) );
				},
				$terms
			);
			$slugs = wp_list_pluck( $terms, 'slug' );
			foreach ( (array) get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ) as $t ) {
				if ( 0 === (int) $t->count && ! in_array( $t->slug, $slugs, true ) && in_array( strtolower( html_entity_decode( $t->name ) ), $names, true ) ) {
					wp_delete_term( $t->term_id, $tax );
					$this->log[] = "removed duplicate term $tax/{$t->slug}";
				}
			}
		}
	}

	private function copy_post( $type, $p, array $fields, array $skip_meta ) {
		$id = $this->find( $type, $p->ID );
		if ( ! $id && 'page' === $type ) {
			$same = get_page_by_path( $p->post_name );
			$id   = $same ? (int) $same->ID : 0;
		}
		$data = array_merge(
			array(
				'post_type'   => $type,
				'post_status' => $p->post_status,
				'post_author' => $p->post_author,
				'menu_order'  => $p->menu_order,
				'post_date'   => $p->post_date,
			),
			$fields
		);
		if ( $id ) {
			$data['ID'] = $id;
			$id         = wp_update_post( wp_slash( $data ), true );
		} else {
			$id = wp_insert_post( wp_slash( $data ), true );
		}
		if ( is_wp_error( $id ) ) {
			$this->log[] = "$type {$p->ID}: " . $id->get_error_message();
			return 0;
		}
		$this->mark( $id, $p->ID );
		$meta = $this->base_do(
			static function () use ( $p ) {
				$m = array();
				foreach ( get_post_meta( $p->ID ) as $k => $v ) {
					$m[ $k ] = maybe_unserialize( $v[0] );
				}
				return $m;
			}
		);
		foreach ( $meta as $k => $v ) {
			if ( in_array( $k, $skip_meta, true ) || preg_match( '/^(_edit_|_wp_old_|_dp_original|_wxr_|_demo_|_aioseo|_tgm_|_aoq_|_thumbnail_id|_booking_inventory)/', $k ) ) {
				continue;
			}
			update_post_meta( $id, $k, wp_slash( $v ) );
		}
		if ( ! empty( $meta['_thumbnail_id'] ) ) {
			$thumb = $this->media( $meta['_thumbnail_id'] );
			if ( $thumb ) {
				set_post_thumbnail( $id, $thumb );
			}
		}
		return array( (int) $id, $meta );
	}

	private function pages_copy() {
		$pages = $this->base_do(
			static function () {
				return get_pages( array( 'post_status' => 'publish' ) );
			}
		);
		foreach ( $pages as $p ) {
			$r = $this->copy_post(
				'page',
				$p,
				array(
					'post_title'   => $this->t( "page:{$p->ID}:title", $p->post_title ),
					'post_content' => $this->t( "page:{$p->ID}:content", self::clean_html( $p->post_content ) ),
					'post_name'    => $p->post_name,
					'post_parent'  => 0,
				),
				array()
			);
			if ( $r ) {
				$this->pages[ $p->ID ] = $r[0];
			}
		}
	}

	private function trips_copy() {
		$trips = $this->base_do(
			static function () {
				return get_posts( array( 'post_type' => 'trip', 'post_status' => 'publish', 'numberposts' => -1 ) );
			}
		);
		$pkgcats = $this->package_categories();
		foreach ( $trips as $p ) {
			$title = $this->t( "trip:{$p->ID}:title", $p->post_title );
			$r     = $this->copy_post(
				'trip',
				$p,
				array(
					'post_title'   => $title,
					'post_content' => $this->t( "trip:{$p->ID}:content", self::clean_html( $p->post_content ) ),
					'post_name'    => sanitize_title( remove_accents( $title ) ),
					'post_excerpt' => '',
				),
				array( 'packages_ids', 'primary_package', 'wpte_gallery_id', 'wp_travel_engine_setting' )
			);
			if ( ! $r ) {
				continue;
			}
			list( $id, $meta ) = $r;
			$this->trips[ $p->ID ] = $id;
			$s                     = (array) ( $meta['wp_travel_engine_setting'] ?? array() );
			foreach ( (array) ( $s['tab_content'] ?? array() ) as $tk => $tv ) {
				$s['tab_content'][ $tk ] = $this->t( "trip:{$p->ID}:tab:$tk", self::clean_html( $tv ) );
			}
			foreach ( array( 'includes_title', 'cost_includes', 'excludes_title', 'cost_excludes' ) as $ck ) {
				if ( isset( $s['cost'][ $ck ] ) ) {
					$s['cost'][ $ck ] = $this->t( "trip:{$p->ID}:cost:$ck", $s['cost'][ $ck ] );
				}
			}
			if ( isset( $s['trip_itinerary_title'] ) ) {
				$s['trip_itinerary_title'] = $this->t( "trip:{$p->ID}:itinerary_title", $s['trip_itinerary_title'] );
			}
			foreach ( array( 'itinerary' => array( 'itinerary_title' => 'it_title', 'itinerary_content' => 'it_content' ), 'faq' => array( 'faq_title' => 'faq_title', 'faq_content' => 'faq_content' ) ) as $group => $fields ) {
				foreach ( $fields as $f => $kk ) {
					foreach ( (array) ( $s[ $group ][ $f ] ?? array() ) as $ik => $iv ) {
						$s[ $group ][ $f ][ $ik ] = $this->t( "trip:{$p->ID}:$kk:$ik", $iv );
					}
				}
			}
			foreach ( (array) ( $s['trip_facts']['field_id'] ?? array() ) as $fk => $label ) {
				$s['trip_facts']['field_id'][ $fk ] = $this->fact_label( $label );
			}
			$s['trip_code'] = 'WTE-' . $id;
			update_post_meta( $id, 'wp_travel_engine_setting', wp_slash( $s ) );
			$gal = $meta['wpte_gallery_id'] ?? null;
			if ( is_array( $gal ) ) {
				$new = array();
				foreach ( $gal as $gk => $gv ) {
					$new[ $gk ] = 'enable' === $gk ? $gv : $this->media( $gv );
				}
				update_post_meta( $id, 'wpte_gallery_id', $new );
			}
			$this->packages( $p->ID, $id, $meta, $pkgcats );
			foreach ( self::WTE_TAXONOMIES as $tax ) {
				$slugs = $this->base_do(
					static function () use ( $p, $tax ) {
						return wp_get_post_terms( $p->ID, $tax, array( 'fields' => 'slugs' ) );
					}
				);
				wp_set_object_terms( $id, is_array( $slugs ) ? $slugs : array(), $tax, false );
			}
		}
	}

	/** Trip fact label translated (facts are named the same in the settings and in each trip). */
	private function fact_label( $label ) {
		$w = $this->base_do(
			static function () {
				return get_option( 'wp_travel_engine_settings' );
			}
		);
		foreach ( (array) ( $w['trip_facts']['field_id'] ?? array() ) as $i => $n ) {
			if ( $n === $label ) {
				return $this->t( "wte:fact:$i", $label );
			}
		}
		return $label;
	}

	/** Package (price) categories: base term id => this site's, with translated names. */
	private function package_categories() {
		$map  = array();
		$cats = $this->base_do(
			static function () {
				$t = get_terms( array( 'taxonomy' => 'trip-packages-categories', 'hide_empty' => false ) );
				return is_array( $t ) ? $t : array();
			}
		);
		foreach ( $cats as $t ) {
			$have = get_term_by( 'slug', $t->slug, 'trip-packages-categories' );
			$name = $this->t( "pkgcat:{$t->slug}", $t->name );
			if ( ! $have ) {
				$r    = wp_insert_term( $name, 'trip-packages-categories', array( 'slug' => $t->slug ) );
				$have = is_wp_error( $r ) ? null : get_term( $r['term_id'] );
			} else {
				wp_update_term( $have->term_id, 'trip-packages-categories', array( 'name' => $name ) );
			}
			if ( $have ) {
				$map[ $t->term_id ] = array( (int) $have->term_id, $name );
			}
		}
		return $map;
	}

	private function remap_cats( $v, array $map ) {
		if ( ! is_array( $v ) ) {
			return $v;
		}
		$out = array();
		foreach ( $v as $k => $x ) {
			$nk = is_numeric( $k ) && isset( $map[ (int) $k ] ) ? $map[ (int) $k ][0] : $k;
			if ( 'c_ids' === $k && is_array( $x ) ) {
				$y = array();
				foreach ( $x as $ck => $cv ) {
					$y[ isset( $map[ (int) $ck ] ) ? $map[ (int) $ck ][0] : $ck ] = isset( $map[ (int) $cv ] ) ? $map[ (int) $cv ][0] : $cv;
				}
				$out[ $k ] = $y;
			} elseif ( 'labels' === $k && is_array( $x ) ) {
				$y = array();
				foreach ( $x as $ck => $cv ) {
					$y[ isset( $map[ (int) $ck ] ) ? $map[ (int) $ck ][0] : $ck ] = isset( $map[ (int) $ck ] ) ? $map[ (int) $ck ][1] : $cv;
				}
				$out[ $k ] = $y;
			} else {
				$out[ $nk ] = is_array( $x ) ? $this->remap_cats( $x, $map ) : $x;
			}
		}
		return $out;
	}

	private function packages( $base_trip, $trip, array $meta, array $cats ) {
		$pkgs = array();
		foreach ( (array) ( $meta['packages_ids'] ?? array() ) as $pid ) {
			$p = $this->base_do(
				static function () use ( $pid ) {
					return get_post( $pid );
				}
			);
			if ( ! $p ) {
				continue;
			}
			$r = $this->copy_post( 'trip-packages', $p, array( 'post_title' => $p->post_title ), array( 'trip_ID', 'package-categories', 'group-pricing' ) );
			if ( ! $r ) {
				continue;
			}
			list( $id, $pm ) = $r;
			update_post_meta( $id, 'trip_ID', $trip );
			foreach ( array( 'package-categories', 'group-pricing' ) as $k ) {
				if ( isset( $pm[ $k ] ) ) {
					update_post_meta( $id, $k, wp_slash( $this->remap_cats( $pm[ $k ], $cats ) ) );
				}
			}
			$pkgs[ (int) $pid ] = $id;
		}
		update_post_meta( $trip, 'packages_ids', array_values( $pkgs ) );
		$primary = (int) ( $meta['primary_package'] ?? 0 );
		update_post_meta( $trip, 'primary_package', $pkgs[ $primary ] ?? ( $pkgs ? reset( $pkgs ) : '' ) );
	}

	private function testimonials() {
		$items = $this->base_do(
			static function () {
				return get_posts( array( 'post_type' => 'tap_testimonial', 'post_status' => 'publish', 'numberposts' => -1 ) );
			}
		);
		foreach ( $items as $p ) {
			if ( ! $this->find( 'tap_testimonial', $p->ID ) ) {
				$same = get_posts( array( 'post_type' => 'tap_testimonial', 'title' => $p->post_title, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) );
				if ( $same ) {
					$this->mark( $same[0], $p->ID );
				}
			}
			$r = $this->copy_post( 'tap_testimonial', $p, array( 'post_title' => $p->post_title, 'post_content' => $this->t( "testimonial:{$p->ID}:content", self::clean_html( $p->post_content ) ) ), array() );
			if ( $r ) {
				update_post_meta( $r[0], '_tap_testimonail_visited_trip', $this->t( "testimonial:{$p->ID}:trip", $r[1]['_tap_testimonail_visited_trip'] ?? '' ) );
				update_post_meta( $r[0], '_tap_testimonail_trip_date', $this->t( "testimonial:{$p->ID}:date", $r[1]['_tap_testimonail_trip_date'] ?? '' ) );
			}
		}
	}

	private function posts_copy() {
		if ( empty( $this->o['posts'] ) ) {
			return;
		}
		foreach ( $this->post_queue() as $p ) {
			$this->post_one( $p );
		}
	}

	/** Action "post": copy the given base posts now (the worker publishes each when it is translated). */
	private function posts_now() {
		$ids   = array_map( 'intval', (array) ( $this->o['post_ids'] ?? array() ) );
		$posts = $this->base_do(
			static function () use ( $ids ) {
				return array_values( array_filter( array_map( 'get_post', $ids ) ) );
			}
		);
		$out = array();
		foreach ( $posts as $p ) {
			$id = $this->post_one( $p );
			if ( $id ) {
				$out[ $p->ID ] = array( 'id' => $id, 'url' => get_permalink( $id ) );
			}
		}
		return array( 'posts' => $out, 'log' => $this->log );
	}

	/** Copy one translated base post with its categories and link it; the new ID, or 0. */
	private function post_one( $p ) {
		if ( 'post' !== $p->post_type || ! isset( $this->tr[ "post:{$p->ID}:content" ] ) ) {
			return 0; // not translated yet: copied by a later run
		}
		$title = $this->t( "post:{$p->ID}:title", $p->post_title );
		$r     = $this->copy_post( 'post', $p, array( 'post_title' => $title, 'post_content' => $this->t( "post:{$p->ID}:content", $p->post_content ), 'post_excerpt' => $this->t( "post:{$p->ID}:excerpt", $p->post_excerpt ), 'post_name' => sanitize_title( remove_accents( $title ) ) ), array() );
		if ( ! $r ) {
			return 0;
		}
		$cats = $this->base_do(
			static function () use ( $p ) {
				return wp_get_post_terms( $p->ID, 'category' );
			}
		);
		foreach ( is_array( $cats ) ? $cats : array() as $t ) {
			if ( ! get_term_by( 'slug', $t->slug, 'category' ) ) {
				$name = $this->t( "term:category:{$t->slug}:name", html_entity_decode( $t->name ) );
				wp_insert_term( $name, 'category', array( 'slug' => $t->slug, 'description' => $this->t( "term:category:{$t->slug}:description", $t->description ) ) );
			}
		}
		wp_set_object_terms( $r[0], wp_list_pluck( is_array( $cats ) ? $cats : array(), 'slug' ), 'category', false );
		AOQ_Language_Links::link( array( $this->base => $p->ID, $this->target => $r[0] ) );
		$this->log[] = "post {$p->ID} -> {$r[0]}: $title";
		return (int) $r[0];
	}

	private function theme_mods() {
		$mods = $this->base_do( 'get_theme_mods' );
		$keep = array( 'nav_menu_locations', 'custom_css_post_id', 'sidebars_widgets', '0' );
		foreach ( (array) $mods as $k => $v ) {
			if ( in_array( (string) $k, $keep, true ) ) {
				continue;
			}
			if ( 'cta_link' === $k && get_theme_mod( 'cta_link' ) ) {
				continue; // the site's own WhatsApp number
			}
			if ( is_string( $v ) ) {
				$v = $this->t( "mod:$k", $v );
				$v = $this->local_url( $v );
			} elseif ( is_array( $v ) ) {
				foreach ( $v as $i => $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					foreach ( $row as $f => $x ) {
						if ( in_array( (string) $f, self::TEXT_KEYS, true ) && is_string( $x ) ) {
							$v[ $i ][ $f ] = $this->t( "mod:$k:$i:$f", $x );
						} elseif ( in_array( (string) $f, array( 'thumbnail', 'image' ), true ) && is_numeric( $x ) ) {
							$v[ $i ][ $f ] = $this->media( $x );
						} elseif ( 'url' === $f || 'link' === $f ) {
							$v[ $i ][ $f ] = $this->local_url( $x );
						}
					}
				}
			}
			if ( preg_match( '/(_image|^custom_logo)$/', (string) $k ) && is_numeric( $v ) ) {
				$v = $this->media( $v );
			}
			if ( preg_match( '/^popular_post_/', (string) $k ) ) {
				$v = $this->trips[ (int) $v ] ?? '';
			}
			if ( 'popular_cat' === $k && is_numeric( $v ) ) {
				$v = $this->termmap[ (int) $v ] ?? '';
			}
			set_theme_mod( $k, $v );
		}
		$hd = (array) ( $mods['header_image_data'] ?? array() );
		$hid = $this->media( $hd['attachment_id'] ?? 0 );
		if ( $hid ) {
			$url = wp_get_attachment_url( $hid );
			set_theme_mod( 'header_image', $url );
			set_theme_mod( 'header_image_data', (object) array_merge( $hd, array( 'attachment_id' => $hid, 'url' => $url, 'thumbnail_url' => $url ) ) );
		}
		$css = $this->base_do( 'wp_get_custom_css' );
		if ( $css ) {
			wp_update_custom_css_post( $css );
		}
	}

	private function site_options() {
		update_option( 'blogname', $this->t( 'site:blogname', $this->base_do( static fn() => get_option( 'blogname' ) ) ) );
		update_option( 'blogdescription', $this->t( 'site:blogdescription', $this->base_do( static fn() => get_option( 'blogdescription' ) ) ) );
		$base = $this->base_do(
			static function () {
				return array(
					'show_on_front'  => get_option( 'show_on_front' ),
					'front'          => (int) get_option( 'page_on_front' ),
					'posts'          => (int) get_option( 'page_for_posts' ),
					'posts_per_page' => (int) get_option( 'posts_per_page' ),
					'permalinks'     => get_option( 'permalink_structure' ),
				);
			}
		);
		update_option( 'show_on_front', $base['show_on_front'] );
		update_option( 'posts_per_page', $base['posts_per_page'] );
		if ( isset( $this->pages[ $base['front'] ] ) ) {
			update_option( 'page_on_front', $this->pages[ $base['front'] ] );
		}
		if ( isset( $this->pages[ $base['posts'] ] ) && ! get_option( 'page_for_posts' ) ) {
			update_option( 'page_for_posts', $this->pages[ $base['posts'] ] );
		}
		if ( ! get_option( 'permalink_structure' ) ) {
			update_option( 'permalink_structure', $base['permalinks'] );
		}
		// The theme's companion plugins are network-active; WP Travel Engine's permalink bases are per site.
		if ( ! get_option( 'wp_travel_engine_permalinks' ) ) {
			update_option( 'wp_travel_engine_permalinks', $this->base_do( static fn() => get_option( 'wp_travel_engine_permalinks' ) ) );
		}
		flush_rewrite_rules( false );
	}

	private function wte_settings() {
		$w = $this->base_do( static fn() => get_option( 'wp_travel_engine_settings' ) );
		if ( ! is_array( $w ) ) {
			return;
		}
		foreach ( (array) ( $w['pages'] ?? array() ) as $k => $pid ) {
			if ( is_numeric( $pid ) && isset( $this->pages[ (int) $pid ] ) ) {
				$w['pages'][ $k ] = (string) $this->pages[ (int) $pid ];
			}
		}
		foreach ( (array) ( $w['trip_tabs']['name'] ?? array() ) as $i => $n ) {
			$w['trip_tabs']['name'][ $i ] = $this->t( "wte:tab:$i", $n );
		}
		foreach ( (array) ( $w['trip_facts']['field_id'] ?? array() ) as $i => $n ) {
			$w['trip_facts']['field_id'][ $i ] = $this->t( "wte:fact:$i", $n );
		}
		foreach ( (array) ( $w['default_trip_facts'] ?? array() ) as $i => $f ) {
			if ( isset( $f['field_id'] ) ) {
				$w['default_trip_facts'][ $i ]['field_id'] = $this->t( "wte:dfact:$i", $f['field_id'] );
			}
		}
		if ( isset( $w['related_trips_section_title'] ) ) {
			$w['related_trips_section_title'] = $this->t( 'wte:related', $w['related_trips_section_title'] );
		}
		if ( isset( $w['query_subject'] ) ) {
			$w['query_subject'] = $this->t( 'wte:query_subject', $w['query_subject'] );
		}
		update_option( 'wp_travel_engine_settings', $w );
	}

	private function widgets_copy() {
		$base = $this->base_do( array( $this, 'widgets' ) );
		// Built fresh from the base site: a new site's own widget lists (its default theme's sidebar-1,
		// block-2 and block-3) would be moved into this theme's first sidebar by WordPress and show the
		// copied widgets of the same numbers twice (Italian site, 2026-11-16).
		$sw   = array(
			'wp_inactive_widgets' => array(),
			'array_version'       => 3,
		);
		$opts = array();
		foreach ( $base as $sidebar => $ws ) {
			$ids = array();
			foreach ( $ws as $wid => $w ) {
				if ( ! preg_match( '/^(.+)-(\d+)$/', $wid, $m ) ) {
					continue;
				}
				if ( 'travel_agency_featured_widget' === $m[1] || 'media_image' === $m[1] ) {
					continue; // they show one base-site post or image by ID
				}
				foreach ( array( 'title', 'text', 'content', 'readmore' ) as $f ) {
					if ( isset( $w[ $f ] ) && is_string( $w[ $f ] ) ) {
						$w[ $f ] = $this->t( "widget:$wid:$f", self::clean_html( $w[ $f ] ) );
					}
				}
				$opts[ $m[1] ][ (int) $m[2] ] = $w;
				$ids[]                        = $wid;
			}
			$sw[ $sidebar ] = $ids;
		}
		foreach ( $opts as $type => $instances ) {
			update_option( 'widget_' . $type, $instances + array( '_multiwidget' => 1 ) );
		}
		update_option( 'sidebars_widgets', $sw );
	}

	/** The main menu, mirrored: pages, tours and terms point to this site's copies. */
	private function menu() {
		$items = $this->base_do(
			function () {
				$id = $this->primary_menu();
				return $id ? (array) wp_get_nav_menu_items( $id ) : array();
			}
		);
		if ( ! $items ) {
			return;
		}
		$homes = array();
		foreach ( AOQ_Language_Links::sites() as $s ) {
			$homes[ untrailingslashit( $s['url'] ) ] = true;
		}
		$name = $this->t( 'site:blogname', get_option( 'blogname' ) );
		$menu = $this->primary_menu();
		if ( ! $menu ) {
			$menu = wp_create_nav_menu( $name . ' – menu' );
			$menu = is_wp_error( $menu ) ? (int) ( wp_get_nav_menu_object( $name . ' – menu' )->term_id ?? 0 ) : $menu;
		}
		foreach ( (array) wp_get_nav_menu_items( $menu ) as $it ) {
			wp_delete_post( $it->ID, true );
		}
		// Language items and the item they hang under: link() adds one set for every language.
		$lang_parents = array();
		foreach ( $items as $it ) {
			if ( isset( $homes[ untrailingslashit( (string) $it->url ) ] ) && $it->menu_item_parent ) {
				$lang_parents[ (int) $it->menu_item_parent ] = true;
			}
		}
		$map = array();
		foreach ( $items as $it ) {
			if ( isset( $homes[ untrailingslashit( (string) $it->url ) ] ) || isset( $lang_parents[ (int) $it->ID ] ) ) {
				continue;
			}
			$args = array(
				'menu-item-title'     => $this->t( "menu:{$it->ID}", $it->title ),
				'menu-item-status'    => 'publish',
				'menu-item-position'  => (int) $it->menu_order,
				'menu-item-parent-id' => $map[ (int) $it->menu_item_parent ] ?? 0,
			);
			if ( 'post_type' === $it->type && 'page' === $it->object && isset( $this->pages[ (int) $it->object_id ] ) ) {
				$args += array( 'menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $this->pages[ (int) $it->object_id ] );
			} elseif ( 'post_type' === $it->type && 'trip' === $it->object && isset( $this->trips[ (int) $it->object_id ] ) ) {
				$args += array( 'menu-item-type' => 'post_type', 'menu-item-object' => 'trip', 'menu-item-object-id' => $this->trips[ (int) $it->object_id ] );
			} elseif ( 'taxonomy' === $it->type && isset( $this->termmap[ (int) $it->object_id ] ) ) {
				$args += array( 'menu-item-type' => 'taxonomy', 'menu-item-object' => $it->object, 'menu-item-object-id' => $this->termmap[ (int) $it->object_id ] );
			} else {
				$args += array( 'menu-item-type' => 'custom', 'menu-item-url' => $this->local_url( preg_replace( '#(?<!:)//+#', '/', (string) $it->url ) ) );
			}
			$map[ (int) $it->ID ] = (int) wp_update_nav_menu_item( $menu, 0, $args );
		}
		$loc            = get_nav_menu_locations();
		$loc['primary'] = $menu;
		set_theme_mod( 'nav_menu_locations', $loc );
	}

	// ------------------------------------------------------------------ link

	/** Language links and the language menus of every language site. */
	private function link() {
		$n = 0;
		foreach ( array( 'page', 'trip', 'post' ) as $type ) {
			foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_aoq_source' ) ) as $p ) {
				list( $blog, $id ) = array_map( 'intval', explode( ':', (string) get_post_meta( $p->ID, '_aoq_source', true ) ) );
				if ( $blog === $this->base && $id ) {
					AOQ_Language_Links::link( array( $this->base => $id, $this->target => $p->ID ) );
					++$n;
				}
			}
		}
		// Every language site's main menu gets a language item per other language, under its
		// "Languages" item (the one whose children link to other sites' home pages).
		$sites = AOQ_Language_Links::sites();
		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site['blog_id'] );
			$this->language_menu( $sites, (int) $site['blog_id'] );
			restore_current_blog();
		}
		return array( 'linked' => $n );
	}

	private function language_menu( array $sites, $blog_id ) {
		$menu = $this->primary_menu();
		if ( ! $menu ) {
			return;
		}
		$items  = (array) wp_get_nav_menu_items( $menu );
		$homes  = array();
		$parent = 0;
		foreach ( $sites as $s ) {
			$homes[ untrailingslashit( $s['url'] ) ] = $s;
		}
		$have = array();
		foreach ( $items as $it ) {
			$home = untrailingslashit( (string) $it->url );
			if ( isset( $homes[ $home ] ) ) {
				$have[ $home ] = true;
				$parent        = (int) $it->menu_item_parent;
			}
		}
		if ( ! $parent ) {
			$label  = $this->native_name( get_locale(), 'languages' );
			$parent = (int) wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => $label, 'menu-item-type' => 'custom', 'menu-item-url' => '#', 'menu-item-status' => 'publish', 'menu-item-position' => 99 ) );
		}
		foreach ( $homes as $home => $s ) {
			if ( (int) $s['blog_id'] === $blog_id || isset( $have[ $home ] ) ) {
				continue;
			}
			wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => $this->native_name( $s['locale'] ), 'menu-item-type' => 'custom', 'menu-item-url' => trailingslashit( $home ), 'menu-item-status' => 'publish', 'menu-item-parent-id' => $parent ) );
		}
	}

	/** A language's own name (Deutsch, Bosanski), or the word for "Languages" in a locale. */
	private function native_name( $locale, $what = 'name' ) {
		if ( 'languages' === $what ) {
			return $this->t( 'ui:Languages', 'Languages' );
		}
		require_once ABSPATH . 'wp-admin/includes/translation-install.php';
		$all = wp_get_available_translations();
		if ( isset( $all[ $locale ]['native_name'] ) ) {
			return preg_replace( '/\s*\(.*\)$/', '', $all[ $locale ]['native_name'] );
		}
		return class_exists( 'Locale' ) ? ucfirst( Locale::getDisplayLanguage( $locale, $locale ) ) : $locale;
	}

	// ------------------------------------------------------------------ mo

	/** Plugin interface words as a language file in wp-content/languages/plugins/. */
	private function mo() {
		require_once ABSPATH . WPINC . '/pomo/mo.php';
		$locale = get_locale();
		$mo     = new MO();
		$mo->set_header( 'Language', $locale );
		$mo->set_header( 'Content-Type', 'text/plain; charset=UTF-8' );
		$n = 0;
		foreach ( self::UI_WORDS as $word ) {
			$t = $this->tr[ 'ui:' . $word ] ?? null;
			if ( ! is_string( $t ) || '' === $t ) {
				continue;
			}
			$parts = explode( "\x04", $word );
			$entry = 2 === count( $parts ) ? array( 'context' => $parts[0], 'singular' => $parts[1], 'translations' => array( $t ) ) : array( 'singular' => $word, 'translations' => array( $t ) );
			$mo->add_entry( new Translation_Entry( $entry ) );
			++$n;
		}
		$file = WP_LANG_DIR . '/plugins/' . self::UI_DOMAIN . '-' . $locale . '.mo';
		$official = file_exists( $file ) && ! get_site_option( 'aoq_generated_mo_' . $locale );
		if ( $official ) {
			return array( 'skipped' => 'an official language file exists', 'file' => $file );
		}
		wp_mkdir_p( dirname( $file ) );
		$mo->export_to_file( $file );
		update_site_option( 'aoq_generated_mo_' . $locale, 1 );
		return array( 'written' => $file, 'strings' => $n );
	}

	// ------------------------------------------------------------------ pages to verify

	private function pages() {
		$out   = array( array( 'base' => get_home_url( $this->base, '/' ), 'target' => home_url( '/' ) ) );
		$types = array( 'page', 'trip' );
		foreach ( $types as $type ) {
			foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_aoq_source' ) ) as $p ) {
				list( $blog, $id ) = array_map( 'intval', explode( ':', (string) get_post_meta( $p->ID, '_aoq_source', true ) ) );
				$base = $blog ? AOQ_Language_Links::post_url( $blog, $id ) : null;
				if ( $base ) {
					$out[] = array( 'base' => $base, 'target' => get_permalink( $p ), 'title' => $p->post_title );
				}
			}
		}
		return $out;
	}
}
