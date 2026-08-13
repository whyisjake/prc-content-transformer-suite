<?php
/**
 * Provider Unavailable Exception
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Domain\Exceptions;

/**
 * Raised when no text-to-speech provider is configured and available.
 */
class Provider_Unavailable_Exception extends TTS_Exception {

	/**
	 * Constructor.
	 *
	 * @param string          $message  Error message.
	 * @param int             $code     Error code.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message = 'No text-to-speech provider is available.',
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 'tts_provider_unavailable', $code, $previous );
	}
}
