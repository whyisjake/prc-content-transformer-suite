<?php
/**
 * Class PluginTest
 *
 * @package PRC_PDF_Extraction
 */

use PRC\Platform\PDF_Extraction\Content_Type;

/**
 * Plugin activation and basic tests
 */
class PluginTest extends WP_UnitTestCase {
	/**
	 * Test that the plugin is loaded
	 */
	public function test_plugin_loaded() {
		$this->assertTrue( defined( 'PRC_PDF_EXTRACTION_VERSION' ) );
		$this->assertEquals( '1.0.0', PRC_PDF_EXTRACTION_VERSION );
	}

	/**
	 * Test that constants are defined
	 */
	public function test_constants_defined() {
		$this->assertTrue( defined( 'PRC_PDF_EXTRACTION_FILE' ) );
		$this->assertTrue( defined( 'PRC_PDF_EXTRACTION_DIR' ) );
		$this->assertTrue( defined( 'PRC_PDF_EXTRACTION_VERSION' ) );
	}

	/**
	 * Test that required classes exist
	 */
	public function test_classes_exist() {
		$this->assertTrue( class_exists( 'PRC\Platform\PDF_Extraction\Bootstrap' ) );
		$this->assertTrue( class_exists( 'PRC\Platform\PDF_Extraction\Loader' ) );
		$this->assertTrue( class_exists( 'PRC\Platform\PDF_Extraction\Content_Type' ) );
	}
}
