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
	 * Test successful response creation
	 */
	public function test_create_successful_response() {
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
		$response = new OCR_Response( false );

		$this->assertFalse( $response->is_success() );
		$this->assertEquals( '', $response->get_text() );
		$this->assertEquals( 0.0, $response->get_confidence() );
	}

	/**
	 * Test character count calculation
	 */
	public function test_character_count() {
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

		// 'This is a test with 30 chars' is 28 characters; the label is a
		// description, not a count.
		$this->assertEquals( 28, $response->get_character_count() );
	}

	/**
	 * Test empty text response
	 */
	public function test_empty_text_response() {
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
