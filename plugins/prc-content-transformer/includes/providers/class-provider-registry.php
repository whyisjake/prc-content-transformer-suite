<?php
/**
 * Provider Registry.
 *
 * @package PRC\Platform\Content_Transformer\Providers
 */

namespace PRC\Platform\Content_Transformer\Providers;

/**
 * Maintains a registry of content transformation providers keyed by slug.
 */
class Provider_Registry {

	/**
	 * Registered providers.
	 *
	 * @var array<string, Provider>
	 */
	private static $providers = array();

	/**
	 * Register a provider.
	 *
	 * @param Provider $provider The provider instance.
	 */
	public static function register( Provider $provider ): void {
		self::$providers[ $provider->get_slug() ] = $provider;
	}

	/**
	 * Get a provider by slug.
	 *
	 * @param string $slug The provider slug.
	 * @return Provider|null
	 */
	public static function get( string $slug ): ?Provider {
		return self::$providers[ $slug ] ?? null;
	}

	/**
	 * Check if a provider is registered.
	 *
	 * @param string $slug The provider slug.
	 * @return bool
	 */
	public static function has( string $slug ): bool {
		return isset( self::$providers[ $slug ] );
	}

	/**
	 * Get all registered providers.
	 *
	 * @return array<string, Provider>
	 */
	public static function get_all(): array {
		return self::$providers;
	}

	/**
	 * Get all available providers (those passing is_available()).
	 *
	 * @return array<string, Provider>
	 */
	public static function get_available(): array {
		return array_filter(
			self::$providers,
			fn( Provider $provider ) => $provider->is_available()
		);
	}

	/**
	 * Reset the registry. Primarily for testing.
	 */
	public static function reset(): void {
		self::$providers = array();
	}
}
