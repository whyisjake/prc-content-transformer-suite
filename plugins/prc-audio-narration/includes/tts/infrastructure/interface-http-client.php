<?php
/**
 * HTTP Client Interface
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Infrastructure;

/**
 * Injectable HTTP boundary so providers can be tested without network access.
 */
interface HTTP_Client_Interface {

	/**
	 * Send a GET request.
	 *
	 * @param string $url  The URL to request.
	 * @param array  $args Optional request arguments.
	 * @return array|\WP_Error Response array or WP_Error on transport failure.
	 */
	public function get( string $url, array $args = array() );

	/**
	 * Send a POST request.
	 *
	 * @param string $url  The URL to request.
	 * @param array  $args Optional request arguments.
	 * @return array|\WP_Error Response array or WP_Error on transport failure.
	 */
	public function post( string $url, array $args = array() );

	/**
	 * Get the response body.
	 *
	 * @param array $response Response array.
	 * @return string
	 */
	public function get_response_body( array $response ): string;

	/**
	 * Get the response status code.
	 *
	 * @param array $response Response array.
	 * @return int
	 */
	public function get_response_code( array $response ): int;

	/**
	 * Whether the response status is in the 2xx range.
	 *
	 * @param array $response Response array.
	 * @return bool
	 */
	public function is_success( array $response ): bool;
}
