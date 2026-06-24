<?php
/**
 * Back-compat tests for legacy prc_llms_txt_sections filter.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

declare( strict_types=1 );

use PRC\Platform\Markdown_For_Agents\LLMs_Txt;
use PRC\Platform\Markdown_For_Agents\Settings;

/**
 * Legacy llms.txt filter shim tests.
 */
class Test_LLMs_Txt_Back_Compat extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_cache_delete( LLMs_Txt::CACHE_KEY, LLMs_Txt::CACHE_GROUP );
		delete_option( Settings::OPTION_KEY );
	}

	public function tear_down(): void {
		remove_all_filters( 'prc_llms_txt_sections' );
		remove_all_filters( 'prc_markdown_for_agents_additional_resources_blocks' );
		delete_option( Settings::OPTION_KEY );
		parent::tear_down();
	}

	public function test_legacy_filter_output_appears_in_additional_resources(): void {
		add_filter(
			'prc_llms_txt_sections',
			static function (): string {
				return "## Religious Landscape Study\n\n- [RLS Home](https://example.com/rls/)";
			}
		);

		$body = LLMs_Txt::get_rendered_body();

		$this->assertStringContainsString( '## Additional Resources', $body );
		$this->assertStringContainsString( 'Religious Landscape Study', $body );
		$this->assertStringContainsString( '[RLS Home](https://example.com/rls/)', $body );
	}

	public function test_legacy_filter_strips_disallowed_html(): void {
		add_filter(
			'prc_llms_txt_sections',
			static function (): string {
				return '<script>alert(1)</script>Safe legacy text';
			}
		);

		$body = LLMs_Txt::get_rendered_body();

		$this->assertStringNotContainsString( '<script>', $body );
		$this->assertStringContainsString( 'Safe legacy text', $body );
	}

	public function test_settings_blocks_alone_render_additional_resources(): void {
		update_option(
			Settings::OPTION_KEY,
			array(
				'additional_resources_blocks' => array(
					array(
						'id'    => 'block-one',
						'title' => 'Agent Notes',
						'body'  => 'Helpful context for crawlers.',
					),
				),
			)
		);

		$body = LLMs_Txt::get_rendered_body();

		$this->assertStringContainsString( '## Additional Resources', $body );
		$this->assertStringContainsString( '### Agent Notes', $body );
		$this->assertStringContainsString( 'Helpful context for crawlers.', $body );
	}

	public function test_legacy_and_settings_blocks_merge_in_order(): void {
		add_filter(
			'prc_llms_txt_sections',
			static function (): string {
				return 'Legacy intro text';
			}
		);

		update_option(
			Settings::OPTION_KEY,
			array(
				'additional_resources_blocks' => array(
					array(
						'id'    => 'block-one',
						'title' => 'After Legacy',
						'body'  => 'Settings body',
					),
				),
			)
		);

		$body = LLMs_Txt::get_rendered_body();

		$legacy_pos = strpos( $body, 'Legacy intro text' );
		$title_pos  = strpos( $body, '### After Legacy' );
		$this->assertNotFalse( $legacy_pos );
		$this->assertNotFalse( $title_pos );
		$this->assertLessThan( $title_pos, $legacy_pos );
	}

	public function test_no_legacy_or_blocks_skips_additional_resources(): void {
		$body = LLMs_Txt::get_rendered_body();
		$this->assertStringNotContainsString( '## Additional Resources', $body );
	}

	public function test_additional_resources_blocks_filter_renders_h3_subsection(): void {
		add_filter(
			'prc_markdown_for_agents_additional_resources_blocks',
			static function ( array $blocks ): array {
				$blocks[] = array(
					'id'    => 'rls-markdown-for-agents',
					'title' => 'Religious Landscape Study',
					'body'  => '- [RLS Home](https://example.com/rls/)',
				);

				return $blocks;
			}
		);

		$body = LLMs_Txt::get_rendered_body();

		$this->assertStringContainsString( '## Additional Resources', $body );
		$this->assertStringContainsString( '### Religious Landscape Study', $body );
		$this->assertStringNotContainsString( '## Religious Landscape Study', $body );
		$this->assertStringContainsString( '[RLS Home](https://example.com/rls/)', $body );
	}

	public function test_additional_resources_blocks_filter_skips_when_title_exists(): void {
		update_option(
			Settings::OPTION_KEY,
			array(
				'additional_resources_blocks' => array(
					array(
						'id'    => 'custom-rls',
						'title' => 'Religious Landscape Study',
						'body'  => 'Editor-owned RLS copy.',
					),
				),
			)
		);

		add_filter(
			'prc_markdown_for_agents_additional_resources_blocks',
			static function ( array $blocks ): array {
				foreach ( $blocks as $block ) {
					if ( strcasecmp( trim( (string) ( $block['title'] ?? '' ) ), 'Religious Landscape Study' ) === 0 ) {
						return $blocks;
					}
				}

				$blocks[] = array(
					'id'    => 'rls-markdown-for-agents',
					'title' => 'Religious Landscape Study',
					'body'  => '- [RLS Home](https://example.com/rls/)',
				);

				return $blocks;
			}
		);

		$body = LLMs_Txt::get_rendered_body();

		$this->assertStringContainsString( 'Editor-owned RLS copy.', $body );
		$this->assertStringNotContainsString( '[RLS Home](https://example.com/rls/)', $body );
		$this->assertSame( 1, substr_count( $body, '### Religious Landscape Study' ) );
	}

	public function test_settings_blocks_render_in_stored_order(): void {
		update_option(
			Settings::OPTION_KEY,
			array(
				'additional_resources_blocks' => array(
					array(
						'id'    => 'block-first',
						'title' => 'First Section',
						'body'  => 'First body.',
					),
					array(
						'id'    => 'block-second',
						'title' => 'Second Section',
						'body'  => 'Second body.',
					),
				),
			)
		);

		$body = LLMs_Txt::get_rendered_body();

		$first_pos  = strpos( $body, '### First Section' );
		$second_pos = strpos( $body, '### Second Section' );
		$this->assertNotFalse( $first_pos );
		$this->assertNotFalse( $second_pos );
		$this->assertLessThan( $second_pos, $first_pos );
	}
}
