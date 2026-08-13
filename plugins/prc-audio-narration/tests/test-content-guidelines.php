<?php
/**
 * Class ContentGuidelinesTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Content_Guidelines;
use PRC\Platform\Audio_Narration\Loader;

/**
 * Tests for the Gutenberg content guidelines integration.
 */
class ContentGuidelinesTest extends WP_UnitTestCase {

	/**
	 * Integration under test.
	 *
	 * @var Content_Guidelines
	 */
	private $guidelines;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->guidelines = new Content_Guidelines( new Loader() );
	}

	/**
	 * Skip when Gutenberg's knowledge feature is absent.
	 */
	private function require_knowledge() {
		if ( ! Content_Guidelines::is_available() ) {
			$this->markTestSkipped( 'Gutenberg knowledge feature is not active in this environment.' );
		}
	}

	/**
	 * Author a guideline row the way the Guidelines screen does.
	 *
	 * @param string $scope   Scope slug.
	 * @param string $content Guideline text.
	 * @return int
	 */
	private function write_guideline( string $scope, string $content ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => Content_Guidelines::POST_TYPE,
				'post_name'    => 'guideline-' . $scope,
				'post_title'   => $scope,
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);
	}

	/**
	 * Our scope is added to the Guidelines screen.
	 */
	public function test_registers_audio_narration_scope() {
		$scopes = $this->guidelines->register_scope( array( 'site' => array( 'title' => 'Site' ) ) );

		$this->assertArrayHasKey( Content_Guidelines::SCOPE, $scopes );
		$this->assertNotEmpty( $scopes[ Content_Guidelines::SCOPE ]['title'] );
		$this->assertNotEmpty( $scopes[ Content_Guidelines::SCOPE ]['description'] );
	}

	/**
	 * Existing scopes are preserved.
	 */
	public function test_preserves_existing_scopes() {
		$scopes = $this->guidelines->register_scope( array( 'site' => array( 'title' => 'Site' ) ) );

		$this->assertArrayHasKey( 'site', $scopes );
	}

	/**
	 * Non-array input passes through rather than fataling.
	 */
	public function test_register_scope_ignores_non_array() {
		$this->assertSame( 'garbage', $this->guidelines->register_scope( 'garbage' ) );
	}

	/**
	 * Guideline text is read from its backing post.
	 */
	public function test_reads_guideline_content() {
		$this->require_knowledge();

		$this->write_guideline( Content_Guidelines::SCOPE, 'Speak percentages as percent.' );

		$this->assertEquals( 'Speak percentages as percent.', Content_Guidelines::get( Content_Guidelines::SCOPE ) );
	}

	/**
	 * A scope with no guideline yields an empty string.
	 */
	public function test_missing_guideline_is_empty() {
		$this->assertSame( '', Content_Guidelines::get( 'nonexistent-scope' ) );
	}

	/**
	 * Draft guidelines are ignored.
	 *
	 * An unpublished guideline is a work in progress and must not silently
	 * start steering generated narration.
	 */
	public function test_draft_guideline_is_ignored() {
		$this->require_knowledge();

		self::factory()->post->create(
			array(
				'post_type'    => Content_Guidelines::POST_TYPE,
				'post_name'    => 'guideline-' . Content_Guidelines::SCOPE,
				'post_content' => 'Not ready yet.',
				'post_status'  => 'draft',
			)
		);

		$this->assertSame( '', Content_Guidelines::get( Content_Guidelines::SCOPE ) );
	}

	/**
	 * Markup is stripped before the text reaches the prompt.
	 */
	public function test_strips_markup() {
		$this->require_knowledge();

		$this->write_guideline( Content_Guidelines::SCOPE, '<script>bad()</script>Keep sentences short.' );

		$content = Content_Guidelines::get( Content_Guidelines::SCOPE );

		$this->assertStringNotContainsString( '<script>', $content );
		$this->assertStringContainsString( 'Keep sentences short.', $content );
	}

	/**
	 * The prompt section is empty when no guidelines are authored.
	 *
	 * A site that has never used the Guidelines screen should get exactly the
	 * format spec, with no dangling empty section.
	 */
	public function test_prompt_section_empty_without_guidelines() {
		$this->assertSame( '', Content_Guidelines::prompt_section() );
	}

	/**
	 * Authored guidelines appear in the prompt section.
	 */
	public function test_prompt_section_includes_guidelines() {
		$this->require_knowledge();

		$this->write_guideline( 'copy', 'Always write Pew Research Center in full.' );
		$this->write_guideline( Content_Guidelines::SCOPE, 'Speak percentages as percent.' );

		$section = Content_Guidelines::prompt_section();

		$this->assertStringContainsString( 'SITE CONTENT GUIDELINES', $section );
		$this->assertStringContainsString( 'Always write Pew Research Center in full.', $section );
		$this->assertStringContainsString( 'Speak percentages as percent.', $section );
	}

	/**
	 * Guidelines are framed as subordinate to the format rules.
	 *
	 * An editorial preference must not be able to license summarizing a report
	 * or altering a statistic, which the rules above forbid.
	 */
	public function test_prompt_section_subordinates_guidelines_to_rules() {
		$this->require_knowledge();

		$this->write_guideline( Content_Guidelines::SCOPE, 'Be brief.' );

		$section = Content_Guidelines::prompt_section();

		$this->assertStringContainsString( 'does not conflict with the rules above', $section );
		$this->assertStringContainsString( 'does not license summarizing', $section );
	}

	/**
	 * Scopes run general to specific so the most targeted guidance reads last.
	 */
	public function test_scope_order_is_general_to_specific() {
		$scopes = Content_Guidelines::applicable_scopes();

		$this->assertEquals( Content_Guidelines::SCOPE, end( $scopes ) );
		$this->assertContains( 'site', $scopes );
		$this->assertContains( 'copy', $scopes );
	}

	/**
	 * The applicable scopes are filterable.
	 */
	public function test_applicable_scopes_are_filterable() {
		add_filter( 'prc_audio_narration_guideline_scopes', fn() => array( 'copy' ) );

		$this->assertEquals( array( 'copy' ), Content_Guidelines::applicable_scopes() );
	}

	/**
	 * Only scopes in the applicable list contribute to the prompt.
	 */
	public function test_unlisted_scopes_are_excluded() {
		$this->require_knowledge();

		$this->write_guideline( 'images', 'Prefer wide crops.' );
		$this->write_guideline( Content_Guidelines::SCOPE, 'Speak percentages as percent.' );

		$section = Content_Guidelines::prompt_section();

		$this->assertStringNotContainsString( 'Prefer wide crops.', $section );
		$this->assertStringContainsString( 'Speak percentages as percent.', $section );
	}
}
