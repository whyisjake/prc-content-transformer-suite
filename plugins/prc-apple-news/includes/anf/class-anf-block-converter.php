<?php
/**
 * ANF Block Converter.
 *
 * @package PRC\Platform\Apple_News\ANF
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\ANF;

use PRC\Platform\Content_Transformer\Pipeline\Transformation_Pipeline;
use PRC\Platform\Markdown_For_Agents\Markdown_Converter;
use WP_Post;

/**
 * Converts a post's block content into a minimal ANF JSON document.
 *
 * Walk order:
 *   1. block.json prcAppleNewsAnf resolver (declarative callbacks + modes)
 *   2. ANF_Block_Registry callback (imperative registration via action hook)
 *   3. Container recursion when inner blocks contain handled descendants
 *   4. Contiguous unhandled runs fall through to prc-content-transformer
 */
class ANF_Block_Converter {

	/**
	 * Transparent structural blocks whose children should be walked directly.
	 *
	 * @var string[]
	 */
	private const CONTAINER_BLOCKS = array(
		'core/group',
		'core/column',
		'core/columns',
	);

	/**
	 * Build ANF JSON for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string JSON-encoded ANF document.
	 */
	public function build( int $post_id ): string {
		$post_object = get_post( $post_id );
		if ( ! $post_object ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		global $post;
		$saved_global = $post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post = $post_object;
		setup_postdata( $post );

		$blocks     = parse_blocks( $post_object->post_content );
		$components = $this->blocks_to_components( $blocks, $post_object );

		wp_reset_postdata();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post = $saved_global;

		$document = array(
			'version'       => '1.11',
			'identifier'    => 'post-' . $post_id,
			'language'      => 'en',
			'title'         => get_the_title( $post_object ),
			'layout'        => array(
				'columns' => 15,
				'width'   => 1024,
				'margin'  => 100,
				'gutter'  => 20,
			),
			'documentStyle' => array(
				'backgroundColor' => '#FFFFFF',
			),
			'components'    => $components,
			'metadata'      => array(),
		);

		return (string) wp_json_encode( $document );
	}

	/**
	 * Recursively convert parsed blocks into ordered ANF components.
	 *
	 * @param array    $blocks Array of parsed block arrays from parse_blocks().
	 * @param WP_Post  $post   Post being converted.
	 * @return array<int,array<string,mixed>>
	 */
	public function blocks_to_components( array $blocks, WP_Post $post ): array {
		$components   = array();
		$fallback_run = array();

		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? null;

			if ( null === $block_name ) {
				$trimmed = trim( $block['innerHTML'] ?? '' );
				if ( '' !== $trimmed ) {
					$fallback_run[] = $block;
				}
				continue;
			}

			$resolved = ANF_Block_Resolver::resolve_strategy( $block_name, $block, $post );
			if ( true === $resolved['handled'] ) {
				$this->flush_fallback_run( $fallback_run, $components, $post );

				if ( true === $resolved['recurse'] ) {
					$inner = $block['innerBlocks'] ?? array();
					if ( ! empty( $inner ) ) {
						$components = array_merge(
							$components,
							$this->blocks_to_components( $inner, $post )
						);
					}
					continue;
				}

				$components = array_merge( $components, $resolved['components'] );
				continue;
			}

			$callback = ANF_Block_Registry::get( $block_name );
			if ( $callback ) {
				$this->flush_fallback_run( $fallback_run, $components, $post );

				$block_components = call_user_func( $callback, $block, $post );
				if ( ! is_array( $block_components ) ) {
					$block_components = array();
				}

				/**
				 * Filter ANF components produced for a specific block type.
				 *
				 * @param array<int,array<string,mixed>> $block_components ANF components.
				 * @param array                          $block            Parsed block array.
				 * @param WP_Post                        $post             Post being converted.
				 */
				$block_components = apply_filters(
					'prc_apple_news_block_' . $block_name,
					$block_components,
					$block,
					$post
				);

				if ( is_array( $block_components ) && ! empty( $block_components ) ) {
					$components = array_merge( $components, $block_components );
				}
				continue;
			}

			$inner = $block['innerBlocks'] ?? array();

			if ( in_array( $block_name, self::CONTAINER_BLOCKS, true ) && ! empty( $inner ) ) {
				$this->flush_fallback_run( $fallback_run, $components, $post );
				$components = array_merge(
					$components,
					$this->blocks_to_components( $inner, $post )
				);
				continue;
			}

			if ( ! empty( $inner ) && $this->has_handled_descendant( $inner ) ) {
				$this->flush_fallback_run( $fallback_run, $components, $post );
				$components = array_merge(
					$components,
					$this->blocks_to_components( $inner, $post )
				);
				continue;
			}

			$fallback_run[] = $block;
		}

		$this->flush_fallback_run( $fallback_run, $components, $post );

		return $components;
	}

	/**
	 * Whether any descendant block has a declarative or registry handler.
	 *
	 * @param array $blocks Parsed block list.
	 */
	private function has_handled_descendant( array $blocks ): bool {
		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? null;
			if ( null === $block_name ) {
				continue;
			}

			if ( ANF_Block_Registry::has( $block_name ) ) {
				return true;
			}

			$config = ANF_Block_Resolver::get_block_config( $block_name );
			if ( is_array( $config ) ) {
				return true;
			}

			$inner = $block['innerBlocks'] ?? array();
			if ( ! empty( $inner ) && $this->has_handled_descendant( $inner ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Convert a contiguous run of unhandled blocks via the content transformer.
	 *
	 * @param array<int,array<string,mixed>> $fallback_run Blocks awaiting fallback.
	 * @param array<int,array<string,mixed>> $components   Accumulated ANF components.
	 * @param WP_Post                        $post         Post being converted.
	 */
	private function flush_fallback_run( array &$fallback_run, array &$components, WP_Post $post ): void {
		if ( empty( $fallback_run ) ) {
			return;
		}

		$run_blocks = $fallback_run;
		$fallback_run = array();

		if ( ! class_exists( Markdown_Converter::class ) ) {
			return;
		}

		do_action( 'prc_markdown_for_agents_set_context', 'apple-news' );

		$converter = new Markdown_Converter();
		$markdown  = $converter->blocks_to_markdown( $run_blocks, $post );

		do_action( 'prc_markdown_for_agents_clear_context' );

		if ( '' === trim( $markdown ) ) {
			return;
		}

		$fallback_components = $this->transform_fallback_fragment( $post->ID, $markdown, $run_blocks, $post );
		if ( ! empty( $fallback_components ) ) {
			$components = array_merge( $components, $fallback_components );
		}
	}

	/**
	 * Transform a markdown fragment into ANF components.
	 *
	 * @param int      $post_id     Post ID.
	 * @param string   $markdown    Markdown fragment.
	 * @param array    $run_blocks  Source blocks for filters/tests.
	 * @param WP_Post  $post        Post being converted.
	 * @return array<int,array<string,mixed>>
	 */
	private function transform_fallback_fragment( int $post_id, string $markdown, array $run_blocks, WP_Post $post ): array {
		/**
		 * Short-circuit fallback ANF component generation.
		 *
		 * Return a non-null array to bypass the AI fragment transformer.
		 *
		 * @param array<int,array<string,mixed>>|null $components  Pre-built components or null.
		 * @param string                            $markdown    Markdown fragment.
		 * @param array                             $run_blocks  Source blocks.
		 * @param WP_Post                           $post        Post being converted.
		 */
		$filtered = apply_filters( 'prc_apple_news_fallback_components', null, $markdown, $run_blocks, $post );
		if ( is_array( $filtered ) ) {
			return $this->normalize_components( $filtered );
		}

		if ( ! class_exists( Transformation_Pipeline::class ) ) {
			return array();
		}

		$result = Transformation_Pipeline::transform_fragment( $post_id, $markdown, 'apple-news' );
		if ( ! $result->is_success() ) {
			error_log( 'PRC Apple News: fragment transform failed for post ' . $post_id . ': ' . $result->get_error() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return array();
		}

		$decoded = json_decode( $result->get_output(), true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return $this->normalize_components( $decoded );
	}

	/**
	 * Keep only valid top-level ANF component arrays.
	 *
	 * @param array<int,mixed> $components Candidate components.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_components( array $components ): array {
		$normalized = array();

		foreach ( $components as $component ) {
			if ( is_array( $component ) && isset( $component['role'] ) && is_string( $component['role'] ) ) {
				$normalized[] = $component;
			}
		}

		return $normalized;
	}
}
