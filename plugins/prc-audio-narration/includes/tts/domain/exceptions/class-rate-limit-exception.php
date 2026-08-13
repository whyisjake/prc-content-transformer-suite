<?php
/**
 * Rate Limit Exception
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Domain\Exceptions;

/**
 * Raised when a provider rate limits the request.
 */
class Rate_Limit_Exception extends TTS_Exception {

	/**
	 * Constructor.
	 *
	 * @param string          $message  Error message.
	 * @param int             $code     Error code.
	 * @param \Throwable|null $previous Previous exception.
	 */
	public function __construct(
		string $message = 'The text-to-speech provider rate limited the request.',
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 'tts_rate_limited', $code, $previous );
	}

	/**
	 * Rate limits clear on their own, so a retry is worthwhile.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return true;
	}
}
