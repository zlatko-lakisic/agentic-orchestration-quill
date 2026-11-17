<?php
/**
 * AI marking that never shows on the public site: protected post meta plus a
 * private taxonomy that only the admin sees.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

final class AOQ_Marking {

	const TAXONOMY  = 'aoq_origin';
	const TERM      = 'ai-generated';
	const META_FLAG = '_aoq_generated';
	const META_RUN  = '_aoq_run_id';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 5 );
		add_filter( 'post_class_taxonomies', array( __CLASS__, 'strip_taxonomy' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( __CLASS__, 'strip_taxonomy' ) );
		add_filter( 'is_protected_meta', array( __CLASS__, 'protect_meta' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'admin_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_admin_filter' ) );
	}

	/**
	 * Apply the admin origin filter (the taxonomy has no public query var).
	 *
	 * @param WP_Query $query Query.
	 */
	public static function apply_admin_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || empty( $_GET[ self::TAXONOMY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( self::TERM !== sanitize_key( wp_unslash( $_GET[ self::TAXONOMY ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$query->set(
			'tax_query',
			array(
				array(
					'taxonomy' => self::TAXONOMY,
					'field'    => 'slug',
					'terms'    => self::TERM,
				),
			)
		);
	}

	/**
	 * Register the private taxonomy on every site.
	 */
	public static function register() {
		register_taxonomy(
			self::TAXONOMY,
			array( 'post' ),
			array(
				'labels'             => array(
					'name'          => __( 'Origin', 'ao-quill' ),
					'singular_name' => __( 'Origin', 'ao-quill' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_in_rest'       => false,
				'show_in_nav_menus'  => false,
				'show_tagcloud'      => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_admin_column'  => true,
				'show_in_quick_edit' => false,
				'query_var'          => false,
				'rewrite'            => false,
				'hierarchical'       => false,
				'capabilities'       => array(
					'manage_terms' => 'manage_options',
					'edit_terms'   => 'manage_options',
					'delete_terms' => 'manage_options',
					'assign_terms' => 'manage_options',
				),
			)
		);
	}

	/**
	 * Remove the taxonomy from a list of taxonomies (post classes, core sitemaps).
	 *
	 * @param array $taxonomies Taxonomies, keyed by name or as values.
	 * @return array
	 */
	public static function strip_taxonomy( $taxonomies ) {
		unset( $taxonomies[ self::TAXONOMY ] );
		return array_filter(
			(array) $taxonomies,
			static function ( $tax ) {
				$name = is_object( $tax ) ? $tax->name : $tax;
				return self::TAXONOMY !== $name;
			}
		);
	}

	/**
	 * Treat our meta as protected even if a theme changes the underscore rule.
	 *
	 * @param bool   $protected Current value.
	 * @param string $meta_key  Key.
	 * @return bool
	 */
	public static function protect_meta( $protected, $meta_key ) {
		return in_array( $meta_key, array( self::META_FLAG, self::META_RUN ), true ) ? true : $protected;
	}

	/**
	 * Mark a post on the current site.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $run_id  Run ID.
	 * @throws RuntimeException On failure.
	 */
	public static function mark( $post_id, $run_id ) {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			self::register();
		}
		update_post_meta( $post_id, self::META_FLAG, 1 );
		update_post_meta( $post_id, self::META_RUN, $run_id );
		$term = term_exists( self::TERM, self::TAXONOMY );
		if ( ! $term ) {
			$term = wp_insert_term( __( 'AI generated', 'ao-quill' ), self::TAXONOMY, array( 'slug' => self::TERM ) );
		}
		if ( is_wp_error( $term ) ) {
			throw new RuntimeException( 'Could not create the AI marking term: ' . $term->get_error_message() );
		}
		$set = wp_set_object_terms( $post_id, (int) $term['term_id'], self::TAXONOMY, false );
		if ( is_wp_error( $set ) ) {
			throw new RuntimeException( 'Could not mark post: ' . $set->get_error_message() );
		}
	}

	/**
	 * Whether a post on the current site is AI generated.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_generated( $post_id ) {
		return (bool) get_post_meta( $post_id, self::META_FLAG, true );
	}

	/**
	 * Admin posts list filter by origin.
	 *
	 * @param string $post_type Post type.
	 */
	public static function admin_filter( $post_type ) {
		if ( 'post' !== $post_type ) {
			return;
		}
		$current = isset( $_GET[ self::TAXONOMY ] ) ? sanitize_key( wp_unslash( $_GET[ self::TAXONOMY ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<label class="screen-reader-text" for="aoq-origin-filter">' . esc_html__( 'Filter by origin', 'ao-quill' ) . '</label>';
		echo '<select name="' . esc_attr( self::TAXONOMY ) . '" id="aoq-origin-filter">';
		echo '<option value="">' . esc_html__( 'All origins', 'ao-quill' ) . '</option>';
		echo '<option value="' . esc_attr( self::TERM ) . '"' . selected( $current, self::TERM, false ) . '>' . esc_html__( 'AI generated', 'ao-quill' ) . '</option>';
		echo '</select>';
	}
}
