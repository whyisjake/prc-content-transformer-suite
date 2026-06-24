<?php

/**
 * Integration with prc-datasets plugin.
 *
 * Hooks into the prc_markdown_for_agents_frontmatter filter to add dataset
 * name and URL for posts that have datasets assigned via the datasets taxonomy.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

namespace PRC\Platform\Markdown_For_Agents;

/**
 * Connects prc-markdown-for-agents with prc-datasets.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */
class Datasets_Integration {

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
		$this->loader->add_filter( 'prc_markdown_for_agents_frontmatter', $this, 'add_datasets_to_frontmatter', 10, 2 );
	}

	/**
	 * Add datasets (name and url) to frontmatter when the post has datasets assigned.
	 *
	 * @param array    $data The frontmatter data.
	 * @param \WP_Post $post The post being converted.
	 * @return array The modified frontmatter data.
	 */
	public function add_datasets_to_frontmatter( $data, $post ) {
		if ( ! taxonomy_exists( 'datasets' ) || ! function_exists( '\TDS\get_related_post' ) ) {
			return $data;
		}

		$terms = wp_get_post_terms( $post->ID, 'datasets' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return $data;
		}

		$datasets = array();
		foreach ( $terms as $term ) {
			$url = get_term_link( $term );
			if ( is_wp_error( $url ) ) {
				continue;
			}
			$datasets[] = array(
				'name' => html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'  => $url,
			);
		}

		if ( ! empty( $datasets ) ) {
			$data['datasets'] = $datasets;
		}

		return $data;
	}
}
