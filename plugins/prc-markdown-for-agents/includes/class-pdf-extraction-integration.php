<?php

/**
 * Integration with prc-pdf-extraction plugin.
 *
 * Hooks into the prc_markdown_for_agents_frontmatter filter to add the extraction
 * URL when the post has extracted PDF content (from prc-pdf-extraction).
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

namespace PRC\Platform\Markdown_For_Agents;

/**
 * Connects prc-markdown-for-agents with prc-pdf-extraction.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */
class PDF_Extraction_Integration {

	/**
	 * The loader instance.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader instance.
	 */
	public function __construct( Loader $loader ) {
		$this->loader = $loader;
		$this->loader->add_filter( 'prc_markdown_for_agents_frontmatter', $this, 'add_extraction_to_frontmatter', 10, 2 );
	}

	/**
	 * Add extraction URL to frontmatter when prc-pdf-extraction is active and post has extraction content.
	 *
	 * @param array    $data The frontmatter data.
	 * @param \WP_Post $post The post being converted.
	 * @return array The modified frontmatter data.
	 */
	public function add_extraction_to_frontmatter( $data, $post ) {
		if ( ! class_exists( 'PRC\Platform\PDF_Extraction\Content_Type' ) ) {
			return $data;
		}

		$permalink = get_permalink( $post );
		if ( ! $permalink ) {
			return $data;
		}

		$extraction_posts = get_posts(
			array(
				'post_type'   => \PRC\Platform\PDF_Extraction\Content_Type::get_post_type(),
				'post_parent' => $post->ID,
				'numberposts' => 1,
				'post_status' => 'publish',
			)
		);

		if ( empty( $extraction_posts ) ) {
			return $data;
		}

		$url_slug = \PRC\Platform\PDF_Extraction\Content_Type::get_url_slug();
		$permalink = rtrim( $permalink, '/' );
		$data[ $url_slug ] = $permalink . '/' . $url_slug;

		return $data;
	}
}
