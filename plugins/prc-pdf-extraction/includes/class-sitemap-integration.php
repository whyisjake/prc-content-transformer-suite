<?php
/**
 * Sitemap Integration
 *
 * Integrates topline posts into PRC sitemaps.
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

/**
 * Sitemap Integration class
 */
class Sitemap_Integration {
	/**
	 * The loader instance
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Constructor
	 *
	 * @param Loader $loader The loader instance.
	 */
	public function __construct( Loader $loader ) {
		$this->loader = $loader;

		$this->loader->add_filter( 'prc_sitemap_supported_post_types', $this, 'add_to_sitemap' );
		$this->loader->add_filter( 'prc_sitemap_entry', $this, 'add_alternate_formats', 10, 2 );
	}

	/**
	 * Add topline to supported post types
	 *
	 * @param array $post_types Supported post types.
	 * @return array Modified post types.
	 */
	public function add_to_sitemap( $post_types ) {
		$post_types[] = Content_Type::get_post_type();
		return $post_types;
	}

	/**
	 * Add alternate format URLs to sitemap entries for topline posts.
	 *
	 * Adds <xhtml:link rel="alternate"> elements for text, markdown, and topline
	 * formats. The xmlns:xhtml namespace is declared on the root <urlset> element
	 * by prc-schema-sitemap so these children are valid.
	 *
	 * @param SimpleXMLElement $entry_xml Sitemap <url> entry XML element.
	 * @param int              $post_id   Post ID being processed. Defaults to 0 (resolved via get_the_ID()).
	 * @return SimpleXMLElement The (possibly modified) entry XML element.
	 */
	public function add_alternate_formats( $entry_xml, $post_id = 0 ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		if ( ! $post_id ) {
			return $entry_xml;
		}

		// Only add alternates for topline posts.
		if ( Content_Type::get_post_type() !== get_post_type( $post_id ) ) {
			return $entry_xml;
		}

		// Require a parent post to build alternate URLs from.
		$post = get_post( $post_id );
		if ( ! $post || ! $post->post_parent ) {
			return $entry_xml;
		}

		$parent_id = $post->post_parent;

		$formats = array(
			'text'     => 'text/plain',
			'markdown' => 'text/markdown',
			'topline'  => 'text/markdown',
		);

		$xhtml_ns = 'http://www.w3.org/1999/xhtml';

		foreach ( $formats as $format => $mime_type ) {
			$link = $entry_xml->addChild( 'xhtml:link', '', $xhtml_ns );
			$link->addAttribute( 'rel', 'alternate' );
			$link->addAttribute( 'type', $mime_type );
			$link->addAttribute( 'href', home_url( "/topline/{$parent_id}/{$format}" ) );
		}

		return $entry_xml;
	}
}
