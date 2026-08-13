<?php
/**
 * Class ScriptProviderRegistrarTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Providers\Audio_Script_Provider;
use PRC\Platform\Audio_Narration\Script_Provider_Registrar;
use PRC\Platform\Audio_Narration\Loader;
use PRC\Platform\Content_Transformer\Providers\Provider;

/**
 * Tests for the transformer registration seam and system instruction override.
 */
class ScriptProviderRegistrarTest extends WP_UnitTestCase {

	/**
	 * Registrar under test.
	 *
	 * @var Script_Provider_Registrar
	 */
	private $registrar;

	/**
	 * Set up a registrar with its own loader.
	 */
	public function set_up() {
		parent::set_up();

		require_once PRC_AUDIO_NARRATION_DIR . '/includes/providers/class-audio-script-provider.php';

		$this->registrar = new Script_Provider_Registrar( new Loader() );
	}

	/**
	 * The narration instruction replaces the default for this provider.
	 *
	 * The transformer's default instruction forbids modifying content. That is
	 * correct for formats that reproduce text verbatim and wrong for narration,
	 * where rewriting for the ear is the entire job.
	 */
	public function test_system_instruction_is_replaced_for_audio_script() {
		$default  = 'Do NOT add, remove, or modify any substantive content.';
		$filtered = $this->registrar->filter_system_instruction( $default, new Audio_Script_Provider() );

		$this->assertNotEquals( $default, $filtered );
		$this->assertStringNotContainsString( 'Do NOT add, remove, or modify any substantive content', $filtered );
		$this->assertStringContainsString( 'read aloud', $filtered );
		$this->assertStringContainsString( 'DO rewrite for the ear', $filtered );
	}

	/**
	 * The replacement instruction still forbids changing the findings.
	 *
	 * Loosening the "do not modify" rule must not loosen it for the numbers --
	 * a narration that misstates a statistic is worse than no narration.
	 */
	public function test_system_instruction_still_protects_statistics() {
		$filtered = $this->registrar->filter_system_instruction( 'default', new Audio_Script_Provider() );

		$this->assertStringContainsString( 'numbers themselves must never change', $filtered );
		$this->assertStringContainsString( 'Do NOT summarize', $filtered );
	}

	/**
	 * The narration instruction carries the format spec along with it.
	 */
	public function test_system_instruction_includes_format_spec() {
		$filtered = $this->registrar->filter_system_instruction( 'default', new Audio_Script_Provider() );

		$this->assertStringContainsString( 'TARGET FORMAT SPECIFICATION', $filtered );
		$this->assertStringContainsString( 'Audio Narration Script Specification', $filtered );
	}

	/**
	 * Other providers keep the transformer's default instruction untouched.
	 */
	public function test_system_instruction_untouched_for_other_providers() {
		$default = 'Do NOT add, remove, or modify any substantive content.';

		$other = new class() implements Provider {
			/**
			 * Name.
			 *
			 * @return string
			 */
			public function get_name(): string {
				return 'Plain Text';
			}

			/**
			 * Slug.
			 *
			 * @return string
			 */
			public function get_slug(): string {
				return 'plain-text';
			}

			/**
			 * Spec.
			 *
			 * @return string
			 */
			public function get_format_spec(): string {
				return '';
			}

			/**
			 * Output type.
			 *
			 * @return string
			 */
			public function get_output_type(): string {
				return 'text';
			}

			/**
			 * Validate.
			 *
			 * @param string $output Output.
			 * @return bool
			 */
			public function validate( string $output ): bool {
				return true;
			}

			/**
			 * Post process.
			 *
			 * @param string $output Output.
			 * @return string
			 */
			public function post_process( string $output ): string {
				return $output;
			}

			/**
			 * Availability.
			 *
			 * @return bool
			 */
			public function is_available(): bool {
				return true;
			}
		};

		$this->assertEquals( $default, $this->registrar->filter_system_instruction( $default, $other ) );
	}

	/**
	 * A non-provider value passes through untouched rather than fataling.
	 */
	public function test_system_instruction_ignores_non_provider() {
		$default = 'unchanged';

		$this->assertEquals( $default, $this->registrar->filter_system_instruction( $default, null ) );
	}
}
