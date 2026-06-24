<?php
/**
 * Post Meta class.
 *
 * Registers all Apple News post meta keys for the 'post' and 'short-read' post types.
 *
 * @package PRC\Platform\Apple_News
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News;

/**
 * Post Meta class.
 *
 * @package PRC\Platform\Apple_News
 */
class Post_Meta {

	/**
	 * The post types to register meta against.
	 *
	 * @var string[]
	 */
	private const POST_TYPES = array( 'post', 'short-read' );

	/**
	 * Initialize and register hooks via the loader.
	 *
	 * @param Loader $loader The plugin hook loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'init', $this, 'register', 10 );
	}

	/**
	 * Register all Apple News post meta keys.
	 *
	 * @hook init
	 */
	public function register(): void {
		foreach ( self::POST_TYPES as $post_type ) {
			// Apple News article ID returned from the API.
			register_post_meta(
				$post_type,
				'apple_news_api_id',
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);

			// Apple News article revision token.
			register_post_meta(
				$post_type,
				'apple_news_api_revision',
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);

			// ISO 8601 timestamp when the article was first published to Apple News.
			register_post_meta(
				$post_type,
				'apple_news_api_created_at',
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);

			// ISO 8601 timestamp when the article was last updated in Apple News.
			register_post_meta(
				$post_type,
				'apple_news_api_modified_at',
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);

			// Public share URL for the article in Apple News.
			register_post_meta(
				$post_type,
				'apple_news_api_share_url',
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'esc_url_raw',
				)
			);

			// Timestamp string indicating a pending publish/update operation.
			register_post_meta(
				$post_type,
				'apple_news_api_pending',
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);

			// Publish article to Apple News as a preview/draft (isPreview API metadata).
			register_post_meta(
				$post_type,
				'apple_news_is_preview',
				array(
					'type'         => 'boolean',
					'single'       => true,
					'default'      => false,
					'show_in_rest' => true,
				)
			);

			// Publish article as hidden from feeds (isHidden API metadata).
			register_post_meta(
				$post_type,
				'apple_news_is_hidden',
				array(
					'type'         => 'boolean',
					'single'       => true,
					'default'      => false,
					'show_in_rest' => true,
				)
			);

			// Checksum of the last article payload sent; used to detect content changes.
			register_post_meta(
				$post_type,
				'apple_news_article_checksum',
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);

			// JSON string of Apple News section IDs assigned to this article.
			register_post_meta(
				$post_type,
				'apple_news_sections',
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);

			// JSON-encoded array of ANF schema validation error strings from the last failed push attempt.
			register_post_meta(
				$post_type,
				'_apple_news_validation_errors',
				array(
					'type'         => 'string',
					'single'       => true,
					'show_in_rest' => false,
				)
			);
		}
	}
}
