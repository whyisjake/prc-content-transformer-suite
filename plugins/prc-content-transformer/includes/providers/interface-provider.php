<?php
/**
 * Provider interface.
 *
 * @package PRC\Platform\Content_Transformer\Providers
 */

namespace PRC\Platform\Content_Transformer\Providers;

/**
 * Contract for all content transformation providers.
 *
 * Each provider defines how content should be transformed into a specific
 * output format. Providers supply their format specification (used as AI
 * prompt context), validate the AI output, and perform any post-processing.
 */
interface Provider {

	/**
	 * Human-readable provider name.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * URL-safe slug used as the provider identifier.
	 *
	 * @return string
	 */
	public function get_slug(): string;

	/**
	 * The format specification document content.
	 *
	 * This is injected into the AI prompt to instruct the model on the
	 * target output format. Typically loaded from a file in format-specs/.
	 *
	 * @return string
	 */
	public function get_format_spec(): string;

	/**
	 * The output content type: 'json', 'html', or 'text'.
	 *
	 * @return string
	 */
	public function get_output_type(): string;

	/**
	 * Validate the AI-generated output.
	 *
	 * @param string $output The raw AI output.
	 * @return bool True if the output is valid for this format.
	 */
	public function validate( string $output ): bool;

	/**
	 * Post-process the AI output before caching.
	 *
	 * @param string $output The raw AI output.
	 * @return string Cleaned/processed output.
	 */
	public function post_process( string $output ): string;

	/**
	 * Whether this provider is available for use.
	 *
	 * @return bool
	 */
	public function is_available(): bool;
}
