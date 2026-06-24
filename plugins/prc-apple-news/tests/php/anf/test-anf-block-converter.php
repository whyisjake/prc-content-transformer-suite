<?php
/**
 * Unit tests for the deterministic ANF block converter pipeline.
 *
 * @package PRC\Platform\Apple_News\ANF
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\ANF\Tests;

use PRC\Platform\Apple_News\ANF\ANF_Block_Converter;
use PRC\Platform\Apple_News\ANF\ANF_Block_Integration;
use PRC\Platform\Apple_News\ANF\ANF_Block_Registry;
use PRC\Platform\Apple_News\ANF\ANF_Post_Processor;
use PRC\Platform\Apple_News\ANF\ANF_Referential_Validator;
use PRC\Platform\Apple_News\ANF\ANF_Validator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PRC\Platform\Apple_News\ANF\ANF_Block_Converter
 * @covers \PRC\Platform\Apple_News\ANF\ANF_Block_Integration
 * @covers \PRC\Platform\Apple_News\ANF\ANF_Block_Registry
 */
class Test_ANF_Block_Converter extends TestCase {

	private ANF_Block_Integration $integration;
	private ANF_Block_Converter $converter;
	/** @var \WP_Post */
	private $post;

	public function setUp(): void {
		parent::setUp();

		\WP_Test_Meta_Store::reset();
		\WP_Test_Filter_Registry::reset();
		\WP_Test_Post_Store::reset();
		ANF_Block_Registry::reset();

		$this->integration = new ANF_Block_Integration( new class() {
			public function add_action( ...$args ): void {}
		} );
		$this->integration->register_block_callbacks();

		$this->post = new \WP_Post(
			array(
				'ID'           => 42,
				'post_title'   => 'Converter Test Article',
				'post_content' => '[]',
			)
		);
		\WP_Test_Post_Store::set( 42, $this->post );

		$this->converter = new ANF_Block_Converter();
	}

	public function tearDown(): void {
		ANF_Block_Registry::reset();
		\WP_Test_Filter_Registry::reset();
		\WP_Test_Post_Store::reset();
		parent::tearDown();
	}

	public function test_registry_register_and_get(): void {
		ANF_Block_Registry::reset();
		$this->assertFalse( ANF_Block_Registry::has( 'test/block' ) );

		ANF_Block_Registry::register(
			'test/block',
			static fn() => array( array( 'role' => 'body', 'text' => 'ok' ) )
		);

		$this->assertTrue( ANF_Block_Registry::has( 'test/block' ) );
		$this->assertContains( 'test/block', ANF_Block_Registry::all_block_names() );
	}

	public function test_paragraph_emits_body_component(): void {
		$components = $this->integration->paragraph(
			array(
				'blockName' => 'core/paragraph',
				'innerHTML' => '<p>Hello world.</p>',
			),
			$this->post
		);

		$this->assertCount( 1, $components );
		$this->assertSame( 'body', $components[0]['role'] );
		$this->assertSame( 'default-body', $components[0]['textStyle'] );
		$this->assertStringContainsString( 'Hello world.', $components[0]['text'] );
	}

	public function test_paragraph_bignumber_uses_bignumber_style(): void {
		$components = $this->integration->paragraph(
			array(
				'blockName' => 'core/paragraph',
				'innerHTML' => '<p class="bignumber">72%</p>',
			),
			$this->post
		);

		$this->assertSame( 'body-bignumber', $components[0]['textStyle'] );
	}

	public function test_heading_emits_heading_role(): void {
		$components = $this->integration->heading(
			array(
				'blockName' => 'core/heading',
				'attrs'     => array( 'level' => 3 ),
				'innerHTML' => 'Section title',
			),
			$this->post
		);

		$this->assertSame( 'heading3', $components[0]['role'] );
		$this->assertSame( 'default-heading-3', $components[0]['textStyle'] );
	}

	public function test_list_without_inner_blocks_uses_rendered_html(): void {
		$components = $this->integration->list_block(
			array(
				'blockName' => 'core/list',
				'innerHTML' => '<ul><li>First</li><li>Second</li></ul>',
			),
			$this->post
		);

		$this->assertCount( 1, $components );
		$this->assertSame( '<ul><li>First</li><li>Second</li></ul>', $components[0]['text'] );
	}

	public function test_list_item_strips_duplicate_li_wrapper(): void {
		$components = $this->integration->list_block(
			array(
				'blockName'   => 'core/list',
				'innerBlocks' => array(
					array(
						'blockName' => 'core/list-item',
						'innerHTML' => '<li>Already wrapped</li>',
					),
				),
			),
			$this->post
		);

		$this->assertCount( 1, $components );
		$this->assertSame( '<ul><li>Already wrapped</li></ul>', $components[0]['text'] );
	}

	public function test_body_component_does_not_wrap_lists_in_paragraph(): void {
		$component = $this->integration->body_component( '<ul><li>Item</li></ul>' );

		$this->assertSame( '<ul><li>Item</li></ul>', $component['text'] );
	}

	public function test_body_component_does_not_wrap_tables_in_paragraph(): void {
		$component = $this->integration->body_component( '<table><tr><td>Cell</td></tr></table>' );

		$this->assertSame( '<table><tr><td>Cell</td></tr></table>', $component['text'] );
	}

	public function test_image_emits_photo_component(): void {
		$components = $this->integration->image(
			array(
				'blockName' => 'core/image',
				'attrs'     => array(
					'id'  => 99,
					'alt' => 'Chart image',
				),
			),
			$this->post
		);

		$this->assertCount( 1, $components );
		$this->assertSame( 'photo', $components[0]['role'] );
		$this->assertSame( 'https://cdn.example.com/image-99.jpg', $components[0]['URL'] );
	}

	public function test_converter_recurses_through_group_container(): void {
		$blocks = array(
			array(
				'blockName'   => 'core/group',
				'innerBlocks' => array(
					array(
						'blockName' => 'core/paragraph',
						'innerHTML' => '<p>Inside group.</p>',
					),
				),
			),
		);

		$components = $this->converter->blocks_to_components( $blocks, $this->post );

		$this->assertCount( 1, $components );
		$this->assertSame( 'body', $components[0]['role'] );
		$this->assertStringContainsString( 'Inside group.', $components[0]['text'] );
	}

	public function test_converter_uses_fallback_filter_for_unhandled_blocks(): void {
		add_filter(
			'prc_apple_news_fallback_components',
			static function( $components, $markdown ) {
				return array(
					array(
						'role' => 'body',
						'text' => '<p>Fallback: ' . esc_html( $markdown ) . '</p>',
						'format' => 'html',
						'textStyle' => 'default-body',
						'layout' => 'body-layout',
					),
				);
			},
			10,
			2
		);

		$blocks = array(
			array(
				'blockName' => 'prc-block/unknown',
				'innerHTML' => '<div>Custom block</div>',
			),
		);

		$components = $this->converter->blocks_to_components( $blocks, $this->post );

		$this->assertCount( 1, $components );
		$this->assertStringContainsString( 'Fallback:', $components[0]['text'] );
	}

	public function test_build_produces_valid_skeleton_and_passes_post_processor(): void {
		$this->post->post_content = wp_json_encode(
			array(
				array(
					'blockName' => 'core/paragraph',
					'innerHTML' => '<p>Opening paragraph.</p>',
				),
				array(
					'blockName' => 'core/heading',
					'attrs'     => array( 'level' => 2 ),
					'innerHTML' => 'Findings',
				),
			)
		) ?: '[]';
		\WP_Test_Post_Store::set( 42, $this->post );

		$json    = $this->converter->build( 42 );
		$decoded = json_decode( $json, true );

		$this->assertSame( '1.11', $decoded['version'] );
		$this->assertSame( 'Converter Test Article', $decoded['title'] );
		$this->assertSame( 15, $decoded['layout']['columns'] );
		$this->assertGreaterThanOrEqual( 2, count( $decoded['components'] ) );

		$processed = ( new ANF_Post_Processor() )->process( $json, 42 );
		$validation = ( new ANF_Validator() )->validate_json( $processed );
		$this->assertFalse( is_wp_error( $validation ), is_wp_error( $validation ) ? $validation->get_error_message() : '' );
	}

	/**
	 * Kitchen-sink block matrix: every deterministic handler + post-processor pass referential validation.
	 */
	public function test_kitchen_sink_pipeline_passes_referential_validation(): void {
		$this->post->post_content = wp_json_encode(
			array(
				array(
					'blockName' => 'core/paragraph',
					'innerHTML' => '<p>Opening paragraph.</p>',
				),
				array(
					'blockName' => 'core/heading',
					'attrs'     => array( 'level' => 2 ),
					'innerHTML' => 'Heading 2',
				),
				array(
					'blockName' => 'core/heading',
					'attrs'     => array( 'level' => 3 ),
					'innerHTML' => 'Heading 3',
				),
				array(
					'blockName' => 'core/heading',
					'attrs'     => array( 'level' => 4 ),
					'innerHTML' => 'Heading 4',
				),
				array(
					'blockName' => 'core/heading',
					'attrs'     => array( 'level' => 5 ),
					'innerHTML' => 'Heading 5',
				),
				array(
					'blockName' => 'core/heading',
					'attrs'     => array( 'level' => 6 ),
					'innerHTML' => 'Heading 6',
				),
				array(
					'blockName'   => 'core/list',
					'attrs'       => array( 'ordered' => true ),
					'innerBlocks' => array(
						array(
							'blockName' => 'core/list-item',
							'innerHTML' => 'First item',
						),
					),
				),
				array(
					'blockName' => 'core/quote',
					'innerHTML' => '<blockquote><p>Quoted text.</p></blockquote>',
				),
				array(
					'blockName' => 'core/image',
					'attrs'     => array(
						'id'  => 101,
						'alt' => 'Kitchen sink image',
					),
				),
				array(
					'blockName' => 'core/separator',
					'innerHTML' => '<hr class="wp-block-separator"/>',
				),
				array(
					'blockName' => 'core/table',
					'innerHTML' => '<table><tbody><tr><td>Cell</td></tr></tbody></table>',
				),
			)
		) ?: '[]';
		\WP_Test_Post_Store::set( 42, $this->post );

		$json       = $this->converter->build( 42 );
		$processed  = ( new ANF_Post_Processor() )->process( $json, 42 );
		$schema     = ( new ANF_Validator() )->validate_json( $processed );
		$references = ( new ANF_Referential_Validator() )->validate_json( $processed );

		$this->assertFalse( is_wp_error( $schema ), is_wp_error( $schema ) ? $schema->get_error_message() : '' );
		$this->assertTrue( $references, is_wp_error( $references ) ? $references->get_error_message() : '' );
	}
}
