<?php
namespace PRC\Platform;

class Scripts {
	// Note: These are public static properties so that they can be accessed from other classes and iterated over.
	public static $rest_endpoints = array();

	public function __construct( $loader = null ) {
		if ( null !== $loader ) {
			// Initialize component rest endpoints.
			$loader->add_action( 'init', $this, 'init_component_rest_endpoints' );
			// Localize the platform info for the react script.
			$loader->add_action( 'admin_enqueue_scripts', $this, 'localize_platform_info', 0 );
			$loader->add_action( 'wp_enqueue_scripts', $this, 'localize_platform_info', 0 );
			// Enqueue scripts in the frontend.
			$loader->add_action( 'wp_enqueue_scripts', $this, 'init_first_party_scripts', 0 );
			$loader->add_action( 'wp_enqueue_scripts', $this, 'init_third_party_scripts', 0 );
			// Enqueue styles in the frontend.
			$loader->add_action( 'wp_enqueue_scripts', $this, 'init_third_party_styles', 0 );
			// Enqueue scripts in the admin area.
			$loader->add_action( 'admin_enqueue_scripts', $this, 'init_first_party_scripts', 0 );
			$loader->add_action( 'admin_enqueue_scripts', $this, 'init_third_party_scripts', 0 );
			// Always load @prc/icons (replaces Icon_Loader::enqueue_icon_loader).
			$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_prc_icons_everywhere', 10 );
			$loader->add_action( 'enqueue_block_assets', $this, 'enqueue_prc_icons_everywhere', 10 );
		}
	}

	/**
	 * Localize the platform info for the react script.
	 * Makes available everywhere siteUrl base, environment type, version, and release name.
	 * Accessible via window.prcPlatform.
	 *
	 * Example:
	 * window.prcPlatform.siteUrl = 'https://alpha.pewresearch.org/pewresearch-org'
	 * window.prcPlatform.envType = 'production' | 'development' | 'local' | 'staging'
	 * window.prcPlatform.version = '1.0.0'
	 * window.prcPlatform.releaseName = 'Release Name'
	 * window.prcPlatform.presenceApiEnabled = true | false
	 *
	 * `presenceApiEnabled` reflects `function_exists( 'wp_set_presence' )` and is
	 * consumed by `@prc/hooks` (`usePresenceUsers`, `useDeclarePresence`) to
	 * gracefully degrade when the optional, vendored `presence-api` plugin is not
	 * loaded on the site. We detect the plugin from the outside rather than
	 * patching it directly because `presence-api` is upstream-maintained and we
	 * don't want to carry a local diff against it. Without this signal, those
	 * hooks happily poll `/wp-presence/v1/presence` on a 15–30 second heartbeat
	 * and trip on `rest_no_route` errors — producing render churn that has been
	 * observed to cascade into React 185 ("maximum update depth exceeded") in
	 * editor SlotFills downstream.
	 *
	 * **Temporary:** `presenceApiEnabled` is a short-term bridge. Plan to remove
	 * it once Presence is wired more deeply into the platform (e.g. a single
	 * canonical integration surface or core-backed presence) so consumers no
	 * longer need a separate opt-in signal on `window.prcPlatform`.
	 *
	 * @hook wp_enqueue_scripts
	 *
	 * @return void
	 */
	public function localize_platform_info(): void {
		$version     = defined( 'PRC_PLATFORM_VERSION' ) ? \PRC_PLATFORM_VERSION : '1.0.0';
		$release_name = defined( 'PRC_PLATFORM_RELEASE_NAME' ) ? \PRC_PLATFORM_RELEASE_NAME : '';
		wp_localize_script(
			'react', // We bind this to react which is effectively enqueued everywhere.
			'prcPlatform',
			array(
				'siteUrl'            => get_site_url(),
				'envType'            => wp_get_environment_type(),
				'version'            => $version,
				'releaseName'        => $release_name,
				'presenceApiEnabled' => function_exists( 'wp_set_presence' ),
			)
		);
	}

	/**
	 * Register first party scripts.
	 * Fires early, on wp_enqueue_scripts.
	 *
	 * @return void
	 */
	public function init_first_party_scripts() {
		// Get all folders in the blocks directory as an array.
		$directories = glob( plugin_dir_path( __FILE__ ) . 'build/@prc/*', GLOB_ONLYDIR );
		foreach ( $directories as $dir ) {
			$asset_file  = include $dir . '/index.asset.php';
			$script_name = basename( $dir );
			$script_slug = 'prc-' . $script_name;
			$script_src  = plugin_dir_url( __FILE__ ) . 'build/@prc/' . $script_name . '/index.js';

			wp_register_script(
				$script_slug,
				$script_src,
				$asset_file['dependencies'],
				$asset_file['version'],
				true
			);

			$style_path = $dir . '/style-index.css';
			if ( file_exists( $style_path ) ) {
				wp_register_style(
					$script_slug,
					plugin_dir_url( __FILE__ ) . 'build/@prc/' . $script_name . '/style-index.css',
					array(),
					$asset_file['version']
				);
			}
		}
	}

	/**
	 * Enqueue the shared @prc/icons bundle on every screen that loads block/editor assets.
	 * Replaces the former Icon_Loader class (admin_enqueue_scripts + enqueue_block_assets).
	 *
	 * @return void
	 */
	public function enqueue_prc_icons_everywhere(): void {
		wp_enqueue_script( 'prc-icons' );
		wp_enqueue_style( 'prc-icons' );
	}

	/**
	 * Register third party scripts.
	 * Fires early, on wp_enqueue_scripts.
	 *
	 * @return void
	 */
	public function init_third_party_scripts() {
		$directories = glob( plugin_dir_path( __FILE__ ) . 'build/third-party/*', GLOB_ONLYDIR );
		foreach ( $directories as $dir ) {
			$asset_file  = include $dir . '/index.asset.php';
			$script_name = basename( $dir );
			$script_slug = $script_name;
			$script_src  = plugin_dir_url( __FILE__ ) . 'build/third-party/' . $script_name . '/index.js';

			// Check if index.js file exists and register it if it does.
			if ( file_exists( $dir . '/index.js' ) ) {
				wp_register_script(
					$script_slug,
					$script_src,
					$asset_file['dependencies'],
					$asset_file['version'],
					true
				);
			}
		}
	}

	/**
	 * Register third party styles.
	 * Fires early, on wp_enqueue_scripts.
	 *
	 * @return void
	 */
	public function init_third_party_styles() {
		$directories = glob( plugin_dir_path( __FILE__ ) . 'build/third-party/*', GLOB_ONLYDIR );
		foreach ( $directories as $dir ) {
			$asset_file = include $dir . '/index.asset.php';
			$style_name = basename( $dir );
			$style_slug = $style_name;
			$style_src  = plugin_dir_url( __FILE__ ) . 'build/third-party/' . $style_name . '/style-index.css';

			// Check if style-index.css file exists and register it if it does.
			if ( file_exists( $dir . '/style-index.css' ) ) {
				$style = wp_register_style(
					$style_slug,
					$style_src,
					array(),
					$asset_file['version'],
					'screen'
				);
			}
		}
	}

	/**
	 * If a component has a class-wp-rest-api.php file, require it and add the endpoint to the rest_endpoints array.
	 */
	public function init_component_rest_endpoints() {
		$directories = glob( plugin_dir_path( __FILE__ ) . 'src/@prc/components/*', GLOB_ONLYDIR );
		foreach ( $directories as $dir ) {
			if ( file_exists( $dir . '/class-wp-rest-api.php' ) ) {
				require_once $dir . '/class-wp-rest-api.php';
				$namespace              = str_replace( '-', '_', basename( $dir ) );
				$class_name             = 'Rest_API_Endpoint';
				$full_class_name        = 'PRC\Platform\Scripts\\' . $namespace . '\\' . $class_name;
				$endpoint               = new $full_class_name();
				self::$rest_endpoints[] = $endpoint->get_endpoint();
			}
		}
	}
}
