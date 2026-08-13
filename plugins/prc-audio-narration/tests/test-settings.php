<?php
/**
 * Class SettingsTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Loader;
use PRC\Platform\Audio_Narration\Settings;

/**
 * Tests for settings storage and credential resolution.
 */
class SettingsTest extends WP_UnitTestCase {

	/**
	 * Clean up between tests.
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * Defaults apply when nothing is stored.
	 */
	public function test_defaults_when_unset() {
		$settings = Settings::all();

		$this->assertSame( '', $settings['api_key'] );
		$this->assertSame( '', $settings['voice_id'] );
		$this->assertSame( Settings::DEFAULT_MODEL, $settings['model_id'] );
	}

	/**
	 * A corrupted option value falls back to defaults rather than fataling.
	 */
	public function test_non_array_option_falls_back_to_defaults() {
		update_option( Settings::OPTION_KEY, 'not-an-array' );

		$this->assertSame( Settings::DEFAULT_MODEL, Settings::all()['model_id'] );
	}

	/**
	 * Skip a test whose premise is that no key constant is defined.
	 *
	 * Constants cannot be undefined once set, and a developer environment may
	 * legitimately have a real key configured. Rather than asserting against
	 * whatever the ambient environment happens to be, the option-precedence
	 * tests run only when no constant is present, and the constant-precedence
	 * test runs only when one is. Both paths stay covered; neither depends on
	 * the machine.
	 */
	private function require_no_constant() {
		if ( Settings::api_key_is_constant() ) {
			$this->markTestSkipped( 'A key constant is defined in this environment; it correctly takes precedence.' );
		}
	}

	/**
	 * The stored option supplies the key when no constant is defined.
	 */
	public function test_resolves_key_from_option() {
		$this->require_no_constant();

		update_option( Settings::OPTION_KEY, array( 'api_key' => 'from-option' ) );

		$this->assertEquals( 'from-option', Settings::resolve_api_key() );
	}

	/**
	 * An unconfigured site resolves to an empty key rather than erroring.
	 */
	public function test_resolves_empty_when_unconfigured() {
		$this->require_no_constant();

		$this->assertSame( '', Settings::resolve_api_key() );
		$this->assertFalse( Settings::api_key_is_constant() );
	}

	/**
	 * Whitespace-only stored keys are treated as unconfigured.
	 */
	public function test_whitespace_key_is_not_configured() {
		$this->require_no_constant();

		update_option( Settings::OPTION_KEY, array( 'api_key' => '   ' ) );

		$this->assertSame( '', Settings::resolve_api_key() );
	}

	/**
	 * A key from the core AI connectors screen is used.
	 *
	 * Once ElevenLabs is available as an AI provider, the connectors screen is
	 * where a site configures it, and that key should not need duplicating in
	 * this plugin's own field.
	 */
	public function test_resolves_key_from_connector_option() {
		$this->require_no_constant();

		update_option( 'connectors_ai_elevenlabs_api_key', 'from-connector' );

		$this->assertEquals( 'from-connector', Settings::resolve_api_key() );

		delete_option( 'connectors_ai_elevenlabs_api_key' );
	}

	/**
	 * The connector key beats this plugin's own stored option.
	 */
	public function test_connector_beats_plugin_option() {
		$this->require_no_constant();

		update_option( Settings::OPTION_KEY, array( 'api_key' => 'from-plugin' ) );
		update_option( 'connectors_ai_elevenlabs_api_key', 'from-connector' );

		$this->assertEquals( 'from-connector', Settings::resolve_api_key() );

		delete_option( 'connectors_ai_elevenlabs_api_key' );
	}

	/**
	 * The plugin option still works when no connector supplies a key.
	 */
	public function test_plugin_option_used_without_connector() {
		$this->require_no_constant();

		update_option( Settings::OPTION_KEY, array( 'api_key' => 'from-plugin' ) );

		$this->assertEquals( 'from-plugin', Settings::resolve_api_key() );
	}

	/**
	 * The alternate connector option name is honoured.
	 */
	public function test_alternate_connector_option_name() {
		$this->require_no_constant();

		update_option( 'ais_elevenlabs_api_key', 'from-ais' );

		$this->assertEquals( 'from-ais', Settings::resolve_api_key() );

		delete_option( 'ais_elevenlabs_api_key' );
	}

	/**
	 * The consulted connector options are filterable.
	 *
	 * The exact option name depends on how the ElevenLabs provider registers
	 * itself, which is not settled yet.
	 */
	public function test_connector_options_are_filterable() {
		$this->require_no_constant();

		update_option( 'my_custom_elevenlabs_key', 'from-custom' );
		add_filter(
			'prc_audio_narration_connector_key_options',
			fn() => array( 'my_custom_elevenlabs_key' )
		);

		$this->assertEquals( 'from-custom', Settings::resolve_api_key() );

		delete_option( 'my_custom_elevenlabs_key' );
	}

	/**
	 * A whitespace-only connector value is treated as unconfigured.
	 */
	public function test_blank_connector_value_ignored() {
		$this->require_no_constant();

		update_option( 'connectors_ai_elevenlabs_api_key', '   ' );
		update_option( Settings::OPTION_KEY, array( 'api_key' => 'from-plugin' ) );

		$this->assertEquals( 'from-plugin', Settings::resolve_api_key() );

		delete_option( 'connectors_ai_elevenlabs_api_key' );
	}

	/**
	 * A constant beats the stored option.
	 *
	 * This is the security-relevant half of the contract: a server-level key
	 * must not be overridable from wp-admin.
	 */
	public function test_constant_wins_over_option() {
		if ( ! Settings::api_key_is_constant() ) {
			$this->markTestSkipped( 'No key constant is defined in this environment.' );
		}

		update_option( Settings::OPTION_KEY, array( 'api_key' => 'from-option' ) );

		$resolved = Settings::resolve_api_key();

		$this->assertNotEquals( 'from-option', $resolved );
		$this->assertNotSame( '', $resolved );
	}

	/**
	 * The model falls back to the default when stored empty.
	 */
	public function test_model_falls_back_to_default() {
		update_option( Settings::OPTION_KEY, array( 'model_id' => '' ) );

		$this->assertEquals( Settings::DEFAULT_MODEL, Settings::model_id() );
	}

	/**
	 * The default voice is filterable.
	 */
	public function test_default_voice_is_filterable() {
		update_option( Settings::OPTION_KEY, array( 'voice_id' => 'stored-voice' ) );
		$this->assertEquals( 'stored-voice', Settings::default_voice_id() );

		add_filter( 'prc_audio_narration_default_voice_id', fn() => 'filtered-voice' );
		$this->assertEquals( 'filtered-voice', Settings::default_voice_id() );
	}

	/**
	 * Saving with a blank key field keeps the stored key.
	 *
	 * The field renders masked, so submitting the form without retyping the
	 * key must not wipe it -- an easy way to silently disable narration.
	 */
	public function test_blank_key_submission_preserves_stored_key() {
		update_option( Settings::OPTION_KEY, array( 'api_key' => 'existing-key' ) );

		$settings  = new Settings( new Loader() );
		$sanitized = $settings->sanitize(
			array(
				'api_key'  => '',
				'voice_id' => 'a-voice',
			)
		);

		$this->assertEquals( 'existing-key', $sanitized['api_key'] );
		$this->assertEquals( 'a-voice', $sanitized['voice_id'] );
	}

	/**
	 * A submitted key replaces the stored one.
	 */
	public function test_submitted_key_replaces_stored_key() {
		update_option( Settings::OPTION_KEY, array( 'api_key' => 'old-key' ) );

		$settings  = new Settings( new Loader() );
		$sanitized = $settings->sanitize( array( 'api_key' => 'new-key' ) );

		$this->assertEquals( 'new-key', $sanitized['api_key'] );
	}

	/**
	 * Non-array input sanitizes to an empty array rather than erroring.
	 */
	public function test_sanitize_rejects_non_array() {
		$settings = new Settings( new Loader() );

		$this->assertSame( array(), $settings->sanitize( 'garbage' ) );
	}

	/**
	 * Sanitization strips tags from submitted values.
	 */
	public function test_sanitize_strips_markup() {
		$settings  = new Settings( new Loader() );
		$sanitized = $settings->sanitize(
			array(
				'api_key'  => '<script>alert(1)</script>key',
				'voice_id' => '<b>voice</b>',
			)
		);

		$this->assertStringNotContainsString( '<script>', $sanitized['api_key'] );
		$this->assertStringNotContainsString( '<b>', $sanitized['voice_id'] );
	}
}
