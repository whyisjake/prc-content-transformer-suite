<?php
/**
 * Minimal Markdown_Converter stub for ANF converter tests.
 *
 * @package PRC\Platform\Apple_News\ANF\Tests
 */

declare(strict_types=1);

namespace PRC\Platform\Markdown_For_Agents;

if ( ! class_exists( Markdown_Converter::class ) ) {
	class Markdown_Converter {
		public function blocks_to_markdown( array $blocks, \WP_Post $post ): string {
			$parts = array();
			foreach ( $blocks as $block ) {
				$inner = trim( (string) ( $block['innerHTML'] ?? '' ) );
				if ( '' !== $inner ) {
					$parts[] = wp_strip_all_tags( $inner );
				}
			}
			return implode( "\n\n", $parts );
		}
	}
}
