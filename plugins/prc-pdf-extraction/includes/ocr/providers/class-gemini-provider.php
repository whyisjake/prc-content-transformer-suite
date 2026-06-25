<?php
/**
 * Gemini Provider for PRC PDF Extraction
 *
 * Uses Google Gemini's native PDF understanding to extract text
 * and return markdown-formatted output. Supports both whole-PDF and
 * per-page-image extraction with Flash Thinking mode.
 *
 * @package PRC\Platform\PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Providers;

use PRC\Platform\PDF_Extraction\OCR\Application\Page_Analyzer;
use PRC\Platform\PDF_Extraction\OCR\Application\Result_Merger;
use PRC\Platform\PDF_Extraction\OCR\Application\Output_Validator;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Request;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Response;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Authentication_Exception;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Extraction_Failed_Exception;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Rate_Limit_Exception;
use PRC\Platform\PDF_Extraction\OCR\Infrastructure\File_Encoder;

/**
 * Gemini Provider class
 */
class Gemini_Provider implements OCR_Provider_Interface {

	use Trait_Shared_Extraction_Prompts;

	/**
	 * Default model name.
	 */
	const DEFAULT_MODEL = 'gemini-3-flash-preview';

	/**
	 * Base API URL (model is appended dynamically).
	 */
	const API_BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

	/**
	 * Files API upload endpoint (avoids base64 in generateContent body, reduces VIP timeout risk).
	 */
	const FILES_API_ENDPOINT = 'https://generativelanguage.googleapis.com/upload/v1beta/files';

	/**
	 * Provider priority (lower = higher priority)
	 *
	 * @var int
	 */
	private int $priority = 5;

	/**
	 * API key
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * File encoder
	 *
	 * @var File_Encoder
	 */
	private File_Encoder $file_encoder;

	/**
	 * Active model name (filterable).
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Constructor
	 *
	 * @param string|null $model Optional model name override (e.g. 'gemini-2.5-flash').
	 */
	public function __construct( ?string $model = null ) {
		$this->api_key      = defined( 'PRC_PLATFORM_GOOGLE_API_KEY' ) ? PRC_PLATFORM_GOOGLE_API_KEY : '';
		$this->file_encoder = new File_Encoder();
		$this->model        = $model ?? self::DEFAULT_MODEL;
	}

	/**
	 * Get the active model name, filtered for runtime overrides.
	 *
	 * @return string
	 */
	public function get_model(): string {
		return apply_filters( 'prc_pdf_extraction_gemini_model', $this->model );
	}

	/**
	 * Build the streamGenerateContent endpoint URL for the active model.
	 *
	 * Uses streaming to avoid 60s idle timeout; chunked responses keep the connection alive.
	 *
	 * @return string
	 */
	private function get_stream_api_endpoint(): string {
		return self::API_BASE_URL . $this->get_model() . ':streamGenerateContent';
	}

	/**
	 * Check if the active model is a Gemini 3.x model.
	 *
	 * @return bool
	 */
	private function is_gemini_3(): bool {
		return str_starts_with( $this->get_model(), 'gemini-3' );
	}

	/**
	 * Build the thinkingConfig for the active model.
	 *
	 * Gemini 2.5 uses thinkingBudget (integer token count).
	 * Gemini 3.x uses thinking_level (string: minimal, low, medium, high).
	 *
	 * @param int    $budget_tokens Token budget for Gemini 2.5 models.
	 * @param string $level         Thinking level for Gemini 3.x models. Default 'medium'.
	 * @return array
	 */
	private function build_thinking_config( int $budget_tokens, string $level = 'medium' ): array {
		if ( $this->is_gemini_3() ) {
			return array(
				'thinkingLevel' => apply_filters( 'prc_pdf_extraction_thinking_level', $level ),
			);
		}
		return array(
			'thinkingBudget' => apply_filters( 'prc_pdf_extraction_thinking_budget', $budget_tokens ),
		);
	}

	/**
	 * Build thinkingConfig for the prescan (page analyzer) task.
	 *
	 * Uses prc_pdf_extraction_prescan_thinking_level filter, default 'minimal'.
	 *
	 * @return array
	 */
	public function build_prescan_thinking_config(): array {
		if ( $this->is_gemini_3() ) {
			return array(
				'thinkingLevel' => apply_filters( 'prc_pdf_extraction_prescan_thinking_level', 'minimal' ),
			);
		}
		return array(
			'thinkingBudget' => apply_filters( 'prc_pdf_extraction_thinking_budget', 2048 ),
		);
	}

	/**
	 * Build thinkingConfig for the validation task.
	 *
	 * Uses prc_pdf_extraction_validation_thinking_level filter, default 'low'.
	 *
	 * @return array
	 */
	public function build_validation_thinking_config(): array {
		if ( $this->is_gemini_3() ) {
			return array(
				'thinkingLevel' => apply_filters( 'prc_pdf_extraction_validation_thinking_level', 'low' ),
			);
		}
		return array(
			'thinkingBudget' => apply_filters( 'prc_pdf_extraction_thinking_budget', 4096 ),
		);
	}

	/**
	 * Get provider name
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'gemini';
	}

	/**
	 * Get provider priority
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return apply_filters( 'prc_pdf_extraction_provider_priority', $this->priority, $this->get_name() );
	}

	/**
	 * Check if provider is available
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return ! empty( $this->api_key );
	}

	/**
	 * Estimate cost for processing a file
	 *
	 * Gemini 2.5 Flash pricing (as of 2025):
	 * - Input: $0.15 per 1M tokens
	 * - Output: $0.60 per 1M tokens
	 * - Roughly $0.002-0.01 per page
	 *
	 * @param string $file_path Path to PDF file.
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( string $file_path ): float {
		$file_size = filesize( $file_path );
		// Rough estimate: 1MB PDF ≈ 10 pages ≈ $0.02
		$pages = max( 1, ceil( $file_size / 100000 ) );
		return $pages * 0.002;
	}

	/**
	 * Upload a file to the Gemini Files API and return its URI.
	 *
	 * Separates file transfer from model inference to avoid VIP's 60s HTTP timeout
	 * on large base64 payloads in generateContent.
	 *
	 * @param string $file_path Absolute path to the file.
	 * @param string $mime_type MIME type (e.g. application/pdf, image/png).
	 * @return string The file_uri (e.g. https://generativelanguage.googleapis.com/v1beta/files/xxx).
	 * @throws Extraction_Failed_Exception On upload failure.
	 */
	public function upload_to_files_api( string $file_path, string $mime_type ): string {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new Extraction_Failed_Exception( "Cannot read file: {$file_path}" );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body = file_get_contents( $file_path );
		if ( false === $body ) {
			throw new Extraction_Failed_Exception( "Failed to read file: {$file_path}" );
		}

		$url = self::FILES_API_ENDPOINT . '?uploadType=media';

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Content-Type'   => $mime_type,
					'Content-Length' => (string) strlen( $body ),
					'x-goog-api-key' => $this->api_key,
				),
				'body'    => $body,
				'timeout' => apply_filters( 'prc_pdf_extraction_ocr_timeout', 120 ),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Extraction_Failed_Exception(
				'Gemini Files API upload failed: ' . $response->get_error_message()
			);
		}

		$status_code   = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data          = json_decode( $response_body, true );

		if ( 401 === $status_code || 403 === $status_code ) {
			throw new Authentication_Exception( 'Invalid Gemini API key' );
		}

		if ( 429 === $status_code ) {
			throw new Rate_Limit_Exception( 'Gemini API rate limit exceeded' );
		}

		if ( $status_code >= 400 ) {
			$error_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: 'Unknown error';
			throw new Extraction_Failed_Exception( 'Gemini Files API error: ' . $error_message );
		}

		$uri  = $data['file']['uri'] ?? $data['uri'] ?? '';
		$name = $data['file']['name'] ?? $data['name'] ?? '';

		if ( empty( $uri ) ) {
			throw new Extraction_Failed_Exception( 'Gemini Files API did not return a file URI' );
		}

		// Poll until ACTIVE if still processing (PDFs/images usually become ACTIVE within seconds).
		$max_wait = apply_filters( 'prc_pdf_extraction_files_api_max_wait', 30 );
		$waited   = 0;
		while ( ! empty( $name ) && ( $data['file']['state'] ?? $data['state'] ?? 'ACTIVE' ) === 'PROCESSING' && $waited < $max_wait ) {
			sleep( 1 );
			$waited  += 1;
			$get_url  = 'https://generativelanguage.googleapis.com/v1beta/' . $name;
			$get_resp = wp_remote_get(
				$get_url,
				array(
					'timeout' => 10,
					'headers' => array( 'x-goog-api-key' => $this->api_key ),
				)
			);
			if ( ! is_wp_error( $get_resp ) && wp_remote_retrieve_response_code( $get_resp ) === 200 ) {
				$data = json_decode( wp_remote_retrieve_body( $get_resp ), true );
				$uri  = $data['uri'] ?? $uri;
			}
		}

		return $uri;
	}

	/**
	 * Delete a file from the Gemini Files API (fire-and-forget).
	 *
	 * Files auto-expire after 48h; this avoids leaving uploads behind.
	 *
	 * @param string $file_uri Full URI (e.g. https://generativelanguage.googleapis.com/v1beta/files/xxx).
	 */
	public function delete_from_files_api( string $file_uri ): void {
		// Extract name from URI: .../v1beta/files/abc123 -> files/abc123
		if ( ! preg_match( '#/v1beta/(files/[a-zA-Z0-9_-]+)$#', $file_uri, $m ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'prc-pdf-extraction: Could not parse file_uri for delete: ' . $file_uri );
			}
			return;
		}

		$name = $m[1];
		$url  = 'https://generativelanguage.googleapis.com/v1beta/' . $name;

		$response = wp_remote_request(
			$url,
			array(
				'method'  => 'DELETE',
				'timeout' => 10,
				'headers' => array( 'x-goog-api-key' => $this->api_key ),
			)
		);

		if ( is_wp_error( $response ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'prc-pdf-extraction: Gemini Files API delete failed: ' . $response->get_error_message() );
		}
	}

	/**
	 * Extract text from PDF using Gemini's page-grouped pipeline.
	 *
	 * 1. Upload PDF once to Gemini Files API
	 * 2. Pre-scan for table continuations (1 API call)
	 * 3. Extract content from each page group independently (same file_uri)
	 * 4. Merge results
	 * 5. Validate against original PDF
	 * 6. Retry on mismatch (up to 2 retries)
	 *
	 * @param OCR_Request $request The OCR request.
	 * @return OCR_Response The extraction response.
	 * @throws Authentication_Exception If API key is invalid.
	 * @throws Rate_Limit_Exception If rate limited.
	 * @throws Extraction_Failed_Exception If extraction fails.
	 */
	public function extract_text( OCR_Request $request ): OCR_Response {
		if ( ! $this->is_available() ) {
			throw new Authentication_Exception( 'Gemini API key not configured' );
		}

		$file_path = $request->get_file_path();
		$mime_type = $this->file_encoder->get_mime_type( $file_path );
		if ( false === $mime_type ) {
			$mime_type = 'application/pdf';
		}

		$page_analyzer = new Page_Analyzer( $this );
		$merger        = new Result_Merger();
		$validator     = new Output_Validator( $this );

		$file_uri = $this->upload_to_files_api( $file_path, $mime_type );

		try {
			$analyzer_result = $page_analyzer->analyze( $file_uri );
			$groups          = $analyzer_result['groups'];
			$page_count      = $analyzer_result['page_count'];

			$responses = array();
			foreach ( $groups as $i => $page_range ) {
				$responses[] = $this->extract_from_pdf_pages( $file_uri, $page_range, $i );
			}

			$merged_response = $merger->merge( $responses );

			$max_retries   = apply_filters( 'prc_pdf_extraction_max_validation_retries', 2 );
			$best_response = $merged_response;

			for ( $attempt = 0; $attempt < $max_retries; $attempt++ ) {
				$validation = $validator->validate(
					$best_response->get_gutenberg(),
					$file_uri,
					$page_count
				);

				if ( $validation['valid'] ) {
					break;
				}

				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log(
					sprintf(
						'prc-pdf-extraction: validation failed (attempt %d/%d): %s',
						$attempt + 1,
						$max_retries,
						implode( '; ', $validation['issues'] )
					)
				);

				$retry_group_indices = $this->find_groups_for_pages( $groups, $validation['pages_with_issues'] );
				if ( empty( $retry_group_indices ) ) {
					$retry_group_indices = array_keys( $groups );
				}

				foreach ( $retry_group_indices as $gi ) {
					if ( isset( $groups[ $gi ] ) ) {
						$responses[ $gi ] = $this->extract_from_pdf_pages( $file_uri, $groups[ $gi ], $gi );
					}
				}

				$best_response = $merger->merge( $responses );
			}

			return $best_response;
		} finally {
			$this->delete_from_files_api( $file_uri );
		}
	}

	/**
	 * Determine which group indices contain the given page indices.
	 *
	 * @param int[][] $groups       Page groupings from the analyzer.
	 * @param int[]   $page_indices 0-based page indices with issues.
	 * @return int[] Group indices that should be re-processed.
	 */
	private function find_groups_for_pages( array $groups, array $page_indices ): array {
		if ( empty( $page_indices ) ) {
			return array();
		}

		$result = array();
		foreach ( $groups as $gi => $group ) {
			foreach ( $group as $page_idx ) {
				if ( in_array( $page_idx, $page_indices, true ) ) {
					$result[] = $gi;
					break;
				}
			}
		}

		return array_unique( $result );
	}

	/**
	 * Extract content from a single page-group image (one or more merged pages).
	 *
	 * Returns an OCR_Response for this group only; the caller is responsible
	 * for merging multiple groups.
	 *
	 * @param string $image_path Path to the PNG image for this group.
	 * @param int    $group_index 0-based index of this group (for logging).
	 * @return OCR_Response
	 * @throws Authentication_Exception If API key is invalid.
	 * @throws Rate_Limit_Exception If rate limited.
	 * @throws Extraction_Failed_Exception If extraction fails.
	 */
	public function extract_from_image( string $image_path, int $group_index = 0 ): OCR_Response {
		if ( ! $this->is_available() ) {
			throw new Authentication_Exception( 'Gemini API key not configured' );
		}

		$file_uri = $this->upload_to_files_api( $image_path, 'image/png' );

		try {
			$file_parts = array(
				array(
					'file_data' => array(
						'mime_type' => 'image/png',
						'file_uri'  => $file_uri,
					),
				),
			);

			// Gutenberg extraction.
			$gutenberg_result = $this->call_api( $this->build_gutenberg_prompt(), $file_parts );
			if ( empty( $gutenberg_result['text'] ) ) {
				throw new Extraction_Failed_Exception( "No Gutenberg HTML from image group {$group_index}" );
			}

			// Markdown extraction.
			$markdown_result = $this->call_api( $this->build_markdown_prompt(), $file_parts );
			if ( empty( $markdown_result['text'] ) ) {
				throw new Extraction_Failed_Exception( "No markdown from image group {$group_index}" );
			}

			$gutenberg_text = $gutenberg_result['text'];
			$markdown_text  = $markdown_result['text'];
			$plain_text     = $this->markdown_to_plain( $markdown_text );
			$confidence     = $this->calculate_confidence( $gutenberg_text, $gutenberg_result['was_truncated'] );

			$cost = 0.004; // ~$0.002 per call x 2 calls.

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
		} finally {
			$this->delete_from_files_api( $file_uri );
		}
	}

	/**
	 * Extract content from a specific page range of an already-uploaded PDF.
	 *
	 * Uses the V2 pipeline flow: same file_uri shared across pre-scan, extraction,
	 * and validation. No GhostScript or local PDF-to-image conversion required.
	 *
	 * @param string $file_uri   URI of the PDF in the Gemini Files API.
	 * @param int[]  $page_range 0-based page indices for this group (e.g. [1, 2, 3]).
	 * @param int    $group_index 0-based index of this group (for logging).
	 * @return OCR_Response
	 * @throws Authentication_Exception If API key is invalid.
	 * @throws Rate_Limit_Exception If rate limited.
	 * @throws Extraction_Failed_Exception If extraction fails.
	 */
	public function extract_from_pdf_pages( string $file_uri, array $page_range, int $group_index = 0 ): OCR_Response {
		if ( ! $this->is_available() ) {
			throw new Authentication_Exception( 'Gemini API key not configured' );
		}

		$file_parts = array(
			array(
				'file_data' => array(
					'mime_type' => 'application/pdf',
					'file_uri'  => $file_uri,
				),
			),
		);

		$page_instruction = $this->build_page_range_instruction( $page_range );
		$group_config     = array( 'maxOutputTokens' => 16384 );

		$gutenberg_result = $this->call_api(
			$page_instruction . "\n\n" . $this->build_gutenberg_prompt(),
			$file_parts,
			$group_config
		);
		if ( empty( $gutenberg_result['text'] ) ) {
			throw new Extraction_Failed_Exception( "No Gutenberg HTML from PDF group {$group_index}" );
		}

		$markdown_result = $this->call_api(
			$page_instruction . "\n\n" . $this->build_markdown_prompt(),
			$file_parts,
			$group_config
		);
		if ( empty( $markdown_result['text'] ) ) {
			throw new Extraction_Failed_Exception( "No markdown from PDF group {$group_index}" );
		}

		$gutenberg_text = $gutenberg_result['text'];
		$markdown_text  = $markdown_result['text'];
		$plain_text     = $this->markdown_to_plain( $markdown_text );
		$confidence     = $this->calculate_confidence( $gutenberg_text, $gutenberg_result['was_truncated'] );

		$cost = 0.004; // ~$0.002 per call x 2 calls.

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
	 * Build the page-range instruction for per-group extraction.
	 *
	 * @param int[] $page_range 0-based page indices.
	 * @return string Instruction string to prepend to extraction prompts.
	 */
	private function build_page_range_instruction( array $page_range ): string {
		if ( empty( $page_range ) ) {
			return 'Extract content from the specified pages of this PDF.';
		}

		$min = min( $page_range );
		$max = max( $page_range );

		// Convert 0-based to 1-based page numbers for the prompt.
		$start = $min + 1;
		$end   = $max + 1;

		if ( $start === $end ) {
			return sprintf(
				'Focus ONLY on page %d of this PDF. Do not extract content from other pages.',
				$start
			);
		}

		return sprintf(
			'Focus ONLY on pages %d through %d of this PDF. Do not extract content from other pages.',
			$start,
			$end
		);
	}

	/**
	 * Make a single Gemini API call with thinking enabled.
	 *
	 * @param string  $prompt      The extraction prompt.
	 * @param array[] $inline_parts Array of inline_data parts (images or PDFs).
	 * @param array   $config_overrides Optional generation config overrides.
	 * @return array Array with 'text', 'was_truncated', and 'raw_data' keys.
	 * @throws Authentication_Exception If API key is invalid.
	 * @throws Rate_Limit_Exception If rate limited.
	 * @throws Extraction_Failed_Exception If extraction fails.
	 */
	public function call_api( string $prompt, array $inline_parts, array $config_overrides = array() ): array {
		$parts   = $inline_parts;
		$parts[] = array( 'text' => $prompt );

		$timeout_override = $config_overrides['timeout'] ?? null;
		$api_config       = $config_overrides;
		unset( $api_config['timeout'] );

		$generation_config = wp_parse_args(
			$api_config,
			array(
				'temperature'     => 0.1,
				'topP'            => 0.8,
				'topK'            => 40,
				'maxOutputTokens' => 16384,
				'thinkingConfig'  => $this->build_thinking_config( 8192 ),
			)
		);

		$request_body = array(
			'contents'         => array(
				array( 'parts' => $parts ),
			),
			'generationConfig' => $generation_config,
		);

		$default_timeout = $this->is_gemini_3() ? 300 : 120;
		$timeout         = (int) apply_filters( 'prc_pdf_extraction_ocr_timeout', $default_timeout );
		if ( null !== $timeout_override ) {
			$timeout = (int) $timeout_override;
		}

		return $this->stream_request(
			wp_json_encode( $request_body ),
			$timeout
		);
	}

	/**
	 * Execute a streaming Gemini API request.
	 *
	 * Uses streamGenerateContent with SSE to avoid idle timeout; chunked responses
	 * keep the connection alive while the model processes.
	 *
	 * @param string $body    JSON-encoded request body.
	 * @param int    $timeout Timeout in seconds.
	 * @return array Array with 'text', 'was_truncated', and 'raw_data' keys.
	 * @throws Authentication_Exception If API key is invalid.
	 * @throws Rate_Limit_Exception Rate limited.
	 * @throws Extraction_Failed_Exception On request or parse failure.
	 */
	private function stream_request( string $body, int $timeout ): array {
		$url = $this->get_stream_api_endpoint() . '?alt=sse';

		$buffer = '';

		$ch = curl_init( $url );
		if ( false === $ch ) {
			throw new Extraction_Failed_Exception( 'Gemini API: failed to initialize cURL' );
		}

		$write_callback = function ( $ch, $data ) use ( &$buffer ) {
			$len     = strlen( $data );
			$buffer .= $data;
			return $len;
		};

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST          => true,
				CURLOPT_POSTFIELDS    => $body,
				CURLOPT_HTTPHEADER    => array( 'Content-Type: application/json', 'x-goog-api-key: ' . $this->api_key ),
				CURLOPT_TIMEOUT       => $timeout,
				CURLOPT_WRITEFUNCTION => $write_callback,
			)
		);

		$success = curl_exec( $ch );
		$errno   = curl_errno( $ch );
		$errmsg  = curl_error( $ch );
		$status  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( ! $success && 0 !== $errno ) {
			throw new Extraction_Failed_Exception(
				'Gemini API request failed: ' . ( $errmsg ?: 'cURL error ' . $errno )
			);
		}

		// Non-2xx: body is JSON error, not SSE.
		if ( $status >= 400 ) {
			$data = json_decode( $buffer, true );
			if ( 401 === $status || 403 === $status ) {
				throw new Authentication_Exception( 'Invalid Gemini API key' );
			}
			if ( 429 === $status ) {
				throw new Rate_Limit_Exception( 'Gemini API rate limit exceeded' );
			}
			$error_message = isset( $data['error']['message'] )
				? $data['error']['message']
				: 'Unknown error';
			throw new Extraction_Failed_Exception( 'Gemini API error: ' . $error_message );
		}

		$merged = $this->merge_streamed_response( $buffer );
		return array(
			'text'          => $merged['text'],
			'was_truncated' => $merged['was_truncated'],
			'raw_data'      => $merged['raw_data'],
		);
	}

	/**
	 * Parse SSE stream buffer and merge chunks into a single response.
	 *
	 * @param string $buffer Raw response body (SSE format).
	 * @return array Array with 'text', 'was_truncated', 'raw_data' keys.
	 */
	private function merge_streamed_response( string $buffer ): array {
		$text_parts      = array();
		$was_truncated   = false;
		$last_finish     = '';
		$last_candidates = array();

		$lines = explode( "\n", $buffer );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 !== strpos( $line, 'data: ' ) ) {
				continue;
			}
			$json = substr( $line, 6 );
			if ( '[DONE]' === $json || '{}' === $json ) {
				continue;
			}
			$data = json_decode( $json, true );
			if ( ! is_array( $data ) || ! isset( $data['candidates'][0] ) ) {
				continue;
			}
			$cand            = $data['candidates'][0];
			$last_finish     = $cand['finishReason'] ?? $last_finish;
			$last_candidates = $cand;

			if ( ! isset( $cand['content']['parts'] ) ) {
				continue;
			}
			foreach ( $cand['content']['parts'] as $part ) {
				if ( ! empty( $part['thought'] ) ) {
					continue;
				}
				if ( isset( $part['text'] ) ) {
					$text_parts[] = $part['text'];
				}
			}
		}

		$text = trim( implode( '', $text_parts ) );
		if ( 'MAX_TOKENS' === $last_finish ) {
			$was_truncated = true;
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Gemini response truncated: MAX_TOKENS reached' );
			}
		}

		// Build synthetic raw_data for callers that expect it.
		$raw_data = array(
			'candidates' => array(
				array(
					'content'      => array( 'parts' => array( array( 'text' => $text ) ) ),
					'finishReason' => $last_finish,
				),
			),
		);

		return array(
			'text'          => $text,
			'was_truncated' => $was_truncated,
			'raw_data'      => $raw_data,
		);
	}

	/**
	 * Parse the API response, skipping thinking parts.
	 *
	 * @param array $data Response data.
	 * @return array Array with 'text' and 'was_truncated' keys.
	 */
	private function parse_response( array $data ): array {
		$result = array(
			'text'          => '',
			'was_truncated' => false,
		);

		if ( ! isset( $data['candidates'][0]['content']['parts'] ) ) {
			return $result;
		}

		// Check for truncation.
		$finish_reason = $data['candidates'][0]['finishReason'] ?? '';
		if ( 'MAX_TOKENS' === $finish_reason ) {
			$result['was_truncated'] = true;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Gemini response truncated: MAX_TOKENS reached' );
		}

		// With thinking enabled, the response contains multiple parts.
		// Skip parts flagged as "thought" and collect the final text.
		foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
			if ( ! empty( $part['thought'] ) ) {
				continue;
			}
			if ( isset( $part['text'] ) ) {
				$result['text'] .= $part['text'];
			}
		}

		$result['text'] = trim( $result['text'] );
		return $result;
	}
}
