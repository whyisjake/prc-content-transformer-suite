<?php

/**
 * Markdown converter for block content.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

namespace PRC\Platform\Markdown_For_Agents;

/**
 * Converts WordPress post content to Markdown.
 *
 * Walks the parsed block tree for each post. Blocks with a registered
 * callback in Block_Markdown_Registry produce their own markdown; all
 * other blocks fall back to render_block() → HTML_To_Markdown_Converter.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */
class Markdown_Converter {

	/**
	 * Convert post content to Markdown.
	 *
	 * @param int|\WP_Post $post Post ID or post object.
	 * @return string Markdown content.
	 */
	public function post_to_markdown( $post ) {
		// Resolve to a WP_Post object before any global $post declaration, so
		// the variable isn't shadowed when we set up the global below.
		$post_object = get_post( $post );
		if ( ! $post_object || ! post_type_supports( $post_object->post_type, 'prc-markdown-for-agents' ) ) {
			return '';
		}

		/**
		 * Allow plugins to supply pre-built Markdown, bypassing block conversion.
		 *
		 * Return a non-null string to short-circuit conversion. Return null to fall
		 * through to the standard pipeline. Useful for post types that store Markdown
		 * directly in meta (e.g. OCR-extracted content).
		 *
		 * @param string|null $pre         Pre-built Markdown string, or null to use default conversion.
		 * @param \WP_Post    $post_object The post being converted.
		 */
		$pre = apply_filters( 'prc_markdown_for_agents_pre_markdown', null, $post_object );
		if ( null !== $pre ) {
			return (string) $pre;
		}

		// Set the global $post so dynamic blocks render correctly.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		global $post;
		$saved_global = $post;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post = $post_object;
		setup_postdata( $post );

		$blocks   = parse_blocks( $post_object->post_content );
		$markdown = $this->blocks_to_markdown( $blocks, $post_object );

		wp_reset_postdata();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post = $saved_global;

		return trim( $markdown );
	}

	/**
	 * Convert a flat array of parsed blocks to markdown.
	 *
	 * @param array    $blocks   Array of parsed block arrays from parse_blocks().
	 * @param \WP_Post $post     The post being converted.
	 * @return string
	 */
	public function blocks_to_markdown( array $blocks, \WP_Post $post ): string {
		$parts     = array();
		$converter = new HTML_To_Markdown_Converter();

		foreach ( $blocks as $block ) {
			$block_name = $block['blockName'] ?? null;

			// Null blockName = freeform/classic content between blocks.
			if ( null === $block_name ) {
				$trimmed = trim( $block['innerHTML'] ?? '' );
				if ( '' !== $trimmed ) {
					$parts[] = $converter->convert( $trimmed );
				}
				continue;
			}

			$resolved = Block_Markdown_Resolver::resolve_strategy( $block_name, $block, $post );
			if ( true === $resolved['handled'] ) {
				if ( true === $resolved['recurse'] ) {
					$inner = $block['innerBlocks'] ?? array();
					if ( ! empty( $inner ) ) {
						$inner_md = $this->blocks_to_markdown( $inner, $post );
						if ( '' !== trim( $inner_md ) ) {
							$parts[] = $inner_md;
						}
					}
					continue;
				}

				if ( '' !== trim( (string) $resolved['markdown'] ) ) {
					$parts[] = (string) $resolved['markdown'];
				}
				continue;
			}

			// Dispatch to registered block-level markdown callback.
			$callback = Block_Markdown_Registry::get( $block_name );
			if ( $callback ) {
				$block_md = call_user_func( $callback, $block, $post );

				/**
				 * Filter markdown output for a specific block type.
				 *
				 * The dynamic portion of the hook name, `$block_name`, is the
				 * fully-qualified block name (e.g. 'prc-chart-builder/controller').
				 *
				 * @param string   $block_md Markdown produced by the block's callback.
				 * @param array    $block    Parsed block array.
				 * @param \WP_Post $post     The post being converted.
				 */
				$block_md = apply_filters(
					'prc_markdown_for_agents_block_' . $block_name,
					$block_md,
					$block,
					$post
				);

				if ( '' !== trim( (string) $block_md ) ) {
					$parts[] = (string) $block_md;
				}
				continue;
			}

			// Container blocks (e.g. core/group) may wrap blocks that have
			// registered callbacks. Recurse into innerBlocks so those callbacks
			// are honoured instead of rendering the entire tree to HTML.
			$inner = $block['innerBlocks'] ?? array();
			if ( ! empty( $inner ) ) {
				$inner_md = $this->blocks_to_markdown( $inner, $post );
				if ( '' !== trim( $inner_md ) ) {
					$parts[] = $inner_md;
				}
				continue;
			}

			// Default: render block to HTML, then convert to markdown.
			$html = render_block( $block );
			$md   = $converter->convert( $html );
			if ( '' !== trim( $md ) ) {
				$parts[] = $md;
			}
		}

		return implode( "\n\n", $parts );
	}

	/**
	 * Estimate token count for a string (rough: ~4 chars per token for English).
	 *
	 * @param string $text The text to estimate.
	 * @return int Estimated token count.
	 */
	public static function estimate_tokens( $text ) {
		return (int) ceil( strlen( $text ) / 4 );
	}
}
