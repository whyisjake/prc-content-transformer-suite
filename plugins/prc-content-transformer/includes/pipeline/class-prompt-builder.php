<?php
/**
 * Prompt Builder.
 *
 * @package PRC\Platform\Content_Transformer\Pipeline
 */

namespace PRC\Platform\Content_Transformer\Pipeline;

use PRC\Platform\Content_Transformer\Providers\Provider;

/**
 * Builds the system instruction and user prompt for the AI transformation call.
 */
class Prompt_Builder {

	/**
	 * Build the system instruction for the AI model.
	 *
	 * @param Provider $provider The target provider.
	 * @return string
	 */
	public static function build_system_instruction( Provider $provider ): string {
		$instruction = 'You are a content transformation engine for Pew Research Center. Your sole task is to convert Markdown-formatted research content into a specific output format.

CRITICAL RULES:
- Preserve ALL factual content, data points, statistics, and citations exactly as they appear.
- Do NOT add, remove, or modify any substantive content.
- Do NOT add commentary, analysis, or editorial changes.
- Only restructure the content to fit the target format specification.
- Output ONLY the transformed content with no explanatory text, preamble, or wrapping.';

		$format_spec = $provider->get_format_spec();
		if ( ! empty( $format_spec ) ) {
			$instruction .= "\n\nTARGET FORMAT SPECIFICATION:\n\n" . $format_spec;
		}

		/**
		 * Filter the system instruction for a content transformation.
		 *
		 * @param string   $instruction The system instruction.
		 * @param Provider $provider    The target provider.
		 */
		return apply_filters( 'prc_content_transformer_system_instruction', $instruction, $provider );
	}

	/**
	 * Build the user prompt containing the content to transform.
	 *
	 * @param string   $markdown The post content as markdown.
	 * @param Provider $provider The target provider.
	 * @return string
	 */
	public static function build_user_prompt( string $markdown, Provider $provider ): string {
		$output_type = $provider->get_output_type();

		$prompt = sprintf(
			"Transform the following Markdown content into %s format.\n\nOutput type: %s\n\n---\n\n%s",
			$provider->get_name(),
			$output_type,
			$markdown
		);

		/**
		 * Filter the user prompt for a content transformation.
		 *
		 * @param string   $prompt   The user prompt.
		 * @param string   $markdown The source markdown.
		 * @param Provider $provider The target provider.
		 */
		return apply_filters( 'prc_content_transformer_user_prompt', $prompt, $markdown, $provider );
	}

	/**
	 * Build a retry prompt that includes validation error feedback.
	 *
	 * @param string $original_prompt The original user prompt.
	 * @param string $failed_output   The output that failed validation.
	 * @param string $error_context   Description of what went wrong.
	 * @return string
	 */
	public static function build_retry_prompt( string $original_prompt, string $failed_output, string $error_context ): string {
		return sprintf(
			"%s\n\n---\n\nPREVIOUS ATTEMPT FAILED VALIDATION:\n%s\n\nERROR: %s\n\nPlease correct the output and try again. Output ONLY the corrected content.",
			$original_prompt,
			mb_substr( $failed_output, 0, 2000 ),
			$error_context
		);
	}

	/**
	 * Build a user prompt for transforming a markdown fragment into ANF components.
	 *
	 * @param string   $markdown Markdown fragment for one unhandled block run.
	 * @param Provider $provider The target provider.
	 * @return string
	 */
	public static function build_fragment_prompt( string $markdown, Provider $provider ): string {
		$prompt = sprintf(
			"Transform the following Markdown fragment into %s format.\n\nOutput type: json array of ANF component objects\n\nIMPORTANT: Output ONLY a JSON array of Apple News Format component objects. Do not wrap the array in a full ANF document. Do not include markdown code fences.\n\n---\n\n%s",
			$provider->get_name(),
			$markdown
		);

		/**
		 * Filter the fragment prompt for a content transformation.
		 *
		 * @param string   $prompt   The fragment prompt.
		 * @param string   $markdown The source markdown fragment.
		 * @param Provider $provider The target provider.
		 */
		return apply_filters( 'prc_content_transformer_fragment_prompt', $prompt, $markdown, $provider );
	}
}
