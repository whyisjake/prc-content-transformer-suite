<?php
/**
 * PRC Apple News: MIME_Builder class
 *
 * @package PRC\Platform\Apple_News
 * @subpackage Apple_News_API
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\Apple_News_API;

use WP_Error;

/**
 * Builds multipart/form-data MIME bodies for Apple News API requests.
 *
 * @since 1.0.0
 */
class MIME_Builder {

	/**
	 * Boundary to separate bundle items in the MIME request.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $boundary;

	/**
	 * Holds a debug version of the MIME request content, minus binary data.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $debug_content = '';

	/**
	 * End of line format for MIME requests.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $eol = "\r\n";

	/**
	 * Valid MIME types for Apple News bundles.
	 *
	 * @var array<string>
	 * @since 1.0.0
	 */
	private static array $valid_mime_types = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'application/font-sfnt',
		'application/x-font-truetype',
		'application/font-truetype',
		'application/vnd.ms-opentype',
		'application/x-font-opentype',
		'application/font-opentype',
		'application/octet-stream',
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->boundary = md5( microtime() );
	}

	/**
	 * Get the boundary string.
	 *
	 * @return string
	 */
	public function boundary(): string {
		return $this->boundary;
	}

	/**
	 * Add metadata to the MIME request.
	 *
	 * @param mixed $meta The meta to include.
	 * @return string The textual representation of the meta.
	 */
	public function add_metadata( $meta ): string {
		$attachment  = '--' . $this->boundary . $this->eol;
		$attachment .= 'Content-Type: application/json' . $this->eol;
		$attachment .= 'Content-Disposition: form-data; name=metadata' . $this->eol . $this->eol;
		$attachment .= wp_json_encode( $meta ) . $this->eol;

		$this->debug_content .= $attachment;

		return $attachment;
	}

	/**
	 * Add a JSON string to the MIME request.
	 *
	 * @param string $name     The name of the JSON string to be added.
	 * @param string $filename The filename of the JSON to be added.
	 * @param string $content  The content to be added.
	 * @return string|WP_Error The textual representation of the content, or WP_Error on failure.
	 */
	public function add_json_string( string $name, string $filename, string $content ): string|\WP_Error {
		return $this->build_attachment(
			$name,
			$filename,
			$content,
			'application/json',
			strlen( $content )
		);
	}

	/**
	 * Add file contents to the MIME request.
	 *
	 * @param string      $filepath The filepath or URL of the file to add.
	 * @param string|null $name     Optional. The name for the attachment. Defaults to null.
	 * @return string|WP_Error The attachment content, or WP_Error on failure.
	 */
	public function add_content_from_file( string $filepath, ?string $name = null ): string|\WP_Error {
		$contents = '';

		// Try wp_remote_get first.
		if ( defined( 'WPCOM_IS_VIP_ENV' ) && WPCOM_IS_VIP_ENV ) {
			$request = vip_safe_wp_remote_get( $filepath );
		} else {
			$request = wp_remote_get( $filepath ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get
		}

		if ( is_wp_error( $request ) ) {
			// Try file_get_contents if this is a local path.
			if ( 0 === validate_file( $filepath ) && file_exists( $filepath ) ) {
				$contents = file_get_contents( $filepath ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			}
		} else {
			$contents = wp_remote_retrieve_body( $request );
		}

		// Attempt to get the size.
		$size = strlen( (string) $contents );

		// If this fails for some reason, try alternate methods.
		if ( empty( $size ) ) {
			if ( filter_var( $filepath, FILTER_VALIDATE_URL ) ) {
				$headers = get_headers( $filepath );
				foreach ( $headers as $header ) {
					if ( preg_match( '/Content-Length: ([0-9]+)/i', $header, $matches ) ) {
						$size = intval( $matches[1] );
					}
				}
			} else {
				$size = filesize( $filepath );
			}
		}

		// If the name wasn't specified, build it from the filename.
		$filename = basename( $filepath );
		if ( empty( $name ) ) {
			$name = sanitize_key( $filename );
		}

		return $this->build_attachment(
			$name,
			$filename,
			(string) $contents,
			'application/octet-stream',
			(int) $size
		);
	}

	/**
	 * Close the MIME multipart body.
	 *
	 * @return string
	 */
	public function close(): string {
		$close                = '--' . $this->boundary . '--';
		$this->debug_content .= $close;
		return $close;
	}

	/**
	 * Build an attachment in the MIME request.
	 *
	 * @param string $name      The name of the attachment.
	 * @param string $filename  The filename of the attachment.
	 * @param string $content   The content of the attachment.
	 * @param string $mime_type The MIME type of the attachment.
	 * @param int    $size      The filesize of the attachment.
	 * @return string|WP_Error The attachment data, or WP_Error on failure.
	 */
	private function build_attachment( string $name, string $filename, string $content, string $mime_type, int $size ): string|\WP_Error {
		// Ensure the file isn't empty.
		if ( empty( $content ) ) {
			return new WP_Error(
				'prc_apple_news_empty_attachment',
				sprintf(
					/* translators: token is an attachment filename. */
					__( 'The attachment %s could not be included in the request because it was empty.', 'prc-apple-news' ),
					$filename
				)
			);
		}

		// Ensure a valid size was provided.
		if ( 0 >= $size ) {
			return new WP_Error(
				'prc_apple_news_invalid_attachment_size',
				sprintf(
					/* translators: first token is the filename, second is the file size. */
					__( 'The attachment %1$s could not be included in the request because its size was %2$s.', 'prc-apple-news' ),
					$filename,
					$size
				)
			);
		}

		// Build the attachment.
		$attachment  = '--' . $this->boundary . $this->eol;
		$attachment .= 'Content-Type: ' . $mime_type . $this->eol;
		$attachment .= 'Content-Disposition: form-data; name=' . $name . '; filename=' . $filename . '; size=' . $size . $this->eol . $this->eol;

		$this->debug_content .= $attachment;

		$attachment .= $content . $this->eol;

		if ( 'application/json' === $mime_type ) {
			$this->debug_content .= $content . $this->eol;
		} else {
			$this->debug_content .= "(binary contents of $filename)" . $this->eol;
		}

		return $attachment;
	}

	/**
	 * Check if a MIME type is valid for Apple News bundles.
	 *
	 * @param string $type The MIME type to check.
	 * @return bool True if it is a valid MIME type, false otherwise.
	 */
	private function is_valid_mime_type( string $type ): bool {
		return in_array( $type, self::$valid_mime_types, true );
	}

	/**
	 * Gets the debug version of the MIME content.
	 *
	 * @param array $args Arguments to parse for debug info.
	 * @return string The debug content, augmented with header information.
	 */
	public function get_debug_content( array $args ): string {
		$content = '';

		if ( ! empty( $args['headers'] ) && is_array( $args['headers'] ) ) {
			foreach ( $args['headers'] as $key => $value ) {
				$content .= sprintf(
					'%s: %s%s',
					$key,
					$value,
					$this->eol
				);
			}
		}

		$content .= $this->eol . $this->debug_content;

		return $content;
	}
}
