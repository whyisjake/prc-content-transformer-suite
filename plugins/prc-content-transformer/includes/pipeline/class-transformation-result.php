<?php
/**
 * Transformation Result value object.
 *
 * @package PRC\Platform\Content_Transformer\Pipeline
 */

namespace PRC\Platform\Content_Transformer\Pipeline;

/**
 * Immutable value object representing the outcome of a content transformation.
 */
class Transformation_Result {

	/**
	 * @var string The transformation status: 'success', 'failed', 'pending', 'cached'.
	 */
	private $status;

	/**
	 * @var string The transformed output content.
	 */
	private $output;

	/**
	 * @var string The provider slug that produced this result.
	 */
	private $provider;

	/**
	 * @var int Unix timestamp of when the transformation completed.
	 */
	private $timestamp;

	/**
	 * @var int Estimated token usage for the AI call.
	 */
	private $tokens_used;

	/**
	 * @var string Error message, if any.
	 */
	private $error;

	/**
	 * @param string $status      The transformation status.
	 * @param string $output      The transformed content.
	 * @param string $provider    The provider slug.
	 * @param int    $timestamp   Unix timestamp.
	 * @param int    $tokens_used Estimated tokens consumed.
	 * @param string $error       Error message on failure.
	 */
	public function __construct(
		string $status,
		string $output = '',
		string $provider = '',
		int $timestamp = 0,
		int $tokens_used = 0,
		string $error = ''
	) {
		$this->status      = $status;
		$this->output      = $output;
		$this->provider    = $provider;
		$this->timestamp   = $timestamp ?: time();
		$this->tokens_used = $tokens_used;
		$this->error       = $error;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_output(): string {
		return $this->output;
	}

	public function get_provider(): string {
		return $this->provider;
	}

	public function get_timestamp(): int {
		return $this->timestamp;
	}

	public function get_tokens_used(): int {
		return $this->tokens_used;
	}

	public function get_error(): string {
		return $this->error;
	}

	public function is_success(): bool {
		return 'success' === $this->status || 'cached' === $this->status;
	}

	/**
	 * Serialize to an array (for post meta storage and REST responses).
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'status'      => $this->status,
			'output'      => $this->output,
			'provider'    => $this->provider,
			'timestamp'   => $this->timestamp,
			'tokens_used' => $this->tokens_used,
			'error'       => $this->error,
		);
	}

	/**
	 * Reconstitute from a stored array.
	 *
	 * @param array $data The serialized result data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		return new self(
			$data['status'] ?? 'failed',
			$data['output'] ?? '',
			$data['provider'] ?? '',
			$data['timestamp'] ?? 0,
			$data['tokens_used'] ?? 0,
			$data['error'] ?? ''
		);
	}
}
