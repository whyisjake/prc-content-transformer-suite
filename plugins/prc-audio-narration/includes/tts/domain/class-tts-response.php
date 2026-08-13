<?php
/**
 * TTS Response Value Object
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Domain;

use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\TTS_Exception;

/**
 * Immutable result of a single speech synthesis call.
 */
class TTS_Response {

	/**
	 * Raw audio payload.
	 *
	 * @var string
	 */
	private $audio;

	/**
	 * Audio MIME type.
	 *
	 * @var string
	 */
	private $mime_type;

	/**
	 * Name of the provider that produced this audio.
	 *
	 * @var string
	 */
	private $provider_name;

	/**
	 * Characters billed for this synthesis.
	 *
	 * @var int
	 */
	private $character_count;

	/**
	 * Duration in seconds, when the provider reports it.
	 *
	 * @var float|null
	 */
	private $duration;

	/**
	 * Constructor.
	 *
	 * @param string     $audio           Raw audio payload.
	 * @param string     $mime_type       Audio MIME type.
	 * @param string     $provider_name   Provider that produced the audio.
	 * @param int        $character_count Characters billed.
	 * @param float|null $duration        Duration in seconds, when known.
	 * @throws TTS_Exception When the audio payload is empty.
	 */
	public function __construct(
		string $audio,
		string $mime_type,
		string $provider_name,
		int $character_count,
		?float $duration = null
	) {
		if ( '' === $audio ) {
			// A provider can return HTTP 200 with an empty body. Catching it
			// here stops a zero-byte attachment that plays as silence.
			throw new TTS_Exception(
				sprintf( 'The %s provider returned an empty audio payload.', $provider_name ),
				'tts_empty_audio'
			);
		}

		$this->audio           = $audio;
		$this->mime_type       = $mime_type;
		$this->provider_name   = $provider_name;
		$this->character_count = $character_count;
		$this->duration        = $duration;
	}

	/**
	 * The raw audio payload.
	 *
	 * @return string
	 */
	public function get_audio(): string {
		return $this->audio;
	}

	/**
	 * The audio MIME type.
	 *
	 * @return string
	 */
	public function get_mime_type(): string {
		return $this->mime_type;
	}

	/**
	 * The provider that produced this audio.
	 *
	 * @return string
	 */
	public function get_provider_name(): string {
		return $this->provider_name;
	}

	/**
	 * Characters billed for this synthesis.
	 *
	 * @return int
	 */
	public function get_character_count(): int {
		return $this->character_count;
	}

	/**
	 * Duration in seconds, or null when the provider does not report it.
	 *
	 * @return float|null
	 */
	public function get_duration(): ?float {
		return $this->duration;
	}

	/**
	 * Byte length of the audio payload.
	 *
	 * Derived from the payload rather than taken on trust, because the podcast
	 * feed's enclosure length must match the real file exactly.
	 *
	 * @return int
	 */
	public function get_byte_length(): int {
		return strlen( $this->audio );
	}
}
