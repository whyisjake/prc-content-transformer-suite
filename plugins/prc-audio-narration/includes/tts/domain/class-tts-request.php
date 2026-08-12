<?php
/**
 * TTS Request Value Object
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Domain;

use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\TTS_Exception;

/**
 * Immutable request for a single speech synthesis call.
 *
 * One request corresponds to one chunk of a narration script. The neighbor
 * context fields carry the text immediately before and after this chunk so a
 * provider can maintain prosody across a boundary the listener should never
 * be able to hear.
 */
class TTS_Request {

	/**
	 * The text to synthesize.
	 *
	 * @var string
	 */
	private $text;

	/**
	 * Request options.
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Constructor.
	 *
	 * @param string $text    The text to synthesize.
	 * @param array  $options Optional: voice_id, output_format, preceding_text,
	 *                        following_text, model.
	 * @throws TTS_Exception When the text is blank.
	 */
	public function __construct( string $text, array $options = array() ) {
		if ( '' === trim( $text ) ) {
			throw new TTS_Exception(
				'Cannot synthesize speech from empty text.',
				'tts_empty_text'
			);
		}

		$this->text    = $text;
		$this->options = array_merge(
			array(
				'voice_id'       => '',
				'model'          => '',
				'output_format'  => 'mp3',
				'preceding_text' => '',
				'following_text' => '',
			),
			$options
		);
	}

	/**
	 * The text to synthesize.
	 *
	 * @return string
	 */
	public function get_text(): string {
		return $this->text;
	}

	/**
	 * Number of billable characters in this request.
	 *
	 * Multibyte-aware: providers bill per character, and research prose is
	 * full of curly quotes and em dashes that a byte count would overstate.
	 *
	 * @return int
	 */
	public function get_character_count(): int {
		return mb_strlen( $this->text );
	}

	/**
	 * The requested voice identifier.
	 *
	 * @return string
	 */
	public function get_voice_id(): string {
		return (string) $this->options['voice_id'];
	}

	/**
	 * The requested model identifier.
	 *
	 * @return string
	 */
	public function get_model(): string {
		return (string) $this->options['model'];
	}

	/**
	 * The requested output format.
	 *
	 * @return string
	 */
	public function get_output_format(): string {
		return (string) $this->options['output_format'];
	}

	/**
	 * Text immediately preceding this chunk, for prosody continuity.
	 *
	 * @return string
	 */
	public function get_preceding_text(): string {
		return (string) $this->options['preceding_text'];
	}

	/**
	 * Text immediately following this chunk, for prosody continuity.
	 *
	 * @return string
	 */
	public function get_following_text(): string {
		return (string) $this->options['following_text'];
	}

	/**
	 * All options.
	 *
	 * @return array
	 */
	public function get_options(): array {
		return $this->options;
	}

	/**
	 * Get a single option.
	 *
	 * @param string $key           Option key.
	 * @param mixed  $default_value Value returned when the option is unset.
	 * @return mixed
	 */
	public function get_option( string $key, $default_value = null ) {
		return $this->options[ $key ] ?? $default_value;
	}

	/**
	 * Derive a copy of this request with different text.
	 *
	 * @param string $text        Replacement text.
	 * @param array  $overrides   Option overrides to apply.
	 * @return self
	 */
	public function with_text( string $text, array $overrides = array() ): self {
		return new self( $text, array_merge( $this->options, $overrides ) );
	}
}
