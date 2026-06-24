<?php
/**
 * Email Provider.
 *
 * @package PRC\Platform\Content_Transformer\Providers
 */

namespace PRC\Platform\Content_Transformer\Providers;

/**
 * Transforms content into email-safe HTML compatible with Mailchimp templates.
 */
class Email_Provider implements Provider {

	public function get_name(): string {
		return 'Email HTML';
	}

	public function get_slug(): string {
		return 'email';
	}

	public function get_format_spec(): string {
		$spec_path = PRC_CONTENT_TRANSFORMER_DIR . '/includes/format-specs/email-html.md';
		if ( file_exists( $spec_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return file_get_contents( $spec_path );
		}
		return '';
	}

	public function get_output_type(): string {
		return 'html';
	}

	/**
	 * Validate the output contains table-based email HTML.
	 *
	 * @param string $output The raw AI output.
	 * @return bool
	 */
	public function validate( string $output ): bool {
		$output = trim( $output );
		if ( empty( $output ) ) {
			return false;
		}

		// Must contain at least one table element (email layout requirement).
		if ( stripos( $output, '<table' ) === false ) {
			return false;
		}

		// Should not contain document-level elements.
		if ( stripos( $output, '<!DOCTYPE' ) !== false || stripos( $output, '<html' ) !== false ) {
			return false;
		}

		return true;
	}

	/**
	 * Post-process: strip any markdown code fences the AI might wrap around HTML.
	 *
	 * Handles common AI output patterns independently rather than requiring the
	 * fence to wrap the entire output:
	 *  - Full wrap:     ```html\n...\n```
	 *  - Opening only:  ```html\n...   (AI forgets closing fence)
	 *  - Case variants: ```HTML
	 *
	 * @param string $output The raw AI output.
	 * @return string
	 */
	public function post_process( string $output ): string {
		$output = trim( $output );

		// Strip opening code fence (```html, ```HTML, or bare ```) from start.
		$output = preg_replace( '/^```(?:html|HTML)?\s*\n?/', '', $output );

		// Strip closing code fence from end.
		$output = preg_replace( '/\n?```\s*$/', '', $output );

		$output = trim( (string) $output );

		// Replace any literal spacer tokens the model left in place (table-safe).
		$output = preg_replace_callback(
			'/\{\{PRC_EMAIL_SPACER\s+height="(\d+(?:\.\d+)?)px"\}\}/',
			static function ( array $m ): string {
				$h = (int) max( 1, min( 500, (int) ceil( (float) $m[1] ) ) );
				return '<table width="100%" cellpadding="0" cellspacing="0" border="0" role="presentation">'
					. '<tr><td height="' . $h . '" style="height:' . $h . 'px;line-height:' . $h . 'px;font-size:0;">&nbsp;</td></tr></table>';
			},
			$output
		);

		// If typography wrappers leaked through, strip markers (keep inner content) and warn.
		if ( str_contains( $output, '{{PRC_EMAIL_TYPOGRAPHY' ) || str_contains( $output, '{{/PRC_EMAIL_TYPOGRAPHY}}' ) ) {
			if ( function_exists( 'do_action' ) ) {
				do_action(
					'qm/warn',
					'Email HTML output contained unresolved {{PRC_EMAIL_TYPOGRAPHY}} markers; stripped before send.'
				);
			}
			$output = preg_replace( '/\{\{PRC_EMAIL_TYPOGRAPHY[^}]+\}\}\s*/', '', $output );
			$output = preg_replace( '/\s*\{\{\/PRC_EMAIL_TYPOGRAPHY\}\}/', '', $output );
		}

		return trim( (string) $output );
	}

	public function is_available(): bool {
		return true;
	}
}
