<?php
/**
 * ANF Block Resolver.
 *
 * @package PRC\Platform\Apple_News\ANF
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\ANF;

use WP_Block_Type_Registry;

/**
 * Resolves block-level ANF behavior from block.json metadata.
 *
 * Blocks may declare a `prcAppleNewsAnf` key in block.json that is injected
 * into `supports` at registration time.
 */
class ANF_Block_Resolver {

	/**
	 * Inject prcAppleNewsAnf block metadata into supports so it survives registration.
	 *
	 * @param array $metadata Raw block metadata.
	 * @return array
	 */
	public static function inject_metadata_into_supports( array $metadata ): array {
		if ( empty( $metadata['prcAppleNewsAnf'] ) || ! is_array( $metadata['prcAppleNewsAnf'] ) ) {
			return $metadata;
		}

		if ( ! isset( $metadata['supports'] ) || ! is_array( $metadata['supports'] ) ) {
			$metadata['supports'] = array();
		}

		$metadata['supports']['prcAppleNewsAnf'] = $metadata['prcAppleNewsAnf'];

		return $metadata;
	}

	/**
	 * Get the prcAppleNewsAnf config for a registered block, if any.
	 *
	 * @param string $block_name Block name.
	 * @return array|null
	 */
	public static function get_block_config( string $block_name ): ?array {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
		if ( ! $block_type || ! isset( $block_type->supports ) || ! is_array( $block_type->supports ) ) {
			return null;
		}

		$config = $block_type->supports['prcAppleNewsAnf'] ?? null;
		if ( ! is_array( $config ) ) {
			return null;
		}

		return $config;
	}

	/**
	 * Resolve a block's ANF handling strategy from block.json metadata.
	 *
	 * Returns an array with:
	 *   handled    (bool)  — whether this resolver claimed the block
	 *   recurse    (bool)  — whether the caller should recurse into innerBlocks
	 *   components (array) — produced ANF components (may be empty)
	 *
	 * @param string   $block_name Block name.
	 * @param array    $block      Parsed block array.
	 * @param \WP_Post $post       Post being converted.
	 * @return array{handled:bool,recurse:bool,components:array<int,array<string,mixed>>}
	 */
	public static function resolve_strategy( string $block_name, array $block, \WP_Post $post ): array {
		$config = self::get_block_config( $block_name );
		if ( null === $config ) {
			return array(
				'handled'    => false,
				'recurse'    => false,
				'components' => array(),
			);
		}

		$mode = isset( $config['mode'] ) ? sanitize_key( (string) $config['mode'] ) : 'html-fallback';

		if ( 'strip' === $mode ) {
			return array(
				'handled'    => true,
				'recurse'    => false,
				'components' => array(),
			);
		}

		if ( 'children-only' === $mode ) {
			return array(
				'handled'    => true,
				'recurse'    => true,
				'components' => array(),
			);
		}

		$callback = $config['callback'] ?? null;
		if ( is_string( $callback ) && is_callable( $callback ) ) {
			$components = call_user_func( $callback, $block, $post );
			if ( ! is_array( $components ) ) {
				$components = array();
			}

			return array(
				'handled'    => true,
				'recurse'    => false,
				'components' => $components,
			);
		}

		return array(
			'handled'    => false,
			'recurse'    => false,
			'components' => array(),
		);
	}
}
