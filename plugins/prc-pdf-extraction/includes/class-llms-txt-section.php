<?php
/**
 * Registers Topline extractions in /llms.txt.
 *
 * @package PRC_PDF_Extraction
 */

declare( strict_types=1 );

namespace PRC\Platform\PDF_Extraction;

use PRC\Platform\Markdown_For_Agents\LLMs_Txt;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contributes the Topline extractions section to prc_markdown_for_agents_llms_txt_sections.
 */
class Llms_Txt_Section {

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader instance.
	 */
	public function __construct( Loader $loader ) {
		unset( $loader );
		add_post_type_support( Content_Type::get_post_type(), 'prc-markdown-for-agents-llms-txt' );
		add_filter( 'prc_markdown_for_agents_llms_txt_sections', array( $this, 'register_section' ) );
	}

	/**
	 * Append the Topline extractions section descriptor.
	 *
	 * @param array<int, array<string, mixed>> $sections Existing sections.
	 * @return array<int, array<string, mixed>>
	 */
	public function register_section( array $sections ): array {
		if ( ! class_exists( LLMs_Txt::class ) ) {
			return $sections;
		}

		$descriptor = $this->build_section_descriptor();
		if ( ! empty( $descriptor['links'] ) ) {
			$sections[] = $descriptor;
		}

		return $sections;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_section_descriptor(): array {
		$extractions = $this->get_extractions( LLMs_Txt::SECTION_CAP + 1 );
		$total       = count( $extractions );
		$links       = array();

		foreach ( array_slice( $extractions, 0, LLMs_Txt::SECTION_CAP ) as $extraction ) {
			$links[] = array(
				'title'       => $extraction['title'],
				'url'         => $extraction['url'],
				'description' => $extraction['date'],
			);
		}

		$archive_url = home_url( '/publications/' );
		$links       = LLMs_Txt::maybe_append_see_all_link( $links, $total, $archive_url );

		return array(
			'slug'        => 'topline-extractions',
			'title'       => __( 'Topline extractions', 'prc-pdf-extraction' ),
			'description' => __( 'Machine-readable topline survey extractions from Pew Research Center reports.', 'prc-pdf-extraction' ),
			'links'       => $links,
		);
	}

	/**
	 * @return array<int, array{title: string, url: string, date: string}>
	 */
	private function get_extractions( int $limit ): array {
		$query = new \WP_Query(
			array(
				'post_type'              => Content_Type::get_post_type(),
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'fields'                 => 'ids',
			)
		);

		$extractions = array();
		$url_slug    = Content_Type::get_url_slug();

		foreach ( $query->posts as $post_id ) {
			$extraction_post = get_post( (int) $post_id );
			if ( ! $extraction_post instanceof \WP_Post ) {
				continue;
			}

			$parent_id = (int) $extraction_post->post_parent;
			if ( $parent_id <= 0 ) {
				continue;
			}

			$parent = get_post( $parent_id );
			if ( ! $parent instanceof \WP_Post || 'publish' !== $parent->post_status ) {
				continue;
			}

			$permalink = get_permalink( $parent );
			if ( ! $permalink ) {
				continue;
			}

			$path = wp_parse_url( $permalink, PHP_URL_PATH );
			$base = is_string( $path ) ? rtrim( $path, '/' ) : '';
			$url  = home_url( $base . '/' . $url_slug );

			$extractions[] = array(
				'title' => get_the_title( $extraction_post ),
				'url'   => $url,
				'date'  => get_the_date( 'Y-m-d', $extraction_post ),
			);
		}

		return $extractions;
	}
}
