<?php
/**
 * Class SettingsRestTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\REST_API;
use PRC\Platform\Audio_Narration\Settings;

/**
 * Tests for the settings and voices REST routes that back the admin screen.
 */
class SettingsRestTest extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	private $admin;

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor;

	/**
	 * Set up the REST server and users.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor = self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		delete_option( Settings::OPTION_KEY );
		delete_transient( 'prc_audio_narration_voices' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Dispatch a settings request.
	 *
	 * @param string $method HTTP method.
	 * @param array  $params Params.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/' . REST_API::NAMESPACE_V1 . '/settings' );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The routes backing the settings screen are registered.
	 */
	public function test_routes_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/' . REST_API::NAMESPACE_V1 . '/settings', $routes );
		$this->assertArrayHasKey( '/' . REST_API::NAMESPACE_V1 . '/voices', $routes );
	}

	/**
	 * Settings are readable by an administrator.
	 */
	public function test_admin_can_read_settings() {
		wp_set_current_user( $this->admin );

		$response = $this->dispatch( 'GET' );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertArrayHasKey( 'voice_id', $response->get_data() );
		$this->assertArrayHasKey( 'models', $response->get_data() );
	}

	/**
	 * The stored API key is never returned.
	 *
	 * A credential must not be readable back out of an endpoint; the screen
	 * only needs to know whether one is configured.
	 */
	public function test_api_key_is_never_returned() {
		wp_set_current_user( $this->admin );
		update_option( Settings::OPTION_KEY, array( 'api_key' => 'super-secret-key' ) );

		$data = $this->dispatch( 'GET' )->get_data();

		$this->assertArrayNotHasKey( 'api_key', $data );
		$this->assertStringNotContainsString( 'super-secret-key', wp_json_encode( $data ) );
		$this->assertTrue( $data['has_key'] );
	}

	/**
	 * An unconfigured site reports no key.
	 */
	public function test_reports_missing_key() {
		if ( Settings::api_key_is_constant() || '' !== Settings::api_key_from_connector() ) {
			$this->markTestSkipped( 'A key is supplied by the environment.' );
		}

		wp_set_current_user( $this->admin );

		$data = $this->dispatch( 'GET' )->get_data();

		$this->assertFalse( $data['has_key'] );
		$this->assertEquals( 'none', $data['key_source'] );
	}

	/**
	 * Model options carry their character ceilings for the picker.
	 */
	public function test_models_include_ceilings() {
		wp_set_current_user( $this->admin );

		$models = $this->dispatch( 'GET' )->get_data()['models'];

		$this->assertNotEmpty( $models );
		foreach ( $models as $model ) {
			$this->assertArrayHasKey( 'id', $model );
			$this->assertGreaterThan( 0, $model['max_characters'] );
		}
	}

	/**
	 * Voice and model can be updated.
	 */
	public function test_updates_voice_and_model() {
		wp_set_current_user( $this->admin );

		$response = $this->dispatch(
			'POST',
			array(
				'voice_id' => 'voice-abc',
				'model_id' => 'eleven_flash_v2_5',
			)
		);

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'voice-abc', $response->get_data()['voice_id'] );
		$this->assertEquals( 'eleven_flash_v2_5', Settings::model_id() );
	}

	/**
	 * A submitted key is stored.
	 */
	public function test_stores_submitted_key() {
		wp_set_current_user( $this->admin );

		$this->dispatch( 'POST', array( 'api_key' => 'new-key' ) );

		$this->assertEquals( 'new-key', Settings::all()['api_key'] );
	}

	/**
	 * Saving without a key keeps the stored one.
	 *
	 * The field renders empty by design, so an omitted key must not wipe it.
	 */
	public function test_blank_key_preserves_stored_key() {
		wp_set_current_user( $this->admin );
		update_option( Settings::OPTION_KEY, array( 'api_key' => 'existing-key' ) );

		$this->dispatch( 'POST', array( 'voice_id' => 'voice-abc' ) );

		$this->assertEquals( 'existing-key', Settings::all()['api_key'] );
	}

	/**
	 * Non-administrators cannot read or write settings.
	 *
	 * @dataProvider method_provider
	 *
	 * @param string $method HTTP method.
	 */
	public function test_forbidden_for_editor( $method ) {
		wp_set_current_user( $this->editor );

		$this->assertEquals( 403, $this->dispatch( $method )->get_status() );
	}

	/**
	 * Logged-out requests are refused.
	 *
	 * @dataProvider method_provider
	 *
	 * @param string $method HTTP method.
	 */
	public function test_forbidden_for_logged_out( $method ) {
		wp_set_current_user( 0 );

		$this->assertEquals( 401, $this->dispatch( $method )->get_status() );
	}

	/**
	 * Methods guarded by the manage_options check.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function method_provider() {
		return array(
			'read'  => array( 'GET' ),
			'write' => array( 'POST' ),
		);
	}

	/**
	 * Editors can read the voice list.
	 *
	 * The panel offers a per-post voice override, so someone who can narrate
	 * a post needs the list to choose from. It is a deliberately lower bar
	 * than the settings routes.
	 */
	public function test_editor_can_read_voices() {
		wp_set_current_user( $this->editor );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/voices' );

		$this->assertEquals( 200, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * Users who cannot edit posts are refused the voice list.
	 */
	public function test_subscriber_cannot_read_voices() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/voices' );

		$this->assertEquals( 403, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * The voice list still requires authentication.
	 */
	public function test_voices_requires_authentication() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/voices' );

		$this->assertEquals( 401, rest_get_server()->dispatch( $request )->get_status() );
	}

	/**
	 * An unconfigured site returns no voices rather than erroring.
	 */
	public function test_voices_empty_without_key() {
		if ( '' !== Settings::resolve_api_key() ) {
			$this->markTestSkipped( 'A key is supplied by the environment.' );
		}

		wp_set_current_user( $this->admin );

		$request  = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/voices' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['voices'] );
	}

	/**
	 * Voices are served from cache without calling the provider.
	 *
	 * A key must be configured for this path to be reachable at all -- an
	 * unconfigured site short-circuits before the cache, which is why this
	 * sets one rather than relying on the environment.
	 */
	public function test_voices_served_from_cache() {
		wp_set_current_user( $this->admin );
		update_option( Settings::OPTION_KEY, array( 'api_key' => 'test-key' ) );

		set_transient(
			'prc_audio_narration_voices',
			array(
				array(
					'id'          => 'cached-voice',
					'name'        => 'Cached',
					'description' => '',
				),
			),
			HOUR_IN_SECONDS
		);

		$request  = new WP_REST_Request( 'GET', '/' . REST_API::NAMESPACE_V1 . '/voices' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'cached-voice', $response->get_data()['voices'][0]['id'] );
	}
}
