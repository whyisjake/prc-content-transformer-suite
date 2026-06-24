<?php
/**
 * Settings class.
 *
 * Provides admin settings page and REST API endpoints for Apple News configuration.
 *
 * @package PRC\Platform\Apple_News
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings class.
 *
 * Registers the WP admin settings page, enqueues the React app,
 * and exposes REST endpoints for reading and writing Apple News credentials.
 *
 * @package PRC\Platform\Apple_News
 */
class Settings {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const ADMIN_PAGE_SLUG = 'prc-apple-news-settings';

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'prc-apple-news/v1';

	/**
	 * Default Apple News channel UUID. Set to empty string; configure via the
	 * settings page (Settings → Apple News) after activation.
	 *
	 * @var string
	 */
	const DEFAULT_CHANNEL_UUID = '';

	/**
	 * WP option key for API key.
	 *
	 * @var string
	 */
	const OPTION_API_KEY = 'prc_apple_news_api_key';

	/**
	 * WP option key for API secret.
	 *
	 * @var string
	 */
	const OPTION_API_SECRET = 'prc_apple_news_api_secret';

	/**
	 * WP option key for channel UUID.
	 *
	 * @var string
	 */
	const OPTION_CHANNEL_UUID = 'prc_apple_news_channel_uuid';

	/**
	 * Constructor. Registers hooks via the loader.
	 *
	 * @param Loader $loader The plugin hook loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'admin_menu', $this, 'register_page' );
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets' );
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	// -------------------------------------------------------------------------
	// Admin page
	// -------------------------------------------------------------------------

	/**
	 * Register the options page under Settings menu.
	 *
	 * @hook admin_menu
	 */
	public function register_page(): void {
		add_options_page(
			__( 'Apple News', 'prc-apple-news' ),
			__( 'Apple News', 'prc-apple-news' ),
			'manage_options',
			self::ADMIN_PAGE_SLUG,
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Render the settings page shell (React mounts here).
	 */
	public function render_page(): void {
		echo '<div class="wrap"><div id="prc-apple-news-settings"></div></div>';
	}

	/**
	 * Enqueue the settings JS/CSS bundle on the settings page.
	 *
	 * @hook admin_enqueue_scripts
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::ADMIN_PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_file = PRC_APPLE_NEWS_DIR . '/build/settings/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset  = require $asset_file;
		$handle = 'prc-apple-news-settings';

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/settings/index.js', PRC_APPLE_NEWS_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( file_exists( PRC_APPLE_NEWS_DIR . '/build/settings/style-index.css' ) ) {
			wp_enqueue_style(
				$handle,
				plugins_url( 'build/settings/style-index.css', PRC_APPLE_NEWS_FILE ),
				[ 'wp-components' ],
				$asset['version']
			);
		}
	}

	// -------------------------------------------------------------------------
	// REST routes
	// -------------------------------------------------------------------------

	/**
	 * Register REST API routes.
	 *
	 * @hook rest_api_init
	 */
	public function register_routes(): void {
		// GET/POST /prc-apple-news/v1/settings
		register_rest_route(
			self::REST_NAMESPACE,
			'/settings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings_endpoint' ],
					'permission_callback' => fn(): bool => current_user_can( 'manage_options' ),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'save_settings_endpoint' ],
					'permission_callback' => fn(): bool => current_user_can( 'manage_options' ),
					'args'                => [
						'api_key'      => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'api_secret'   => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
						'channel_uuid' => [
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						],
					],
				],
			]
		);

		// GET /prc-apple-news/v1/test
		register_rest_route(
			self::REST_NAMESPACE,
			'/test',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'test_connection_endpoint' ],
				'permission_callback' => fn(): bool => current_user_can( 'manage_options' ),
			]
		);
	}

	/**
	 * GET /prc-apple-news/v1/settings
	 *
	 * Returns api_key, channel_uuid, and has_secret flag.
	 * The API secret is NEVER returned in plain text.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings_endpoint(): \WP_REST_Response {
		$api_key      = (string) get_option( self::OPTION_API_KEY, '' );
		$api_secret   = (string) get_option( self::OPTION_API_SECRET, '' );
		$channel_uuid = self::get_channel_uuid();

		return rest_ensure_response( [
			'api_key'      => $api_key,
			'channel_uuid' => $channel_uuid,
			'has_secret'   => ! empty( $api_secret ),
		] );
	}

	/**
	 * POST /prc-apple-news/v1/settings
	 *
	 * Saves api_key, api_secret (if non-empty), and channel_uuid.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function save_settings_endpoint( \WP_REST_Request $request ): \WP_REST_Response {
		$api_key      = $request->get_param( 'api_key' );
		$api_secret   = $request->get_param( 'api_secret' );
		$channel_uuid = $request->get_param( 'channel_uuid' );

		if ( null !== $api_key ) {
			update_option( self::OPTION_API_KEY, sanitize_text_field( $api_key ) );
		}

		// Only update the secret when a non-empty value is explicitly provided.
		if ( ! empty( $api_secret ) ) {
			update_option( self::OPTION_API_SECRET, sanitize_text_field( $api_secret ) );
		}

		if ( ! empty( $channel_uuid ) ) {
			update_option( self::OPTION_CHANNEL_UUID, sanitize_text_field( $channel_uuid ) );
		}

		return $this->get_settings_endpoint();
	}

	/**
	 * GET /prc-apple-news/v1/test
	 *
	 * Tests the stored credentials by calling the Apple News API.
	 *
	 * @return \WP_REST_Response
	 */
	public function test_connection_endpoint(): \WP_REST_Response {
		$credentials = self::get_credentials();

		if ( null === $credentials ) {
			return rest_ensure_response( [
				'success'      => false,
				'channel_name' => null,
				'error'        => __( 'API key and secret are required.', 'prc-apple-news' ),
			] );
		}

		$api    = new Apple_News_API\API( $credentials );
		$result = $api->get_channel( $credentials->channel_uuid() );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response( [
				'success'      => false,
				'channel_name' => null,
				'error'        => $result->get_error_message(),
			] );
		}

		$channel_name = $result->data->name ?? null;

		return rest_ensure_response( [
			'success'      => true,
			'channel_name' => $channel_name,
			'error'        => null,
		] );
	}

	// -------------------------------------------------------------------------
	// Static helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a Credentials object from stored WP options.
	 *
	 * Returns null when either the API key or secret is missing.
	 *
	 * @return Apple_News_API\Credentials|null
	 */
	public static function get_credentials(): ?Apple_News_API\Credentials {
		$api_key      = (string) get_option( self::OPTION_API_KEY, '' );
		$api_secret   = (string) get_option( self::OPTION_API_SECRET, '' );
		$channel_uuid = self::get_channel_uuid();

		if ( empty( $api_key ) || empty( $api_secret ) ) {
			return null;
		}

		return new Apple_News_API\Credentials( $api_key, $api_secret, $channel_uuid );
	}

	/**
	 * Return the stored channel UUID or the PRC default.
	 *
	 * @return string
	 */
	public static function get_channel_uuid(): string {
		$stored = (string) get_option( self::OPTION_CHANNEL_UUID, '' );
		return ! empty( $stored ) ? $stored : self::DEFAULT_CHANNEL_UUID;
	}

	/**
	 * Seed default option values on plugin activation.
	 *
	 * Safe to call multiple times — only sets values that are not yet stored.
	 */
	public static function seed_defaults(): void {
		if ( ! get_option( self::OPTION_CHANNEL_UUID ) && '' !== self::DEFAULT_CHANNEL_UUID ) {
			update_option( self::OPTION_CHANNEL_UUID, self::DEFAULT_CHANNEL_UUID );
		}
	}

	/**
	 * Migrate credentials from the legacy publish-to-apple-news plugin.
	 *
	 * Reads the `apple_news_settings` option written by the Alley Interactive
	 * plugin and copies api_key, api_secret, and api_channel into our own
	 * options — but only when those options are not already set.
	 *
	 * Safe to call multiple times; skips any value already present.
	 */
	public static function migrate_from_legacy(): void {
		$legacy = get_option( 'apple_news_settings', [] );

		if ( empty( $legacy ) || ! is_array( $legacy ) ) {
			return;
		}

		if ( ! get_option( self::OPTION_API_KEY ) && ! empty( $legacy['api_key'] ) ) {
			update_option( self::OPTION_API_KEY, sanitize_text_field( $legacy['api_key'] ) );
		}

		if ( ! get_option( self::OPTION_API_SECRET ) && ! empty( $legacy['api_secret'] ) ) {
			update_option( self::OPTION_API_SECRET, sanitize_text_field( $legacy['api_secret'] ) );
		}

		// The legacy plugin stores the channel UUID under 'api_channel'.
		if ( ! get_option( self::OPTION_CHANNEL_UUID ) && ! empty( $legacy['api_channel'] ) ) {
			update_option( self::OPTION_CHANNEL_UUID, sanitize_text_field( $legacy['api_channel'] ) );
		}
	}
}
