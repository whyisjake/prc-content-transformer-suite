<?php
/**
 * Class Claude_Provider_Test
 *
 * @package PRC_PDF_Extraction
 */

use PRC\Platform\PDF_Extraction\OCR\Providers\Claude_Provider;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Request;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Authentication_Exception;
use PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\Extraction_Failed_Exception;

/**
 * Claude Provider unit tests
 */
class Claude_Provider_Test extends WP_UnitTestCase {

	/**
	 * Load the provider under test.
	 *
	 * OCR_Orchestrator requires provider classes lazily, and only when that
	 * provider is configured, so a test constructing one directly cannot rely
	 * on it having been loaded.
	 */
	public function set_up() {
		parent::set_up();

		require_once dirname( __DIR__, 2 ) . '/includes/ocr/providers/class-claude-provider.php';
	}

	/**
	 * Test provider name
	 */
	public function test_get_name() {
		$provider = new Claude_Provider();
		$this->assertEquals( 'claude', $provider->get_name() );
	}

	/**
	 * Test provider priority is 4 (primary — runs before Gemini at 5)
	 */
	public function test_get_priority() {
		$provider = new Claude_Provider();
		$this->assertEquals( 4, $provider->get_priority() );
	}

	/**
	 * Test provider is unavailable when PRC_PLATFORM_ANTHROPIC_API_KEY is not defined
	 */
	public function test_is_unavailable_without_key() {
		// PRC_PLATFORM_ANTHROPIC_API_KEY is not defined in test environment
		$provider = new Claude_Provider();
		$this->assertFalse( $provider->is_available() );
	}

	/**
	 * Test cost estimate returns a positive value for a real PDF file
	 */
	public function test_estimate_cost_with_real_file() {
		$provider = new Claude_Provider();
		$test_pdf = dirname( __DIR__ ) . '/PR_2026.01.21_religion-in-latin-america_topline.pdf';

		if ( ! file_exists( $test_pdf ) ) {
			$this->markTestSkipped( 'Test PDF not found: ' . $test_pdf );
		}

		$cost = $provider->estimate_cost( $test_pdf );
		$this->assertGreaterThan( 0.0, $cost );
		$this->assertIsFloat( $cost );
	}

	/**
	 * Test extract_text throws Authentication_Exception when no API key is set
	 */
	public function test_extract_text_throws_without_key() {
		$provider = new Claude_Provider();
		$request  = new OCR_Request( '/path/to/file.pdf' );

		$this->expectException( Authentication_Exception::class );
		$provider->extract_text( $request );
	}

	/**
	 * Test extract_text throws Extraction_Failed_Exception for missing file
	 * (requires a valid API key to get past the availability check)
	 */
	public function test_extract_text_throws_for_missing_file() {

		if ( ! defined( 'PRC_PLATFORM_ANTHROPIC_API_KEY' ) || ! PRC_PLATFORM_ANTHROPIC_API_KEY ) {
			$this->markTestSkipped( 'PRC_PLATFORM_ANTHROPIC_API_KEY not configured — skipping live API tests.' );
		}

		$provider = new Claude_Provider();
		$request  = new OCR_Request( '/nonexistent/path/to/file.pdf' );

		$this->expectException( Extraction_Failed_Exception::class );
		$provider->extract_text( $request );
	}

	/**
	 * Test markdown_to_plain strips markdown syntax correctly
	 */
	public function test_markdown_to_plain() {
		$provider = new Claude_Provider();

		$markdown = "# Section Header\n\n**Bold text** and *italic* text.\n\n> A blockquote note\n\n[Link text](https://example.com)";
		$plain    = $provider->markdown_to_plain( $markdown );

		$this->assertStringNotContainsString( '#', $plain );
		$this->assertStringNotContainsString( '**', $plain );
		$this->assertStringNotContainsString( '*', $plain );
		$this->assertStringNotContainsString( '>', $plain );
		$this->assertStringNotContainsString( '[Link text](', $plain );
		$this->assertStringContainsString( 'Bold text', $plain );
		$this->assertStringContainsString( 'italic', $plain );
		$this->assertStringContainsString( 'Link text', $plain );
	}

	/**
	 * Test default model name
	 */
	public function test_default_model() {
		$provider = new Claude_Provider();
		$this->assertEquals( 'claude-sonnet-4-6', $provider->get_model() );
	}

	/**
	 * Test custom model name override via constructor
	 */
	public function test_custom_model_via_constructor() {
		$provider = new Claude_Provider( 'claude-opus-4-6' );
		$this->assertEquals( 'claude-opus-4-6', $provider->get_model() );
	}
}
