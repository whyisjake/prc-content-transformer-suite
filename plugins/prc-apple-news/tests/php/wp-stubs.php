<?php
/**
 * WordPress function and class stubs for running ANF tests without WordPress loaded.
 *
 * @package PRC\Platform\Apple_News\ANF\Tests
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// In-memory post meta store
// ---------------------------------------------------------------------------

class WP_Test_Meta_Store {
	private static array $store = array();

	public static function set( int $post_id, string $key, mixed $value ): void {
		self::$store[ $post_id ][ $key ] = $value;
	}

	public static function get( int $post_id, string $key ): mixed {
		return self::$store[ $post_id ][ $key ] ?? '';
	}

	public static function delete( int $post_id, string $key ): void {
		unset( self::$store[ $post_id ][ $key ] );
	}

	public static function reset(): void {
		self::$store = array();
	}
}

// ---------------------------------------------------------------------------
// In-memory filter registry (supports add_filter / apply_filters / remove_all_filters)
// ---------------------------------------------------------------------------

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

	public static function reset(): void {
		self::$filters = array();
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

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_parse_str' ) ) {
	function wp_parse_str( string $string, mixed &$result ): void {
		$result = array();
		parse_str( $string, $result );
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int|WP_Post|null $post = null ): string {
		if ( $post instanceof WP_Post ) {
			return $post->post_title;
		}
		if ( null !== $post ) {
			$stored = WP_Test_Meta_Store::get( $post, '_stub_post_title' );
			if ( '' !== $stored ) {
				return (string) $stored;
			}
		}
		return 'Test Apple News Article';
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( int $post = 0 ): string|false {
		if ( $post > 0 ) {
			$stored = WP_Test_Meta_Store::get( $post, '_stub_post_permalink' );
			if ( '' !== $stored ) {
				return (string) $stored;
			}
		}
		return 'https://www.pewresearch.org/?p=' . $post;
	}
}

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( int $post = 0 ): string|false {
		if ( $post > 0 ) {
			$stored = WP_Test_Meta_Store::get( $post, '_stub_post_status' );
			if ( '' !== $stored ) {
				return (string) $stored;
			}
		}
		return 'publish';
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
	function get_the_post_thumbnail_url( int|null $post = null, string $size = 'post-thumbnail' ): string|false {
		return false;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $flags, $depth );
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type(): string {
		return 'development';
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

if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( string $hook, bool|int $priority = false ): true {
		WP_Test_Filter_Registry::remove_all( $hook );
		return true;
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( string $field, int $post_id, string $context = 'raw' ): string {
		if ( 'post_name' === $field ) {
			return 'test-post-slug';
		}
		return '';
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		return WP_Test_Meta_Store::get( $post_id, $key );
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): int|bool {
		WP_Test_Meta_Store::set( $post_id, $meta_key, $meta_value );
		return true;
	}
}

if ( ! function_exists( 'delete_post_meta' ) ) {
	function delete_post_meta( int $post_id, string $meta_key, mixed $meta_value = '' ): bool {
		WP_Test_Meta_Store::delete( $post_id, $meta_key );
		return true;
	}
}

if ( ! function_exists( 'get_the_excerpt' ) ) {
	function get_the_excerpt( int|null $post = null ): string {
		return WP_Test_Filter_Registry::apply( 'get_the_excerpt', '' );
	}
}

if ( ! function_exists( 'wp_delete_post' ) ) {
	function wp_delete_post( int $post_id, bool $force_delete = false ): mixed {
		WP_Test_Meta_Store::delete( $post_id, '' );
		return true;
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 1;
		public string $post_title = 'Test Article';
		public string $post_content = '';
		public string $post_status = 'publish';
		public string $post_type = 'post';

		public function __construct( array $data = array() ) {
			foreach ( $data as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}

if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	class WP_Block_Type_Registry {
		private static ?WP_Block_Type_Registry $instance = null;

		public static function get_instance(): WP_Block_Type_Registry {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		public function get_registered( string $name ): ?object {
			return null;
		}
	}
}

class WP_Test_Post_Store {
	private static array $posts = array();

	public static function set( int $post_id, WP_Post $post ): void {
		self::$posts[ $post_id ] = $post;
	}

	public static function get( int $post_id ): ?WP_Post {
		return self::$posts[ $post_id ] ?? null;
	}

	public static function reset(): void {
		self::$posts = array();
	}
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( int|WP_Post|null $post = null, string $output = OBJECT, string $filter = 'raw' ): WP_Post|null {
		if ( $post instanceof WP_Post ) {
			return $post;
		}
		$post_id = (int) $post;
		if ( $post_id <= 0 ) {
			return null;
		}
		return WP_Test_Post_Store::get( $post_id );
	}
}

if ( ! function_exists( 'setup_postdata' ) ) {
	function setup_postdata( WP_Post|array $post ): true {
		return true;
	}
}

if ( ! function_exists( 'wp_reset_postdata' ) ) {
	function wp_reset_postdata(): void {
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( string $content ): array {
		$decoded = json_decode( $content, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}

if ( ! function_exists( 'render_block' ) ) {
	function render_block( array $block ): string {
		return (string) ( $block['innerHTML'] ?? '' );
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( int $attachment_id ): string|false {
		return 'https://cdn.example.com/image-' . $attachment_id . '.jpg';
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return strip_tags( $text );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) );
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		WP_Test_Filter_Registry::apply( $hook, null, ...$args );
	}
}

if ( ! function_exists( 'error_log' ) ) {
	function error_log( string $message ): void {
	}
}
