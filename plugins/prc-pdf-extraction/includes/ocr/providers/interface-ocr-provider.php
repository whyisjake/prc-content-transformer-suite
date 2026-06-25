<?php
/**
 * OCR Provider Interface
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Providers;

use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Request;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Response;

/**
 * Contract for all OCR providers
 */
interface OCR_Provider_Interface {
	/**
	 * Extract text from a PDF
	 *
	 * @param OCR_Request $request OCR request.
	 * @return OCR_Response Response object.
	 * @throws \PRC\Platform\PDF_Extraction\OCR\Domain\Exceptions\OCR_Exception On failure.
	 */
	public function extract_text( OCR_Request $request ): OCR_Response;

	/**
	 * Estimate cost for extracting text from a file
	 *
	 * @param string $file_path Path to the file.
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( string $file_path ): float;

	/**
	 * Check if the provider is available (configured and ready)
	 *
	 * @return bool True if available, false otherwise.
	 */
	public function is_available(): bool;

	/**
	 * Get the provider priority (lower number = higher priority)
	 *
	 * @return int Priority value.
	 */
	public function get_priority(): int;

	/**
	 * Get the provider name
	 *
	 * @return string Provider name.
	 */
	public function get_name(): string;
}
