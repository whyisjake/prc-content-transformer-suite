<?php
/**
 * Apple News Provider.
 *
 * @package PRC\Platform\Content_Transformer\Providers
 */

namespace PRC\Platform\Content_Transformer\Providers;

/**
 * Transforms content into Apple News Format (ANF) JSON.
 */
class Apple_News_Provider implements Provider {

	public function get_name(): string {
		return 'Apple News';
	}

	public function get_slug(): string {
		return 'apple-news';
	}

	public function get_format_spec(): string {
		$spec_path = PRC_CONTENT_TRANSFORMER_DIR . '/includes/format-specs/apple-news.md';
		if ( file_exists( $spec_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return file_get_contents( $spec_path );
		}
		return '';
	}

	public function get_output_type(): string {
		return 'json';
	}

	/**
	 * Validate the output is valid ANF JSON with required top-level fields.
	 *
	 * Delegates to validate_with_reason() and casts to bool so that the
	 * Provider interface contract (bool return type) is preserved.
	 *
	 * @param string $output The post-processed AI output.
	 * @return bool
	 */
	public function validate( string $output ): bool {
		return true === $this->validate_with_reason( $output );
	}

	/**
	 * Validate the output and return a specific error description on failure.
	 *
	 * Returns true on success or a human-readable string describing the first
	 * validation failure, suitable for inclusion in a retry prompt. This
	 * companion method is not part of the Provider interface and does not
	 * affect the interface contract for validate().
	 *
	 * Applies the prc_apple_news_validate_anf filter so the prc-apple-news
	 * plugin can inject full JSON Schema validation when it is active.
	 *
	 * @param string $output The post-processed AI output.
	 * @return true|string True on success; a descriptive error string on failure.
	 */
	public function validate_with_reason( string $output ): true|string {
		$data = json_decode( $output, true );
		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
			return 'Output is not valid JSON: ' . json_last_error_msg();
		}

		// Required top-level fields per ANF spec.
		$required = array( 'version', 'identifier', 'title', 'layout', 'components' );
		foreach ( $required as $field ) {
			if ( ! isset( $data[ $field ] ) ) {
				return 'Missing required top-level key: ' . $field;
			}
		}

		if ( ! is_array( $data['components'] ) || empty( $data['components'] ) ) {
			return 'components must be a non-empty array';
		}

		// Every component must have a role.
		foreach ( $data['components'] as $index => $component ) {
			if ( ! isset( $component['role'] ) ) {
				return 'Component at index ' . $index . ' has no role field';
			}
		}

		// Allow prc-apple-news (or any plugin) to run full JSON Schema validation.
		// Filter receives null as $result and the decoded document; return WP_Error to reject.
		$schema_result = apply_filters( 'prc_apple_news_validate_anf', null, $data );
		if ( is_wp_error( $schema_result ) ) {
			return 'Schema validation failed: ' . $schema_result->get_error_message();
		}

		return true;
	}

	/**
	 * Post-process: strip any markdown code fences the AI might wrap around JSON,
	 * then repair literal control characters that the AI sometimes embeds inside
	 * JSON string values (producing JSON_ERROR_CTRL_CHAR on decode).
	 *
	 * @param string $output The raw AI output.
	 * @return string
	 */
	public function post_process( string $output ): string {
		$output = trim( $output );

		// Strip leading ```json and trailing ```.
		if ( preg_match( '/^```(?:json)?\s*\n?(.*?)\n?```$/s', $output, $matches ) ) {
			$output = $matches[1];
		}

		$output = trim( $output );

		// If json_decode fails due to control characters, repair them.
		// The AI sometimes emits literal bytes (LF, CR, TAB) inside JSON string values
		// instead of the required escape sequences (\n, \r, \t).
		json_decode( $output );
		if ( JSON_ERROR_CTRL_CHAR === json_last_error() ) {
			$output = $this->repair_control_chars( $output );
		}

		return $output;
	}

	/**
	 * Escape literal control characters inside JSON string values.
	 *
	 * Walks the JSON string character by character, tracking string boundaries
	 * and escape sequences. Replaces literal control characters (0x00–0x1F)
	 * found inside string values with their proper JSON escape sequences.
	 * Structural whitespace outside strings is left untouched.
	 *
	 * @param string $json Possibly-invalid JSON with literal control chars in strings.
	 * @return string JSON with control characters properly escaped.
	 */
	private function repair_control_chars( string $json ): string {
		$result    = '';
		$in_string = false;
		$escaped   = false;
		$len       = strlen( $json );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $json[ $i ];
			$ord  = ord( $char );

			if ( $escaped ) {
				$result  .= $char;
				$escaped  = false;
				continue;
			}

			if ( $in_string ) {
				if ( '\\' === $char ) {
					$result  .= $char;
					$escaped  = true;
					continue;
				}
				if ( '"' === $char ) {
					$result    .= $char;
					$in_string  = false;
					continue;
				}
				// Escape any literal control character inside a string value.
				if ( $ord <= 0x1F ) {
					switch ( $char ) {
						case "\n":
							$result .= '\n';
							break;
						case "\r":
							$result .= '\r';
							break;
						case "\t":
							$result .= '\t';
							break;
						case "\x08":
							$result .= '\b';
							break;
						case "\x0C":
							$result .= '\f';
							break;
						default:
							$result .= '\u' . str_pad( dechex( $ord ), 4, '0', STR_PAD_LEFT );
							break;
					}
					continue;
				}
				$result .= $char;
			} else {
				if ( '"' === $char ) {
					$result    .= $char;
					$in_string  = true;
					continue;
				}
				$result .= $char;
			}
		}

		return $result;
	}

	public function is_available(): bool {
		return true;
	}
}
