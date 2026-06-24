<?php
/**
 * PRC Apple News: Request class
 *
 * @package PRC\Platform\Apple_News
 * @subpackage Apple_News_API
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\Apple_News_API;

use WP_Error;

/**
 * Sends HMAC-signed HTTP requests to the Apple News API.
 *
 * Uses wp_safe_remote_request() for all HTTP calls. Returns WP_Error (never
 * throws exceptions) on any failure — WP_Error from the HTTP layer, non-2xx
 * HTTP status codes, or JSON decode failures.
 *
 * @since 1.0.0
 */
class Request {

	/**
	 * The credentials used to sign requests.
	 *
	 * @var Credentials
	 * @since 1.0.0
	 */
	private Credentials $credentials;

	/**
	 * Helper used to build MIME multipart bodies.
	 *
	 * @var MIME_Builder
	 * @since 1.0.0
	 */
	private MIME_Builder $mime_builder;

	/**
	 * Constructor.
	 *
	 * @param Credentials       $credentials  The API credentials.
	 * @param MIME_Builder|null $mime_builder Optional MIME builder instance.
	 */
	public function __construct( Credentials $credentials, ?MIME_Builder $mime_builder = null ) {
		$this->credentials  = $credentials;
		$this->mime_builder = $mime_builder ?? new MIME_Builder();
	}

	/**
	 * Send a POST request with the given article JSON and optional bundles.
	 *
	 * @param string     $url     The API endpoint URL.
	 * @param string     $article The article JSON string.
	 * @param array      $bundles Optional. File paths / URLs of bundle assets. Defaults to [].
	 * @param array|null $meta    Optional. Metadata array to include in the request. Defaults to null.
	 * @param int|null   $post_id Optional. WordPress post ID. Defaults to null.
	 * @return \stdClass|WP_Error Decoded response object, or WP_Error on failure.
	 */
	public function post( string $url, string $article, array $bundles = [], ?array $meta = null, ?int $post_id = null ): \stdClass|WP_Error {
		return $this->request(
			'POST',
			$url,
			[
				'article' => $article,
				'bundles' => $bundles,
				'meta'    => $meta,
				'post_id' => $post_id,
			]
		);
	}

	/**
	 * Send a DELETE request.
	 *
	 * @param string $url The API endpoint URL.
	 * @return bool|WP_Error True on success (204 No Content), WP_Error on failure.
	 */
	public function delete( string $url ): bool|WP_Error {
		return $this->request( 'DELETE', $url );
	}

	/**
	 * Send a GET request.
	 *
	 * @param string $url The API endpoint URL.
	 * @return \stdClass|WP_Error Decoded response object, or WP_Error on failure.
	 */
	public function get( string $url ): \stdClass|WP_Error {
		return $this->request( 'GET', $url );
	}

	/**
	 * Perform the signed HTTP request and parse the response.
	 *
	 * @param string $verb The HTTP verb: GET, POST, or DELETE.
	 * @param string $url  The full API URL.
	 * @param array  $data Optional. Payload data (article, bundles, meta, post_id). Only used for POST.
	 * @return \stdClass|bool|WP_Error Decoded object for GET/POST, true for DELETE 204, WP_Error on failure.
	 */
	private function request( string $verb, string $url, array $data = [] ): \stdClass|bool|WP_Error {
		// Build MIME body for POST requests.
		$content = null;
		if ( 'POST' === $verb ) {
			$content = $this->build_content(
				$data['article'],
				$data['bundles'] ?? [],
				$data['meta'] ?? [],
				$data['post_id'] ?? null
			);

			if ( is_wp_error( $content ) ) {
				return $content;
			}
		}

		// Build signed request args.
		$args = [
			'headers' => [
				'Authorization' => $this->sign( $url, $verb, $content ),
			],
			'method'  => $verb,
		];

		if ( 'POST' === $verb ) {
			$args['headers']['Content-Length'] = strlen( $content );
			$args['headers']['Content-Type']   = 'multipart/form-data; boundary=' . $this->mime_builder->boundary();
			$args['body']                      = $content;
			$args['timeout']                   = 30; // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
		}

		if ( 'GET' === $verb ) {
			$args['timeout'] = 10; // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
		}

		if ( 'DELETE' === $verb ) {
			$args['timeout'] = 5; // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
		}

		// Execute the request.
		$response = wp_safe_remote_request( esc_url_raw( $url ), $args );

		// HTTP transport failure.
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		// Successful DELETE: 204 No Content — return true, no body to parse.
		if ( 'DELETE' === $verb && 204 === $status_code ) {
			return true;
		}

		// Non-2xx HTTP status codes are failures.
		if ( $status_code < 200 || $status_code >= 300 ) {
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body );
			$message = wp_remote_retrieve_response_message( $response );
			$details = array();

			if ( is_object( $decoded ) && ! empty( $decoded->errors ) && is_array( $decoded->errors ) ) {
				foreach ( $decoded->errors as $error ) {
					$key_path = '';
					if ( ! empty( $error->keyPath ) && is_array( $error->keyPath ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						$key_path = ' (keyPath ' . implode( '->', $error->keyPath ) . ')'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					}
					$details[] = sprintf(
						'%s%s%s%s',
						$error->code ?? 'UNKNOWN',
						( ! empty( $error->message ) ) ? ' - ' : '',
						( ! empty( $error->message ) ) ? $error->message : '',
						$key_path
					);
				}
				if ( ! empty( $details ) ) {
					$message .= ': ' . implode( ', ', $details );
				}
			}

			return new WP_Error(
				'prc_apple_news_http_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: response message. */
					__( 'Apple News API returned HTTP %1$s: %2$s', 'prc-apple-news' ),
					$status_code,
					$message
				),
				[
					'status' => $status_code,
					'body'   => $body,
				]
			);
		}

		// Decode JSON body.
		$body = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'prc_apple_news_json_decode_error',
				__( 'Unable to decode JSON from the Apple News API response.', 'prc-apple-news' ),
				[ 'body' => $body ]
			);
		}

		// Surface API-level errors returned inside the response body.
		if ( ! empty( $decoded->errors ) && is_array( $decoded->errors ) ) {
			$messages = [];
			foreach ( $decoded->errors as $error ) {
				$key_path = '';
				if ( ! empty( $error->keyPath ) && is_array( $error->keyPath ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$key_path = ' (keyPath ' . implode( '->', $error->keyPath ) . ')'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}
				$messages[] = sprintf(
					'%s%s%s%s',
					$error->code,
					( ! empty( $error->message ) ) ? ' - ' : '',
					( ! empty( $error->message ) ) ? $error->message : '',
					$key_path
				);
			}

			return new WP_Error(
				'prc_apple_news_api_error',
				implode( ', ', $messages ),
				[ 'decoded_response' => $decoded ]
			);
		}

		return $decoded;
	}

	/**
	 * Build the multipart MIME body for a POST request.
	 *
	 * @param string     $article The article JSON string.
	 * @param array      $bundles File paths / URLs of bundle assets.
	 * @param array      $meta    Metadata array.
	 * @param int|null   $post_id WordPress post ID for the apply_filters call.
	 * @return string|WP_Error The MIME body string, or WP_Error on failure.
	 */
	private function build_content( string $article, array $bundles = [], array $meta = [], ?int $post_id = null ): string|\WP_Error {
		$bundles = array_unique( $bundles );
		$content = '';

		/**
		 * Filters custom metadata for the article before being sent to Apple.
		 *
		 * @param array    $meta    The Apple News Format metadata to be sent to Apple.
		 * @param int|null $post_id The ID of the post being prepared.
		 */
		$meta = apply_filters( 'prc_apple_news_api_post_meta', $meta, $post_id );

		if ( ! empty( $meta['data'] ) && is_array( $meta['data'] ) ) {
			$result = $this->mime_builder->add_metadata( $meta );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$content .= $result;
		}

		$result = $this->mime_builder->add_json_string( 'my_article', 'article.json', $article );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$content .= $result;

		foreach ( $bundles as $bundle ) {
			$result = $this->mime_builder->add_content_from_file( $bundle );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$content .= $result;
		}

		$content .= $this->mime_builder->close();

		return $content;
	}

	/**
	 * Compute the HMAC-SHA256 Authorization header value for a request.
	 *
	 * Algorithm (preserved exactly from publish-to-apple-news):
	 *   request_info = verb + url + ISO-8601-date
	 *   if POST: request_info += content_type + content_body
	 *   secret_key = base64_decode(credentials.secret)
	 *   hash = hash_hmac('sha256', request_info, secret_key, raw=true)
	 *   signature = base64_encode(hash)
	 *   header = 'HHMAC; key=<key>; signature=<sig>; date=<date>'
	 *
	 * @param string      $url     The full request URL.
	 * @param string      $verb    The HTTP verb (GET, POST, DELETE).
	 * @param string|null $content The request body (only used for POST).
	 * @return string The Authorization header value.
	 */
	private function sign( string $url, string $verb, ?string $content = null ): string {
		$current_date = gmdate( 'c' );

		$request_info = $verb . $url . $current_date;
		if ( 'POST' === $verb ) {
			$content_type  = 'multipart/form-data; boundary=' . $this->mime_builder->boundary();
			$request_info .= $content_type . $content;
		}

		$secret_key = base64_decode( $this->credentials->secret() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$hash       = hash_hmac( 'sha256', $request_info, $secret_key, true );
		$signature  = base64_encode( $hash ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return 'HHMAC; key=' . $this->credentials->key() . '; signature=' . $signature . '; date=' . $current_date;
	}
}
