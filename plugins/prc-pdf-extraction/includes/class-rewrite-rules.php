<?php
/**
 * Rewrite Rules for Content Endpoints
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

/**
 * Handles custom rewrite rules for extracted content endpoints.
 *
 * Supported endpoints:
 *  - /parent-slug/{url_slug} - Markdown with frontmatter (via prc-markdown-for-agents)
 *  - /parent-slug/text       - Plain text (via template-text.php)
 *
 * The URL slug is filterable via prc_pdf_extraction_url_slug (default: extraction).
 *
 * @package PRC_PDF_Extraction
 */
class Rewrite_Rules {
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

		$this->loader->add_action( 'parse_request', $this, 'maybe_intercept_extraction_request', 1 );
	}

	/**
	 * Maybe intercept extraction request.
	 *
	 * Intercepts requests early and checks if the URL ends with /{url_slug} or /text.
	 * For /{url_slug}, delegates to prc-markdown-for-agents (Markdown_Response::serve)
	 * which produces standardized markdown with YAML frontmatter. Falls back to the
	 * legacy template if prc-markdown-for-agents is unavailable.
	 * For /text, loads the plain-text template directly.
	 *
	 * @param \WP $wp Current WordPress environment instance.
	 */
	public function maybe_intercept_extraction_request( $wp ) {
		$request_path = isset( $wp->request ) ? $wp->request : '';
		$url_slug     = Content_Type::get_url_slug();
		$pattern      = '#/(' . preg_quote( $url_slug, '#' ) . '|text)/?$#';

		if ( ! preg_match( $pattern, $request_path, $matches ) ) {
			return;
		}

		$format = $matches[1];

		$parent_path = preg_replace( $pattern, '', $request_path );

		$post_id = url_to_postid( home_url( $parent_path ) );

		if ( ! $post_id ) {
			return;
		}

		if ( $url_slug === $format ) {
			$this->serve_extraction_markdown( $post_id );
			return;
		}

		// text format: load the plain-text template.
		$template_file = PRC_PDF_EXTRACTION_DIR . '/includes/templates/template-text.php';

		set_query_var( 'extraction_post', $post_id );

		if ( file_exists( $template_file ) ) {
			include $template_file;
			exit;
		}
	}

	/**
	 * Serve the extraction post as markdown via prc-markdown-for-agents.
	 *
	 * Looks up the child extraction post for the given parent and calls
	 * Markdown_Response::serve() which produces a standardized response with
	 * YAML frontmatter, Content-Signal, X-Markdown-Tokens, and Vary headers.
	 *
	 * Falls back to the legacy template-extraction.php if prc-markdown-for-agents
	 * is not available.
	 *
	 * @param int $parent_post_id Parent post ID.
	 */
	protected function serve_extraction_markdown( $parent_post_id ) {
		$extraction_posts = get_posts(
			array(
				'post_type'   => Content_Type::get_post_type(),
				'post_parent' => $parent_post_id,
				'numberposts' => 1,
				'post_status' => 'publish',
			)
		);

		if ( empty( $extraction_posts ) ) {
			// Return a WordPress 404.
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			include get_404_template();
			exit;
		}

		$extraction_post = $extraction_posts[0];

		if ( class_exists( 'PRC\Platform\Markdown_For_Agents\Markdown_Response' ) ) {
			\PRC\Platform\Markdown_For_Agents\Markdown_Response::serve( $extraction_post );
			// serve() calls exit; this line is never reached.
		}

		// Fallback: prc-markdown-for-agents is not active.
		set_query_var( 'extraction_post', $parent_post_id );
		$template_file = PRC_PDF_EXTRACTION_DIR . '/includes/templates/template-extraction.php';

		if ( file_exists( $template_file ) ) {
			include $template_file;
		}

		exit;
	}

	/**
	 * Flush rewrite rules on plugin activation.
	 *
	 * Call this method during plugin activation to ensure rules are registered.
	 */
	public static function flush_rules() {
		flush_rewrite_rules();
	}
}
