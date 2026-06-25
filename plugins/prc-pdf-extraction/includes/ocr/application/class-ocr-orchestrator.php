<?php
/**
 * OCR Orchestrator
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Application;

use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Request;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Response;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\OCR_Exception;
use PRC\Platform\PDF_Extraction\OCR\Providers\OCR_Provider_Interface;

/**
 * Orchestrates OCR operations across multiple providers
 */
class OCR_Orchestrator {
	/**
	 * Registered OCR providers
	 *
	 * @var OCR_Provider_Interface[]
	 */
	private $providers = array();

	/**
	 * Quality validator
	 *
	 * @var Quality_Validator
	 */
	private $validator;

	/**
	 * Constructor
	 *
	 * @param Quality_Validator|null $validator Optional quality validator.
	 */
	public function __construct( ?Quality_Validator $validator = null ) {
		$this->validator = $validator ?? new Quality_Validator();
		$this->register_providers();
	}

	/**
	 * Register all available OCR providers
	 */
	private function register_providers(): void {
		$providers = array();

		// Register Claude provider if configured (primary: native PDF document understanding, priority 4)
		if ( defined( 'PRC_PLATFORM_ANTHROPIC_API_KEY' ) && PRC_PLATFORM_ANTHROPIC_API_KEY ) {
			require_once __DIR__ . '/../providers/class-claude-provider.php';
			$providers[] = new \PRC\Platform\PDF_Extraction\OCR\Providers\Claude_Provider();
		}

		// Register Gemini provider if configured (fallback: best PDF understanding + markdown output, priority 5)
		if ( defined( 'PRC_PLATFORM_GOOGLE_API_KEY' ) && PRC_PLATFORM_GOOGLE_API_KEY ) {
			require_once __DIR__ . '/../providers/class-gemini-provider.php';
			$providers[] = new \PRC\Platform\PDF_Extraction\OCR\Providers\Gemini_Provider();
		}

		// Register WP AI provider if configured
		if ( function_exists( 'wp_ai_client_prompt' ) ) {
			require_once __DIR__ . '/../providers/class-wp-ai-provider.php';
			$providers[] = new \PRC\Platform\PDF_Extraction\OCR\Providers\WP_AI_Provider();
		}

		// Sort by priority (lower number = higher priority)
		usort(
			$providers,
			function ( OCR_Provider_Interface $a, OCR_Provider_Interface $b ) {
				return $a->get_priority() <=> $b->get_priority();
			}
		);

		$this->providers = $providers;
	}

	/**
	 * Extract text from a PDF using available providers
	 *
	 * @param OCR_Request $request OCR request.
	 * @return OCR_Response|\WP_Error Response object or WP_Error if all providers fail.
	 */
	public function extract_text( OCR_Request $request ) {
		if ( empty( $this->providers ) ) {
			return new \WP_Error(
				'no_providers',
				'No OCR providers are configured. Please configure at least one provider in vip-config/keys-and-tokens.php'
			);
		}

		$errors = array();

		foreach ( $this->providers as $provider ) {
			// Skip unavailable providers
			if ( ! $provider->is_available() ) {
				$errors[ $provider->get_name() ] = 'Provider is not available';
				continue;
			}

			try {
				// Attempt extraction
				$response = $provider->extract_text( $request );

				// Validate quality
				$validation = $this->validator->validate( $response );

				if ( $validation['valid'] ) {
					// Success! Return the response
					return $response;
				}

				// Validation failed, try next provider
				$errors[ $provider->get_name() ] = 'Validation failed: ' . implode( '; ', $validation['issues'] );

			} catch ( OCR_Exception $e ) {
				// Log the error and try next provider
				$errors[ $provider->get_name() ] = $e->getMessage();

				// Some errors should be logged for debugging
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log(
						sprintf(
							'[PRC PDF Extraction] Provider %s failed: %s',
							$provider->get_name(),
							$e->getMessage()
						)
					);
				}
			}
		}

		// All providers failed
		return new \WP_Error(
			'all_providers_failed',
			'All OCR providers failed to extract text',
			array( 'errors' => $errors )
		);
	}

	/**
	 * Estimate cost for extracting text from a file
	 *
	 * @param string $file_path Path to the file.
	 * @return float Estimated cost in USD (using cheapest available provider).
	 */
	public function estimate_cost( string $file_path ): float {
		if ( empty( $this->providers ) ) {
			return 0.0;
		}

		$costs = array();

		foreach ( $this->providers as $provider ) {
			if ( $provider->is_available() ) {
				$costs[] = $provider->estimate_cost( $file_path );
			}
		}

		// Return cheapest option
		return empty( $costs ) ? 0.0 : min( $costs );
	}

	/**
	 * Get all registered providers
	 *
	 * @return OCR_Provider_Interface[]
	 */
	public function get_providers(): array {
		return $this->providers;
	}

	/**
	 * Get available providers (those that pass is_available check)
	 *
	 * @return OCR_Provider_Interface[]
	 */
	public function get_available_providers(): array {
		return array_filter(
			$this->providers,
			function ( OCR_Provider_Interface $provider ) {
				return $provider->is_available();
			}
		);
	}
}
