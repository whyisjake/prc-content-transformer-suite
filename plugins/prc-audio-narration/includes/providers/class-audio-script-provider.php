<?php
/**
 * Audio Script Provider.
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\Providers;

use PRC\Platform\Content_Transformer\Providers\Provider;

/**
 * Transforms article content into a narration script written for the ear.
 *
 * This provider plugs into prc-content-transformer's pipeline, which supplies
 * markdown conversion, prompt building, validation retry, and content-hash
 * caching. Only the format specification and the validation rules are ours.
 *
 * The class is deliberately loaded lazily -- it implements an interface owned
 * by another plugin, so declaring it when that plugin is inactive would fatal.
 * See Script_Provider_Registrar for the loading seam.
 */
class Audio_Script_Provider implements Provider {

	/**
	 * The provider slug, used as the transformation cache key.
	 */
	const SLUG = 'audio-script';

	/**
	 * Human-readable provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'Audio Narration Script';
	}

	/**
	 * URL-safe slug used as the provider identifier.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return self::SLUG;
	}

	/**
	 * The format specification document content.
	 *
	 * @return string
	 */
	public function get_format_spec(): string {
		$spec_path = PRC_AUDIO_NARRATION_DIR . '/includes/format-specs/audio-script.md';
		if ( file_exists( $spec_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return file_get_contents( $spec_path );
		}
		return '';
	}

	/**
	 * The output content type.
	 *
	 * @return string
	 */
	public function get_output_type(): string {
		return 'text';
	}

	/**
	 * Validate the generated script.
	 *
	 * Rejects the specific failure modes the format spec targets. Markup
	 * surviving into the script is not cosmetic: a text-to-speech engine will
	 * read a pipe character or a URL aloud, so any of these makes the audio
	 * unusable. The pipeline retries once with this failure as feedback.
	 *
	 * @param string $output The raw AI output.
	 * @return bool True if the output is usable as a narration script.
	 */
	public function validate( string $output ): bool {
		return empty( self::validation_failures( $output ) );
	}

	/**
	 * Describe why a script failed validation.
	 *
	 * Kept separate from validate() so callers -- and the pipeline's retry
	 * prompt -- can report the specific problem rather than a bare false.
	 *
	 * @param string $output The raw AI output.
	 * @return array<string, string> Map of failure key to human-readable reason.
	 */
	public static function validation_failures( string $output ): array {
		$failures = array();

		if ( '' === trim( $output ) ) {
			$failures['empty'] = 'The script is empty.';
			return $failures;
		}

		$checks = array(
			'markdown_table'    => array(
				'pattern' => '/^\s*\|.*\|\s*$/m',
				'reason'  => 'The script still contains a Markdown table. Convert tables to spoken comparison.',
			),
			'markdown_image'    => array(
				'pattern' => '/!\[[^\]]*\]\([^)]*\)/',
				'reason'  => 'The script still contains Markdown image syntax. Omit images or describe them in a sentence.',
			),
			'markdown_link'     => array(
				'pattern' => '/\[[^\]]+\]\([^)]*\)/',
				'reason'  => 'The script still contains Markdown link syntax. Speak the link text only.',
			),
			'markdown_heading'  => array(
				'pattern' => '/^\s{0,3}#{1,6}\s+\S/m',
				'reason'  => 'The script still contains Markdown headings. Fold headings into spoken transitions.',
			),
			'footnote_marker'   => array(
				'pattern' => '/\[\^[^\]]+\]/',
				'reason'  => 'The script still contains footnote markers. Fold essential footnotes into the prose.',
			),
			'url'               => array(
				'pattern' => '#https?://#i',
				'reason'  => 'The script contains a URL. URLs must never be spoken aloud.',
			),
			'list_bullet'       => array(
				'pattern' => '/^\s{0,3}[-*+]\s+\S/m',
				'reason'  => 'The script still contains list bullets. Convert lists to flowing prose.',
			),
			'horizontal_rule'   => array(
				'pattern' => '/^\s{0,3}(?:-{3,}|\*{3,}|_{3,})\s*$/m',
				'reason'  => 'The script still contains a horizontal rule.',
			),
			'code_fence'        => array(
				'pattern' => '/^\s{0,3}```/m',
				'reason'  => 'The script still contains a code fence.',
			),
			'visual_reference'  => array(
				'pattern' => '/\b(?:chart|table|figure|graph|image)\s+(?:above|below|at\s+(?:the\s+)?(?:left|right))\b/i',
				'reason'  => 'The script refers to a visual by position. A listener has no chart to look at.',
			),
			'percent_symbol'    => array(
				'pattern' => '/\d\s*%/',
				'reason'  => 'The script contains a percent symbol. Write "percent" so it is spoken correctly.',
			),
		);

		foreach ( $checks as $key => $check ) {
			if ( preg_match( $check['pattern'], $output ) ) {
				$failures[ $key ] = $check['reason'];
			}
		}

		return $failures;
	}

	/**
	 * Post-process the generated script.
	 *
	 * Strips residual inline emphasis markers and normalizes whitespace. This
	 * is cleanup for things a listener would never notice were removed -- it
	 * deliberately does not repair the failures validate() rejects, because
	 * silently patching those would hide a bad script rather than retry it.
	 *
	 * @param string $output The raw AI output.
	 * @return string Cleaned script.
	 */
	public function post_process( string $output ): string {
		// Remove inline bold/italic markers while preserving the wrapped text.
		$output = preg_replace( '/(\*\*|__)(.*?)\1/s', '$2', $output );
		$output = preg_replace( '/(?<![\w*])(\*|_)(?!\s)(.+?)(?<!\s)\1(?![\w*])/s', '$2', $output );

		// Remove inline code backticks, keeping their contents.
		$output = preg_replace( '/`([^`]*)`/', '$1', $output );

		// Collapse runs of blank lines to a single paragraph break and trim
		// trailing whitespace, which some models emit line by line.
		$output = preg_replace( '/[ \t]+$/m', '', $output );
		$output = preg_replace( '/\n{3,}/', "\n\n", $output );

		return trim( $output );
	}

	/**
	 * Whether this provider can be used.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return '' !== $this->get_format_spec();
	}
}
