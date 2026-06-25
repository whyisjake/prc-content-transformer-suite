<?php
/**
 * Bootstrap class.
 *
 * @package PRC\Platform\Content_Transformer
 */

namespace PRC\Platform\Content_Transformer;

use PRC\Platform\Content_Transformer\Providers\Provider_Registry;

/**
 * Bootstrap class.
 *
 * @package PRC\Platform\Content_Transformer
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
		$this->version     = PRC_CONTENT_TRANSFORMER_VERSION;
		$this->plugin_name = 'prc-content-transformer';

		$this->load_dependencies();
		$this->init_dependencies();
	}

	/**
	 * Load the required dependencies for this plugin.
	 */
	private function load_dependencies() {
		$base = plugin_dir_path( __DIR__ );

		require_once $base . '/includes/class-loader.php';
		$this->loader = new Loader();

		// Providers.
		require_once $base . '/includes/providers/interface-provider.php';
		require_once $base . '/includes/providers/class-provider-registry.php';
		require_once $base . '/includes/providers/class-plain-text-provider.php';
		require_once $base . '/includes/providers/class-apple-news-provider.php';
		require_once $base . '/includes/providers/class-email-provider.php';

		// Pipeline.
		require_once $base . '/includes/pipeline/class-transformation-result.php';
		require_once $base . '/includes/pipeline/class-prompt-builder.php';
		require_once $base . '/includes/pipeline/class-transformation-pipeline.php';

		// Cache.
		require_once $base . '/includes/cache/class-transformation-cache.php';

		// Async.
		require_once $base . '/includes/async/class-action-scheduler-handler.php';

		// REST API.
		require_once $base . '/includes/api/class-rest-controller.php';

		// CLI.
		require_once $base . '/includes/cli/class-cli-command.php';

		// AI Experiment.
		require_once $base . '/includes/ai-experiment/class-content-transformer-ability.php';
	}

	/**
	 * Initialize the dependencies.
	 */
	private function init_dependencies() {
		// Register built-in providers and fire the registration action.
		$this->loader->add_action( 'init', $this, 'register_providers', 5 );
		$this->loader->add_action( 'init', $this, 'migrate_ai_feature_flags', 20 );

		// Cache invalidation on content change.
		new Cache\Transformation_Cache( $this->loader );

		// Action Scheduler async handler.
		Async\Action_Scheduler_Handler::init();

		// REST API.
		new API\REST_Controller( $this->loader );

		// WP-CLI commands.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI\CLI_Command::register();
		}

		// AI Experiment integration for AI plugin 0.6.0+ feature registry.
		if ( class_exists( '\WordPress\AI\Abstracts\Abstract_Feature' ) ) {
			require_once plugin_dir_path( __DIR__ ) . '/includes/ai-experiment/class-content-transformer-experiment.php';

			add_action(
				'wpai_register_features',
				function ( $registry ) {
					if ( method_exists( $registry, 'register_feature' ) ) {
						$registry->register_feature(
							new AI_Experiment\Content_Transformer_Experiment()
						);
					}
				}
			);
		}
	}

	/**
	 * Migrate legacy AI experiment flags to AI 0.6+ feature flags.
	 *
	 * AI 0.6 renamed options from `ai_experiment_*` to `wpai_feature_*` and
	 * from `ai_experiments_enabled` to `wpai_features_enabled`.
	 *
	 * @hook init
	 */
	public function migrate_ai_feature_flags() {
		$legacy_feature_key = 'ai_experiment_content-transformer_enabled';
		$new_feature_key    = 'wpai_feature_content-transformer_enabled';

		if ( false === get_option( $new_feature_key, false ) ) {
			$legacy_feature_value = get_option( $legacy_feature_key, null );
			if ( null !== $legacy_feature_value ) {
				update_option( $new_feature_key, (bool) $legacy_feature_value );
			}
		}

		$legacy_global_key = 'ai_experiments_enabled';
		$new_global_key    = 'wpai_features_enabled';

		if ( false === get_option( $new_global_key, false ) ) {
			$legacy_global_value = get_option( $legacy_global_key, null );
			if ( null !== $legacy_global_value ) {
				update_option( $new_global_key, (bool) $legacy_global_value );
			}
		}
	}

	/**
	 * Register built-in providers and fire the registration action for third parties.
	 *
	 * @hook init 5
	 */
	public function register_providers() {
		Provider_Registry::register( new Providers\Plain_Text_Provider() );
		Provider_Registry::register( new Providers\Apple_News_Provider() );
		Provider_Registry::register( new Providers\Email_Provider() );

		/**
		 * Allow other plugins to register custom content transformer providers.
		 *
		 * @since 1.0.0
		 */
		do_action( 'prc_content_transformer_register_providers' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin.
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks.
	 *
	 * @return Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}
}
