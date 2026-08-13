<?php
/**
 * ElevenLabs TTS Provider
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Providers;

use PRC\Platform\Audio_Narration\Settings;
use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Request;
use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Response;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Authentication_Exception;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Rate_Limit_Exception;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Synthesis_Failed_Exception;
use PRC\Platform\Audio_Narration\TTS\Infrastructure\HTTP_Client;
use PRC\Platform\Audio_Narration\TTS\Infrastructure\HTTP_Client_Interface;

/**
 * Synthesizes speech through the ElevenLabs text-to-speech API.
 */
class ElevenLabs_Provider implements TTS_Provider_Interface {

	/**
	 * API base URL.
	 */
	const API_BASE = 'https://api.elevenlabs.io/v1';

	/**
	 * Per-request character ceiling by model, from the ElevenLabs /v1/models
	 * endpoint.
	 *
	 * The ceiling is a property of the model, not of the API, and the spread
	 * is wide -- 5,000 to 40,000. Treating it as one flat number forces far
	 * more requests than a model actually needs, and every extra request is
	 * another chunk boundary the listener can potentially hear.
	 *
	 * Models absent from this map fall back to DEFAULT_MAX_CHARACTERS.
	 */
	const MODEL_MAX_CHARACTERS = array(
		'eleven_v3'              => 5000,
		'eleven_multilingual_v2' => 10000,
		'eleven_turbo_v2'        => 30000,
		'eleven_flash_v2'        => 30000,
		'eleven_turbo_v2_5'      => 40000,
		'eleven_flash_v2_5'      => 40000,
	);

	/**
	 * Ceiling used for models this plugin does not know about.
	 *
	 * Deliberately conservative: exceeding a model's real limit fails the
	 * whole chunk, while undershooting only costs an extra seam.
	 */
	const DEFAULT_MAX_CHARACTERS = 5000;

	/**
	 * Default cost per character in USD.
	 *
	 * An order-of-magnitude figure for pre-generation estimates, not billing.
	 * Filterable because published rates change more often than releases do.
	 */
	const DEFAULT_RATE_PER_CHARACTER = 0.00018;

	/**
	 * HTTP client.
	 *
	 * @var HTTP_Client_Interface
	 */
	private $http;

	/**
	 * Resolved API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param HTTP_Client_Interface|null $http    HTTP client; defaults to the
	 *                                            WordPress HTTP API.
	 * @param string|null                $api_key API key; defaults to the
	 *                                            resolved credential chain.
	 */
	public function __construct( ?HTTP_Client_Interface $http = null, ?string $api_key = null ) {
		$this->http    = $http ?? new HTTP_Client();
		$this->api_key = null !== $api_key ? $api_key : Settings::resolve_api_key();
	}

	/**
	 * Provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'elevenlabs';
	}

	/**
	 * Selection priority.
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return 10;
	}

	/**
	 * Whether the provider is configured.
	 *
	 * Returning false rather than throwing keeps the plugin inert on a site
	 * that has never configured a key.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Maximum characters accepted in a single request.
	 *
	 * @return int
	 */
	public function get_max_characters(): int {
		$model   = Settings::model_id();
		$ceiling = self::MODEL_MAX_CHARACTERS[ $model ] ?? self::DEFAULT_MAX_CHARACTERS;

		/**
		 * Filter the ElevenLabs per-request character ceiling.
		 *
		 * @param int    $ceiling The ceiling for the configured model.
		 * @param string $model   The configured model identifier.
		 */
		$max = (int) apply_filters( 'prc_audio_narration_elevenlabs_max_characters', $ceiling, $model );

		return $max > 0 ? $max : self::DEFAULT_MAX_CHARACTERS;
	}

	/**
	 * Estimate cost for a character count.
	 *
	 * @param int $characters Character count.
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( int $characters ): float {
		/**
		 * Filter the per-character cost used for narration estimates.
		 *
		 * @param float $rate Cost per character in USD.
		 */
		$rate = (float) apply_filters( 'prc_audio_narration_elevenlabs_rate_per_character', self::DEFAULT_RATE_PER_CHARACTER );

		return max( 0, $characters ) * $rate;
	}

	/**
	 * Synthesize speech for a request.
	 *
	 * @param TTS_Request $request The synthesis request.
	 * @return TTS_Response
	 * @throws Authentication_Exception When credentials are missing or rejected.
	 * @throws Rate_Limit_Exception When the provider rate limits the request.
	 * @throws Synthesis_Failed_Exception On any other failure.
	 */
	public function synthesize( TTS_Request $request ): TTS_Response {
		if ( ! $this->is_available() ) {
			throw new Authentication_Exception( 'No ElevenLabs API key is configured.' );
		}

		$voice_id = $request->get_voice_id();
		if ( '' === $voice_id ) {
			$voice_id = Settings::default_voice_id();
		}

		if ( '' === $voice_id ) {
			throw new Synthesis_Failed_Exception(
				'No ElevenLabs voice is configured. Set a default voice in Settings > Audio Narration.',
				false
			);
		}

		$model = $request->get_model();
		if ( '' === $model ) {
			$model = Settings::model_id();
		}

		$body = array(
			'text'     => $request->get_text(),
			'model_id' => $model,
		);

		// Neighbor context lets the model carry intonation across a chunk
		// boundary the listener should never be able to hear.
		if ( '' !== $request->get_preceding_text() ) {
			$body['previous_text'] = $request->get_preceding_text();
		}
		if ( '' !== $request->get_following_text() ) {
			$body['next_text'] = $request->get_following_text();
		}

		$url = sprintf(
			'%s/text-to-speech/%s?output_format=%s',
			self::API_BASE,
			rawurlencode( $voice_id ),
			rawurlencode( $this->output_format( $request->get_output_format() ) )
		);

		$response = $this->http->post(
			$url,
			array(
				'headers' => array(
					'xi-api-key'   => $this->api_key,
					'Content-Type' => 'application/json',
					'Accept'       => 'audio/mpeg',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			// A transport failure is worth retrying; it says nothing about
			// whether the request itself was valid.
			throw new Synthesis_Failed_Exception(
				sprintf( 'ElevenLabs request failed: %s', $response->get_error_message() ),
				true
			);
		}

		$status = $this->http->get_response_code( $response );
		$body   = $this->http->get_response_body( $response );

		if ( 401 === $status || 403 === $status ) {
			throw new Authentication_Exception(
				sprintf( 'ElevenLabs rejected the API key (HTTP %d).', $status )
			);
		}

		if ( 429 === $status ) {
			throw new Rate_Limit_Exception(
				'ElevenLabs rate limited the request (HTTP 429).'
			);
		}

		if ( ! $this->http->is_success( $response ) ) {
			throw new Synthesis_Failed_Exception(
				sprintf(
					'ElevenLabs returned HTTP %d: %s',
					$status,
					$this->extract_error_message( $body )
				),
				// 5xx is transient; a 4xx other than auth or rate limiting
				// means the request itself is wrong and will fail again.
				$status >= 500
			);
		}

		if ( '' === $body ) {
			throw new Synthesis_Failed_Exception(
				'ElevenLabs returned a successful response with no audio.',
				true
			);
		}

		return new TTS_Response(
			$body,
			'audio/mpeg',
			$this->get_name(),
			$request->get_character_count()
		);
	}

	/**
	 * Map a generic output format to an ElevenLabs format identifier.
	 *
	 * @param string $format Generic format name.
	 * @return string
	 */
	private function output_format( string $format ): string {
		$map = array(
			'mp3' => 'mp3_44100_128',
		);

		/**
		 * Filter the map of generic output formats to ElevenLabs identifiers.
		 *
		 * @param array $map Format map.
		 */
		$map = apply_filters( 'prc_audio_narration_elevenlabs_output_formats', $map );

		return $map[ $format ] ?? $format;
	}

	/**
	 * Pull a human-readable message out of an error response body.
	 *
	 * @param string $body Raw response body.
	 * @return string
	 */
	private function extract_error_message( string $body ): string {
		if ( '' === $body ) {
			return 'no response body';
		}

		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['detail']['message'] ) && is_string( $decoded['detail']['message'] ) ) {
				return $decoded['detail']['message'];
			}
			if ( isset( $decoded['detail'] ) && is_string( $decoded['detail'] ) ) {
				return $decoded['detail'];
			}
			if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
				return $decoded['message'];
			}
		}

		return mb_substr( $body, 0, 200 );
	}
}
