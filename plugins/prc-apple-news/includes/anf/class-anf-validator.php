<?php
/**
 * ANF Validator class.
 *
 * Validates an Apple News Format document against the vendored JSON Schema
 * (Lonely Planet ANF schema, Draft 06) using justinrainbow/json-schema.
 *
 * @package PRC\Platform\Apple_News\ANF
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\ANF;

use JsonSchema\Validator;
use JsonSchema\SchemaStorage;
use JsonSchema\Uri\UriRetriever;
use JsonSchema\Uri\UriResolver;
use JsonSchema\Constraints\Constraint;
use WP_Error;

/**
 * Validates ANF documents against the Apple News Format JSON Schema.
 */
class ANF_Validator {

	/**
	 * Absolute path to the vendored ANF JSON Schema file.
	 */
	private string $schema_path;

	public function __construct() {
		$this->schema_path = __DIR__ . '/schema/anf-article.json';
	}

	/**
	 * Validate a decoded ANF document (PHP array) against the schema.
	 *
	 * @param array $anf_document Decoded ANF JSON as a PHP array.
	 * @return true|WP_Error true on success; WP_Error with validation messages on failure.
	 */
	public function validate( array $anf_document ): true|WP_Error {
		if ( ! file_exists( $this->schema_path ) ) {
			return new WP_Error(
				'anf_schema_missing',
				'ANF schema file not found: ' . $this->schema_path
			);
		}

		// The library validates objects, not arrays — convert the document.
		$document_object = json_decode( (string) json_encode( $anf_document ) );

		return $this->run_validation( $document_object );
	}

	/**
	 * Validate a raw ANF JSON string against the schema.
	 *
	 * @param string $json_string Raw ANF JSON string.
	 * @return true|WP_Error true on success; WP_Error with validation messages on failure.
	 */
	public function validate_json( string $json_string ): true|WP_Error {
		$decoded = json_decode( $json_string );

		if ( JSON_ERROR_NONE !== json_last_error() || null === $decoded ) {
			return new WP_Error(
				'anf_json_invalid',
				'ANF document is not valid JSON: ' . json_last_error_msg()
			);
		}

		if ( ! file_exists( $this->schema_path ) ) {
			return new WP_Error(
				'anf_schema_missing',
				'ANF schema file not found: ' . $this->schema_path
			);
		}

		return $this->run_validation( $decoded );
	}

	/**
	 * Run schema validation against a decoded stdClass document object.
	 *
	 * @param object $document_object Decoded JSON as stdClass.
	 * @return true|WP_Error
	 */
	private function run_validation( object $document_object ): true|WP_Error {
		$retriever     = new UriRetriever();
		$resolver      = new UriResolver();
		$schema_storage = new SchemaStorage( $retriever, $resolver );

		$schema_uri = 'file://' . realpath( $this->schema_path );
		$schema     = $schema_storage->getSchema( $schema_uri );

		$validator = new Validator();
		$validator->validate( $document_object, $schema, Constraint::CHECK_MODE_TYPE_CAST );

		if ( $validator->isValid() ) {
			return true;
		}

		$messages = array_map(
			static function ( array $error ): string {
				$prop = $error['property'] ?? 'document';
				return "[{$prop}] {$error['message']}";
			},
			$validator->getErrors()
		);

		return new WP_Error(
			'anf_schema_error',
			implode( '; ', $messages ),
			array( 'errors' => $validator->getErrors() )
		);
	}
}
