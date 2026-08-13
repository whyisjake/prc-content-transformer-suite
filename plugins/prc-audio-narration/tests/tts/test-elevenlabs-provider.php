<?php
/**
 * Class ElevenLabsProviderTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Settings;
use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Request;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Authentication_Exception;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Rate_Limit_Exception;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Synthesis_Failed_Exception;
use PRC\Platform\Audio_Narration\TTS\Providers\ElevenLabs_Provider;

require_once __DIR__ . '/../helpers/class-fake-http-client.php';

use PRC\Platform\Audio_Narration\Tests\Fake_HTTP_Client;

/**
 * Tests for the ElevenLabs provider's request shape and error mapping.
 */
class ElevenLabsProviderTest extends WP_UnitTestCase {

	/**
	 * Configure a default voice so requests are well-formed.
	 */
	public function set_up() {
		parent::set_up();

		update_option(
			Settings::OPTION_KEY,
			array(
				'api_key'  => 'option-key',
				'voice_id' => 'default-voice',
				'model_id' => 'eleven_multilingual_v2',
			)
		);
	}

	/**
	 * Clean up options between tests.
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * Build a request.
	 *
	 * @param array $options Request options.
	 * @return TTS_Request
	 */
	private function request( array $options = array() ): TTS_Request {
		return new TTS_Request( 'A narration chunk.', $options );
	}

	/**
	 * A successful response yields the audio payload unchanged.
	 */
	public function test_synthesize_returns_audio() {
		$http     = new Fake_HTTP_Client( array( Fake_HTTP_Client::response( 'BINARY_AUDIO' ) ) );
		$provider = new ElevenLabs_Provider( $http, 'test-key' );

		$response = $provider->synthesize( $this->request() );

		$this->assertEquals( 'BINARY_AUDIO', $response->get_audio() );
		$this->assertEquals( 'audio/mpeg', $response->get_mime_type() );
		$this->assertEquals( 'elevenlabs', $response->get_provider_name() );
	}

	/**
	 * The request carries the key, text, and model to the API.
	 */
	public function test_request_shape() {
		$http     = new Fake_HTTP_Client( array( Fake_HTTP_Client::response( 'AUDIO' ) ) );
		$provider = new ElevenLabs_Provider( $http, 'test-key' );

		$provider->synthesize( $this->request() );

		$last = $http->last_request();
		$this->assertStringContainsString( '/text-to-speech/default-voice', $last['url'] );
		$this->assertStringContainsString( 'output_format=mp3_44100_128', $last['url'] );
		$this->assertEquals( 'test-key', $last['args']['headers']['xi-api-key'] );

		$body = $http->last_body();
		$this->assertEquals( 'A narration chunk.', $body['text'] );
		$this->assertEquals( 'eleven_multilingual_v2', $body['model_id'] );
	}

	/**
	 * Neighbor context is forwarded so prosody carries across chunks.
	 */
	public function test_neighbor_context_is_sent() {
		$http     = new Fake_HTTP_Client( array( Fake_HTTP_Client::response( 'AUDIO' ) ) );
		$provider = new ElevenLabs_Provider( $http, 'test-key' );

		$provider->synthesize(
			$this->request(
				array(
					'preceding_text' => 'The chunk before.',
					'following_text' => 'The chunk after.',
				)
			)
		);

		$body = $http->last_body();
		$this->assertEquals( 'The chunk before.', $body['previous_text'] );
		$this->assertEquals( 'The chunk after.', $body['next_text'] );
	}

	/**
	 * Neighbor fields are omitted rather than sent empty for a lone chunk.
	 */
	public function test_neighbor_context_omitted_when_absent() {
		$http     = new Fake_HTTP_Client( array( Fake_HTTP_Client::response( 'AUDIO' ) ) );
		$provider = new ElevenLabs_Provider( $http, 'test-key' );

		$provider->synthesize( $this->request() );

		$body = $http->last_body();
		$this->assertArrayNotHasKey( 'previous_text', $body );
		$this->assertArrayNotHasKey( 'next_text', $body );
	}

	/**
	 * A per-request voice overrides the site default.
	 */
	public function test_request_voice_overrides_default() {
		$http     = new Fake_HTTP_Client( array( Fake_HTTP_Client::response( 'AUDIO' ) ) );
		$provider = new ElevenLabs_Provider( $http, 'test-key' );

		$provider->synthesize( $this->request( array( 'voice_id' => 'override-voice' ) ) );

		$this->assertStringContainsString( '/text-to-speech/override-voice', $http->last_request()['url'] );
	}

	/**
	 * Missing credentials fail before any network call.
	 */
	public function test_missing_key_raises_authentication_error() {
		$http     = new Fake_HTTP_Client();
		$provider = new ElevenLabs_Provider( $http, '' );

		$this->expectException( Authentication_Exception::class );

		try {
			$provider->synthesize( $this->request() );
		} finally {
			$this->assertCount( 0, $http->requests, 'An unconfigured provider must not call the API.' );
		}
	}

	/**
	 * A missing voice fails before any network call, and is not retryable.
	 */
	public function test_missing_voice_raises_non_retryable_error() {
		update_option( Settings::OPTION_KEY, array( 'api_key' => 'option-key' ) );

		$http     = new Fake_HTTP_Client();
		$provider = new ElevenLabs_Provider( $http, 'test-key' );

		try {
			$provider->synthesize( $this->request() );
			$this->fail( 'Expected a Synthesis_Failed_Exception.' );
		} catch ( Synthesis_Failed_Exception $e ) {
			$this->assertFalse( $e->is_retryable() );
			$this->assertCount( 0, $http->requests );
		}
	}

	/**
	 * Rejected credentials map to an authentication error.
	 *
	 * @dataProvider auth_status_provider
	 *
	 * @param int $status HTTP status.
	 */
	public function test_auth_failures_map_to_authentication_exception( $status ) {
		$http     = new Fake_HTTP_Client( array( Fake_HTTP_Client::response( '{"detail":"bad key"}', $status ) ) );
		$provider = new ElevenLabs_Provider( $http, 'test-key' );

		$this->expectException( Authentication_Exception::class );

		$provider->synthesize( $this->request() );
	}

	/**
	 * Auth-rejecting statuses.
	 *
	 * @return array<string, array{0: int}>
	 */
	public function auth_status_provider() {
		return array(
			'unauthorized' => array( 401 ),
			'forbidden'    => array( 403 ),
		);
	}

	/**
	 * Rate limiting maps to a distinct, retryable error.
	 *
	 * The scheduler needs to tell a transient limit apart from a bad key so it
	 * does not retry something that can never succeed.
	 */
	public function test_rate_limit_maps_to_retryable_exception() {
		$http     = new Fake_HTTP_Client( array( Fake_HTTP_Client::response( '{"detail":"slow down"}', 429 ) ) );
		$provider = new ElevenLabs_Provider( $http, 'test-key' );

		try {
			$provider->synthesize( $this->request() );
			$this->fail( 'Expected a Rate_Limit_Exception.' );
		} catch ( Rate_Limit_Exception $e ) {
			$this->assertTrue( $e->is_retryable() );
		}
	}

	/**
	 * A transport error surfaces as a retryable synthesis failure.
	 */
	public function test_wp_error_maps_to_retryable_failure() {
		$http     = new Fake_HTTP_Client( array( new WP_Error( 'http_request_failed', 'connection reset' ) ) );
		$provider = new ElevenLabs_Provider( $http, 'test-key' );

		try {
			$provider->synthesize( $this->request() );
			$this->fail( 'Expected a Synthesis_Failed_Exception.' );
		} catch ( Synthesis_Failed_Exception $e ) {
			$this->assertTrue( $e->is_retryable() );
			$this->assertStringContainsString( 'connection reset', $e->getMessage() );
		}
	}

	/**
	 * A 5xx is retryable; a 4xx that is not auth or rate limiting is not.
	 *
	 * @dataProvider failure_status_provider
	 *
	 * @param int  $status    HTTP status.
	 * @param bool $retryable Whether a retry could succeed.
	 */
	public function test_failure_retryability( $status, $retryable ) {
		$http     = new Fake_HTTP_Client( array( Fake_HTTP_Client::response( '{"detail":{"message":"nope"}}', $status ) ) );
		$provider = new ElevenLabs_Provider( $http, 'test-key' );

		try {
			$provider->synthesize( $this->request() );
			$this->fail( 'Expected a Synthesis_Failed_Exception.' );
		} catch ( Synthesis_Failed_Exception $e ) {
			$this->assertEquals( $retryable, $e->is_retryable() );
			$this->assertStringContainsString( 'nope', $e->getMessage() );
		}
	}

	/**
	 * Failure statuses and whether a retry is worthwhile.
	 *
	 * @return array<string, array{0: int, 1: bool}>
	 */
	public function failure_status_provider() {
		return array(
			'bad request'   => array( 400, false ),
			'unprocessable' => array( 422, false ),
			'server error'  => array( 500, true ),
			'bad gateway'   => array( 502, true ),
		);
	}

	/**
	 * A 200 with an empty body fails instead of storing silence.
	 */
	public function test_empty_body_on_success_raises() {
		$http     = new Fake_HTTP_Client( array( Fake_HTTP_Client::response( '', 200 ) ) );
		$provider = new ElevenLabs_Provider( $http, 'test-key' );

		$this->expectException( Synthesis_Failed_Exception::class );

		$provider->synthesize( $this->request() );
	}

	/**
	 * Availability follows whether a key resolved.
	 */
	public function test_availability_tracks_key() {
		$this->assertTrue( ( new ElevenLabs_Provider( new Fake_HTTP_Client(), 'k' ) )->is_available() );
		$this->assertFalse( ( new ElevenLabs_Provider( new Fake_HTTP_Client(), '' ) )->is_available() );
	}

	/**
	 * Cost scales with character count and honours the rate filter.
	 */
	public function test_estimate_cost_scales_and_is_filterable() {
		$provider = new ElevenLabs_Provider( new Fake_HTTP_Client(), 'test-key' );

		$single = $provider->estimate_cost( 1000 );
		$double = $provider->estimate_cost( 2000 );
		$this->assertEqualsWithDelta( $single * 2, $double, 0.000001 );

		add_filter( 'prc_audio_narration_elevenlabs_rate_per_character', fn() => 0.001 );
		$this->assertEqualsWithDelta( 1.0, $provider->estimate_cost( 1000 ), 0.000001 );
	}

	/**
	 * A zero or negative character count costs nothing.
	 */
	public function test_estimate_cost_never_negative() {
		$provider = new ElevenLabs_Provider( new Fake_HTTP_Client(), 'test-key' );

		$this->assertEquals( 0.0, $provider->estimate_cost( 0 ) );
		$this->assertEquals( 0.0, $provider->estimate_cost( -500 ) );
	}

	/**
	 * The character ceiling is positive and filterable.
	 */
	public function test_max_characters_is_filterable() {
		$provider = new ElevenLabs_Provider( new Fake_HTTP_Client(), 'test-key' );

		$this->assertGreaterThan( 0, $provider->get_max_characters() );

		add_filter( 'prc_audio_narration_elevenlabs_max_characters', fn() => 1234 );
		$this->assertEquals( 1234, $provider->get_max_characters() );
	}

	/**
	 * The ceiling follows the configured model.
	 *
	 * ElevenLabs sets this per model, from 5,000 to 40,000. Using one flat
	 * number would chunk a 40,000-character model eight times more finely
	 * than it needs, and every extra chunk is another audible seam risk.
	 *
	 * @dataProvider model_ceiling_provider
	 *
	 * @param string $model    Model identifier.
	 * @param int    $expected Expected ceiling.
	 */
	public function test_max_characters_follows_model( $model, $expected ) {
		update_option(
			Settings::OPTION_KEY,
			array(
				'api_key'  => 'option-key',
				'voice_id' => 'default-voice',
				'model_id' => $model,
			)
		);

		$provider = new ElevenLabs_Provider( new Fake_HTTP_Client(), 'test-key' );

		$this->assertEquals( $expected, $provider->get_max_characters() );
	}

	/**
	 * Model ceilings as reported by the ElevenLabs /v1/models endpoint.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public function model_ceiling_provider() {
		return array(
			'v3'              => array( 'eleven_v3', 5000 ),
			'multilingual v2' => array( 'eleven_multilingual_v2', 10000 ),
			'turbo v2'        => array( 'eleven_turbo_v2', 30000 ),
			'flash v2'        => array( 'eleven_flash_v2', 30000 ),
			'turbo v2.5'      => array( 'eleven_turbo_v2_5', 40000 ),
			'flash v2.5'      => array( 'eleven_flash_v2_5', 40000 ),
		);
	}

	/**
	 * An unknown model falls back to the conservative default.
	 *
	 * Undershooting costs an extra seam; overshooting fails the whole chunk.
	 */
	public function test_unknown_model_uses_conservative_default() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'api_key'  => 'option-key',
				'voice_id' => 'default-voice',
				'model_id' => 'eleven_some_future_model',
			)
		);

		$provider = new ElevenLabs_Provider( new Fake_HTTP_Client(), 'test-key' );

		$this->assertEquals( ElevenLabs_Provider::DEFAULT_MAX_CHARACTERS, $provider->get_max_characters() );
	}

	/**
	 * The ceiling filter receives the model it is overriding.
	 */
	public function test_max_characters_filter_receives_model() {
		$seen = null;

		add_filter(
			'prc_audio_narration_elevenlabs_max_characters',
			function ( $ceiling, $model ) use ( &$seen ) {
				$seen = $model;
				return $ceiling;
			},
			10,
			2
		);

		( new ElevenLabs_Provider( new Fake_HTTP_Client(), 'test-key' ) )->get_max_characters();

		$this->assertEquals( 'eleven_multilingual_v2', $seen );
	}

	/**
	 * A nonsensical filtered ceiling falls back to the default.
	 */
	public function test_max_characters_rejects_non_positive_filter() {
		$provider = new ElevenLabs_Provider( new Fake_HTTP_Client(), 'test-key' );

		add_filter( 'prc_audio_narration_elevenlabs_max_characters', fn() => 0 );

		$this->assertEquals( ElevenLabs_Provider::DEFAULT_MAX_CHARACTERS, $provider->get_max_characters() );
	}
}
