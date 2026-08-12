<?php
/**
 * HTTP Client
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Infrastructure;

/**
 * WordPress HTTP API implementation of the client boundary.
 */
class HTTP_Client implements HTTP_Client_Interface {

	/**
	 * Default request timeout in seconds.
	 *
	 * Speech synthesis of a long chunk routinely exceeds the WordPress default
	 * of five seconds, and a timeout mid-job costs a real API charge for audio
	 * that is then discarded.
	 */
	const DEFAULT_TIMEOUT = 120;

	/**
	 * Send a GET request.
	 *
	 * @param string $url  The URL to request.
	 * @param array  $args Optional request arguments.
	 * @return array|\WP_Error
	 */
	public function get( string $url, array $args = array() ) {
		return wp_remote_get( $url, $this->prepare_args( $args ) );
	}

	/**
	 * Send a POST request.
	 *
	 * @param string $url  The URL to request.
	 * @param array  $args Optional request arguments.
	 * @return array|\WP_Error
	 */
	public function post( string $url, array $args = array() ) {
		return wp_remote_post( $url, $this->prepare_args( $args ) );
	}

	/**
	 * Apply defaults to request arguments.
	 *
	 * @param array $args Request arguments.
	 * @return array
	 */
	private function prepare_args( array $args ): array {
		return wp_parse_args(
			$args,
			array(
				'timeout' => self::DEFAULT_TIMEOUT,
			)
		);
	}

	/**
	 * Get the response body.
	 *
	 * @param array $response Response array.
	 * @return string
	 */
	public function get_response_body( array $response ): string {
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Get the response status code.
	 *
	 * @param array $response Response array.
	 * @return int
	 */
	public function get_response_code( array $response ): int {
		return (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Whether the response status is in the 2xx range.
	 *
	 * @param array $response Response array.
	 * @return bool
	 */
	public function is_success( array $response ): bool {
		$code = $this->get_response_code( $response );

		return $code >= 200 && $code < 300;
	}
}
