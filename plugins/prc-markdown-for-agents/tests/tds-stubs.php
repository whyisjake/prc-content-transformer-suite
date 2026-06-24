<?php
/**
 * TDS function stubs for PHPUnit when term-data-store is unavailable.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

declare( strict_types=1 );

namespace PRC\TDS;

if ( ! function_exists( __NAMESPACE__ . '\\add_relationship' ) ) {
	/**
	 * @param string $post_type Post type slug.
	 * @param string $taxonomy Taxonomy slug.
	 * @param bool   $bidirectional Whether the relationship is bidirectional.
	 */
	function add_relationship( string $post_type, string $taxonomy, bool $bidirectional = true ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $post_type, $taxonomy, $bidirectional );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\get_related_term' ) ) {
	/**
	 * @param int $post_id Staff post ID.
	 * @return \WP_Term|false
	 */
	function get_related_term( int $post_id ): \WP_Term|false {
		$terms = get_terms(
			array(
				'taxonomy'   => 'bylines',
				'hide_empty' => false,
				'meta_query' => array(
					array(
						'key'   => 'tds_post_id',
						'value' => $post_id,
					),
				),
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return false;
		}
		return $terms[0];
	}
}
