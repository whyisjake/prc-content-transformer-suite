<?php
/**
 * Claude Provider for PRC PDF Extraction
 *
 * Uses Anthropic's Claude API with native PDF document support to extract text
 * and return markdown-formatted output. When attachment_id is provided, uses the
 * Files API: uploads once, caches file_id on the attachment, and reuses it for
 * retries and future workloads. Falls back to base64 inline when no attachment
 * is available.
 *
 * Claude is the primary provider (priority 4) because its native multi-page PDF
 * comprehension produces superior table and structure extraction.
 *
 * @package PRC\Platform\PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Providers;

use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Request;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Response;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Authentication_Exception;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Extraction_Failed_Exception;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Rate_Limit_Exception;

/**
 * Claude Provider class
 */
class Claude_Provider implements OCR_Provider_Interface {

	use Trait_Shared_Extraction_Prompts;

	/**
	 * Default model name.
	 */
	const DEFAULT_MODEL = 'claude-sonnet-4-6';

	/**
	 * Anthropic Messages API endpoint.
	 */
	const API_ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * Anthropic API version header value.
	 */
	const ANTHROPIC_VERSION = '2023-06-01';

	/**
	 * Anthropic Files API endpoint.
	 */
	const FILES_API_ENDPOINT = 'https://api.anthropic.com/v1/files';

	/**
	 * Beta header for Files API.
	 */
	const FILES_API_BETA = 'files-api-2025-04-14';

	/**
	 * Post meta key for cached Claude file_id on attachments.
	 */
	const ATTACHMENT_META_KEY = '_claude_file_id';

	/**
	 * Provider priority (lower = higher priority).
	 *
	 * Priority 4 ensures Claude runs before Gemini (5) and WP AI (5).
	 *
	 * @var int
	 */
	private int $priority = 4;

	/**
	 * Anthropic API key.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Active model name (filterable).
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Constructor.
	 *
	 * @param string|null $model Optional model name override (e.g. 'claude-opus-4-6').
	 */
	public function __construct( ?string $model = null ) {
		$this->api_key = defined( 'PRC_PLATFORM_ANTHROPIC_API_KEY' ) ? PRC_PLATFORM_ANTHROPIC_API_KEY : '';
		$this->model   = $model ?? self::DEFAULT_MODEL;
	}

	/**
	 * Get the active model name, filtered for runtime overrides.
	 *
	 * @return string
	 */
	public function get_model(): string {
		return apply_filters( 'prc_pdf_extraction_claude_model', $this->model );
	}

	/**
	 * Get provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'claude';
	}

	/**
	 * Get provider priority.
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return apply_filters( 'prc_pdf_extraction_provider_priority', $this->priority, $this->get_name() );
	}

	/**
	 * Check if provider is available.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return ! empty( $this->api_key );
	}

	/**
	 * Estimate cost for processing a file.
	 *
	 * Claude Sonnet pricing (as of 2025):
	 * - Input: $3.00 per 1M tokens
	 * - Output: $15.00 per 1M tokens
	 * - Roughly $0.003-0.01 per page
	 *
	 * @param string $file_path Path to PDF file.
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( string $file_path ): float {
		$file_size = filesize( $file_path );
		// Rough estimate: 1MB PDF ≈ 10 pages ≈ $0.03 (two calls)
		$pages = max( 1, ceil( $file_size / 100000 ) );
		return $pages * 0.003;
	}

	/**
	 * Upload a PDF to the Anthropic Files API.
	 *
	 * Requires anthropic-beta: files-api-2025-04-14 header. Files are retained
	 * for ~30 days and can be reused across multiple API calls.
	 *
	 * @param string $file_path Path to the PDF file.
	 * @return string The file_id returned by the API.
	 * @throws Authentication_Exception If API key is invalid.
	 * @throws Extraction_Failed_Exception If upload fails.
	 */
	public function upload_to_files_api( string $file_path ): string {
		if ( ! $this->is_available() ) {
			throw new Authentication_Exception( 'Claude API key not configured' );
		}

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new Extraction_Failed_Exception( 'PDF file not found or not readable: ' . $file_path );
		}

		// wp_remote_post cannot send CURLFile objects as multipart/form-data,
		// so we use native cURL for the file upload.
		$ch = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			$ch,
			array(
				CURLOPT_URL            => self::FILES_API_ENDPOINT,
				CURLOPT_POST           => true,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 60,
				CURLOPT_HTTPHEADER     => array(
					'x-api-key: ' . $this->api_key,
					'anthropic-version: ' . self::ANTHROPIC_VERSION,
					'anthropic-beta: ' . self::FILES_API_BETA,
				),
				CURLOPT_POSTFIELDS     => array(
					'file' => new \CURLFile( $file_path, 'application/pdf', basename( $file_path ) ),
				),
			)
		);

		$response_body = curl_exec( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$status_code   = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		$curl_error    = curl_error( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
		curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		if ( false === $response_body || ! empty( $curl_error ) ) {
			throw new Extraction_Failed_Exception(
				'Claude Files API upload failed: ' . $curl_error
			);
		}

		$data = json_decode( $response_body, true );

		if ( 401 === $status_code || 403 === $status_code ) {
			throw new Authentication_Exception( 'Invalid Claude API key' );
		}

		if ( $status_code >= 400 ) {
			$error_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: 'Unknown error';
			throw new Extraction_Failed_Exception( 'Claude Files API upload error: ' . $error_message );
		}

		$file_id = $data['id'] ?? '';
		if ( empty( $file_id ) ) {
			throw new Extraction_Failed_Exception( 'Claude Files API did not return a file_id' );
		}

		return $file_id;
	}

	/**
	 * Delete a file from the Anthropic Files API.
	 *
	 * Files expire after ~30 days; this allows explicit cleanup.
	 *
	 * @param string $file_id The file_id returned by upload_to_files_api().
	 * @return void
	 */
	public function delete_from_files_api( string $file_id ): void {
		if ( ! $this->is_available() ) {
			return;
		}

		$response = wp_remote_request(
			self::FILES_API_ENDPOINT . '/' . rawurlencode( $file_id ),
			array(
				'method'  => 'DELETE',
				'headers' => array(
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::ANTHROPIC_VERSION,
					'anthropic-beta'    => self::FILES_API_BETA,
				),
			)
		);

		if ( is_wp_error( $response ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'prc-pdf-extraction: Claude Files API delete failed: ' . $response->get_error_message() );
		}
	}

	/**
	 * Extract text from PDF using Claude's native document understanding.
	 *
	 * When attachment_id is present in the request options, uses the Files API:
	 * uploads once (or reuses cached file_id from attachment meta), then makes
	 * two API calls by reference. Enables retries and future workloads without
	 * re-upload. When attachment_id is 0, falls back to base64 inline.
	 *
	 * @param OCR_Request $request The OCR request.
	 * @return OCR_Response The extraction response.
	 * @throws Authentication_Exception If API key is invalid or missing.
	 * @throws Rate_Limit_Exception If rate limited.
	 * @throws Extraction_Failed_Exception If extraction fails.
	 */
	public function extract_text( OCR_Request $request ): OCR_Response {
		if ( ! $this->is_available() ) {
			throw new Authentication_Exception( 'Claude API key not configured' );
		}

		$file_path     = $request->get_file_path();
		$attachment_id = (int) $request->get_option( 'attachment_id', 0 );

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new Extraction_Failed_Exception( 'PDF file not found or not readable: ' . $file_path );
		}

		if ( $attachment_id > 0 ) {
			return $this->extract_text_via_files_api( $file_path, $attachment_id );
		}

		return $this->extract_text_via_base64( $file_path );
	}

	/**
	 * Extract text using the Files API (upload once, reuse file_id).
	 *
	 * @param string $file_path     Path to the PDF.
	 * @param int    $attachment_id WP attachment ID (for caching file_id).
	 * @return OCR_Response
	 */
	private function extract_text_via_files_api( string $file_path, int $attachment_id ): OCR_Response {
		$file_id = get_post_meta( $attachment_id, self::ATTACHMENT_META_KEY, true );

		$max_attempts = 2;
		for ( $attempt = 0; $attempt < $max_attempts; $attempt++ ) {
			try {
				if ( empty( $file_id ) ) {
					$file_id = $this->upload_to_files_api( $file_path );
					update_post_meta( $attachment_id, self::ATTACHMENT_META_KEY, $file_id );
				}

				$gutenberg_result = $this->call_api_with_file_id( $this->build_gutenberg_prompt(), $file_id );
				if ( empty( $gutenberg_result['text'] ) ) {
					throw new Extraction_Failed_Exception( 'No Gutenberg block HTML extracted from PDF' );
				}

				$markdown_result = $this->call_api_with_file_id( $this->build_markdown_prompt(), $file_id );
				if ( empty( $markdown_result['text'] ) ) {
					throw new Extraction_Failed_Exception( 'No markdown extracted from PDF' );
				}

				$gutenberg_text = $gutenberg_result['text'];
				$markdown_text  = $markdown_result['text'];
				$plain_text     = $this->markdown_to_plain( $markdown_text );
				$confidence     = $this->calculate_confidence( $gutenberg_text, $gutenberg_result['was_truncated'] );
				$cost           = $this->estimate_cost( $file_path ) * 2;

				return new OCR_Response(
					true,
					$plain_text,
					$markdown_text,
					$gutenberg_text,
					$confidence,
					$this->get_name(),
					$cost,
					$gutenberg_result['raw_data']
				);
			} catch ( Extraction_Failed_Exception $e ) {
				if ( strpos( $e->getMessage(), 'file not found (may have expired)' ) !== false && ! empty( $file_id ) ) {
					delete_post_meta( $attachment_id, self::ATTACHMENT_META_KEY );
					$file_id = '';
					continue;
				}
				throw $e;
			}
		}

		throw new Extraction_Failed_Exception( 'Failed to extract after retrying expired file_id' );
	}

	/**
	 * Extract text using base64 inline (legacy path when no attachment_id).
	 *
	 * @param string $file_path Path to the PDF.
	 * @return OCR_Response
	 */
	private function extract_text_via_base64( string $file_path ): OCR_Response {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$pdf_contents = file_get_contents( $file_path );
		if ( false === $pdf_contents || empty( $pdf_contents ) ) {
			throw new Extraction_Failed_Exception( 'Failed to read PDF file: ' . $file_path );
		}

		$pdf_base64 = base64_encode( $pdf_contents );

		$gutenberg_result = $this->call_api( $this->build_gutenberg_prompt(), $pdf_base64, 'application/pdf' );
		if ( empty( $gutenberg_result['text'] ) ) {
			throw new Extraction_Failed_Exception( 'No Gutenberg block HTML extracted from PDF' );
		}

		$markdown_result = $this->call_api( $this->build_markdown_prompt(), $pdf_base64, 'application/pdf' );
		if ( empty( $markdown_result['text'] ) ) {
			throw new Extraction_Failed_Exception( 'No markdown extracted from PDF' );
		}

		$gutenberg_text = $gutenberg_result['text'];
		$markdown_text  = $markdown_result['text'];
		$plain_text     = $this->markdown_to_plain( $markdown_text );
		$confidence     = $this->calculate_confidence( $gutenberg_text, $gutenberg_result['was_truncated'] );
		$cost           = $this->estimate_cost( $file_path ) * 2;

		return new OCR_Response(
			true,
			$plain_text,
			$markdown_text,
			$gutenberg_text,
			$confidence,
			$this->get_name(),
			$cost,
			$gutenberg_result['raw_data']
		);
	}

	/**
	 * Make a single Claude API call with a PDF document block.
	 *
	 * Uses the Anthropic Messages API with the PDF embedded as a base64-encoded
	 * document content block. Claude natively understands PDF structure, layout,
	 * and multi-page content without any pre-processing.
	 *
	 * @param string $prompt    The extraction prompt.
	 * @param string $base64    Base64-encoded file data.
	 * @param string $mime_type MIME type of the file (e.g. 'application/pdf').
	 * @return array Array with 'text', 'was_truncated', and 'raw_data' keys.
	 * @throws Authentication_Exception If API key is invalid.
	 * @throws Rate_Limit_Exception If rate limited.
	 * @throws Extraction_Failed_Exception If extraction fails.
	 */
	public function call_api( string $prompt, string $base64, string $mime_type = 'application/pdf' ): array {
		$content_type = ( 0 === strpos( $mime_type, 'image/' ) ) ? 'image' : 'document';

		$request_body = array(
			'model'      => $this->get_model(),
			'max_tokens' => apply_filters( 'prc_pdf_extraction_claude_max_tokens', 16384 ),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'   => $content_type,
							'source' => array(
								'type'       => 'base64',
								'media_type' => $mime_type,
								'data'       => $base64,
							),
						),
						array(
							'type' => 'text',
							'text' => $prompt,
						),
					),
				),
			),
		);

		$timeout = (int) apply_filters( 'prc_pdf_extraction_ocr_timeout', 120 );

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::ANTHROPIC_VERSION,
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Extraction_Failed_Exception(
				'Claude API request failed: ' . $response->get_error_message()
			);
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( 401 === $status_code || 403 === $status_code ) {
			throw new Authentication_Exception( 'Invalid Claude API key' );
		}

		if ( 429 === $status_code ) {
			throw new Rate_Limit_Exception( 'Claude API rate limit exceeded' );
		}

		if ( $status_code >= 400 ) {
			$error_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: 'Unknown error';
			throw new Extraction_Failed_Exception( 'Claude API error: ' . $error_message );
		}

		return $this->parse_response( $data );
	}

	/**
	 * Make a Claude API call referencing an already-uploaded file by file_id.
	 *
	 * Uses the Files API (anthropic-beta header) so the document is sent by
	 * reference instead of inline base64.
	 *
	 * @param string $prompt  The extraction prompt.
	 * @param string $file_id The file_id from upload_to_files_api().
	 * @return array Array with 'text', 'was_truncated', and 'raw_data' keys.
	 * @throws Authentication_Exception If API key is invalid.
	 * @throws Rate_Limit_Exception If rate limited.
	 * @throws Extraction_Failed_Exception If extraction fails.
	 */
	public function call_api_with_file_id( string $prompt, string $file_id ): array {
		$request_body = array(
			'model'      => $this->get_model(),
			'max_tokens' => apply_filters( 'prc_pdf_extraction_claude_max_tokens', 16384 ),
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'   => 'document',
							'source' => array(
								'type'    => 'file',
								'file_id' => $file_id,
							),
						),
						array(
							'type' => 'text',
							'text' => $prompt,
						),
					),
				),
			),
		);

		$timeout = (int) apply_filters( 'prc_pdf_extraction_ocr_timeout', 120 );

		$response = wp_remote_post(
			self::API_ENDPOINT,
			array(
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $this->api_key,
					'anthropic-version' => self::ANTHROPIC_VERSION,
					'anthropic-beta'    => self::FILES_API_BETA,
				),
				'body'    => wp_json_encode( $request_body ),
				'timeout' => $timeout,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Extraction_Failed_Exception(
				'Claude API request failed: ' . $response->get_error_message()
			);
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( 401 === $status_code || 403 === $status_code ) {
			throw new Authentication_Exception( 'Invalid Claude API key' );
		}

		if ( 429 === $status_code ) {
			throw new Rate_Limit_Exception( 'Claude API rate limit exceeded' );
		}

		if ( 404 === $status_code ) {
			throw new Extraction_Failed_Exception( 'Claude API: file not found (may have expired)' );
		}

		if ( $status_code >= 400 ) {
			$error_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: 'Unknown error';
			throw new Extraction_Failed_Exception( 'Claude API error: ' . $error_message );
		}

		return $this->parse_response( $data );
	}

	/**
	 * Parse the Anthropic Messages API response.
	 *
	 * @param array $data Decoded JSON response.
	 * @return array Array with 'text', 'was_truncated', and 'raw_data' keys.
	 */
	private function parse_response( array $data ): array {
		$text          = '';
		$was_truncated = false;

		$stop_reason = $data['stop_reason'] ?? '';
		if ( 'max_tokens' === $stop_reason ) {
			$was_truncated = true;
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Claude response truncated: max_tokens reached' );
			}
		}

		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
					$text .= $block['text'];
				}
			}
		}

		return array(
			'text'          => trim( $text ),
			'was_truncated' => $was_truncated,
			'raw_data'      => $data,
		);
	}
}
