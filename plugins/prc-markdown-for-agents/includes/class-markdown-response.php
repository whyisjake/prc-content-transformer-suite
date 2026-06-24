<?php

/**
 * Markdown response output handler.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

namespace PRC\Platform\Markdown_For_Agents;

/**
 * Serves a post as markdown with frontmatter and headers.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */
class Markdown_Response {

	/**
	 * Cache group for markdown document output.
	 */
	const CACHE_GROUP = 'prc_markdown_for_agents_doc';

	/**
	 * Cache TTL (1 hour).
	 */
	const CACHE_TTL = 3600;

	/**
	 * Output markdown response for a post.
	 *
	 * Sends appropriate headers and outputs the full markdown body with frontmatter.
	 * Exits after output.
	 *
	 * @param \WP_Post $post Post object.
	 */
	public static function serve( $post ) {
		$cache_key = 'markdown_' . $post->ID;
		$content   = wp_cache_get( $cache_key, self::CACHE_GROUP );
		$title     = get_the_title( $post );

		if ( ! is_string( $content ) || '' === $content ) {
			$converter   = new Markdown_Converter();
			$frontmatter = new Frontmatter();

			$markdown_body = $converter->post_to_markdown( $post );
			$yaml          = $frontmatter->build( $post, $markdown_body );

			/** This filter is documented in class-markdown-response.php */
			$markdown_body = apply_filters( 'prc_markdown_for_agents_after_markdown', $markdown_body, $post );

			$content = $yaml . ( $title ? "# $title\n\n" : '' ) . $markdown_body;

			if ( 'publish' === $post->post_status ) {
				wp_cache_set( $cache_key, $content, self::CACHE_GROUP, self::CACHE_TTL );
			}
		}

		$canonical_url  = get_permalink( $post );
		$token_count    = Markdown_Converter::estimate_tokens( $content );
		$content_signal = self::get_content_signal_header();

		self::send_headers( $title, $canonical_url, $post->post_name, $token_count, $content_signal );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Send standard headers for a markdown response.
	 *
	 * Centralises all header emission so callers only need to provide
	 * the document metadata.
	 *
	 * @param string $title          Human-readable document title.
	 * @param string $canonical_url  Canonical HTML URL for this document.
	 * @param string $slug           URL-safe slug used for the filename.
	 * @param int    $token_count    Estimated token count.
	 * @param string $content_signal Content-Signal header value.
	 * @param int    $cache_ttl      Cache max-age in seconds. 0 (default) uses self::CACHE_TTL.
	 */
	public static function send_headers( string $title, string $canonical_url, string $slug, int $token_count, string $content_signal = '', int $cache_ttl = 0 ) {
		$escaped_title = addcslashes( $title, '"\\' );
		$filename      = sanitize_file_name( $slug ?: 'document' ) . '.md';
		$ttl           = $cache_ttl > 0 ? $cache_ttl : self::CACHE_TTL;

		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Vary: Accept' );
		header( 'X-Robots-Tag: noindex' );
		header( 'X-Markdown-Tokens: ' . $token_count );
		header( 'Cache-Control: public, max-age=' . $ttl );

		if ( $content_signal ) {
			header( 'Content-Signal: ' . $content_signal );
		}

		header( 'Link: <' . esc_url( $canonical_url ) . '>; rel="canonical"; title="' . $escaped_title . '"' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
	}

	/**
	 * Get the Content-Signal header value from options.
	 *
	 * @return string Header value (e.g. ai-train=yes, search=yes, ai-input=yes).
	 */
	public static function get_content_signal_header() {
		$options = get_option( 'prc_markdown_for_agents_content_signal', array(
			'ai-train' => 'yes',
			'search'   => 'yes',
			'ai-input' => 'yes',
		) );

		$parts = array();
		foreach ( $options as $key => $value ) {
			$safe_key = sanitize_key( $key );
			$parts[]  = $safe_key . '=' . ( $value ? 'yes' : 'no' );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Delete cached markdown document for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function delete_cache( $post_id ) {
		wp_cache_delete( 'markdown_' . $post_id, self::CACHE_GROUP );
	}
}
