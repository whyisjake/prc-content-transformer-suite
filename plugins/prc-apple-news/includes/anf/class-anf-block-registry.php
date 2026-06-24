<?php
/**
 * ANF Block Registry.
 *
 * @package PRC\Platform\Apple_News\ANF
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\ANF;

/**
 * Static registry mapping block names to ANF component callbacks.
 *
 * Other plugins register callbacks on `prc_apple_news_register_block_callbacks`
 * (init priority 5). Each callback receives the parsed block array and the
 * WP_Post being converted, and returns a list of ANF component arrays (0..n).
 */
class ANF_Block_Registry {

	/**
	 * Registered block-name → callable map.
	 *
	 * @var array<string, callable>
	 */
	private static array $callbacks = array();

	/**
	 * Register an ANF callback for a block type.
	 *
	 * @param string   $block_name Fully-qualified block name (e.g. 'core/paragraph').
	 * @param callable $callback   fn(array $block, \WP_Post $post): array
	 */
	public static function register( string $block_name, callable $callback ): void {
		self::$callbacks[ $block_name ] = $callback;
	}

	/**
	 * Get the registered callback for a block type, if any.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return callable|null
	 */
	public static function get( string $block_name ): ?callable {
		return self::$callbacks[ $block_name ] ?? null;
	}

	/**
	 * Check if a block type has a registered ANF callback.
	 *
	 * @param string $block_name Fully-qualified block name.
	 */
	public static function has( string $block_name ): bool {
		return isset( self::$callbacks[ $block_name ] );
	}

	/**
	 * Return all registered block names (useful for tests/introspection).
	 *
	 * @return string[]
	 */
	public static function all_block_names(): array {
		return array_keys( self::$callbacks );
	}

	/**
	 * Reset the registry (test use only).
	 *
	 * @internal
	 */
	public static function reset(): void {
		self::$callbacks = array();
	}
}
