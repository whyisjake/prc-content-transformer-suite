<?php
/**
 * Bootstrap class.
 *
 * @package PRC\Platform\Apple_News
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News;

/**
 * Bootstrap class.
 *
 * @package PRC\Platform\Apple_News
 */
class Bootstrap {

	/**
	 * The loader that's responsible for maintaining and registering all hooks.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @var string
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 */
	public function __construct() {
		$this->version     = PRC_APPLE_NEWS_VERSION;
		$this->plugin_name = 'prc-apple-news';

		$this->load_dependencies();
		$this->init_dependencies();
	}

	/**
	 * Load the required dependencies for this plugin.
	 */
	private function load_dependencies(): void {
		$base = plugin_dir_path( __DIR__ );

		require_once $base . '/includes/class-loader.php';
		$this->loader = new Loader();

		// Apple News API client (must load before any class that uses it).
		require_once $base . '/includes/apple-news-api/class-credentials.php';
		require_once $base . '/includes/apple-news-api/class-mime-builder.php';
		require_once $base . '/includes/apple-news-api/class-request.php';
		require_once $base . '/includes/apple-news-api/class-api.php';

		// ANF utilities.
		require_once $base . '/includes/anf/class-anf-block-registry.php';
		require_once $base . '/includes/anf/class-anf-block-resolver.php';
		require_once $base . '/includes/anf/class-anf-block-converter.php';
		require_once $base . '/includes/anf/class-anf-block-integration.php';
		require_once $base . '/includes/anf/class-anf-post-processor.php';
		require_once $base . '/includes/anf/class-anf-theme-definitions.php';
		require_once $base . '/includes/anf/class-anf-referential-validator.php';
		require_once $base . '/includes/anf/class-anf-validator.php';

		require_once $base . '/includes/class-post-meta.php';
		require_once $base . '/includes/class-settings.php';
		require_once $base . '/includes/class-post-sync.php';
		require_once $base . '/includes/class-rest-controller.php';
		require_once $base . '/includes/class-editor-assets.php';

		// CLI.
		require_once $base . '/includes/cli/class-cli-command.php';
	}

	/**
	 * Initialize the dependencies.
	 */
	private function init_dependencies(): void {
		$this->loader->add_filter( 'block_type_metadata', ANF\ANF_Block_Resolver::class, 'inject_metadata_into_supports' );
		$this->loader->add_action( 'init', $this, 'fire_register_anf_callbacks', 5 );

		new Post_Meta( $this->loader );
		new Settings( $this->loader );
		new Post_Sync( $this->loader );
		new REST_Controller( $this->loader );
		new Editor_Assets( $this->loader );
		new ANF\ANF_Block_Integration( $this->loader );

		// WP-CLI commands.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI\CLI_Command::register();
		}
	}

	/**
	 * Fire the ANF block callback registration action.
	 *
	 * @hook init
	 */
	public function fire_register_anf_callbacks(): void {
		do_action( 'prc_apple_news_register_block_callbacks' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 */
	public function run(): void {
		$this->loader->run();
	}

	/**
	 * The name of the plugin.
	 *
	 * @return string
	 */
	public function get_plugin_name(): string {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks.
	 *
	 * @return Loader
	 */
	public function get_loader(): Loader {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return string
	 */
	public function get_version(): string {
		return $this->version;
	}
}
