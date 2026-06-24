<?php
/**
 * ANF referential-integrity validator.
 *
 * Ensures string layout, textStyle, and style references on components
 * resolve to keys in the document-level registries before Apple API push.
 *
 * @package PRC\Platform\Apple_News\ANF
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\ANF;

use WP_Error;

/**
 * Validates ANF document referential integrity for named style/layout references.
 */
class ANF_Referential_Validator {

	/**
	 * Validate a raw ANF JSON string for referential integrity.
	 *
	 * @param string $json_string Raw ANF JSON string.
	 * @return true|WP_Error True on success; WP_Error with error list on failure.
	 */
	public function validate_json( string $json_string ): true|WP_Error {
		$decoded = json_decode( $json_string );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $decoded ) ) {
			return new WP_Error(
				'anf_json_invalid',
				'ANF document is not valid JSON: ' . json_last_error_msg()
			);
		}

		return $this->validate( $decoded );
	}

	/**
	 * Validate a decoded ANF document object for referential integrity.
	 *
	 * @param object $document Decoded ANF JSON object.
	 * @return true|WP_Error True on success; WP_Error with error list on failure.
	 */
	public function validate( object $document ): true|WP_Error {
		$layouts     = $this->registry_keys( $document, 'componentLayouts' );
		$text_styles = $this->registry_keys( $document, 'componentTextStyles' );
		$styles      = $this->registry_keys( $document, 'componentStyles' );

		$errors = array();
		$this->walk_components(
			$document->components ?? array(),
			$layouts,
			$text_styles,
			$styles,
			array(),
			$errors
		);

		if ( empty( $errors ) ) {
			return true;
		}

		$messages = array_map(
			static fn( array $error ): string => sprintf(
				'%s "%s" not found at %s',
				$error['type'],
				$error['id'],
				implode( '->', $error['keyPath'] )
			),
			$errors
		);

		return new WP_Error(
			'anf_referential_error',
			'ANF referential validation failed: ' . implode( '; ', $messages ),
			array( 'errors' => $errors )
		);
	}

	/**
	 * @param object $document   Decoded ANF document.
	 * @param string $property   Registry property name.
	 * @return array<string, true>
	 */
	private function registry_keys( object $document, string $property ): array {
		$registry = $document->{$property} ?? null;
		if ( ! is_object( $registry ) ) {
			return array();
		}

		$keys = array();
		foreach ( get_object_vars( $registry ) as $key => $value ) {
			$keys[ (string) $key ] = true;
		}

		return $keys;
	}

	/**
	 * Recursively walk components and collect missing reference errors.
	 *
	 * @param mixed              $components   Component list.
	 * @param array<string,true> $layouts      Known layout keys.
	 * @param array<string,true> $text_styles  Known text style keys.
	 * @param array<string,true> $styles       Known component style keys.
	 * @param array<int,string>  $key_path     Current component index path.
	 * @param array<int,array<string,mixed>> $errors Collected errors (by reference).
	 */
	private function walk_components(
		mixed $components,
		array $layouts,
		array $text_styles,
		array $styles,
		array $key_path,
		array &$errors
	): void {
		if ( ! is_array( $components ) ) {
			return;
		}

		foreach ( $components as $index => $component ) {
			if ( ! is_object( $component ) ) {
				continue;
			}

			$path = array_merge( $key_path, array( 'components', (string) $index ) );

			if ( isset( $component->layout ) && is_string( $component->layout ) && ! isset( $layouts[ $component->layout ] ) ) {
				$errors[] = array(
					'type'    => 'layout',
					'id'      => $component->layout,
					'keyPath' => array_merge( $path, array( 'layout' ) ),
				);
			}

			if ( isset( $component->textStyle ) && is_string( $component->textStyle ) && ! isset( $text_styles[ $component->textStyle ] ) ) {
				$errors[] = array(
					'type'    => 'textStyle',
					'id'      => $component->textStyle,
					'keyPath' => array_merge( $path, array( 'textStyle' ) ),
				);
			}

			if ( isset( $component->style ) && is_string( $component->style ) && ! isset( $styles[ $component->style ] ) ) {
				$errors[] = array(
					'type'    => 'style',
					'id'      => $component->style,
					'keyPath' => array_merge( $path, array( 'style' ) ),
				);
			}

			if ( isset( $component->components ) ) {
				$this->walk_components(
					$component->components,
					$layouts,
					$text_styles,
					$styles,
					$path,
					$errors
				);
			}
		}
	}
}
