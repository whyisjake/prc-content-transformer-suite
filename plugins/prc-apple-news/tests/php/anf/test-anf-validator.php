<?php
/**
 * Unit tests for ANF_Validator.
 *
 * @package PRC\Platform\Apple_News\ANF
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\ANF\Tests;

use PRC\Platform\Apple_News\ANF\ANF_Validator;
use WP_Error;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ANF_Validator schema validation.
 */
class Test_ANF_Validator extends TestCase {

	private ANF_Validator $validator;

	private string $fixtures_dir;

	public function setUp(): void {
		parent::setUp();
		$this->validator    = new ANF_Validator();
		$this->fixtures_dir = dirname( __DIR__ ) . '/fixtures';
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function fixture( string $name ): string {
		return (string) file_get_contents( $this->fixtures_dir . '/' . $name );
	}

	private function fixture_array( string $name ): array {
		return (array) json_decode( $this->fixture( $name ), true );
	}

	// -----------------------------------------------------------------------
	// Happy path
	// -----------------------------------------------------------------------

	public function test_valid_json_string_returns_true(): void {
		$result = $this->validator->validate_json( $this->fixture( 'anf-valid.json' ) );
		$this->assertTrue( $result, 'Expected valid fixture to pass schema validation.' );
	}

	public function test_valid_array_returns_true(): void {
		$result = $this->validator->validate( $this->fixture_array( 'anf-valid.json' ) );
		$this->assertTrue( $result, 'Expected valid array fixture to pass schema validation.' );
	}

	// -----------------------------------------------------------------------
	// Error paths — schema violations
	// -----------------------------------------------------------------------

	public function test_missing_version_returns_wp_error(): void {
		$result = $this->validator->validate_json( $this->fixture( 'anf-invalid-missing-version.json' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'version', $result->get_error_message() );
	}

	public function test_wrong_layout_column_type_returns_wp_error(): void {
		$result = $this->validator->validate_json( $this->fixture( 'anf-invalid-wrong-layout-type.json' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'integer', $result->get_error_message() );
	}

	public function test_wp_error_has_anf_schema_error_code(): void {
		$result = $this->validator->validate_json( $this->fixture( 'anf-invalid-missing-version.json' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'anf_schema_error', $result->get_error_code() );
	}

	public function test_wp_error_data_contains_raw_errors_array(): void {
		$result = $this->validator->validate_json( $this->fixture( 'anf-invalid-missing-version.json' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'errors', $data );
		$this->assertNotEmpty( $data['errors'] );
	}

	// -----------------------------------------------------------------------
	// Malformed JSON
	// -----------------------------------------------------------------------

	public function test_malformed_json_string_returns_wp_error(): void {
		$result = $this->validator->validate_json( '{this is not valid json' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'anf_json_invalid', $result->get_error_code() );
	}

	public function test_empty_json_string_returns_wp_error(): void {
		$result = $this->validator->validate_json( '' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'anf_json_invalid', $result->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// Array variant — validates that validate() and validate_json() agree
	// -----------------------------------------------------------------------

	public function test_invalid_array_returns_wp_error(): void {
		$doc = json_decode( $this->fixture( 'anf-invalid-missing-version.json' ), true );
		$this->assertIsArray( $doc );
		$result = $this->validator->validate( $doc );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_valid_array_and_valid_json_agree(): void {
		$json   = $this->fixture( 'anf-valid.json' );
		$result_json  = $this->validator->validate_json( $json );
		$result_array = $this->validator->validate( (array) json_decode( $json, true ) );

		// Both should succeed.
		$this->assertTrue( $result_json );
		$this->assertTrue( $result_array );
	}
}
