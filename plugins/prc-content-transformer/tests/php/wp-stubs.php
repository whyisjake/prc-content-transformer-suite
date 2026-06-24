<?php
/**
 * WordPress function and class stubs for running provider tests without WordPress loaded.
 *
 * @package PRC\Platform\Content_Transformer\Providers\Tests
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// In-memory filter registry
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Test_Filter_Registry' ) ) {
	class WP_Test_Filter_Registry {
		private static array $filters = array();

		public static function add( string $hook, callable $callback, int $priority ): void {
			self::$filters[ $hook ][ $priority ][] = $callback;
		}

		public static function apply( string $hook, mixed $value, mixed ...$args ): mixed {
			if ( ! isset( self::$filters[ $hook ] ) ) {
				return $value;
			}
			ksort( self::$filters[ $hook ] );
			foreach ( self::$filters[ $hook ] as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$value = $callback( $value, ...$args );
				}
			}
			return $value;
		}

		public static function remove_all( string $hook ): void {
			unset( self::$filters[ $hook ] );
		}
	}
}

// ---------------------------------------------------------------------------
// WP_Error stub
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private mixed $data;

		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message( string $code = '' ): string {
			return $this->message;
		}

		public function get_error_data( string $code = '' ): mixed {
			return $this->data;
		}
	}
}

// ---------------------------------------------------------------------------
// WP functions
// ---------------------------------------------------------------------------

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $flags, $depth );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		return WP_Test_Filter_Registry::apply( $hook, $value, ...$args );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): true {
		WP_Test_Filter_Registry::add( $hook, $callback, $priority );
		return true;
	}
}
