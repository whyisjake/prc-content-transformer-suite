<?php
/**
 * Class OCR_Orchestrator_Test
 *
 * @package PRC_PDF_Extraction
 */

use PRC\Platform\PDF_Extraction\OCR\Application\OCR_Orchestrator;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Request;

/**
 * OCR Orchestrator integration tests
 */
class OCR_Orchestrator_Test extends WP_UnitTestCase {

	/**
	 * Extraction fails cleanly when it cannot succeed.
	 *
	 * Which error comes back depends on the environment. The documented dev
	 * setup installs the WordPress AI client, which registers a provider
	 * unconditionally, so 'no_providers' is unreachable there. Asserting one
	 * or the other keeps this meaningful in both, rather than encoding an
	 * assumption about which plugins happen to be active.
	 */
	public function test_orchestrator_fails_without_a_working_provider() {
		$orchestrator = new OCR_Orchestrator();
		$request      = new OCR_Request( '/path/to/file.pdf' );

		$result = $orchestrator->extract_text( $request );

		$this->assertWPError( $result );

		$expected = empty( $orchestrator->get_providers() )
			? 'no_providers'
			: 'all_providers_failed';

		$this->assertEquals( $expected, $result->get_error_code() );
	}

	/**
	 * Only configured providers are registered.
	 *
	 * With no Anthropic or Google credentials present, neither of those
	 * providers may appear. The WordPress AI provider registers on the
	 * presence of its client rather than a key, so it is allowed.
	 */
	public function test_only_configured_providers_are_registered() {
		$orchestrator = new OCR_Orchestrator();
		$providers    = $orchestrator->get_providers();

		$this->assertIsArray( $providers );

		$names = array_map(
			static function ( $provider ) {
				return $provider->get_name();
			},
			$providers
		);

		if ( ! defined( 'PRC_PLATFORM_ANTHROPIC_API_KEY' ) || ! PRC_PLATFORM_ANTHROPIC_API_KEY ) {
			if ( ! get_option( 'ais_anthropic_api_key' ) && ! get_option( 'connectors_ai_anthropic_api_key' ) ) {
				$this->assertNotContains( 'claude', $names );
			}
		}

		if ( ! defined( 'PRC_PLATFORM_GOOGLE_API_KEY' ) || ! PRC_PLATFORM_GOOGLE_API_KEY ) {
			$this->assertNotContains( 'gemini', $names );
		}
	}

	/**
	 * Test orchestrator returns empty available providers list when unconfigured
	 */
	public function test_get_available_providers_when_unconfigured() {
		$orchestrator = new OCR_Orchestrator();
		$providers    = $orchestrator->get_available_providers();

		$this->assertIsArray( $providers );
		$this->assertEmpty( $providers );
	}

	/**
	 * Test cost estimation with no providers
	 */
	public function test_estimate_cost_no_providers() {
		$orchestrator = new OCR_Orchestrator();
		$cost         = $orchestrator->estimate_cost( '/path/to/file.pdf' );

		$this->assertEquals( 0.0, $cost );
	}

	/**
	 * Test orchestrator initializes successfully
	 */
	public function test_orchestrator_initializes() {
		$orchestrator = new OCR_Orchestrator();

		$this->assertInstanceOf( OCR_Orchestrator::class, $orchestrator );
	}
}
