<?php
/**
 * Multilingual adapter contract. One implementation per multilingual setup
 * (multisite per language, WPML, Polylang, TranslatePress). Only the multisite
 * adapter is implemented; see docs/decisions.md D4.
 *
 * @package AOQuill
 */

defined( 'ABSPATH' ) || exit;

interface AOQ_Translation_Adapter {

	/**
	 * All site languages, excluded ones included and flagged.
	 *
	 * @return array<int, array{code:string, locale:string, name:string, blog_id:int, url:string, primary:bool, excluded:bool}>
	 */
	public function languages();

	/**
	 * Language code of the primary language.
	 *
	 * @return string
	 */
	public function primary_language();

	/**
	 * Language code of a post.
	 *
	 * @param int $blog_id Site ID.
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	public function post_language( $blog_id, $post_id );

	/**
	 * Known translations of a term, as a list of {language, id}.
	 *
	 * @param int    $blog_id  Site ID.
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 * @return array<int, array{language:string, id:int}>
	 */
	public function term_translations( $blog_id, $taxonomy, $term_id );

	/**
	 * Translation group of a post, or null.
	 *
	 * @param int $blog_id Site ID.
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	public function translation_group( $blog_id, $post_id );

	/**
	 * Link posts as translations of each other.
	 *
	 * @param array<int, array{language:string, blog_id:int, post_id:int}> $posts  Posts to link.
	 * @param string                                                        $run_id Run that created them.
	 * @return string Translation group ID.
	 */
	public function link_translations( array $posts, $run_id );

	/**
	 * Remove a translation group (used by rollback).
	 *
	 * @param string $group_id Group ID.
	 * @return void
	 */
	public function unlink_group( $group_id );
}
