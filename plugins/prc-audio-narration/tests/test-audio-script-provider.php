<?php
/**
 * Class AudioScriptProviderTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Providers\Audio_Script_Provider;
use PRC\Platform\Content_Transformer\Providers\Provider;
use PRC\Platform\Content_Transformer\Providers\Provider_Registry;

/**
 * Tests for the audio narration script provider.
 */
class AudioScriptProviderTest extends WP_UnitTestCase {

	/**
	 * Provider under test.
	 *
	 * @var Audio_Script_Provider
	 */
	private $provider;

	/**
	 * Set up the provider, loading it through the same seam the plugin uses.
	 */
	public function set_up() {
		parent::set_up();

		require_once PRC_AUDIO_NARRATION_DIR . '/includes/providers/class-audio-script-provider.php';

		$this->provider = new Audio_Script_Provider();
	}

	/**
	 * The provider identifies itself with the expected slug and output type.
	 */
	public function test_identity() {
		$this->assertEquals( 'audio-script', $this->provider->get_slug() );
		$this->assertEquals( 'text', $this->provider->get_output_type() );
		$this->assertNotEmpty( $this->provider->get_name() );
	}

	/**
	 * The provider satisfies the transformer's provider contract.
	 */
	public function test_implements_provider_interface() {
		$this->assertInstanceOf( Provider::class, $this->provider );
	}

	/**
	 * The format spec loads from disk and carries its substantive rules.
	 */
	public function test_format_spec_loads() {
		$spec = $this->provider->get_format_spec();

		$this->assertNotEmpty( $spec );
		$this->assertStringContainsString( 'percent', $spec );
		$this->assertStringContainsString( 'Acronym', $spec );
	}

	/**
	 * A provider with a readable spec reports itself available.
	 */
	public function test_is_available_with_spec() {
		$this->assertTrue( $this->provider->is_available() );
	}

	/**
	 * Registering through the transformer hook puts the provider in the registry.
	 */
	public function test_registers_with_transformer_registry() {
		Provider_Registry::reset();

		do_action( 'prc_content_transformer_register_providers' );

		$this->assertTrue( Provider_Registry::has( 'audio-script' ) );
		$this->assertInstanceOf( Audio_Script_Provider::class, Provider_Registry::get( 'audio-script' ) );
	}

	/**
	 * Clean prose with no markup passes validation.
	 */
	public function test_validate_accepts_clean_prose() {
		$script = "Turning to trust in local news.\n\n72 percent of Americans say they trust local news more than national outlets, according to the American Community Survey.";

		$this->assertTrue( $this->provider->validate( $script ) );
	}

	/**
	 * Empty and whitespace-only output is rejected.
	 *
	 * @dataProvider empty_output_provider
	 *
	 * @param string $output The output to validate.
	 */
	public function test_validate_rejects_empty( $output ) {
		$this->assertFalse( $this->provider->validate( $output ) );
	}

	/**
	 * Empty-ish outputs.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function empty_output_provider() {
		return array(
			'empty string'     => array( '' ),
			'spaces only'      => array( '   ' ),
			'newlines only'    => array( "\n\n\n" ),
			'tabs and spaces'  => array( " \t \n " ),
		);
	}

	/**
	 * Each markup failure mode the spec targets is rejected by name.
	 *
	 * @dataProvider markup_failure_provider
	 *
	 * @param string $output       The offending script.
	 * @param string $expected_key The validation failure key expected.
	 */
	public function test_validate_rejects_markup( $output, $expected_key ) {
		$this->assertFalse( $this->provider->validate( $output ) );

		$failures = Audio_Script_Provider::validation_failures( $output );
		$this->assertArrayHasKey( $expected_key, $failures );
		$this->assertNotEmpty( $failures[ $expected_key ] );
	}

	/**
	 * Scripts that must fail validation, with the reason each should report.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function markup_failure_provider() {
		return array(
			'markdown table'      => array( "Findings follow.\n\n| Source | Trust |\n|---|---|\n| Local | 72 percent |", 'markdown_table' ),
			'markdown image'      => array( 'The finding is clear. ![A chart](chart.png)', 'markdown_image' ),
			'markdown link'       => array( 'Read the [full study](https://example.com) for more.', 'markdown_link' ),
			'markdown heading'    => array( "## Trust in local news\n\nMost Americans agree.", 'markdown_heading' ),
			'footnote marker'     => array( 'Most Americans agree.[^1]', 'footnote_marker' ),
			'bare url'            => array( 'The study is at https://example.com and covers 2026.', 'url' ),
			'list bullet'         => array( "Three findings emerged.\n\n- First finding\n- Second finding", 'list_bullet' ),
			'horizontal rule'     => array( "One section ends.\n\n---\n\nAnother begins.", 'horizontal_rule' ),
			'code fence'          => array( "Here is the data.\n\n```\nvalue\n```", 'code_fence' ),
			'chart reference'     => array( 'As the chart below shows, support has grown.', 'visual_reference' ),
			'figure reference'    => array( 'See figure above for the breakdown.', 'visual_reference' ),
			'percent symbol'      => array( 'Some 72% of Americans agree.', 'percent_symbol' ),
			'percent with space'  => array( 'Some 72 % of Americans agree.', 'percent_symbol' ),
		);
	}

	/**
	 * A script carrying several problems reports all of them, so the retry
	 * prompt can correct them in one pass rather than one per attempt.
	 */
	public function test_validation_failures_reports_all_problems() {
		$output   = "## Heading\n\n- A bullet with 72% and a [link](https://example.com)";
		$failures = Audio_Script_Provider::validation_failures( $output );

		$this->assertArrayHasKey( 'markdown_heading', $failures );
		$this->assertArrayHasKey( 'list_bullet', $failures );
		$this->assertArrayHasKey( 'percent_symbol', $failures );
		$this->assertArrayHasKey( 'markdown_link', $failures );
		$this->assertArrayHasKey( 'url', $failures );
	}

	/**
	 * Post-processing strips emphasis markers while keeping the words.
	 */
	public function test_post_process_strips_emphasis() {
		$output = $this->provider->post_process( 'A **strong** claim and an _emphasized_ one.' );

		$this->assertEquals( 'A strong claim and an emphasized one.', $output );
	}

	/**
	 * Post-processing removes inline code backticks but keeps their contents.
	 */
	public function test_post_process_strips_backticks() {
		$this->assertEquals(
			'The value is 72 percent.',
			$this->provider->post_process( 'The value is `72 percent`.' )
		);
	}

	/**
	 * Post-processing normalizes whitespace without collapsing paragraphs.
	 */
	public function test_post_process_normalizes_whitespace() {
		$output = $this->provider->post_process( "  First paragraph.   \n\n\n\nSecond paragraph.  \n" );

		$this->assertEquals( "First paragraph.\n\nSecond paragraph.", $output );
	}

	/**
	 * Underscores inside words survive post-processing.
	 *
	 * Naive emphasis stripping mangles snake_case and similar tokens; a script
	 * quoting a variable or a survey field name should come through intact.
	 */
	public function test_post_process_preserves_intraword_underscores() {
		$this->assertEquals(
			'The field is named party_id in the dataset.',
			$this->provider->post_process( 'The field is named party_id in the dataset.' )
		);
	}

	/**
	 * Post-processing does not repair validation failures.
	 *
	 * Silently patching a table or a URL would hide a bad script instead of
	 * letting the pipeline retry it with feedback.
	 */
	public function test_post_process_does_not_mask_validation_failures() {
		$output = $this->provider->post_process( "Findings.\n\n| A | B |\n|---|---|\n| 1 | 2 |" );

		$this->assertFalse( $this->provider->validate( $output ) );
	}
}
