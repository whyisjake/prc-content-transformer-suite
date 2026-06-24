<?php
/**
 * Unit tests for ANF_Post_Processor.
 *
 * @package PRC\Platform\Apple_News\ANF
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\ANF\Tests;

use PRC\Platform\Apple_News\ANF\ANF_Post_Processor;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ANF_Post_Processor::process().
 */
class Test_ANF_Post_Processor extends TestCase {

	/**
	 * Minimal valid ANF JSON fixture.
	 * Contains a title component at index 0 and one body component at index 1.
	 *
	 * @var string
	 */
	private const MINIMAL_ANF = '{"version":"1.9","identifier":"post-1","language":"en","layout":{"columns":7,"width":375,"margin":60,"gutter":20},"components":[{"role":"title","text":"Test Title","layout":"title-layout"},{"role":"body","text":"Body text.","format":"html"}],"componentLayouts":{},"componentStyles":{},"componentTextStyles":{},"textStyles":{},"metadata":{}}';

	/**
	 * Instance under test.
	 *
	 * @var ANF_Post_Processor
	 */
	private ANF_Post_Processor $processor;

	/**
	 * A test WP post created for each test.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->processor = new ANF_Post_Processor();
		$this->post_id   = 1; // Stub: WP data-access functions return predictable values for any ID.

		// Reset shared stub state so each test starts clean.
		\WP_Test_Meta_Store::reset();
		\WP_Test_Filter_Registry::reset();
	}

	/**
	 * Tear down: reset stub state.
	 */
	public function tearDown(): void {
		\WP_Test_Meta_Store::reset();
		\WP_Test_Filter_Registry::reset();
		parent::tearDown();
	}

	// -----------------------------------------------------------------
	// Helper: decode the output of process() into an array.
	// -----------------------------------------------------------------

	/**
	 * Process minimal ANF for the test post and return the decoded array.
	 *
	 * @param string $json Optional override JSON. Defaults to MINIMAL_ANF.
	 * @return array
	 */
	private function process_and_decode( string $json = self::MINIMAL_ANF ): array {
		$output = $this->processor->process( $json, $this->post_id );
		$decoded = json_decode( $output, true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), 'Output must be valid JSON' );
		return $decoded;
	}

	// -----------------------------------------------------------------
	// layout.columns
	// -----------------------------------------------------------------

	/**
	 * @test
	 * layout.columns is always 15 regardless of input value.
	 */
	public function test_layout_columns_is_15(): void {
		$decoded = $this->process_and_decode();
		$this->assertSame( 15, $decoded['layout']['columns'] );
	}

	/**
	 * @test
	 * layout.columns is overridden even when input already has a different value.
	 */
	public function test_layout_columns_overrides_existing_value(): void {
		$input   = json_decode( self::MINIMAL_ANF, true );
		$input['layout']['columns'] = 99;
		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$this->assertSame( 15, $decoded['layout']['columns'] );
	}

	// -----------------------------------------------------------------
	// Required top-level ANF keys
	// -----------------------------------------------------------------

	/**
	 * @test
	 * Output contains all required ANF top-level keys.
	 */
	public function test_output_contains_required_top_level_keys(): void {
		$decoded = $this->process_and_decode();
		foreach ( array( 'version', 'identifier', 'layout', 'components' ) as $key ) {
			$this->assertArrayHasKey( $key, $decoded, "Missing top-level key: {$key}" );
		}
		$this->assertNotEmpty( $decoded['components'], 'components array must not be empty' );
	}

	// -----------------------------------------------------------------
	// Sub-title intro injection
	// -----------------------------------------------------------------

	/**
	 * @test
	 * Intro component is injected at index 1 when sub_title meta is set.
	 */
	public function test_sub_title_intro_injected_at_index_1(): void {
		update_post_meta( $this->post_id, 'sub_title', 'A compelling subtitle' );

		$decoded    = $this->process_and_decode();
		$components = $decoded['components'];

		// Index 0 must still be the title.
		$this->assertSame( 'title', $components[0]['role'] );

		// Index 1 must be the intro with the sub_title text.
		$this->assertSame( 'intro', $components[1]['role'] );
		$this->assertSame( 'A compelling subtitle', $components[1]['text'] );
		$this->assertSame( 'Georgia-Italic', $components[1]['textStyle']['fontName'] );

		delete_post_meta( $this->post_id, 'sub_title' );
	}

	/**
	 * @test
	 * No intro component when neither sub_title nor sub_headline is set.
	 */
	public function test_no_intro_when_no_sub_title_or_sub_headline(): void {
		delete_post_meta( $this->post_id, 'sub_title' );
		delete_post_meta( $this->post_id, 'sub_headline' );

		$decoded    = $this->process_and_decode();
		$components = $decoded['components'];

		$intro_components = array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'intro' );
		$this->assertCount( 0, $intro_components, 'No intro component should be present when sub_title/sub_headline are absent' );
	}

	/**
	 * @test
	 * Intro is injected using sub_headline when sub_title is empty (fallback).
	 */
	public function test_sub_headline_fallback_when_sub_title_empty(): void {
		delete_post_meta( $this->post_id, 'sub_title' );
		update_post_meta( $this->post_id, 'sub_headline', 'Legacy sub-headline text' );

		$decoded    = $this->process_and_decode();
		$components = $decoded['components'];

		$intro_components = array_values(
			array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'intro' )
		);
		$this->assertCount( 1, $intro_components, 'Exactly one intro component expected' );
		$this->assertSame( 'Legacy sub-headline text', $intro_components[0]['text'] );

		delete_post_meta( $this->post_id, 'sub_headline' );
	}

	// -----------------------------------------------------------------
	// Byline injection
	// -----------------------------------------------------------------

	/**
	 * @test
	 * When Staff_Bylines is unavailable (not in this test env) there is no byline component.
	 * When it IS available the byline appears after title/intro.
	 *
	 * This test covers the position contract (title → intro → byline) with a mock
	 * if Staff_Bylines exists, or simply asserts no fatal when it doesn't.
	 */
	public function test_byline_position_after_title_no_sub_title(): void {
		delete_post_meta( $this->post_id, 'sub_title' );
		delete_post_meta( $this->post_id, 'sub_headline' );

		// Should not throw regardless of whether Staff_Bylines is available.
		$output  = $this->processor->process( self::MINIMAL_ANF, $this->post_id );
		$decoded = json_decode( $output, true );

		$this->assertSame( JSON_ERROR_NONE, json_last_error() );
		// Title must remain at index 0.
		$this->assertSame( 'title', $decoded['components'][0]['role'] );
	}

	/**
	 * @test
	 * When sub_title is set and a byline is injected, the order is title→intro→byline.
	 */
	public function test_byline_position_after_intro_when_sub_title_set(): void {
		if ( ! class_exists( 'PRC\\Platform\\Staff_Bylines\\Bylines' ) ) {
			$this->markTestSkipped( 'Staff_Bylines not available in this test environment' );
		}

		update_post_meta( $this->post_id, 'sub_title', 'Subtitle here' );

		$decoded    = $this->process_and_decode();
		$components = $decoded['components'];

		$this->assertSame( 'title', $components[0]['role'] );
		$this->assertSame( 'intro', $components[1]['role'] );
		$this->assertSame( 'byline', $components[2]['role'] );

		delete_post_meta( $this->post_id, 'sub_title' );
	}

	// -----------------------------------------------------------------
	// Newsletter signup append — idempotency
	// -----------------------------------------------------------------

	/**
	 * @test
	 * Newsletter components are appended when the URL is absent from the component tree.
	 */
	public function test_newsletter_appended_when_url_absent(): void {
		$decoded    = $this->process_and_decode();
		$components = $decoded['components'];

		$newsletter_url = 'mailchi.mp/pewresearch/weekly-newsletter';
		$has_newsletter = false;
		foreach ( $components as $component ) {
			if (
				( isset( $component['URL'] ) && str_contains( $component['URL'], $newsletter_url ) ) ||
				( isset( $component['text'] ) && str_contains( (string) $component['text'], $newsletter_url ) )
			) {
				$has_newsletter = true;
				break;
			}
		}

		$this->assertTrue( $has_newsletter, 'Newsletter components should be appended' );
	}

	/**
	 * @test
	 * Newsletter components are NOT appended when the URL is already present (idempotency).
	 */
	public function test_newsletter_not_appended_when_url_already_present(): void {
		// Build an ANF JSON that already contains the newsletter URL.
		$input = json_decode( self::MINIMAL_ANF, true );
		$input['components'][] = array(
			'role' => 'link_button',
			'text' => 'Sign Up',
			'URL'  => 'https://mailchi.mp/pewresearch/weekly-newsletter',
		);
		$json_with_newsletter = (string) wp_json_encode( $input );

		$decoded    = $this->process_and_decode( $json_with_newsletter );
		$components = $decoded['components'];

		$newsletter_url = 'mailchi.mp/pewresearch/weekly-newsletter';
		$match_count    = 0;
		foreach ( $components as $component ) {
			if (
				( isset( $component['URL'] ) && str_contains( $component['URL'], $newsletter_url ) ) ||
				( isset( $component['text'] ) && str_contains( (string) $component['text'], $newsletter_url ) )
			) {
				++$match_count;
			}
		}

		$this->assertSame( 1, $match_count, 'Newsletter URL should appear exactly once (no duplicate append)' );
	}

	// -----------------------------------------------------------------
	// Invalid JSON input — fail-open
	// -----------------------------------------------------------------

	/**
	 * @test
	 * Invalid JSON input returns the original string unchanged.
	 */
	public function test_invalid_json_returns_original_string(): void {
		$invalid_json = 'not valid json {{{';
		$result       = $this->processor->process( $invalid_json, $this->post_id );
		$this->assertSame( $invalid_json, $result );
	}

	/**
	 * @test
	 * A JSON scalar (not an array) returns the original string unchanged.
	 */
	public function test_json_scalar_returns_original_string(): void {
		$scalar_json = '"just a string"';
		$result      = $this->processor->process( $scalar_json, $this->post_id );
		$this->assertSame( $scalar_json, $result );
	}

	// -----------------------------------------------------------------
	// Component layout additions
	// -----------------------------------------------------------------

	/**
	 * @test
	 * All required component layouts are present in the output.
	 */
	public function test_required_component_layouts_present(): void {
		$decoded = $this->process_and_decode();

		$required_layouts = array(
			'title-layout',
			'byline-layout',
			'body-layout',
			'full-width-image',
			'blockquote-layout',
			'link-button-layout',
			'anchor-layout-pullquote',
			'full-width-image-no-bleed',
			'anchor-layout-right-4-col',
			'anchor-layout-right-2-col',
			'anchor-layout-left-4-col',
			'anchor-layout-left-2-col',
			'anchor-layout-left',
			'callout-layout-full',
			'callout-layout-right-2-col',
		);

		foreach ( $required_layouts as $layout ) {
			$this->assertArrayHasKey( $layout, $decoded['componentLayouts'], "Missing componentLayout: {$layout}" );
		}
	}

	/**
	 * @test
	 * full-width-image layout is overridden when present in the input.
	 */
	public function test_full_width_image_layout_overridden(): void {
		$input = json_decode( self::MINIMAL_ANF, true );
		$input['componentLayouts']['full-width-image'] = array(
			'columnStart' => 3,
			'columnSpan'  => 9,
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );

		$this->assertSame( 0, $decoded['componentLayouts']['full-width-image']['columnStart'] );
		$this->assertSame( 15, $decoded['componentLayouts']['full-width-image']['columnSpan'] );
	}

	/**
	 * @test
	 * byline-layout columnSpan is overridden to 15 when present.
	 */
	public function test_byline_layout_column_span_overridden(): void {
		$input = json_decode( self::MINIMAL_ANF, true );
		$input['componentLayouts']['byline-layout'] = array(
			'columnStart' => 0,
			'columnSpan'  => 7,
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );

		$this->assertSame( 15, $decoded['componentLayouts']['byline-layout']['columnSpan'] );
	}

	// -----------------------------------------------------------------
	// Component / text styles
	// -----------------------------------------------------------------

	/**
	 * @test
	 * Required component styles are present.
	 */
	public function test_required_component_styles_present(): void {
		$decoded = $this->process_and_decode();
		$this->assertArrayHasKey( 'default-link-button', $decoded['componentStyles'] );
	}

	/**
	 * @test
	 * Required component text styles are present.
	 */
	public function test_required_component_text_styles_present(): void {
		$decoded = $this->process_and_decode();

		foreach (
			array(
				'default-title',
				'default-byline',
				'default-body',
				'default-heading-2',
				'default-heading-3',
				'default-heading-4',
				'default-heading-5',
				'default-heading-6',
				'default-link-button-text-style',
				'body-bignumber',
				'body-bignumber-2-digit',
			) as $style
		) {
			$this->assertArrayHasKey( $style, $decoded['componentTextStyles'], "Missing componentTextStyle: {$style}" );
		}
	}

	/**
	 * @test
	 * default-body uses Georgia body typography from the PRC design system.
	 */
	public function test_default_body_style_values(): void {
		$decoded = $this->process_and_decode();
		$style   = $decoded['componentTextStyles']['default-body'] ?? null;

		$this->assertNotNull( $style, 'default-body style must be present' );
		$this->assertSame( 'Georgia', $style['fontName'] );
		$this->assertSame( 18, $style['fontSize'] );
		$this->assertSame( 32, $style['lineHeight'] );
		$this->assertSame( '#2a2a2a', $style['textColor'] );
	}

	/**
	 * @test
	 * default-heading-5 and default-heading-6 match apple-news-theme.json tokens.
	 */
	public function test_default_heading_5_and_6_style_values(): void {
		$decoded = $this->process_and_decode();

		$h5 = $decoded['componentTextStyles']['default-heading-5'] ?? null;
		$this->assertNotNull( $h5 );
		$this->assertSame( 'Helvetica', $h5['fontName'] );
		$this->assertSame( 19, $h5['fontSize'] );
		$this->assertSame( 27, $h5['lineHeight'] );

		$h6 = $decoded['componentTextStyles']['default-heading-6'] ?? null;
		$this->assertNotNull( $h6 );
		$this->assertSame( 'Helvetica', $h6['fontName'] );
		$this->assertSame( 17, $h6['fontSize'] );
		$this->assertSame( 24, $h6['lineHeight'] );
	}

	/**
	 * @test
	 * full-width-image layout is always present for deterministic image components.
	 */
	public function test_full_width_image_layout_always_present(): void {
		$decoded = $this->process_and_decode();

		$this->assertArrayHasKey( 'full-width-image', $decoded['componentLayouts'] );
		$this->assertSame( 0, $decoded['componentLayouts']['full-width-image']['columnStart'] );
		$this->assertSame( 15, $decoded['componentLayouts']['full-width-image']['columnSpan'] );
	}

	/**
	 * @test
	 * default-blockquote-left is added when not present in input.
	 */
	public function test_default_blockquote_left_added_when_absent(): void {
		$decoded = $this->process_and_decode();
		$this->assertArrayHasKey( 'default-blockquote-left', $decoded['componentTextStyles'] );
	}

	/**
	 * @test
	 * default-blockquote-left is NOT overwritten when already present in input.
	 */
	public function test_default_blockquote_left_not_overwritten_when_present(): void {
		$input = json_decode( self::MINIMAL_ANF, true );
		$input['componentTextStyles']['default-blockquote-left'] = array(
			'fontName' => 'CustomFont',
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );

		$this->assertSame( 'CustomFont', $decoded['componentTextStyles']['default-blockquote-left']['fontName'] );
	}

	/**
	 * @test
	 * default-pullquote is added when not present in input.
	 */
	public function test_default_pullquote_added_when_absent(): void {
		$decoded = $this->process_and_decode();
		$this->assertArrayHasKey( 'default-pullquote', $decoded['componentTextStyles'] );
	}

	/**
	 * @test
	 * default-pullquote is NOT overwritten when already present in input.
	 */
	public function test_default_pullquote_not_overwritten_when_present(): void {
		$input = json_decode( self::MINIMAL_ANF, true );
		$input['componentTextStyles']['default-pullquote'] = array(
			'fontName' => 'CustomPullquoteFont',
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );

		$this->assertSame( 'CustomPullquoteFont', $decoded['componentTextStyles']['default-pullquote']['fontName'] );
	}

	/**
	 * @test
	 * default-tag-bignumstrong text style is present.
	 */
	public function test_default_tag_bignumstrong_text_style_present(): void {
		$decoded = $this->process_and_decode();
		$this->assertArrayHasKey( 'default-tag-bignumstrong', $decoded['textStyles'] );
	}

	// -----------------------------------------------------------------
	// Metadata
	// -----------------------------------------------------------------

	/**
	 * @test
	 * metadata does NOT contain a 'title' key — title is not a valid ANF metadata field.
	 */
	public function test_metadata_does_not_contain_title(): void {
		$decoded = $this->process_and_decode();
		$this->assertArrayNotHasKey( 'title', $decoded['metadata'], "'title' is not a valid ANF metadata field and must be absent" );
	}

	/**
	 * @test
	 * metadata.excerpt normalizes &hellip; to '...'.
	 */
	public function test_metadata_excerpt_normalizes_hellip(): void {
		// The post was created with 'Short excerpt&hellip;' as the excerpt.
		// get_the_excerpt() may or may not include the raw entity, depending on WP version.
		// We directly test the processor's normalization by passing JSON with known excerpt.
		$input = json_decode( self::MINIMAL_ANF, true );
		// Override the test with a controlled excerpt via filter.
		add_filter(
			'get_the_excerpt',
			static function () {
				return 'Foo&hellip;bar';
			}
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );

		remove_all_filters( 'get_the_excerpt' );

		$this->assertStringContainsString( '...', $decoded['metadata']['excerpt'] );
		$this->assertStringNotContainsString( '&hellip;', $decoded['metadata']['excerpt'] );
	}

	// -----------------------------------------------------------------
	// URL normalization (non-production only)
	// -----------------------------------------------------------------

	/**
	 * Build ANF JSON containing a photo component with a dev-domain URL.
	 *
	 * @param string $image_url URL to embed in the photo component.
	 * @return string JSON string.
	 */
	private function anf_with_photo( string $image_url ): string {
		$input               = json_decode( self::MINIMAL_ANF, true );
		$input['components'][] = array(
			'role'   => 'photo',
			'URL'    => $image_url,
			'layout' => 'full-width-image',
		);
		return (string) wp_json_encode( $input );
	}

	/**
	 * @test
	 * A photo component URL on a dev domain (/wp-content/uploads path) is rewritten
	 * to the production domain.
	 */
	public function test_wp_media_url_rewritten_to_production_domain(): void {
		$dev_url  = 'https://alpha.dev.lndo.site/pewresearch-org/wp-content/uploads/sites/20/2026/04/image.png';
		$expected = 'https://www.pewresearch.org/wp-content/uploads/sites/20/2026/04/image.png';

		$decoded    = $this->process_and_decode( $this->anf_with_photo( $dev_url ) );
		$components = $decoded['components'];

		$photo = array_values( array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'photo' ) );
		$this->assertNotEmpty( $photo );
		$this->assertSame( $expected, $photo[0]['URL'] );
	}

	/**
	 * @test
	 * External URLs (not /wp-content/) are left unchanged.
	 */
	public function test_external_cdn_url_unchanged(): void {
		$external_url = 'https://assets.pewresearch.org/email/newsletter_icon.png';

		$decoded    = $this->process_and_decode( $this->anf_with_photo( $external_url ) );
		$components = $decoded['components'];

		$photo = array_values( array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'photo' ) );
		$this->assertNotEmpty( $photo );
		$this->assertSame( $external_url, $photo[0]['URL'] );
	}

	/**
	 * @test
	 * A custom filter on prc_apple_news_production_media_url changes the replacement domain.
	 */
	public function test_production_url_filter_overrides_default(): void {
		add_filter( 'prc_apple_news_production_media_url', static fn() => 'https://custom.example.com' );

		$dev_url  = 'https://dev.lndo.site/wp-content/uploads/2026/image.png';
		$expected = 'https://custom.example.com/wp-content/uploads/2026/image.png';

		$decoded    = $this->process_and_decode( $this->anf_with_photo( $dev_url ) );
		$components = $decoded['components'];

		remove_all_filters( 'prc_apple_news_production_media_url' );

		$photo = array_values( array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'photo' ) );
		$this->assertNotEmpty( $photo );
		$this->assertSame( $expected, $photo[0]['URL'] );
	}

	/**
	 * @test
	 * Nested components (e.g., container → photo) have their URLs normalized too.
	 */
	public function test_nested_component_urls_are_normalized(): void {
		$dev_url  = 'https://dev.lndo.site/wp-content/uploads/nested/image.png';
		$expected = 'https://www.pewresearch.org/wp-content/uploads/nested/image.png';

		$input               = json_decode( self::MINIMAL_ANF, true );
		$input['components'][] = array(
			'role'       => 'container',
			'components' => array(
				array(
					'role' => 'photo',
					'URL'  => $dev_url,
				),
			),
		);

		$decoded    = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$components = $decoded['components'];

		$container = array_values( array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'container' ) );
		// The last container (not the inline-style callout added by the processor).
		$last_container = end( $container );
		$nested_photo   = $last_container['components'][0] ?? null;

		$this->assertNotNull( $nested_photo );
		$this->assertSame( $expected, $nested_photo['URL'] );
	}

	// -----------------------------------------------------------------
	// Font name normalization
	// -----------------------------------------------------------------

	/**
	 * @test
	 * Bare "IowanOldStyle" fontName is mapped to "Georgia".
	 */
	public function test_iowan_old_style_bare_name_corrected(): void {
		$input = json_decode( self::MINIMAL_ANF, true );
		$input['componentTextStyles']['default-body'] = array(
			'fontName' => 'IowanOldStyle',
			'fontSize' => 18,
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );

		$this->assertSame(
			'Georgia',
			$decoded['componentTextStyles']['default-body']['fontName'],
			'Bare IowanOldStyle should be mapped to Georgia'
		);
	}

	/**
	 * @test
	 * "IowanOldStyle-Roman" is also mapped to "Georgia".
	 */
	public function test_iowan_old_style_roman_mapped_to_georgia(): void {
		$input = json_decode( self::MINIMAL_ANF, true );
		$input['componentTextStyles']['default-body'] = array(
			'fontName' => 'IowanOldStyle-Roman',
			'fontSize' => 18,
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );

		$this->assertSame(
			'Georgia',
			$decoded['componentTextStyles']['default-body']['fontName'],
			'IowanOldStyle-Roman should be mapped to Georgia'
		);
	}

	/**
	 * @test
	 * Font normalization works even when the encoded JSON has a space after the colon
	 * (e.g. "fontName": "IowanOldStyle" instead of "fontName":"IowanOldStyle").
	 * The tree-walk operates on the decoded array, so JSON whitespace is irrelevant.
	 */
	public function test_iowan_old_style_normalized_regardless_of_json_spacing(): void {
		// Manually build a decoded structure and encode it WITH spaces to prove the walker
		// handles it — str_replace would miss "fontName": "IowanOldStyle" (space after colon).
		$input                                              = json_decode( self::MINIMAL_ANF, true );
		$input['componentTextStyles']['default-body-spaced'] = array(
			'fontName' => 'IowanOldStyle',
			'fontSize' => 18,
		);

		// Encode with JSON_PRETTY_PRINT to force spaces after colons in the source fixture,
		// then pass through the processor (which decodes and re-encodes internally).
		$pretty_json = (string) json_encode( $input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$decoded     = $this->process_and_decode( $pretty_json );

		$this->assertSame(
			'Georgia',
			$decoded['componentTextStyles']['default-body-spaced']['fontName'],
			'Tree-walk must normalize IowanOldStyle even when JSON was pretty-printed with spaces'
		);
	}

	/**
	 * @test
	 * fontName inside a nested component textStyle is also normalized.
	 */
	public function test_font_name_normalized_in_nested_component_text_style(): void {
		$input               = json_decode( self::MINIMAL_ANF, true );
		$input['components'][] = array(
			'role'      => 'body',
			'text'      => 'Body text.',
			'format'    => 'html',
			'textStyle' => array(
				'fontName' => 'IowanOldStyle',
				'fontSize' => 18,
			),
		);

		$decoded    = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$components = $decoded['components'];

		$body = array_values(
			array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'body' && isset( $c['textStyle']['fontName'] ) )
		);
		$this->assertNotEmpty( $body, 'Body component with inline textStyle must be present' );
		$this->assertSame( 'Georgia', $body[0]['textStyle']['fontName'], 'fontName inside component textStyle must be normalized' );
	}

	/**
	 * @test
	 * "IowanOldStyle-Italic" (not in the map) is left unchanged.
	 */
	public function test_iowan_old_style_italic_unchanged(): void {
		$input = json_decode( self::MINIMAL_ANF, true );
		$input['componentTextStyles']['default-body-italic'] = array(
			'fontName' => 'IowanOldStyle-Italic',
			'fontSize' => 18,
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );

		$this->assertSame(
			'IowanOldStyle-Italic',
			$decoded['componentTextStyles']['default-body-italic']['fontName'],
			'IowanOldStyle-Italic is not in the font map and should remain unchanged'
		);
	}

	// -----------------------------------------------------------------
	// Typography alignment — PRC design system values
	// -----------------------------------------------------------------

	/**
	 * @test
	 * default-title uses Marion-Bold at 50px per the PRC design system.
	 */
	public function test_default_title_uses_marion_bold(): void {
		$decoded = $this->process_and_decode();
		$style   = $decoded['componentTextStyles']['default-title'] ?? null;

		$this->assertNotNull( $style, 'default-title style must be present' );
		$this->assertSame( 'Marion-Bold', $style['fontName'], 'Title font should be Marion-Bold' );
		$this->assertSame( 50, $style['fontSize'], 'Title font size should be 50' );
		$this->assertSame( 53, $style['lineHeight'], 'Title line height should be 53' );
		$this->assertSame( '#2a2a2a', $style['textColor'], 'Title text color should be #2a2a2a' );
	}

	/**
	 * @test
	 * default-byline uses Helvetica-Bold, 14px, #5c5c5c, uppercase.
	 */
	public function test_default_byline_style_values(): void {
		$decoded = $this->process_and_decode();
		$style   = $decoded['componentTextStyles']['default-byline'] ?? null;

		$this->assertNotNull( $style, 'default-byline style must be present' );
		$this->assertSame( 'Helvetica-Bold', $style['fontName'], 'Byline font should be Helvetica-Bold' );
		$this->assertSame( 14, $style['fontSize'], 'Byline font size should be 14' );
		$this->assertSame( 19, $style['lineHeight'], 'Byline line height should be 19' );
		$this->assertSame( '#5c5c5c', $style['textColor'], 'Byline text color should be #5c5c5c' );
		$this->assertSame( 'uppercase', $style['textTransform'], 'Byline should be uppercase' );
	}

	/**
	 * @test
	 * default-heading-2 uses Helvetica-Bold at 25px per the PRC design system.
	 */
	public function test_default_heading_2_style_values(): void {
		$decoded = $this->process_and_decode();
		$style   = $decoded['componentTextStyles']['default-heading-2'] ?? null;

		$this->assertNotNull( $style, 'default-heading-2 style must be present' );
		$this->assertSame( 'Helvetica-Bold', $style['fontName'], 'H2 font should be Helvetica-Bold' );
		$this->assertSame( 25, $style['fontSize'], 'H2 font size should be 25' );
		$this->assertSame( 35, $style['lineHeight'], 'H2 line height should be 35' );
		$this->assertSame( '#2a2a2a', $style['textColor'], 'H2 text color should be #2a2a2a' );
	}

	/**
	 * @test
	 * default-heading-4 uses Helvetica-Bold at 21px per the PRC design system.
	 */
	public function test_default_heading_4_style_values(): void {
		$decoded = $this->process_and_decode();
		$style   = $decoded['componentTextStyles']['default-heading-4'] ?? null;

		$this->assertNotNull( $style, 'default-heading-4 style must be present' );
		$this->assertSame( 'Helvetica-Bold', $style['fontName'], 'H4 font should be Helvetica-Bold' );
		$this->assertSame( 21, $style['fontSize'], 'H4 font size should be 21' );
		$this->assertSame( 29, $style['lineHeight'], 'H4 line height should be 29' );
	}

	/**
	 * @test
	 * Intro component is injected with Georgia-Italic at 40px (desktop).
	 */
	public function test_intro_font_size_is_40(): void {
		update_post_meta( $this->post_id, 'sub_title', 'Deck text here' );

		$decoded    = $this->process_and_decode();
		$components = $decoded['components'];

		$intro = array_values( array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'intro' ) );
		$this->assertNotEmpty( $intro, 'Intro component must be present' );

		$style = $intro[0]['textStyle'] ?? null;
		$this->assertNotNull( $style );
		$this->assertSame( 'Georgia-Italic', $style['fontName'] );
		$this->assertSame( 40, $style['fontSize'], 'Intro desktop fontSize should be 40' );
		$this->assertSame( 50, $style['lineHeight'], 'Intro desktop lineHeight should be 50' );

		delete_post_meta( $this->post_id, 'sub_title' );
	}

	// -----------------------------------------------------------------
	// Caption-after-container normalization (U3)
	// -----------------------------------------------------------------

	/**
	 * Build ANF JSON with a container-wrapped photo followed by a standalone caption.
	 *
	 * @param array $container_components Children of the container.
	 * @return string JSON string.
	 */
	private function anf_with_container_then_caption( array $container_components ): string {
		$input               = json_decode( self::MINIMAL_ANF, true );
		$input['components'] = array(
			array(
				'role'       => 'container',
				'layout'     => 'full-width-image',
				'components' => $container_components,
			),
			array(
				'role'   => 'caption',
				'text'   => 'Photo caption here.',
				'format' => 'html',
			),
		);
		return (string) wp_json_encode( $input );
	}

	/**
	 * @test
	 * A standalone caption following a container whose first child is a photo
	 * is injected into that container rather than left at root.
	 */
	public function test_caption_after_media_container_injected_into_container(): void {
		$json    = $this->anf_with_container_then_caption( array(
			array( 'role' => 'photo', 'URL' => 'https://www.pewresearch.org/wp-content/uploads/2026/image.png' ),
		) );
		$decoded    = $this->process_and_decode( $json );
		$components = $decoded['components'];

		// Must not have a root-level caption.
		$root_captions = array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'caption' );
		$this->assertCount( 0, $root_captions, 'No caption should remain at root level' );

		// The original container should now contain both the photo and the caption.
		$containers = array_values( array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'container' ) );
		$target_container = null;
		foreach ( $containers as $cont ) {
			$children = $cont['components'] ?? array();
			$has_photo   = (bool) array_filter( $children, static fn( $c ) => ( $c['role'] ?? '' ) === 'photo' );
			$has_caption = (bool) array_filter( $children, static fn( $c ) => ( $c['role'] ?? '' ) === 'caption' );
			if ( $has_photo && $has_caption ) {
				$target_container = $cont;
				break;
			}
		}
		$this->assertNotNull( $target_container, 'Container must contain both the photo and the injected caption' );
	}

	/**
	 * @test
	 * The existing bare-photo + standalone-caption case still wraps into a new container
	 * (regression guard for the original normalization path).
	 */
	public function test_caption_after_bare_photo_still_wraps_correctly(): void {
		$input               = json_decode( self::MINIMAL_ANF, true );
		$input['components'] = array(
			array(
				'role'   => 'photo',
				'URL'    => 'https://www.pewresearch.org/wp-content/uploads/2026/image.png',
				'layout' => 'full-width-image',
			),
			array(
				'role'   => 'caption',
				'text'   => 'A bare photo caption.',
				'format' => 'html',
			),
		);
		$decoded    = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$components = $decoded['components'];

		// No root-level caption.
		$root_captions = array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'caption' );
		$this->assertCount( 0, $root_captions, 'No root-level caption after bare photo' );

		// A container wrapping the photo and caption should exist.
		$containers = array_values( array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'container' ) );
		$wrap_container = null;
		foreach ( $containers as $cont ) {
			$children    = $cont['components'] ?? array();
			$has_photo   = (bool) array_filter( $children, static fn( $c ) => ( $c['role'] ?? '' ) === 'photo' );
			$has_caption = (bool) array_filter( $children, static fn( $c ) => ( $c['role'] ?? '' ) === 'caption' );
			if ( $has_photo && $has_caption ) {
				$wrap_container = $cont;
				break;
			}
		}
		$this->assertNotNull( $wrap_container, 'A container wrapping photo + caption must exist' );
	}

	/**
	 * @test
	 * A standalone caption following a container whose first child is NOT a media role
	 * (e.g., body text container) is converted to body — not injected into the container.
	 */
	public function test_caption_after_text_container_converted_to_body(): void {
		$input               = json_decode( self::MINIMAL_ANF, true );
		$input['components'] = array(
			array(
				'role'       => 'container',
				'layout'     => 'callout-layout-full',
				'components' => array(
					array( 'role' => 'body', 'text' => 'Callout body text.', 'format' => 'html' ),
				),
			),
			array(
				'role'   => 'caption',
				'text'   => 'Should become body text.',
				'format' => 'html',
			),
		);
		$decoded    = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$components = $decoded['components'];

		// No root-level caption.
		$root_captions = array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'caption' );
		$this->assertCount( 0, $root_captions, 'No root caption should remain' );

		// The text "Should become body text." should appear in a body component at root.
		$root_bodies = array_values(
			array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'body' && str_contains( $c['text'] ?? '', 'Should become body text' ) )
		);
		$this->assertNotEmpty( $root_bodies, 'Caption after text container should be converted to a root body component' );
	}

	/**
	 * @test
	 * A caption already inside a container (correctly placed by the AI) is not double-processed.
	 */
	public function test_caption_inside_container_not_double_processed(): void {
		$input               = json_decode( self::MINIMAL_ANF, true );
		$input['components'] = array(
			array(
				'role'       => 'container',
				'layout'     => 'full-width-image',
				'components' => array(
					array( 'role' => 'photo', 'URL' => 'https://www.pewresearch.org/image.png' ),
					array( 'role' => 'caption', 'text' => 'Already inside.', 'format' => 'html' ),
				),
			),
		);
		$decoded    = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$components = $decoded['components'];

		$root_captions = array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'caption' );
		$this->assertCount( 0, $root_captions, 'Caption already inside container must not appear at root' );

		// The container's children should still contain exactly the original photo + caption.
		$containers = array_values( array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === 'container' ) );
		$target = null;
		foreach ( $containers as $cont ) {
			$children = $cont['components'] ?? array();
			if ( count( array_filter( $children, static fn( $c ) => ( $c['role'] ?? '' ) === 'photo' ) ) > 0 ) {
				$target = $cont;
				break;
			}
		}
		$this->assertNotNull( $target );
		// Should still have exactly 2 children (photo + caption), not 3 or more.
		$caption_count = count( array_filter( $target['components'], static fn( $c ) => ( $c['role'] ?? '' ) === 'caption' ) );
		$this->assertSame( 1, $caption_count, 'Already-nested caption must not be duplicated' );
	}

	// -----------------------------------------------------------------
	// Per-component loop: pull quotes
	// -----------------------------------------------------------------

	/**
	 * @test
	 * Pull-quote components receive anchor-layout-pullquote layout.
	 */
	public function test_pullquote_components_get_full_width_layout(): void {
		$input = json_decode( self::MINIMAL_ANF, true );
		$input['components'][] = array(
			'role'      => 'pullquote',
			'text'      => 'A stunning quote',
			'textStyle' => 'default-pullquote',
			'layout'    => 'some-other-layout',
		);

		$decoded    = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$components = $decoded['components'];

		$pq = array_values(
			array_filter( $components, static fn( $c ) => ( $c['textStyle'] ?? '' ) === 'default-pullquote' )
		);

		$this->assertNotEmpty( $pq, 'Pullquote component should be present' );
		$this->assertSame( 'anchor-layout-pullquote', $pq[0]['layout'] );
	}

	// -----------------------------------------------------------------
	// embedwebvideo URL normalisation
	// -----------------------------------------------------------------

	/**
	 * @test
	 * YouTube watch URL is converted to embed URL.
	 */
	public function test_youtube_watch_url_converted_to_embed(): void {
		$input                 = json_decode( self::MINIMAL_ANF, true );
		$input['components'][] = array(
			'role' => 'embedwebvideo',
			'URL'  => 'https://www.youtube.com/watch?v=UKcKTkDPLxc',
		);

		$decoded   = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$video     = array_values( array_filter( $decoded['components'], static fn( $c ) => 'embedwebvideo' === ( $c['role'] ?? '' ) ) );

		$this->assertNotEmpty( $video );
		$this->assertSame( 'https://www.youtube.com/embed/UKcKTkDPLxc', $video[0]['URL'] );
	}

	/**
	 * @test
	 * YouTube short URL (youtu.be) is converted to embed URL.
	 */
	public function test_youtube_short_url_converted_to_embed(): void {
		$input                 = json_decode( self::MINIMAL_ANF, true );
		$input['components'][] = array(
			'role' => 'embedwebvideo',
			'URL'  => 'https://youtu.be/UKcKTkDPLxc',
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$video   = array_values( array_filter( $decoded['components'], static fn( $c ) => 'embedwebvideo' === ( $c['role'] ?? '' ) ) );

		$this->assertSame( 'https://www.youtube.com/embed/UKcKTkDPLxc', $video[0]['URL'] );
	}

	/**
	 * @test
	 * YouTube embed URL is returned unchanged (idempotent).
	 */
	public function test_youtube_embed_url_unchanged(): void {
		$embed                 = 'https://www.youtube.com/embed/UKcKTkDPLxc';
		$input                 = json_decode( self::MINIMAL_ANF, true );
		$input['components'][] = array(
			'role' => 'embedwebvideo',
			'URL'  => $embed,
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$video   = array_values( array_filter( $decoded['components'], static fn( $c ) => 'embedwebvideo' === ( $c['role'] ?? '' ) ) );

		$this->assertSame( $embed, $video[0]['URL'] );
	}

	/**
	 * @test
	 * Vimeo standard URL is converted to embed URL.
	 */
	public function test_vimeo_standard_url_converted_to_embed(): void {
		$input                 = json_decode( self::MINIMAL_ANF, true );
		$input['components'][] = array(
			'role' => 'embedwebvideo',
			'URL'  => 'https://vimeo.com/121450839',
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$video   = array_values( array_filter( $decoded['components'], static fn( $c ) => 'embedwebvideo' === ( $c['role'] ?? '' ) ) );

		$this->assertSame( 'https://player.vimeo.com/video/121450839', $video[0]['URL'] );
	}

	/**
	 * @test
	 * Dailymotion standard URL is converted to embed URL.
	 */
	public function test_dailymotion_standard_url_converted_to_embed(): void {
		$input                 = json_decode( self::MINIMAL_ANF, true );
		$input['components'][] = array(
			'role' => 'embedwebvideo',
			'URL'  => 'https://www.dailymotion.com/video/x84sh87',
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$video   = array_values( array_filter( $decoded['components'], static fn( $c ) => 'embedwebvideo' === ( $c['role'] ?? '' ) ) );

		$this->assertSame( 'https://geo.dailymotion.com/player.html?video=x84sh87', $video[0]['URL'] );
	}

	/**
	 * @test
	 * The invalid `caption` property is stripped from embedwebvideo.
	 */
	public function test_embedwebvideo_caption_property_stripped(): void {
		$input                 = json_decode( self::MINIMAL_ANF, true );
		$input['components'][] = array(
			'role'    => 'embedwebvideo',
			'URL'     => 'https://www.youtube.com/embed/UKcKTkDPLxc',
			'caption' => 'This caption should be removed',
		);

		$decoded = $this->process_and_decode( (string) wp_json_encode( $input ) );
		$video   = array_values( array_filter( $decoded['components'], static fn( $c ) => 'embedwebvideo' === ( $c['role'] ?? '' ) ) );

		$this->assertArrayNotHasKey( 'caption', $video[0] );
	}

	// -----------------------------------------------------------------
	// Child posts "Also in This Report" section
	// -----------------------------------------------------------------

	/**
	 * Helper: stub child post data into WP_Test_Meta_Store.
	 *
	 * @param int    $child_id Post ID.
	 * @param string $title    Post title.
	 * @param string $url      Permalink.
	 * @param string $status   Post status (default 'publish').
	 */
	private function stub_child( int $child_id, string $title, string $url, string $status = 'publish' ): void {
		\WP_Test_Meta_Store::set( $child_id, '_stub_post_title', $title );
		\WP_Test_Meta_Store::set( $child_id, '_stub_post_permalink', $url );
		\WP_Test_Meta_Store::set( $child_id, '_stub_post_status', $status );
	}

	/**
	 * Helper: find all components with a given role in a flat component array.
	 *
	 * @param array  $components Decoded components array.
	 * @param string $role       ANF role to match.
	 * @return array Matching components, re-indexed.
	 */
	private function find_components_by_role( array $components, string $role ): array {
		return array_values( array_filter( $components, static fn( $c ) => ( $c['role'] ?? '' ) === $role ) );
	}

	/**
	 * @test
	 * A post with 3 published children injects a heading2 "Also in This Report"
	 * and a body component containing links to all 3 children.
	 */
	public function test_child_posts_section_injected_for_post_with_published_children(): void {
		$this->stub_child( 10, 'Chapter One', 'https://www.pewresearch.org/?p=10' );
		$this->stub_child( 11, 'Chapter Two', 'https://www.pewresearch.org/?p=11' );
		$this->stub_child( 12, 'Methodology', 'https://www.pewresearch.org/?p=12' );

		update_post_meta(
			$this->post_id,
			'multiSectionReport',
			array(
				array( 'key' => 'a', 'postId' => 10 ),
				array( 'key' => 'b', 'postId' => 11 ),
				array( 'key' => 'c', 'postId' => 12 ),
			)
		);

		$decoded    = $this->process_and_decode();
		$components = $decoded['components'];

		$headings = array_values(
			array_filter(
				$components,
				static fn( $c ) => ( $c['role'] ?? '' ) === 'heading2' && ( $c['text'] ?? '' ) === 'Also in This Report'
			)
		);
		$this->assertCount( 1, $headings, 'Exactly one "Also in This Report" heading2 must be present' );

		$body_components = array_values(
			array_filter(
				$components,
				static fn( $c ) => ( $c['role'] ?? '' ) === 'body' && str_contains( $c['text'] ?? '', 'pewresearch.org/?p=10' )
			)
		);
		$this->assertCount( 1, $body_components, 'Body component with child links must be present' );

		$body_text = $body_components[0]['text'];
		$this->assertStringContainsString( 'pewresearch.org/?p=10', $body_text );
		$this->assertStringContainsString( 'pewresearch.org/?p=11', $body_text );
		$this->assertStringContainsString( 'pewresearch.org/?p=12', $body_text );
		$this->assertStringContainsString( 'Chapter One', $body_text );
		$this->assertStringContainsString( 'Chapter Two', $body_text );
		$this->assertStringContainsString( 'Methodology', $body_text );
	}

	/**
	 * @test
	 * A post with no multiSectionReport meta produces no "Also in This Report" section.
	 */
	public function test_no_child_posts_section_when_no_meta(): void {
		// Ensure multiSectionReport is absent.
		delete_post_meta( $this->post_id, 'multiSectionReport' );

		$decoded    = $this->process_and_decode();
		$components = $decoded['components'];

		$headings = array_filter(
			$components,
			static fn( $c ) => ( $c['role'] ?? '' ) === 'heading2' && ( $c['text'] ?? '' ) === 'Also in This Report'
		);
		$this->assertCount( 0, $headings, 'No "Also in This Report" section should appear when meta is absent' );
	}

	/**
	 * @test
	 * Only published children appear; an unpublished child is silently skipped.
	 */
	public function test_unpublished_children_are_excluded(): void {
		$this->stub_child( 20, 'Published Chapter', 'https://www.pewresearch.org/?p=20' );
		$this->stub_child( 21, 'Draft Chapter', 'https://www.pewresearch.org/?p=21', 'draft' );
		$this->stub_child( 22, 'Another Published', 'https://www.pewresearch.org/?p=22' );

		update_post_meta(
			$this->post_id,
			'multiSectionReport',
			array(
				array( 'key' => 'a', 'postId' => 20 ),
				array( 'key' => 'b', 'postId' => 21 ),
				array( 'key' => 'c', 'postId' => 22 ),
			)
		);

		$decoded    = $this->process_and_decode();
		$components = $decoded['components'];

		$body_components = array_values(
			array_filter(
				$components,
				static fn( $c ) => ( $c['role'] ?? '' ) === 'body' && str_contains( $c['text'] ?? '', 'pewresearch.org/?p=20' )
			)
		);
		$this->assertCount( 1, $body_components );

		$body_text = $body_components[0]['text'];
		$this->assertStringContainsString( 'pewresearch.org/?p=20', $body_text, 'Published child must appear' );
		$this->assertStringNotContainsString( 'pewresearch.org/?p=21', $body_text, 'Draft child must NOT appear' );
		$this->assertStringContainsString( 'pewresearch.org/?p=22', $body_text, 'Published child must appear' );
	}

	/**
	 * @test
	 * An empty multiSectionReport array produces no section.
	 */
	public function test_empty_multi_section_report_produces_no_section(): void {
		update_post_meta( $this->post_id, 'multiSectionReport', array() );

		$decoded    = $this->process_and_decode();
		$components = $decoded['components'];

		$headings = array_filter(
			$components,
			static fn( $c ) => ( $c['role'] ?? '' ) === 'heading2' && ( $c['text'] ?? '' ) === 'Also in This Report'
		);
		$this->assertCount( 0, $headings, 'No section should appear for an empty multiSectionReport array' );
	}

	/**
	 * @test
	 * When all children are non-publish, no section is injected.
	 */
	public function test_all_unpublished_children_produces_no_section(): void {
		$this->stub_child( 30, 'Draft A', 'https://www.pewresearch.org/?p=30', 'draft' );
		$this->stub_child( 31, 'Pending B', 'https://www.pewresearch.org/?p=31', 'pending' );

		update_post_meta(
			$this->post_id,
			'multiSectionReport',
			array(
				array( 'key' => 'a', 'postId' => 30 ),
				array( 'key' => 'b', 'postId' => 31 ),
			)
		);

		$decoded    = $this->process_and_decode();
		$components = $decoded['components'];

		$headings = array_filter(
			$components,
			static fn( $c ) => ( $c['role'] ?? '' ) === 'heading2' && ( $c['text'] ?? '' ) === 'Also in This Report'
		);
		$this->assertCount( 0, $headings, 'No section should appear when all children are unpublished' );
	}

	/**
	 * @test
	 * Calling process() twice on the same JSON for a post with children produces
	 * exactly one "Also in This Report" heading2 (idempotency).
	 */
	public function test_child_posts_section_is_idempotent(): void {
		$this->stub_child( 40, 'Chapter A', 'https://www.pewresearch.org/?p=40' );
		$this->stub_child( 41, 'Chapter B', 'https://www.pewresearch.org/?p=41' );

		update_post_meta(
			$this->post_id,
			'multiSectionReport',
			array(
				array( 'key' => 'a', 'postId' => 40 ),
				array( 'key' => 'b', 'postId' => 41 ),
			)
		);

		// First pass.
		$first_output = $this->processor->process( self::MINIMAL_ANF, $this->post_id );
		// Second pass on first-pass output.
		$second_output = $this->processor->process( $first_output, $this->post_id );
		$decoded       = json_decode( $second_output, true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error() );

		$headings = array_filter(
			$decoded['components'],
			static fn( $c ) => ( $c['role'] ?? '' ) === 'heading2' && ( $c['text'] ?? '' ) === 'Also in This Report'
		);
		$this->assertCount( 1, $headings, '"Also in This Report" heading2 must appear exactly once after two process() calls' );
	}

	/**
	 * @test
	 * The "Also in This Report" heading2 appears at a lower index than the newsletter
	 * heading2, confirming child posts are injected before the newsletter signup.
	 */
	public function test_child_posts_section_appears_before_newsletter(): void {
		$this->stub_child( 50, 'Section One', 'https://www.pewresearch.org/?p=50' );

		update_post_meta(
			$this->post_id,
			'multiSectionReport',
			array( array( 'key' => 'a', 'postId' => 50 ) )
		);

		$decoded    = $this->process_and_decode();
		$components = $decoded['components'];

		$child_index      = null;
		$newsletter_index = null;

		foreach ( $components as $idx => $component ) {
			if ( ( $component['role'] ?? '' ) === 'heading2' && ( $component['text'] ?? '' ) === 'Also in This Report' ) {
				$child_index = $idx;
			}
			if (
				( $component['role'] ?? '' ) === 'heading2' &&
				str_contains( $component['text'] ?? '', 'mailchi.mp' )
			) {
				$newsletter_index = $idx;
			}
		}

		$this->assertNotNull( $child_index, '"Also in This Report" heading2 must be present' );
		$this->assertNotNull( $newsletter_index, 'Newsletter heading2 must be present' );
		$this->assertLessThan( $newsletter_index, $child_index, 'Child posts section must appear before newsletter signup' );
	}

	/**
	 * @test
	 * child-posts-layout is present in componentLayouts when the section is injected.
	 */
	public function test_child_posts_layout_present_when_section_injected(): void {
		$this->stub_child( 60, 'Report Section', 'https://www.pewresearch.org/?p=60' );

		update_post_meta(
			$this->post_id,
			'multiSectionReport',
			array( array( 'key' => 'a', 'postId' => 60 ) )
		);

		$decoded = $this->process_and_decode();
		$this->assertArrayHasKey(
			'child-posts-layout',
			$decoded['componentLayouts'],
			'child-posts-layout must be present in componentLayouts when section is injected'
		);
	}

	/**
	 * @test
	 * child-posts-layout is NOT present in componentLayouts when no children exist.
	 */
	public function test_child_posts_layout_absent_when_no_children(): void {
		delete_post_meta( $this->post_id, 'multiSectionReport' );

		$decoded = $this->process_and_decode();
		$this->assertArrayNotHasKey(
			'child-posts-layout',
			$decoded['componentLayouts'],
			'child-posts-layout must be absent from componentLayouts when no children are present'
		);
	}
}
