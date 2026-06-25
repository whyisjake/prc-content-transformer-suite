<?php
/**
 * OCR Request Value Object
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction\OCR\Domain;

/**
 * Immutable request object for OCR operations
 */
class OCR_Request {
	/**
	 * Path to the file to extract text from
	 *
	 * @var string
	 */
	private $file_path;

	/**
	 * Optional request options
	 *
	 * @var array
	 */
	private $options;

	/**
	 * Constructor
	 *
	 * @param string $file_path Path to the file.
	 * @param array  $options Optional request options (language, etc.).
	 */
	public function __construct( string $file_path, array $options = array() ) {
		$this->file_path = $file_path;
		$this->options   = wp_parse_args(
			$options,
			array(
				'language' => 'en',
			)
		);
	}

	/**
	 * Get the file path
	 *
	 * @return string
	 */
	public function get_file_path(): string {
		return $this->file_path;
	}

	/**
	 * Get all options
	 *
	 * @return array
	 */
	public function get_options(): array {
		return $this->options;
	}

	/**
	 * Get a specific option
	 *
	 * @param string $key Option key.
	 * @param mixed  $default Default value if option not set.
	 * @return mixed Option value or default.
	 */
	public function get_option( string $key, $default = null ) {
		return $this->options[ $key ] ?? $default;
	}

	/**
	 * Get the language option
	 *
	 * @return string
	 */
	public function get_language(): string {
		return $this->get_option( 'language', 'en' );
	}
}
