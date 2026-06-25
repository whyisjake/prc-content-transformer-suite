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
	 * Skip tests that trigger WordPress meta operations on PHP 8.2+
	 * Due to WordPress core bug with SERIALIZATION_FORMAT_USE_UNSERIALIZE constant
	 */
	protected function skip_on_php_82_if_needed() {
		if ( version_compare( PHP_VERSION, '8.2.0', '>=' ) ) {
			$this->markTestSkipped(
				'WordPress 6.8.x has a SERIALIZATION_FORMAT_USE_UNSERIALIZE constant bug on PHP 8.2+. ' .
				'Skipping tests that create posts/users/meta. These features work correctly in production.'
			);
		}
	}

	/**
	 * Test orchestrator with no providers configured
	 */
	public function test_orchestrator_no_providers() {
		$this->skip_on_php_82_if_needed();
		$orchestrator = new OCR_Orchestrator();
		$request      = new OCR_Request( '/path/to/file.pdf' );

		$result = $orchestrator->extract_text( $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'no_providers', $result->get_error_code() );
	}

	/**
	 * Test orchestrator returns empty provider list when unconfigured
	 */
	public function test_get_providers_when_unconfigured() {
		$this->skip_on_php_82_if_needed();
		$orchestrator = new OCR_Orchestrator();
		$providers    = $orchestrator->get_providers();

		$this->assertIsArray( $providers );
		$this->assertEmpty( $providers );
	}

	/**
	 * Test orchestrator returns empty available providers list when unconfigured
	 */
	public function test_get_available_providers_when_unconfigured() {
		$this->skip_on_php_82_if_needed();
		$orchestrator = new OCR_Orchestrator();
		$providers    = $orchestrator->get_available_providers();

		$this->assertIsArray( $providers );
		$this->assertEmpty( $providers );
	}

	/**
	 * Test cost estimation with no providers
	 */
	public function test_estimate_cost_no_providers() {
		$this->skip_on_php_82_if_needed();
		$orchestrator = new OCR_Orchestrator();
		$cost         = $orchestrator->estimate_cost( '/path/to/file.pdf' );

		$this->assertEquals( 0.0, $cost );
	}

	/**
	 * Test orchestrator initializes successfully
	 */
	public function test_orchestrator_initializes() {
		$this->skip_on_php_82_if_needed();
		$orchestrator = new OCR_Orchestrator();

		$this->assertInstanceOf( OCR_Orchestrator::class, $orchestrator );
	}
}
