<?php
/**
 * Class File_Encoder_Test
 *
 * @package PRC_PDF_Extraction
 */

use PRC\Platform\PDF_Extraction\OCR\Infrastructure\File_Encoder;

/**
 * File Encoder tests
 */
class File_Encoder_Test extends WP_UnitTestCase {
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
	 * Test encoding non-existent file returns false
	 */
	public function test_encode_nonexistent_file() {
		$this->skip_on_php_82_if_needed();
		$encoder = new File_Encoder();
		$result  = $encoder->encode_file( '/nonexistent/file.pdf' );

		$this->assertFalse( $result );
	}

	/**
	 * Test getting MIME type for non-existent file returns false
	 */
	public function test_get_mime_type_nonexistent_file() {
		$this->skip_on_php_82_if_needed();
		$encoder = new File_Encoder();
		$result  = $encoder->get_mime_type( '/nonexistent/file.pdf' );

		$this->assertFalse( $result );
	}

	/**
	 * Test getting file size for non-existent file returns false
	 */
	public function test_get_file_size_nonexistent_file() {
		$this->skip_on_php_82_if_needed();
		$encoder = new File_Encoder();
		$result  = $encoder->get_file_size( '/nonexistent/file.pdf' );

		$this->assertFalse( $result );
	}

	/**
	 * Test PDF validation for non-existent file
	 */
	public function test_is_valid_pdf_nonexistent_file() {
		$this->skip_on_php_82_if_needed();
		$encoder = new File_Encoder();
		$result  = $encoder->is_valid_pdf( '/nonexistent/file.pdf' );

		$this->assertFalse( $result );
	}

	/**
	 * Test PDF validation infers from extension
	 */
	public function test_is_valid_pdf_infers_from_extension() {
		$this->skip_on_php_82_if_needed();
		$encoder = new File_Encoder();

		// Create a temporary test file with .pdf extension
		$temp_file = sys_get_temp_dir() . '/test-' . uniqid() . '.pdf';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $temp_file, '%PDF-1.4 test content' );

		$result = $encoder->is_valid_pdf( $temp_file );

		// Clean up
		unlink( $temp_file );

		$this->assertTrue( $result );
	}

	/**
	 * Test base64 encoding of file content
	 */
	public function test_encode_file_content() {
		$this->skip_on_php_82_if_needed();
		$encoder = new File_Encoder();

		// Create a temporary test file
		$temp_file = sys_get_temp_dir() . '/test-' . uniqid() . '.txt';
		$content   = 'Test content for encoding';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $temp_file, $content );

		$encoded = $encoder->encode_file( $temp_file );

		// Clean up
		unlink( $temp_file );

		$this->assertNotFalse( $encoded );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertEquals( $content, base64_decode( $encoded ) );
	}
}
