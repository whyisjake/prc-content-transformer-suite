<?php
/**
 * Scripted HTTP client for tests.
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\Tests;

use PRC\Platform\Audio_Narration\TTS\Infrastructure\HTTP_Client_Interface;

/**
 * Returns canned responses and records the requests it was given.
 *
 * Lets provider error mapping be tested without network access or an API key.
 */
class Fake_HTTP_Client implements HTTP_Client_Interface {

	/**
	 * Queued responses, consumed in order. The last one repeats.
	 *
	 * @var array<int, array|\WP_Error>
	 */
	private $responses;

	/**
	 * Recorded requests: url, args, method.
	 *
	 * @var array<int, array>
	 */
	public $requests = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, array|\WP_Error> $responses Queued responses.
	 */
	public function __construct( array $responses = array() ) {
		$this->responses = $responses;
	}

	/**
	 * Build a canned successful response.
	 *
	 * @param string $body   Response body.
	 * @param int    $status Status code.
	 * @return array
	 */
	public static function response( string $body, int $status = 200 ): array {
		return array(
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'body'     => $body,
		);
	}

	/**
	 * Send a GET request.
	 *
	 * @param string $url  URL.
	 * @param array  $args Args.
	 * @return array|\WP_Error
	 */
	public function get( string $url, array $args = array() ) {
		return $this->record( 'GET', $url, $args );
	}

	/**
	 * Send a POST request.
	 *
	 * @param string $url  URL.
	 * @param array  $args Args.
	 * @return array|\WP_Error
	 */
	public function post( string $url, array $args = array() ) {
		return $this->record( 'POST', $url, $args );
	}

	/**
	 * Record a request and return the next queued response.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    URL.
	 * @param array  $args   Args.
	 * @return array|\WP_Error
	 */
	private function record( string $method, string $url, array $args ) {
		$this->requests[] = array(
			'method' => $method,
			'url'    => $url,
			'args'   => $args,
		);

		if ( empty( $this->responses ) ) {
			return self::response( 'DEFAULT_AUDIO' );
		}

		return count( $this->responses ) > 1
			? array_shift( $this->responses )
			: $this->responses[0];
	}

	/**
	 * The most recently recorded request.
	 *
	 * @return array|null
	 */
	public function last_request(): ?array {
		return empty( $this->requests ) ? null : $this->requests[ count( $this->requests ) - 1 ];
	}

	/**
	 * Decoded JSON body of the most recent request.
	 *
	 * @return array
	 */
	public function last_body(): array {
		$last = $this->last_request();
		if ( null === $last || ! isset( $last['args']['body'] ) ) {
			return array();
		}

		$decoded = json_decode( (string) $last['args']['body'], true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Get the response body.
	 *
	 * @param array $response Response array.
	 * @return string
	 */
	public function get_response_body( array $response ): string {
		return (string) ( $response['body'] ?? '' );
	}

	/**
	 * Get the response status code.
	 *
	 * @param array $response Response array.
	 * @return int
	 */
	public function get_response_code( array $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}

	/**
	 * Whether the response status is 2xx.
	 *
	 * @param array $response Response array.
	 * @return bool
	 */
	public function is_success( array $response ): bool {
		$code = $this->get_response_code( $response );

		return $code >= 200 && $code < 300;
	}
}
