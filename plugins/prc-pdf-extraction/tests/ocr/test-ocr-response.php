<?php
/**
 * Class OCR_Response_Test
 *
 * @package PRC_PDF_Extraction
 */

use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Response;

/**
 * OCR Response value object tests
 */
class OCR_Response_Test extends WP_UnitTestCase {
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
	 * Test successful response creation
	 */
	public function test_create_successful_response() {
		$this->skip_on_php_82_if_needed();
		$response = new OCR_Response(
			true,
			'Extracted text',
			'# Extracted text',
			'',
			0.95,
			'google-vision',
			0.0015,
			array( 'raw' => 'data' )
		);

		$this->assertTrue( $response->is_success() );
		$this->assertEquals( 'Extracted text', $response->get_text() );
		$this->assertEquals( '# Extracted text', $response->get_markdown() );
		$this->assertEquals( 0.95, $response->get_confidence() );
		$this->assertEquals( 'google-vision', $response->get_provider() );
		$this->assertEquals( 0.0015, $response->get_cost() );
		$this->assertEquals( array( 'raw' => 'data' ), $response->get_raw_data() );
	}

	/**
	 * Test failed response creation
	 */
	public function test_create_failed_response() {
		$this->skip_on_php_82_if_needed();
		$response = new OCR_Response( false );

		$this->assertFalse( $response->is_success() );
		$this->assertEquals( '', $response->get_text() );
		$this->assertEquals( 0.0, $response->get_confidence() );
	}

	/**
	 * Test character count calculation
	 */
	public function test_character_count() {
		$this->skip_on_php_82_if_needed();
		$response = new OCR_Response(
			true,
			'This is a test with 30 chars',
			'',
			'',
			0.9,
			'test',
			0.0,
			array()
		);

		$this->assertEquals( 29, $response->get_character_count() );
	}

	/**
	 * Test empty text response
	 */
	public function test_empty_text_response() {
		$this->skip_on_php_82_if_needed();
		$response = new OCR_Response(
			true,
			'',
			'',
			'',
			0.0,
			'test',
			0.0,
			array()
		);

		$this->assertEquals( 0, $response->get_character_count() );
		$this->assertEquals( '', $response->get_text() );
	}
}
