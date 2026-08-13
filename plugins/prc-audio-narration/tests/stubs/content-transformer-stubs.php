<?php
/**
 * Minimal stand-ins for prc-content-transformer's public contract.
 *
 * The audio script provider implements an interface owned by another plugin.
 * When the test suite runs without prc-content-transformer present, these
 * stubs supply just enough of that contract for the provider to be declared
 * and registered. They are skipped entirely when the real plugin is loaded,
 * so tests exercise the genuine contract wherever it is available.
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Content_Transformer\Providers;

if ( ! interface_exists( __NAMESPACE__ . '\Provider' ) ) {
	/**
	 * Contract for all content transformation providers.
	 */
	interface Provider {
		/**
		 * Human-readable provider name.
		 *
		 * @return string
		 */
		public function get_name(): string;

		/**
		 * URL-safe slug used as the provider identifier.
		 *
		 * @return string
		 */
		public function get_slug(): string;

		/**
		 * The format specification document content.
		 *
		 * @return string
		 */
		public function get_format_spec(): string;

		/**
		 * The output content type.
		 *
		 * @return string
		 */
		public function get_output_type(): string;

		/**
		 * Validate the AI-generated output.
		 *
		 * @param string $output The raw AI output.
		 * @return bool
		 */
		public function validate( string $output ): bool;

		/**
		 * Post-process the AI output before caching.
		 *
		 * @param string $output The raw AI output.
		 * @return string
		 */
		public function post_process( string $output ): string;

		/**
		 * Whether this provider is available for use.
		 *
		 * @return bool
		 */
		public function is_available(): bool;
	}
}

if ( ! class_exists( __NAMESPACE__ . '\Provider_Registry' ) ) {
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
		 * Reset the registry. Primarily for testing.
		 */
		public static function reset(): void {
			self::$providers = array();
		}
	}
}
