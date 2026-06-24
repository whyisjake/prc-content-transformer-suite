<?php
/**
 * Unit tests for Apple_News_Provider::validate() and validate_with_reason().
 *
 * @package PRC\Platform\Content_Transformer\Providers
 */

declare(strict_types=1);

namespace PRC\Platform\Content_Transformer\Providers\Tests;

use PRC\Platform\Content_Transformer\Providers\Apple_News_Provider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Apple_News_Provider validation methods.
 */
class Test_Apple_News_Provider extends TestCase {

	/**
	 * Minimal valid ANF JSON for use in test fixtures.
	 *
	 * @var string
	 */
	private const VALID_ANF = '{"version":"1.11","identifier":"post-1","language":"en","title":"Test","layout":{"columns":15,"width":1024,"margin":100,"gutter":20},"components":[{"role":"body","text":"Body.","format":"html"}],"componentTextStyles":{},"componentLayouts":{},"metadata":{}}';

	/**
	 * Provider under test.
	 *
	 * @var Apple_News_Provider
	 */
	private Apple_News_Provider $provider;

	public function setUp(): void {
		parent::setUp();
		$this->provider = new Apple_News_Provider();
	}

	// -----------------------------------------------------------------
	// validate_with_reason() — happy path
	// -----------------------------------------------------------------

	/**
	 * @test
	 * Valid ANF returns true.
	 */
	public function test_validate_with_reason_returns_true_for_valid_anf(): void {
		$this->assertTrue( $this->provider->validate_with_reason( self::VALID_ANF ) );
	}

	// -----------------------------------------------------------------
	// validate_with_reason() — error paths
	// -----------------------------------------------------------------

	/**
	 * @test
	 * Non-JSON input returns a string describing the JSON parse error.
	 */
	public function test_validate_with_reason_invalid_json_returns_string(): void {
		$result = $this->provider->validate_with_reason( 'not valid json' );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'not valid JSON', $result );
	}

	/**
	 * @test
	 * Missing required key returns a string naming the missing key.
	 */
	public function test_validate_with_reason_missing_components_key(): void {
		$data = json_decode( self::VALID_ANF, true );
		unset( $data['components'] );
		$result = $this->provider->validate_with_reason( (string) wp_json_encode( $data ) );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'components', $result );
	}

	/**
	 * @test
	 * Missing required 'title' key returns a string naming the missing key.
	 */
	public function test_validate_with_reason_missing_title_key(): void {
		$data = json_decode( self::VALID_ANF, true );
		unset( $data['title'] );
		$result = $this->provider->validate_with_reason( (string) wp_json_encode( $data ) );
		$this->assertIsString( $result );
		$this->assertStringContainsString( 'title', $result );
	}

	/**
	 * @test
	 * A component without a role key returns a string identifying the component index.
	 */
	public function test_validate_with_reason_component_missing_role(): void {
		$data                = json_decode( self::VALID_ANF, true );
		$data['components'][] = array( 'text' => 'No role here.' ); // index 1
		$result              = $this->provider->validate_with_reason( (string) wp_json_encode( $data ) );
		$this->assertIsString( $result );
		$this->assertStringContainsString( '1', $result, 'Error must identify the component index' );
	}

	/**
	 * @test
	 * Empty components array returns a descriptive string.
	 */
	public function test_validate_with_reason_empty_components_returns_string(): void {
		$data               = json_decode( self::VALID_ANF, true );
		$data['components'] = array();
		$result             = $this->provider->validate_with_reason( (string) wp_json_encode( $data ) );
		$this->assertIsString( $result );
	}

	// -----------------------------------------------------------------
	// validate() — interface contract preserved
	// -----------------------------------------------------------------

	/**
	 * @test
	 * validate() returns true for valid ANF (delegation to validate_with_reason() works).
	 */
	public function test_validate_returns_true_for_valid_anf(): void {
		$this->assertTrue( $this->provider->validate( self::VALID_ANF ) );
	}

	/**
	 * @test
	 * validate() returns false for invalid JSON (interface bool contract preserved).
	 */
	public function test_validate_returns_false_for_invalid_json(): void {
		$this->assertFalse( $this->provider->validate( 'not valid json' ) );
	}

	/**
	 * @test
	 * validate() returns false when a required key is missing.
	 */
	public function test_validate_returns_false_when_required_key_missing(): void {
		$data = json_decode( self::VALID_ANF, true );
		unset( $data['layout'] );
		$this->assertFalse( $this->provider->validate( (string) wp_json_encode( $data ) ) );
	}
}
