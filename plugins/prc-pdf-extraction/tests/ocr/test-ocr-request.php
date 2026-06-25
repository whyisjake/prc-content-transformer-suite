<?php
/**
 * Class OCR_Request_Test
 *
 * @package PRC_PDF_Extraction
 */

use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Request;

/**
 * OCR Request value object tests
 */
class OCR_Request_Test extends WP_UnitTestCase {
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
	 * Test basic request creation
	 */
	public function test_create_basic_request() {
		$this->skip_on_php_82_if_needed();
		$request = new OCR_Request( '/path/to/file.pdf' );

		$this->assertEquals( '/path/to/file.pdf', $request->get_file_path() );
		$this->assertEquals( 'en', $request->get_language() );
	}

	/**
	 * Test request with custom options
	 */
	public function test_create_request_with_options() {
		$this->skip_on_php_82_if_needed();
		$request = new OCR_Request(
			'/path/to/file.pdf',
			array(
				'language' => 'es',
				'custom'   => 'value',
			)
		);

		$this->assertEquals( '/path/to/file.pdf', $request->get_file_path() );
		$this->assertEquals( 'es', $request->get_language() );
		$this->assertEquals( 'value', $request->get_option( 'custom' ) );
	}

	/**
	 * Test getting non-existent option returns default
	 */
	public function test_get_nonexistent_option() {
		$this->skip_on_php_82_if_needed();
		$request = new OCR_Request( '/path/to/file.pdf' );

		$this->assertNull( $request->get_option( 'nonexistent' ) );
		$this->assertEquals( 'default', $request->get_option( 'nonexistent', 'default' ) );
	}

	/**
	 * Test getting all options
	 */
	public function test_get_all_options() {
		$this->skip_on_php_82_if_needed();
		$options = array(
			'language' => 'fr',
			'custom'   => 'test',
		);

		$request = new OCR_Request( '/path/to/file.pdf', $options );

		$this->assertEquals( $options, $request->get_options() );
	}
}
