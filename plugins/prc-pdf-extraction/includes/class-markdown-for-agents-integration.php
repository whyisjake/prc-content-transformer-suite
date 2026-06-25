<?php
/**
 * Integration with prc-markdown-for-agents plugin.
 *
 * Registers the topline post type with the markdown pipeline, supplies
 * pre-built OCR markdown when available, and enriches frontmatter with
 * extraction metadata.
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

/**
 * Connects prc-pdf-extraction with prc-markdown-for-agents.
 *
 * @package PRC_PDF_Extraction
 */
class Markdown_For_Agents_Integration {

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

		$this->loader->add_action( 'init', $this, 'register_post_type_support', 10 );
		$this->loader->add_filter( 'prc_markdown_for_agents_frontmatter', $this, 'enrich_frontmatter', 10, 2 );
	}

	/**
	 * Declare prc-markdown-for-agents support for the topline post type.
	 *
	 * Enables .md URLs, Accept: text/markdown content negotiation, and
	 * discovery link tags for topline posts.
	 *
	 * @hook init
	 */
	public function register_post_type_support() {
		add_post_type_support( Content_Type::get_post_type(), 'prc-markdown-for-agents' );
	}

	/**
	 * Enrich frontmatter with topline extraction metadata.
	 *
	 * Adds OCR provider, confidence, extraction date, and source information
	 * to the YAML frontmatter for topline posts. Also links back to the
	 * parent article.
	 *
	 * @param array    $data Frontmatter data array.
	 * @param \WP_Post $post The post being converted.
	 * @return array
	 */
	public function enrich_frontmatter( $data, $post ) {
		if ( Content_Type::get_post_type() !== $post->post_type ) {
			return $data;
		}

		$provider   = get_post_meta( $post->ID, '_ocr_provider_used', true );
		$confidence = get_post_meta( $post->ID, '_ocr_confidence_score', true );
		$date       = get_post_meta( $post->ID, '_extraction_date', true );
		$source_url = get_post_meta( $post->ID, '_pdf_source_url', true );

		if ( ! empty( $provider ) ) {
			$data['ocr_provider'] = $provider;
		}

		if ( is_numeric( $confidence ) && '' !== $confidence ) {
			$data['ocr_confidence'] = round( (float) $confidence * 100, 2 ) . '%';
		}

		if ( ! empty( $date ) ) {
			$data['extraction_date'] = $date;
		}

		if ( ! empty( $source_url ) ) {
			$data['source_url'] = $source_url;
		}

		// Link back to the parent article if this topline has a parent post.
		if ( $post->post_parent ) {
			$parent_permalink = get_permalink( $post->post_parent );
			if ( $parent_permalink ) {
				$data['parent_article'] = $parent_permalink;
			}
		}

		return $data;
	}
}
