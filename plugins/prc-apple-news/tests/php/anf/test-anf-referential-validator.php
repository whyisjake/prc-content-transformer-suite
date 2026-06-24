<?php
/**
 * Unit tests for ANF_Referential_Validator.
 *
 * @package PRC\Platform\Apple_News\ANF
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\ANF\Tests;

use PRC\Platform\Apple_News\ANF\ANF_Referential_Validator;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * @covers \PRC\Platform\Apple_News\ANF\ANF_Referential_Validator
 */
class Test_ANF_Referential_Validator extends TestCase {

	private ANF_Referential_Validator $validator;

	private string $fixtures_dir;

	public function setUp(): void {
		parent::setUp();
		$this->validator    = new ANF_Referential_Validator();
		$this->fixtures_dir = dirname( __DIR__ ) . '/fixtures';
	}

	private function fixture( string $name ): string {
		return (string) file_get_contents( $this->fixtures_dir . '/' . $name );
	}

	public function test_valid_fixture_passes(): void {
		$result = $this->validator->validate_json( $this->fixture( 'anf-valid.json' ) );
		$this->assertTrue( $result );
	}

	public function test_missing_text_style_returns_wp_error(): void {
		$document = json_decode( $this->fixture( 'anf-valid.json' ) );
		$document->components[1]->textStyle = 'missing-style';

		$result = $this->validator->validate( $document );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'anf_referential_error', $result->get_error_code() );
		$this->assertStringContainsString( 'missing-style', $result->get_error_message() );

		$errors = $result->get_error_data( 'anf_referential_error' )['errors'] ?? $result->get_error_data()['errors'] ?? array();
		$this->assertNotEmpty( $errors );
		$this->assertSame( 'textStyle', $errors[0]['type'] );
		$this->assertSame( 'missing-style', $errors[0]['id'] );
	}

	public function test_missing_layout_in_nested_component_returns_wp_error(): void {
		$document = (object) array(
			'componentLayouts'    => (object) array(),
			'componentTextStyles' => (object) array( 'default-body' => (object) array() ),
			'componentStyles'     => (object) array(),
			'components'          => array(
				(object) array(
					'role'       => 'container',
					'layout'     => 'missing-layout',
					'components' => array(
						(object) array(
							'role'      => 'body',
							'textStyle' => 'default-body',
						),
					),
				),
			),
		);

		$result = $this->validator->validate( $document );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'missing-layout', $result->get_error_message() );
	}

	public function test_inline_text_style_object_is_allowed(): void {
		$document = (object) array(
			'componentLayouts'    => (object) array(),
			'componentTextStyles' => (object) array(),
			'componentStyles'     => (object) array(),
			'components'          => array(
				(object) array(
					'role'      => 'intro',
					'textStyle' => (object) array(
						'fontName' => 'Georgia-Italic',
						'fontSize' => 24,
					),
				),
			),
		);

		$result = $this->validator->validate( $document );
		$this->assertTrue( $result );
	}
}
