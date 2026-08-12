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
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/class-settings.php';
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/class-script-provider-registrar.php';

		// TTS infrastructure.
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/tts/infrastructure/interface-http-client.php';
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/tts/infrastructure/class-http-client.php';

		// TTS domain.
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/tts/domain/exceptions/class-tts-exception.php';
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/tts/domain/exceptions/class-provider-unavailable-exception.php';
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/tts/domain/exceptions/class-authentication-exception.php';
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/tts/domain/exceptions/class-rate-limit-exception.php';
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/tts/domain/exceptions/class-synthesis-failed-exception.php';
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/tts/domain/class-tts-request.php';
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/tts/domain/class-tts-response.php';

		// TTS providers and orchestration.
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/tts/providers/interface-tts-provider.php';
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/tts/providers/class-elevenlabs-provider.php';
		require_once PRC_AUDIO_NARRATION_DIR . '/includes/tts/application/class-tts-orchestrator.php';

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
		new Settings( $this->loader );
		new Script_Provider_Registrar( $this->loader );
	}

	/**
	 * Build a TTS orchestrator with the registered speech providers.
	 *
	 * Providers are collected through a filter so a site can add or replace
	 * one without editing this plugin.
	 *
	 * @return TTS\Application\TTS_Orchestrator
	 */
	public static function tts_orchestrator(): TTS\Application\TTS_Orchestrator {
		$providers = array( new TTS\Providers\ElevenLabs_Provider() );

		/**
		 * Filter the registered text-to-speech providers.
		 *
		 * @param TTS\Providers\TTS_Provider_Interface[] $providers Registered providers.
		 */
		$providers = apply_filters( 'prc_audio_narration_tts_providers', $providers );

		return new TTS\Application\TTS_Orchestrator( $providers );
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
