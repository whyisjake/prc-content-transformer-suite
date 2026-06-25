<?php
/**
 * WP-CLI Commands for PRC PDF Extraction
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

use PRC\Platform\PDF_Extraction\OCR\Application\OCR_Orchestrator;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Request;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Response;
use WP_CLI;

/**
 * WP-CLI commands for managing PRC PDF Extraction
 */
class WP_CLI_Commands {
	/**
	 * OCR Orchestrator instance (retained for provider-level commands).
	 *
	 * @var OCR_Orchestrator
	 */
	private $orchestrator;

	/**
	 * Shared extraction service.
	 *
	 * @var Extraction_Service
	 */
	private $service;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->service      = new Extraction_Service();
		$this->orchestrator = $this->service->get_orchestrator();
	}

	/**
	 * Check that API keys required by the V2 pipeline are available.
	 *
	 * V2 uses Gemini's native PDF handling — no GhostScript or system binaries required.
	 */
	private function check_system_dependencies(): void {
		if ( ! defined( 'PRC_PLATFORM_GOOGLE_API_KEY' ) || ! PRC_PLATFORM_GOOGLE_API_KEY ) {
			WP_CLI::warning( 'PRC_PLATFORM_GOOGLE_API_KEY is not defined. V2 pipeline will fall back to legacy extraction.' );
		}
	}

	/**
	 * Enable Form Parser mode on Document AI provider
	 */
	private function enable_form_parser() {
		$providers = $this->orchestrator->get_providers();
		foreach ( $providers as $provider ) {
			if ( method_exists( $provider, 'use_form_parser' ) ) {
				if ( $provider->has_form_parser() ) {
					$provider->use_form_parser( true );
					WP_CLI::line( 'Using Form Parser processor for better table extraction.' );
				} else {
					WP_CLI::warning( 'Form Parser processor not configured. Using Document OCR instead.' );
				}
				break;
			}
		}
	}

	/**
	 * Extract text using a specific provider.
	 *
	 * @param string $file_path     Path to the PDF file.
	 * @param string $provider_name Name of the provider to use.
	 * @param int    $attachment_id Optional. WP attachment ID for Files API integration.
	 * @return OCR_Response|\WP_Error Response or error.
	 */
	private function extract_with_provider( string $file_path, string $provider_name, int $attachment_id = 0 ) {
		$providers = $this->orchestrator->get_providers();

		foreach ( $providers as $provider ) {
			if ( $provider->get_name() === $provider_name ) {
				if ( ! $provider->is_available() ) {
					return new \WP_Error(
						'provider_unavailable',
						"Provider '{$provider_name}' is not available. Check API key configuration."
					);
				}

				try {
					$request = new OCR_Request(
						$file_path,
						array(
							'format'        => 'topline',
							'attachment_id' => $attachment_id,
						)
					);
					return $provider->extract_text( $request );
				} catch ( \Exception $e ) {
					return new \WP_Error(
						'extraction_failed',
						"Provider '{$provider_name}' failed: " . $e->getMessage()
					);
				}
			}
		}

		$available = array_map(
			function ( $p ) {
				return $p->get_name();
			},
			$providers
		);

		return new \WP_Error(
			'provider_not_found',
			"Provider '{$provider_name}' not found. Available: " . implode( ', ', $available )
		);
	}

	/**
	 * List available OCR providers
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc-pdf-extraction list-providers
	 *
	 * @when after_wp_load
	 */
	public function list_providers() {
		$providers = $this->orchestrator->get_providers();

		if ( empty( $providers ) ) {
			WP_CLI::warning( 'No OCR providers are configured.' );
			WP_CLI::line( 'Configure providers by adding API keys to vip-config/keys-and-tokens.php' );
			return;
		}

		WP_CLI::line( 'Available OCR Providers:' );
		WP_CLI::line( '' );

		$rows = array();
		foreach ( $providers as $provider ) {
			$rows[] = array(
				'name'      => $provider->get_name(),
				'priority'  => $provider->get_priority(),
				'available' => $provider->is_available() ? 'Yes' : 'No',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'name', 'priority', 'available' ) );
	}

	/**
	 * List PDF extractions for a post
	 *
	 * ## OPTIONS
	 *
	 * --post_id=<post_id>
	 * : The post ID to check for PDF extractions
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc-pdf-extraction list-extractions --post_id=123
	 *
	 * @when after_wp_load
	 */
	public function list_extractions( $args, $assoc_args ) {
		$post_id = $assoc_args['post_id'] ?? null;

		if ( ! $post_id ) {
			WP_CLI::error( 'Please provide a post ID using --post_id=123' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			WP_CLI::error( "Post {$post_id} not found" );
		}

		$materials = $this->get_extraction_materials_from_post( $post_id );

		if ( empty( $materials ) ) {
			WP_CLI::warning( "No extraction materials found for post {$post_id}" );
			return;
		}

		WP_CLI::line( "Extraction materials for post {$post_id}: {$post->post_title}" );
		WP_CLI::line( '' );

		$rows = array();
		foreach ( $materials as $material ) {
			$attachment_id = $material['attachmentId'] ?? null;
			$extraction    = $attachment_id ? $this->get_extraction_for_material( $post_id, $attachment_id ) : null;
			$rows[]        = array(
				'label'         => $material['label'] ?? 'PDF',
				'attachment_id' => $attachment_id ?? 'N/A',
				'extracted'     => $extraction ? 'Yes' : 'No',
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'label', 'attachment_id', 'extracted' ) );
	}

	/**
	 * Estimate cost for extracting text from a post's PDF
	 *
	 * ## OPTIONS
	 *
	 * --post_id=<post_id>
	 * : The post ID to estimate cost for
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc-pdf-extraction estimate-cost --post_id=123
	 *
	 * @when after_wp_load
	 */
	public function estimate_cost( $args, $assoc_args ) {
		$post_id = $assoc_args['post_id'] ?? null;

		if ( ! $post_id ) {
			WP_CLI::error( 'Please provide a post ID using --post_id=123' );
		}

		$toplines = $this->get_extraction_materials_from_post( $post_id );

		if ( empty( $toplines ) ) {
			WP_CLI::error( "No toplines found for post {$post_id}" );
		}

		$topline   = reset( $toplines );
		$file_path = $this->get_file_path_from_attachment( $topline['attachmentId'], $topline );

		if ( ! $file_path ) {
			WP_CLI::error( 'Could not get file path for attachment' );
		}

		$is_temp_file = strpos( $file_path, sys_get_temp_dir() ) === 0;

		$cost = $this->orchestrator->estimate_cost( $file_path );

		// Clean up if downloaded
		if ( $is_temp_file ) {
			$this->cleanup_temp_file( $file_path );
		}

		WP_CLI::success( sprintf( 'Estimated cost: $%.4f USD', $cost ) );
	}

	/**
	 * Validate an existing extraction
	 *
	 * ## OPTIONS
	 *
	 * --post_id=<post_id>
	 * : The extraction post ID to validate
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc-pdf-extraction validate --post_id=456
	 *
	 * @when after_wp_load
	 */
	public function validate( $args, $assoc_args ) {
		$post_id = $assoc_args['post_id'] ?? null;

		if ( ! $post_id ) {
			WP_CLI::error( 'Please provide an extraction post ID using --post_id=456' );
		}

		$post = get_post( $post_id );
		if ( ! $post || Content_Type::get_post_type() !== $post->post_type ) {
			WP_CLI::error( "Extraction post {$post_id} not found" );
		}

		$validation_status = get_post_meta( $post_id, '_validation_status', true );
		$validation_issues = get_post_meta( $post_id, '_validation_issues', true );
		$char_count        = get_post_meta( $post_id, '_character_count', true );
		$confidence        = get_post_meta( $post_id, '_ocr_confidence_score', true );
		$duration          = get_post_meta( $post_id, '_extraction_duration_seconds', true );

		WP_CLI::line( "Validation for extraction {$post_id}:" );
		WP_CLI::line( '' );
		WP_CLI::line( "Status: {$validation_status}" );
		WP_CLI::line( "Character count: {$char_count}" );
		WP_CLI::line( "Confidence: {$confidence}" );
		if ( $duration !== '' && $duration !== false ) {
			WP_CLI::line( sprintf( 'Extraction duration: %.2f seconds', (float) $duration ) );
		}

		if ( ! empty( $validation_issues ) ) {
			WP_CLI::line( '' );
			WP_CLI::line( 'Issues:' );
			$issues = json_decode( $validation_issues, true );
			if ( is_array( $issues ) ) {
				foreach ( $issues as $issue ) {
					WP_CLI::line( "  - {$issue}" );
				}
			} else {
				WP_CLI::line( "  - {$validation_issues}" );
			}
		}
	}

	/**
	 * Extract text from a post's topline PDF
	 *
	 * ## OPTIONS
	 *
	 * --post_id=<post_id>
	 * : The post ID to process
	 *
	 * [--file=<path>]
	 * : Use a local PDF file instead of downloading (for testing)
	 *
	 * [--dry-run]
	 * : Show what would happen without actually processing
	 *
	 * [--force]
	 * : Force reprocessing even if already extracted
	 *
	 * [--provider=<provider>]
	 * : Force a specific OCR provider (claude, gemini, wp-ai). Bypasses the V2 pipeline.
	 *
	 * [--insecure]
	 * : Allow insecure SSL connections for downloading PDFs
	 *
	 * [--form-parser]
	 * : Use Form Parser processor for better table extraction
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc-pdf-extraction process --post_id=123
	 *     wp prc-pdf-extraction process --post_id=123 --dry-run
	 *     wp prc-pdf-extraction process --post_id=123 --force
	 *     wp prc-pdf-extraction process --post_id=123 --insecure
	 *     wp prc-pdf-extraction process --post_id=123 --file=/path/to/local.pdf
	 *     wp prc-pdf-extraction process --post_id=123 --form-parser
	 *
	 * @when after_wp_load
	 */
	public function process( $args, $assoc_args ) {
		$post_id         = $assoc_args['post_id'] ?? null;
		$local_file      = $assoc_args['file'] ?? null;
		$dry_run         = isset( $assoc_args['dry-run'] );
		$force           = isset( $assoc_args['force'] );
		$provider_name   = $assoc_args['provider'] ?? null;
		$insecure        = isset( $assoc_args['insecure'] );
		$use_form_parser = isset( $assoc_args['form-parser'] );

		// Enable Form Parser mode if requested
		if ( $use_form_parser ) {
			$this->enable_form_parser();
		}

		if ( ! $post_id ) {
			WP_CLI::error( 'Please provide a post ID using --post_id=123' );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			WP_CLI::error( "Post {$post_id} not found" );
		}

		WP_CLI::line( "Processing post {$post_id}: {$post->post_title}" );

		// Get PDF extractions
		$toplines = $this->get_extraction_materials_from_post( $post_id );

		if ( empty( $toplines ) ) {
			WP_CLI::error( 'No toplines found for this post' );
		}

		$topline = reset( $toplines );
		WP_CLI::line( "Processing topline: {$topline['label']}" );

		// Check if already extracted
		$existing = $this->get_extraction_for_material( $post_id, $topline['attachmentId'] );
		if ( $existing && ! $force ) {
			WP_CLI::warning( "Topline already extracted (extraction ID: {$existing}). Use --force to reprocess." );
			return;
		}
		if ( $existing && $force ) {
			WP_CLI::line( "Existing extraction found (ID: {$existing}). Will update with new extraction." );
		}

		// Get file path (use --file if provided, otherwise try attachment)
		$attachment_id_for_extraction = 0;
		if ( $local_file ) {
			if ( ! file_exists( $local_file ) ) {
				WP_CLI::error( "Local file not found: {$local_file}" );
			}
			$file_path = $local_file;
			WP_CLI::line( "Using local file: {$file_path}" );
			$is_temp_file = false;
		} else {
			$attachment_id_for_extraction = (int) $topline['attachmentId'];
			$file_path                    = $this->get_file_path_from_attachment( $topline['attachmentId'], $topline, $insecure );
			if ( ! $file_path ) {
				WP_CLI::error( 'Could not get file path for attachment' );
			}
			$is_temp_file = strpos( $file_path, sys_get_temp_dir() ) === 0;
		}

		// Estimate cost
		$cost = $this->orchestrator->estimate_cost( $file_path );
		WP_CLI::line( sprintf( 'Estimated cost: $%.4f USD', $cost ) );

		if ( $dry_run ) {
			// Clean up temp file if downloaded
			if ( $is_temp_file ) {
				$this->cleanup_temp_file( $file_path );
			}
			WP_CLI::success( 'Dry run complete. No processing performed.' );
			return;
		}

		// Verify system dependencies before extraction.
		$this->check_system_dependencies();

		WP_CLI::line( 'Extracting text from PDF...' );

		$start_time = microtime( true );
		if ( $provider_name ) {
			WP_CLI::line( "Using provider: {$provider_name}" );
			$response = $this->extract_with_provider( $file_path, $provider_name, $attachment_id_for_extraction );
		} else {
			$response = $this->service->extract_text( $file_path, $attachment_id_for_extraction );
		}
		$duration_seconds = microtime( true ) - $start_time;

		// Clean up temp file after extraction
		if ( $is_temp_file ) {
			$this->cleanup_temp_file( $file_path );
		}

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( 'Extraction failed: ' . $response->get_error_message() );
		}

		WP_CLI::line(
			sprintf(
				'Extracted %d characters with %.2f%% confidence using %s (%.2fs)',
				$response->get_character_count(),
				$response->get_confidence() * 100,
				$response->get_provider(),
				$duration_seconds
			)
		);

		// Create or update extraction post
		$extraction_id = $this->save_extraction( $post_id, $topline, $response, $existing, $duration_seconds );

		$action = $existing ? 'Updated' : 'Created';
		WP_CLI::success(
			sprintf(
				'Extraction complete! %s post %d (%.2fs, Cost: $%.4f)',
				$action,
				$extraction_id,
				$duration_seconds,
				$response->get_cost()
			)
		);
	}

	/**
	 * Get PDF extractions from a post's reportMaterials meta. (Topline material type)
	 *
	 * @param int $post_id Post ID.
	 * @return array Array of PDF extraction materials.
	 */
	private function get_extraction_materials_from_post( int $post_id ): array {
		return $this->service->get_extraction_materials_from_post( $post_id );
	}

	/**
	 * Get an existing extraction post for a PDF extraction by its attachment ID.
	 *
	 * @param int $parent_id     Parent post ID.
	 * @param int $attachment_id Attachment ID of the PDF extraction material.
	 * @return int|null Extraction post ID or null.
	 */
	private function get_extraction_for_material( int $parent_id, int $attachment_id ): ?int {
		return $this->service->get_extraction_for_material( $parent_id, $attachment_id );
	}

	/**
	 * Get a file path for a PDF extraction attachment.
	 *
	 * Tries the local WordPress uploads directory first, then downloads from
	 * the remote URL in the PDF extraction data.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $topline       Optional PDF extraction data with URL.
	 * @param bool  $insecure      Allow insecure SSL connections.
	 * @return string|false File path or false.
	 */
	private function get_file_path_from_attachment( int $attachment_id, array $topline = array(), bool $insecure = false ) {
		if ( ! get_attached_file( $attachment_id ) && ! empty( $topline['url'] ) ) {
			WP_CLI::line( 'Local file not found. Downloading from remote URL...' );
		}
		$file_path = $this->service->get_file_path_from_attachment( $attachment_id, $topline, $insecure );
		if ( $file_path && strpos( $file_path, sys_get_temp_dir() ) === 0 ) {
			WP_CLI::success( sprintf( 'Downloaded PDF to %s (%.2f MB)', $file_path, filesize( $file_path ) / 1024 / 1024 ) );
		}
		return $file_path;
	}

	/**
	 * Clean up a temporary file created during a remote download.
	 *
	 * @param string $file_path File path to delete.
	 */
	private function cleanup_temp_file( string $file_path ): void {
		$this->service->cleanup_temp_file( $file_path );
		WP_CLI::line( 'Cleaned up temporary file.' );
	}

	/**
	 * Test OCR extraction with a local PDF file
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : Path to local PDF file
	 *
	 * [--provider=<provider>]
	 * : Force a specific OCR provider (gemini, document-ai, google-vision, azure-vision, aws-textract)
	 *
	 * [--form-parser]
	 * : Use Form Parser processor for better table extraction
	 *
	 * [--show-markdown]
	 * : Show markdown output instead of plain text (useful for Gemini)
	 *
	 * [--model=<model>]
	 * : Override the Gemini model name (e.g. gemini-3-flash-preview). Only applies to Gemini provider.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc-pdf-extraction test-file --file=/path/to/test.pdf
	 *     wp prc-pdf-extraction test-file --file=/path/to/test.pdf --provider=gemini --show-markdown
	 *     wp prc-pdf-extraction test-file --file=/path/to/test.pdf --provider=gemini --model=gemini-3-flash-preview
	 *     wp prc-pdf-extraction test-file --file=/path/to/test.pdf --provider=google-vision
	 *     wp prc-pdf-extraction test-file --file=/path/to/test.pdf --form-parser
	 *
	 * @when after_wp_load
	 */
	public function test_file( $args, $assoc_args ) {
		$file_path       = $assoc_args['file'] ?? null;
		$provider_name   = $assoc_args['provider'] ?? null;
		$use_form_parser = isset( $assoc_args['form-parser'] );
		$show_markdown   = isset( $assoc_args['show-markdown'] );
		$model_override  = $assoc_args['model'] ?? null;

		if ( $model_override ) {
			WP_CLI::line( "Overriding Gemini model to: {$model_override}" );
			add_filter(
				'prc_pdf_extraction_gemini_model',
				function () use ( $model_override ) {
					return $model_override;
				}
			);
		}

		if ( $use_form_parser ) {
			$this->enable_form_parser();
		}

		if ( ! $file_path ) {
			WP_CLI::error( 'Please provide a file path using --file=/path/to/file.pdf' );
		}

		if ( ! file_exists( $file_path ) ) {
			WP_CLI::error( "File not found: {$file_path}" );
		}

		WP_CLI::line( "Testing OCR extraction with: {$file_path}" );

		// Verify system dependencies before extraction.
		$this->check_system_dependencies();

		$cost = $this->orchestrator->estimate_cost( $file_path );
		WP_CLI::line( sprintf( 'Estimated cost: $%.4f USD', $cost ) );

		WP_CLI::line( 'Extracting text from PDF...' );

		$start_time = microtime( true );
		if ( $provider_name ) {
			WP_CLI::line( "Using provider: {$provider_name}" );
			$response = $this->extract_with_provider( $file_path, $provider_name );
		} else {
			$response = $this->service->extract_text( $file_path );
		}
		$duration_seconds = microtime( true ) - $start_time;

		if ( is_wp_error( $response ) ) {
			$error_msg  = 'Extraction failed: ' . $response->get_error_message();
			$error_data = $response->get_error_data();
			if ( ! empty( $error_data ) ) {
				$error_msg .= "\n" . print_r( $error_data, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
			}
			WP_CLI::error( $error_msg );
		}

		WP_CLI::success(
			sprintf(
				'Extracted %d characters with %.2f%% confidence using %s (%.2fs)',
				$response->get_character_count(),
				$response->get_confidence() * 100,
				$response->get_provider(),
				$duration_seconds
			)
		);

		if ( $show_markdown ) {
			$display_text = $response->get_markdown();
			$display_type = 'markdown';
		} else {
			$display_text = $response->get_text();
			$display_type = 'plain text';
		}

		WP_CLI::line( '' );
		WP_CLI::line( "First 2000 characters of {$display_type}:" );
		WP_CLI::line( '---' );
		WP_CLI::line( substr( $display_text, 0, 2000 ) );
		WP_CLI::line( '---' );
		WP_CLI::line( '' );
		WP_CLI::line( sprintf( 'Duration: %.2fs | Processing cost: $%.4f', $duration_seconds, $response->get_cost() ) );
	}

	/**
	 * Save extraction to database.
	 *
	 * Delegates to Extraction_Service and translates WP_Error into WP_CLI::error().
	 *
	 * @param int          $parent_id   Parent post ID.
	 * @param array        $topline     Topline material data.
	 * @param OCR_Response $response    OCR response from extract_text().
	 * @param int|null     $existing_id Existing extraction post ID to update.
	 * @return int Extraction post ID.
	 */
	private function save_extraction( int $parent_id, array $topline, $response, ?int $existing_id = null, ?float $duration_seconds = null ): int {
		$result = $this->service->save_extraction( $parent_id, $topline, $response, $existing_id, $duration_seconds );

		if ( is_wp_error( $result ) ) {
			$action = $existing_id ? 'update' : 'create';
			WP_CLI::error( "Failed to {$action} extraction post: " . $result->get_error_message() );
		}

		return $result;
	}
}
