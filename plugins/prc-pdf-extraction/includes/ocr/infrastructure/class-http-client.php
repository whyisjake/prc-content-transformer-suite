<?php
/**
 * VIP-safe HTTP Client
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Infrastructure;

/**
 * HTTP client implementation using VIP-safe functions
 */
class HTTP_Client implements HTTP_Client_Interface {
	/**
	 * Default timeout in seconds
	 *
	 * @var int
	 */
	private $default_timeout;

	/**
	 * Constructor
	 *
	 * @param int $timeout Default timeout in seconds.
	 */
	public function __construct( int $timeout = 60 ) {
		$this->default_timeout = apply_filters( 'prc_pdf_extraction_ocr_timeout', $timeout );
	}

	/**
	 * Send a GET request
	 *
	 * @param string $url The URL to request.
	 * @param array  $args Optional request arguments.
	 * @return array|\WP_Error Response array or WP_Error on failure.
	 */
	public function get( string $url, array $args = array() ) {
		$args = $this->prepare_args( $args );

		// Use VIP-safe function if available, fallback to wp_remote_get
		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			return vip_safe_wp_remote_get( $url, '', 3, $args['timeout'], 20, $args );
		}

		return wp_remote_get( $url, $args );
	}

	/**
	 * Send a POST request
	 *
	 * @param string $url The URL to request.
	 * @param array  $args Optional request arguments.
	 * @return array|\WP_Error Response array or WP_Error on failure.
	 */
	public function post( string $url, array $args = array() ) {
		$args = $this->prepare_args( $args );

		// Use VIP-safe function if available, fallback to wp_remote_post
		if ( function_exists( 'vip_safe_wp_remote_post' ) ) {
			return vip_safe_wp_remote_post( $url, '', 3, $args['timeout'], 20, $args );
		}

		return wp_remote_post( $url, $args );
	}

	/**
	 * Get the response body from a response array
	 *
	 * @param array $response Response array from get() or post().
	 * @return string Response body.
	 */
	public function get_response_body( array $response ): string {
		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Get the response code from a response array
	 *
	 * @param array $response Response array from get() or post().
	 * @return int Response code.
	 */
	public function get_response_code( array $response ): int {
		return wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Check if a response is successful (200-299)
	 *
	 * @param array $response Response array from get() or post().
	 * @return bool True if successful, false otherwise.
	 */
	public function is_success( array $response ): bool {
		$code = $this->get_response_code( $response );
		return $code >= 200 && $code < 300;
	}

	/**
	 * Prepare request arguments
	 *
	 * @param array $args Request arguments.
	 * @return array Prepared arguments with defaults.
	 */
	private function prepare_args( array $args ): array {
		return wp_parse_args(
			$args,
			array(
				'timeout' => $this->default_timeout,
				'headers' => array(),
			)
		);
	}
}
