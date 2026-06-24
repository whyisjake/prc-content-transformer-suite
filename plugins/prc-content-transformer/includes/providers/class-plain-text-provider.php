<?php
/**
 * Plain Text Provider.
 *
 * @package PRC\Platform\Content_Transformer\Providers
 */

namespace PRC\Platform\Content_Transformer\Providers;

/**
 * Transforms content into clean plain text.
 *
 * This is the simplest provider and serves as a baseline for testing
 * the transformation pipeline end-to-end.
 */
class Plain_Text_Provider implements Provider {

	public function get_name(): string {
		return 'Plain Text';
	}

	public function get_slug(): string {
		return 'plain-text';
	}

	public function get_format_spec(): string {
		$spec_path = PRC_CONTENT_TRANSFORMER_DIR . '/includes/format-specs/plain-text.md';
		if ( file_exists( $spec_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return file_get_contents( $spec_path );
		}
		return '';
	}

	public function get_output_type(): string {
		return 'text';
	}

	public function validate( string $output ): bool {
		return ! empty( trim( $output ) );
	}

	public function post_process( string $output ): string {
		return trim( $output );
	}

	public function is_available(): bool {
		return true;
	}
}
