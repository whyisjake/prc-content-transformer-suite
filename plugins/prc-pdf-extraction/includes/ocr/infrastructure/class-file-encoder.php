<?php
/**
 * File Encoder
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Infrastructure;

/**
 * Handles file encoding for OCR requests
 */
class File_Encoder {
	/**
	 * Encode a file to base64
	 *
	 * @param string $file_path Path to the file.
	 * @return string|false Base64-encoded file content, or false on failure.
	 */
	public function encode_file( string $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		if ( ! is_readable( $file_path ) ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$file_content = file_get_contents( $file_path );

		if ( false === $file_content ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $file_content );
	}

	/**
	 * Get the MIME type of a file
	 *
	 * @param string $file_path Path to the file.
	 * @return string|false MIME type, or false on failure.
	 */
	public function get_mime_type( string $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		// Use WordPress function if available
		if ( function_exists( 'wp_check_filetype' ) ) {
			$file_info = wp_check_filetype( $file_path );
			if ( ! empty( $file_info['type'] ) ) {
				return $file_info['type'];
			}
		}

		// Fallback to mime_content_type
		if ( function_exists( 'mime_content_type' ) ) {
			return mime_content_type( $file_path );
		}

		// Default for PDFs
		if ( pathinfo( $file_path, PATHINFO_EXTENSION ) === 'pdf' ) {
			return 'application/pdf';
		}

		return false;
	}

	/**
	 * Validate that a file is a PDF
	 *
	 * @param string $file_path Path to the file.
	 * @return bool True if valid PDF, false otherwise.
	 */
	public function is_valid_pdf( string $file_path ): bool {
		$mime_type = $this->get_mime_type( $file_path );
		return 'application/pdf' === $mime_type;
	}

	/**
	 * Get file size in bytes
	 *
	 * @param string $file_path Path to the file.
	 * @return int|false File size in bytes, or false on failure.
	 */
	public function get_file_size( string $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		return filesize( $file_path );
	}
}
