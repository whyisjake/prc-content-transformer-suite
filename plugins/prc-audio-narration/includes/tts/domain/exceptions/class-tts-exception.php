<?php
/**
 * Base TTS Exception
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Domain\Exceptions;

/**
 * Base exception for all text-to-speech errors.
 */
class TTS_Exception extends \Exception {

	/**
	 * Error identifier for semantic error handling.
	 *
	 * @var string
	 */
	protected $error_identifier;

	/**
	 * Constructor.
	 *
	 * @param string          $message          Error message.
	 * @param string          $error_identifier Semantic error identifier.
	 * @param int             $code             Error code.
	 * @param \Throwable|null $previous         Previous exception.
	 */
	public function __construct(
		string $message = '',
		string $error_identifier = 'tts_error',
		int $code = 0,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, $code, $previous );
		$this->error_identifier = $error_identifier;
	}

	/**
	 * Get the error identifier.
	 *
	 * @return string
	 */
	public function get_error_identifier(): string {
		return $this->error_identifier;
	}

	/**
	 * Whether retrying the same request could plausibly succeed.
	 *
	 * Lets the scheduler distinguish a transient rate limit from a
	 * misconfigured key, instead of retrying something that can never work.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return false;
	}

	/**
	 * Convert to WP_Error.
	 *
	 * @return \WP_Error
	 */
	public function to_wp_error(): \WP_Error {
		return new \WP_Error(
			$this->error_identifier,
			$this->getMessage(),
			array( 'code' => $this->getCode() )
		);
	}
}
