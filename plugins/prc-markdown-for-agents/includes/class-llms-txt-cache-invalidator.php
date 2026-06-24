<?php
/**
 * Cache invalidation for /llms.txt rendered body.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

declare( strict_types=1 );

namespace PRC\Platform\Markdown_For_Agents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Busts object cache and VIP edge cache for /llms.txt on relevant content changes.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */
class Llms_Txt_Cache_Invalidator {

	/**
	 * Topics taxonomy slug for term-change invalidation.
	 */
	public const TOPICS_TAXONOMY = 'category';

	/**
	 * Loader instance.
	 *
	 * @var Loader
	 */
	protected Loader $loader;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader instance.
	 */
	public function __construct( Loader $loader ) {
		$this->loader = $loader;

		$this->loader->add_action( 'save_post', $this, 'maybe_invalidate', 10, 3 );
		$this->loader->add_action( 'deleted_post', $this, 'invalidate', 10, 2 );
		$this->loader->add_action( 'wp_trash_post', $this, 'invalidate', 10, 1 );
		$this->loader->add_action( 'updated_option', $this, 'maybe_invalidate_option', 10, 3 );
		$this->loader->add_action( 'created_term', $this, 'maybe_invalidate_term', 10, 3 );
		$this->loader->add_action( 'edited_term', $this, 'maybe_invalidate_term', 10, 3 );
		$this->loader->add_action( 'deleted_term', $this, 'maybe_invalidate_term', 10, 3 );
	}

	/**
	 * Invalidate cache when a contributing post type is saved.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $_post   Post object.
	 * @param bool          $_update Whether this is an update.
	 */
	public function maybe_invalidate( int $post_id, ?\WP_Post $_post, bool $_update ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		if ( ! is_string( $post_type ) || ! post_type_supports( $post_type, 'prc-markdown-for-agents-llms-txt' ) ) {
			return;
		}

		self::purge_cache();
	}

	/**
	 * Invalidate cache for delete/trash events (post type checked upstream by hook usage).
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object (available on deleted_post before row removal).
	 */
	public function invalidate( int $post_id, ?\WP_Post $post = null ): void {
		$post_type = ( $post instanceof \WP_Post ) ? $post->post_type : get_post_type( $post_id );
		if ( ! is_string( $post_type ) || ! post_type_supports( $post_type, 'prc-markdown-for-agents-llms-txt' ) ) {
			return;
		}

		self::purge_cache();
	}

	/**
	 * Invalidate when Featured Posts settings or Content-Signal option changes.
	 *
	 * @param string $option Option name.
	 */
	public function maybe_invalidate_option( string $option ): void {
		if ( in_array( $option, array( Settings::OPTION_KEY, 'prc_markdown_for_agents_content_signal' ), true ) ) {
			self::purge_cache();
		}
	}

	/**
	 * Invalidate when a Topics taxonomy term changes.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function maybe_invalidate_term( int $term_id, int $tt_id, string $taxonomy ): void {
		unset( $term_id, $tt_id );
		if ( self::TOPICS_TAXONOMY === $taxonomy ) {
			self::purge_cache();
		}
	}

	/**
	 * Delete cached body and purge VIP edge cache for /llms.txt.
	 */
	public static function purge_cache(): void {
		wp_cache_delete( LLMs_Txt::CACHE_KEY, LLMs_Txt::CACHE_GROUP );

		if ( function_exists( 'wpcom_invalidate_cache_for_url' ) ) {
			wpcom_invalidate_cache_for_url( home_url( '/llms.txt' ) );
		}
	}
}
