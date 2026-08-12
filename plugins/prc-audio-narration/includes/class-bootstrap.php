<?php
/**
 * Plugin bootstrap class
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

/**
 * Bootstrap class for plugin initialization
 */
class Bootstrap {
	/**
	 * The loader responsible for maintaining and registering all hooks
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Plugin name
	 *
	 * @var string
	 */
	protected $plugin_name;

	/**
	 * Plugin version
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Initialize the plugin
	 */
	public function __construct() {
		$this->plugin_name = 'prc-audio-narration';
		$this->version     = PRC_AUDIO_NARRATION_VERSION;

		$this->load_dependencies();
		$this->register_modules();
	}

	/**
	 * Load required dependencies
	 */
	private function load_dependencies() {
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/class-loader.php';

		$this->loader = new Loader();
	}

	/**
	 * Register plugin modules with the loader.
	 *
	 * Modules are registered here as each implementation unit lands. Every
	 * module receives the loader and registers its own hooks, so this method
	 * stays a manifest rather than a hook registry.
	 */
	private function register_modules() {
		// Modules are wired up here as they are implemented.
	}

	/**
	 * Run the loader to execute all registered hooks
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * Get the plugin name
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Get the plugin version
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Get the loader
	 *
	 * @return Loader
	 */
	public function get_loader() {
		return $this->loader;
	}
}
