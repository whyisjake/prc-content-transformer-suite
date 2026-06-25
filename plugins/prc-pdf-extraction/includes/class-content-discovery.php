<?php
/**
 * Content Discovery for Topline Extractions
 *
 * Adds meta tags, JSON-LD, and discovery endpoints to make extracted
 * topline content discoverable by LLMs and crawlers.
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

/**
 * Adds link rel="alternate" tags, JSON-LD structured data, LLMs.txt endpoint,
 * and robots.txt rules for topline extraction discovery.
 */
class Content_Discovery {

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

		$this->loader->add_action( 'wp_head', $this, 'add_alternate_link_tags', 10 );
		$this->loader->add_action( 'wp_head', $this, 'add_json_ld_structured_data', 11 );
		$this->loader->add_filter( 'robots_txt', $this, 'add_robots_txt_rules', 10, 2 );
	}

	/**
	 * Add link rel="alternate" tags for /topline and /text endpoints.
	 *
	 * Emitted when the current post has a published topline child.
	 *
	 * @hook wp_head
	 */
	public function add_alternate_link_tags() {
		if ( ! is_singular() ) {
			return;
		}

		global $post;
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		$extraction = $this->get_extraction_for_post( $post->ID );
		if ( ! $extraction ) {
			return;
		}

		$permalink = get_permalink( $post );
		if ( ! $permalink ) {
			return;
		}

		$path = wp_parse_url( $permalink, PHP_URL_PATH );
		if ( ! $path ) {
			return;
		}

		$base = rtrim( $path, '/' );
		$url_slug = Content_Type::get_url_slug();
		$extraction_url = home_url( $base . '/' . $url_slug );
		$text_url   = home_url( $base . '/text' );

		printf(
			'<link rel="alternate" type="text/markdown" href="%s">' . "\n",
			esc_url( $extraction_url )
		);
		printf(
			'<link rel="alternate" type="text/plain" href="%s">' . "\n",
			esc_url( $text_url )
		);
	}

	/**
	 * Add JSON-LD structured data for the extraction.
	 *
	 * @hook wp_head
	 */
	public function add_json_ld_structured_data() {
		if ( ! is_singular() ) {
			return;
		}

		global $post;
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		$extraction = $this->get_extraction_for_post( $post->ID );
		if ( ! $extraction ) {
			return;
		}

		$permalink = get_permalink( $post );
		if ( ! $permalink ) {
			return;
		}

		$path = wp_parse_url( $permalink, PHP_URL_PATH );
		if ( ! $path ) {
			return;
		}

		$base = rtrim( $path, '/' );
		$url_slug = Content_Type::get_url_slug();
		$extraction_url = home_url( $base . '/' . $url_slug );
		$date       = get_the_date( 'c', $extraction );

		$json_ld = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'DigitalDocument',
			'name'            => get_the_title( $extraction ),
			'url'             => $extraction_url,
			'encodingFormat'  => 'text/markdown',
			'datePublished'   => $date,
		);

		$parent_permalink = get_permalink( $post );
		if ( $parent_permalink ) {
			$json_ld['isPartOf'] = array(
				'@type' => 'Article',
				'url'   => $parent_permalink,
				'name'  => get_the_title( $post ),
			);
		}

		echo '<script type="application/ld+json">' . "\n";
		echo wp_json_encode( $json_ld, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		echo "\n" . '</script>' . "\n";
	}

	/**
	 * Add Allow rules to robots.txt for topline, text, and markdown endpoints.
	 *
	 * @param string $output The robots.txt output.
	 * @param int    $public Whether the site is public.
	 * @return string Modified output.
	 */
	public function add_robots_txt_rules( $output, $public ) {
		if ( '1' !== (string) $public ) {
			return $output;
		}

		$output .= "# AI-readable extraction endpoints\n";
		$url_slug = Content_Type::get_url_slug();
		$output .= "Allow: /*/{$url_slug}\n";
		$output .= "Allow: /*/text\n";

		return $output;
	}

	/**
	 * Get the published topline post for a parent post ID.
	 *
	 * @param int $post_id Parent post ID.
	 * @return \WP_Post|null Topline post or null.
	 */
	protected function get_extraction_for_post( $post_id ) {
		$extraction_posts = get_posts(
			array(
				'post_type'   => Content_Type::get_post_type(),
				'post_parent' => $post_id,
				'numberposts' => 1,
				'post_status' => 'publish',
			)
		);

		return ! empty( $extraction_posts ) ? $extraction_posts[0] : null;
	}

	/**
	 * Get all published extractions for the LLMs.txt directory.
	 *
	 * @deprecated 2.0.0 Use Llms_Txt_Section instead.
	 * @return array List of extraction entries with title, url, date.
	 */
	protected function get_all_extractions() {
		return array();
	}
}
