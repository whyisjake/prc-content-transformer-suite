<?php
/**
 * OCR Response Value Object
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Domain;

/**
 * Immutable response object for OCR operations
 */
class OCR_Response {
	/**
	 * Whether the extraction was successful
	 *
	 * @var bool
	 */
	private $success;

	/**
	 * Extracted plain text
	 *
	 * @var string
	 */
	private $text;

	/**
	 * Extracted text in markdown format
	 *
	 * @var string
	 */
	private $markdown;

	/**
	 * Extracted text as Gutenberg block HTML
	 *
	 * @var string
	 */
	private $gutenberg;

	/**
	 * Confidence score (0-1)
	 *
	 * @var float
	 */
	private $confidence;

	/**
	 * Provider name that performed the extraction
	 *
	 * @var string
	 */
	private $provider;

	/**
	 * Estimated cost in USD
	 *
	 * @var float
	 */
	private $cost;

	/**
	 * Raw response data from provider
	 *
	 * @var array
	 */
	private $raw_data;

	/**
	 * Constructor
	 *
	 * @param bool   $success Whether extraction was successful.
	 * @param string $text Extracted plain text.
	 * @param string $markdown Extracted text in markdown.
	 * @param string $gutenberg Extracted text as Gutenberg block HTML.
	 * @param float  $confidence Confidence score (0-1).
	 * @param string $provider Provider name.
	 * @param float  $cost Estimated cost in USD.
	 * @param array  $raw_data Raw response data.
	 */
	public function __construct(
		bool $success,
		string $text = '',
		string $markdown = '',
		string $gutenberg = '',
		float $confidence = 0.0,
		string $provider = '',
		float $cost = 0.0,
		array $raw_data = array()
	) {
		$this->success    = $success;
		$this->text       = $text;
		$this->markdown   = $markdown;
		$this->gutenberg  = $gutenberg;
		$this->confidence = $confidence;
		$this->provider   = $provider;
		$this->cost       = $cost;
		$this->raw_data   = $raw_data;
	}

	/**
	 * Check if the extraction was successful
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->success;
	}

	/**
	 * Get the extracted plain text
	 *
	 * @return string
	 */
	public function get_text(): string {
		return $this->text;
	}

	/**
	 * Get the extracted markdown text
	 *
	 * @return string
	 */
	public function get_markdown(): string {
		return $this->markdown;
	}

	/**
	 * Get the extracted Gutenberg block HTML
	 *
	 * @return string
	 */
	public function get_gutenberg(): string {
		return $this->gutenberg;
	}

	/**
	 * Get the confidence score
	 *
	 * @return float
	 */
	public function get_confidence(): float {
		return $this->confidence;
	}

	/**
	 * Get the provider name
	 *
	 * @return string
	 */
	public function get_provider(): string {
		return $this->provider;
	}

	/**
	 * Get the estimated cost
	 *
	 * @return float
	 */
	public function get_cost(): float {
		return $this->cost;
	}

	/**
	 * Get the raw response data
	 *
	 * @return array
	 */
	public function get_raw_data(): array {
		return $this->raw_data;
	}

	/**
	 * Get character count of extracted text
	 *
	 * @return int
	 */
	public function get_character_count(): int {
		return strlen( $this->text );
	}
}
