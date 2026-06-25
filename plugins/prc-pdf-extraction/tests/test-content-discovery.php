<?php
/**
 * Tests for Content_Discovery class.
 *
 * @package PRC_PDF_Extraction
 */

use PRC\Platform\PDF_Extraction\Content_Discovery;
use PRC\Platform\PDF_Extraction\Content_Type;
use PRC\Platform\PDF_Extraction\Loader;

/**
 * Tests for meta tags, JSON-LD, LLMs.txt, and robots.txt in Content_Discovery.
 */
class ContentDiscoveryTest extends WP_UnitTestCase {

	/**
	 * Loader instance.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Content_Discovery instance.
	 *
	 * @var Content_Discovery
	 */
	protected $content_discovery;

	/**
	 * Set up test fixtures.
	 */
	public function set_up() {
		parent::set_up();
		$this->loader           = new Loader();
		$this->content_discovery = new Content_Discovery( $this->loader );
		$this->loader->run();
	}

	/**
	 * Test that alternate link tags are output when post has extraction child.
	 */
	public function test_alternate_link_tags_output_when_post_has_extraction_child() {
		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Parent With Topline',
				'post_status' => 'publish',
			)
		);

		self::factory()->post->create(
			array(
				'post_type'   => Content_Type::get_post_type(),
				'post_title'  => 'Extracted Topline',
				'post_parent' => $parent_id,
				'post_status' => 'publish',
			)
		);

		$this->go_to( get_permalink( $parent_id ) );

		ob_start();
		do_action( 'wp_head' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'rel="alternate" type="text/markdown"', $output );
		$this->assertStringContainsString( '/' . Content_Type::get_url_slug(), $output );
		$this->assertStringContainsString( 'rel="alternate" type="text/plain"', $output );
		$this->assertStringContainsString( '/text', $output );
	}

	/**
	 * Test that no link tags are output when post has no extraction child.
	 */
	public function test_no_alternate_link_tags_when_post_has_no_extraction_child() {
		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Parent Without Topline',
				'post_status' => 'publish',
			)
		);

		$this->go_to( get_permalink( $parent_id ) );

		ob_start();
		do_action( 'wp_head' );
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '/' . Content_Type::get_url_slug(), $output );
		$this->assertStringNotContainsString( '/text', $output );
	}

	/**
	 * Test that JSON-LD structured data is output when post has extraction child.
	 */
	public function test_json_ld_output_when_post_has_extraction_child() {
		$parent_id = self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Parent For JSON-LD',
				'post_status' => 'publish',
			)
		);

		$extraction_title = 'Extracted Topline Data';
		self::factory()->post->create(
			array(
				'post_type'   => Content_Type::get_post_type(),
				'post_title'  => $extraction_title,
				'post_parent' => $parent_id,
				'post_status' => 'publish',
			)
		);

		$this->go_to( get_permalink( $parent_id ) );

		ob_start();
		do_action( 'wp_head' );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'application/ld+json', $output );
		$this->assertStringContainsString( '@context', $output );
		$this->assertStringContainsString( 'DigitalDocument', $output );
		$this->assertStringContainsString( $extraction_title, $output );
		$this->assertStringContainsString( 'encodingFormat', $output );
		$this->assertStringContainsString( 'text/markdown', $output );

		// Verify valid JSON can be extracted.
		preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $output, $matches );
		$this->assertNotEmpty( $matches );
		$json = json_decode( $matches[1], true );
		$this->assertIsArray( $json );
		$this->assertEquals( 'https://schema.org', $json['@context'] );
		$this->assertEquals( 'DigitalDocument', $json['@type'] );
	}

	/**
	 * Test robots.txt filter adds Allow rules.
	 */
	public function test_robots_txt_adds_allow_rules() {
		$output = apply_filters( 'robots_txt', '', 1 );

		$this->assertStringContainsString( 'Allow: /*/' . Content_Type::get_url_slug(), $output );
		$this->assertStringContainsString( 'Allow: /*/text', $output );
		$this->assertStringContainsString( 'Allow: /*/markdown', $output );
	}

	/**
	 * Test robots.txt filter does nothing when site is not public.
	 */
	public function test_robots_txt_does_nothing_when_not_public() {
		$initial = "User-agent: *\nDisallow: /admin\n";
		$output  = apply_filters( 'robots_txt', $initial, 0 );

		$this->assertEquals( $initial, $output );
	}
}
