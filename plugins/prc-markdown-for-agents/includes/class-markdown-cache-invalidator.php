<?php
/**
 * Markdown cache invalidator.
 *
 * Hooks into post lifecycle events to clear cached markdown documents.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

namespace PRC\Platform\Markdown_For_Agents;

/**
 * Invalidates markdown document cache when posts are saved, trashed, or deleted.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */
class Markdown_Cache_Invalidator {

	/**
	 * Loader instance.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader instance.
	 */
	public function __construct( $loader ) {
		$this->loader = $loader;

		$this->loader->add_action( 'save_post', $this, 'maybe_clear_cache', 10, 3 );
		$this->loader->add_action( 'deleted_post', $this, 'clear_cache', 10, 1 );
		$this->loader->add_action( 'wp_trash_post', $this, 'clear_cache', 10, 1 );
	}

	/**
	 * Maybe clear the cache on save if post type is enabled.
	 *
	 * Clears this post's cache and, when the post is a child (e.g. report chapter),
	 * the parent's cache so the parent's TOC stays current.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $_post   Post object (unused; signature matches save_post).
	 * @param bool     $_update Whether this is an existing post (unused; signature matches save_post).
	 */
	public function maybe_clear_cache( $post_id, $_post, $_update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		if ( ! post_type_supports( $post_type, 'prc-markdown-for-agents' ) ) {
			return;
		}

		Markdown_Response::delete_cache( $post_id );

		// When a chapter is saved, the parent report's cached markdown (TOC with chapter titles) becomes stale.
		$parent_id = wp_get_post_parent_id( $post_id );
		if ( 0 !== $parent_id ) {
			Markdown_Response::delete_cache( $parent_id );
		}
	}

	/**
	 * Clear cache for a post (delete/trash).
	 *
	 * Also clears the parent's cache when the post is a child (e.g. report chapter)
	 * so the parent's TOC is invalidated.
	 *
	 * @param int $post_id Post ID.
	 */
	public function clear_cache( $post_id ) {
		Markdown_Response::delete_cache( $post_id );

		$parent_id = wp_get_post_parent_id( $post_id );
		if ( 0 !== $parent_id ) {
			Markdown_Response::delete_cache( $parent_id );
		}
	}
}
