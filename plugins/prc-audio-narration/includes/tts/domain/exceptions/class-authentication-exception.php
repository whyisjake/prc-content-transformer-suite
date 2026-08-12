<?php
/**
 * Authentication Exception
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Domain\Exceptions;

/**
 * Raised when a provider rejects the supplied credentials.
 */
class Authentication_Exception extends TTS_Exception {

	/**
	 * Constructor.
	 *
	 * @param string          $message  Error message.
	 * @param int             $code     Error code.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message = 'The text-to-speech provider rejected the API credentials.',
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 'tts_authentication_failed', $code, $previous );
	}
}
