<?php
/**
 * HTTP Client Interface
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Infrastructure;

/**
 * Interface for HTTP clients
 */
interface HTTP_Client_Interface {
	/**
	 * Send a GET request
	 *
	 * @param string $url The URL to request.
	 * @param array  $args Optional request arguments.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function get( string $url, array $args = array() );

	/**
	 * Send a POST request
	 *
	 * @param string $url The URL to request.
	 * @param array  $args Optional request arguments.
	 * @return array|WP_Error Response array or WP_Error on failure.
	 */
	public function post( string $url, array $args = array() );

	/**
	 * Get the response body from a response array
	 *
	 * @param array $response Response array from get() or post().
	 * @return string Response body.
	 */
	public function get_response_body( array $response ): string;

	/**
	 * Get the response code from a response array
	 *
	 * @param array $response Response array from get() or post().
	 * @return int Response code.
	 */
	public function get_response_code( array $response ): int;

	/**
	 * Check if a response is successful (200-299)
	 *
	 * @param array $response Response array from get() or post().
	 * @return bool True if successful, false otherwise.
	 */
	public function is_success( array $response ): bool;
}
