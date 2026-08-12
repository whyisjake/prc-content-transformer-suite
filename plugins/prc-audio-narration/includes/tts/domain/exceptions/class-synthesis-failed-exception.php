<?php
/**
 * Synthesis Failed Exception
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Domain\Exceptions;

/**
 * Raised when a provider fails to synthesize audio for a valid request.
 */
class Synthesis_Failed_Exception extends TTS_Exception {

	/**
	 * Whether this failure is worth retrying.
	 *
	 * @var bool
	 */
	private $retryable;

	/**
	 * Constructor.
	 *
	 * @param string          $message   Error message.
	 * @param bool            $retryable Whether a retry could succeed.
	 * @param int             $code      Error code.
	 * @param \Throwable|null $previous  Previous exception.
	 */
	public function __construct(
		string $message = 'Speech synthesis failed.',
		bool $retryable = true,
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 'tts_synthesis_failed', $code, $previous );
		$this->retryable = $retryable;
	}

	/**
	 * Whether a retry could succeed.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return $this->retryable;
	}
}
