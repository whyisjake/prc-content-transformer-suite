<?php
/**
 * Class MarkdownForAgentsIntegrationTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Loader;
use PRC\Platform\Audio_Narration\Markdown_For_Agents_Integration;
use PRC\Platform\Audio_Narration\Narration_Store;

/**
 * Tests for exposing narration through markdown frontmatter and llms.txt.
 */
class MarkdownForAgentsIntegrationTest extends WP_UnitTestCase {

	/**
	 * Integration under test.
	 *
	 * @var Markdown_For_Agents_Integration
	 */
	private $integration;

	/**
	 * Narration storage.
	 *
	 * @var Narration_Store
	 */
	private $store;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		$this->store       = new Narration_Store();
		$this->integration = new Markdown_For_Agents_Integration( new Loader(), $this->store );
	}

	/**
	 * Create a published post.
	 *
	 * @param string $title Post title.
	 * @return int
	 */
	private function make_post( string $title = 'Trust in local news' ): int {
		return self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => 'Seventy-two percent of Americans agree.',
			)
		);
	}

	/**
	 * Fresh narration adds an audio URL to frontmatter.
	 */
	public function test_frontmatter_gains_audio_url() {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO', array( 'provider' => 'elevenlabs' ) );

		$data = $this->integration->enrich_frontmatter( array( 'title' => 'x' ), get_post( $post_id ) );

		$this->assertArrayHasKey( 'audio_url', $data );
		$this->assertNotEmpty( $data['audio_url'] );
		$this->assertEquals( 'elevenlabs', $data['audio_provider'] );
	}

	/**
	 * Duration is included when known, rounded to whole seconds.
	 */
	public function test_frontmatter_includes_duration_when_known() {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO', array( 'duration' => 125.4 ) );

		$data = $this->integration->enrich_frontmatter( array(), get_post( $post_id ) );

		$this->assertEquals( 125, $data['audio_duration'] );
	}

	/**
	 * Duration is omitted rather than guessed when unknown.
	 */
	public function test_frontmatter_omits_unknown_duration() {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO', array( 'duration' => null ) );

		$data = $this->integration->enrich_frontmatter( array(), get_post( $post_id ) );

		$this->assertArrayNotHasKey( 'audio_duration', $data );
	}

	/**
	 * A post with no narration is untouched.
	 */
	public function test_frontmatter_unchanged_without_narration() {
		$post_id  = $this->make_post();
		$original = array( 'title' => 'Trust in local news' );

		$this->assertEquals(
			$original,
			$this->integration->enrich_frontmatter( $original, get_post( $post_id ) )
		);
	}

	/**
	 * Stale narration is not advertised.
	 *
	 * A crawler cannot tell that the audio no longer matches the article, so
	 * publishing the URL would be worse than publishing nothing.
	 */
	public function test_frontmatter_omits_stale_narration() {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Entirely different content.',
			)
		);

		$data = $this->integration->enrich_frontmatter( array(), get_post( $post_id ) );

		$this->assertArrayNotHasKey( 'audio_url', $data );
	}

	/**
	 * A non-post argument passes through untouched rather than fataling.
	 */
	public function test_frontmatter_ignores_non_post() {
		$this->assertEquals(
			array( 'a' => 'b' ),
			$this->integration->enrich_frontmatter( array( 'a' => 'b' ), null )
		);
	}

	/**
	 * The llms.txt section appears once narration exists.
	 */
	public function test_llms_txt_section_is_added() {
		$post_id = $this->make_post( 'A narrated article' );
		$this->store->store( $post_id, 'AUDIO' );

		$sections = $this->integration->register_section( array() );

		$this->assertCount( 1, $sections );
		$this->assertEquals( 'audio-narration', $sections[0]['slug'] );
		$this->assertNotEmpty( $sections[0]['links'] );
		$this->assertEquals( 'A narrated article', $sections[0]['links'][0]['title'] );
	}

	/**
	 * No section is added when nothing is narrated.
	 *
	 * An empty section in llms.txt advertises a capability the site does not
	 * currently have.
	 */
	public function test_no_section_without_narration() {
		$this->make_post();

		$this->assertSame( array(), $this->integration->register_section( array() ) );
	}

	/**
	 * Stale narration is excluded from the section.
	 */
	public function test_stale_narration_excluded_from_section() {
		$fresh = $this->make_post( 'Fresh article' );
		$stale = $this->make_post( 'Stale article' );

		$this->store->store( $fresh, 'AUDIO' );
		$this->store->store( $stale, 'AUDIO' );

		wp_update_post(
			array(
				'ID'           => $stale,
				'post_content' => 'Changed after narration.',
			)
		);

		$sections = $this->integration->register_section( array() );
		$titles   = wp_list_pluck( $sections[0]['links'], 'title' );

		$this->assertContains( 'Fresh article', $titles );
		$this->assertNotContains( 'Stale article', $titles );
	}

	/**
	 * Existing sections from other plugins are preserved.
	 */
	public function test_existing_sections_are_preserved() {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO' );

		$existing = array(
			array(
				'slug'  => 'topline-extractions',
				'title' => 'Topline extractions',
				'links' => array(),
			),
		);

		$sections = $this->integration->register_section( $existing );

		$this->assertCount( 2, $sections );
		$this->assertEquals( 'topline-extractions', $sections[0]['slug'] );
	}

	/**
	 * Non-array input passes through rather than fataling.
	 */
	public function test_register_section_ignores_non_array() {
		$this->assertSame( 'garbage', $this->integration->register_section( 'garbage' ) );
	}

	/**
	 * Section entries carry a description with the publication date.
	 */
	public function test_section_links_carry_description() {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO', array( 'duration' => 300.0 ) );

		$sections = $this->integration->register_section( array() );

		$this->assertNotEmpty( $sections[0]['links'][0]['description'] );
		$this->assertStringContainsString( '5 minutes', $sections[0]['links'][0]['description'] );
	}
}
