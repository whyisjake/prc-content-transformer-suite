<?php
/**
 * Plugin settings
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

/**
 * Settings storage, credential resolution, and the options screen.
 */
class Settings {

	/**
	 * Option key holding the settings array.
	 */
	const OPTION_KEY = 'prc_audio_narration_settings';

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'prc-audio-narration';

	/**
	 * Default ElevenLabs model.
	 */
	const DEFAULT_MODEL = 'eleven_multilingual_v2';

	/**
	 * The loader instance.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The hook loader.
	 */
	public function __construct( Loader $loader ) {
		$this->loader = $loader;

		$this->loader->add_action( 'admin_menu', $this, 'register_page' );
		$this->loader->add_action( 'admin_init', $this, 'register_settings' );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue' );
	}

	/**
	 * All settings, with defaults applied.
	 *
	 * @return array
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge(
			array(
				'api_key'  => '',
				'voice_id' => '',
				'model_id' => self::DEFAULT_MODEL,
			),
			$stored
		);
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key           Setting key.
	 * @param mixed  $default_value Value returned when unset.
	 * @return mixed
	 */
	public static function get( string $key, $default_value = '' ) {
		$settings = self::all();

		return $settings[ $key ] ?? $default_value;
	}

	/**
	 * Options written by the core AI connectors screen.
	 *
	 * Mirrors the naming prc-content-transformer already reads for Anthropic
	 * (connectors_ai_anthropic_api_key / ais_anthropic_api_key).
	 */
	const CONNECTOR_OPTIONS = array(
		'connectors_ai_elevenlabs_api_key',
		'ais_elevenlabs_api_key',
	);

	/**
	 * Resolve the ElevenLabs API key.
	 *
	 * Order: server constants, then the core AI connectors screen, then this
	 * plugin's own setting.
	 *
	 * Constants win so a server-level key cannot be overridden from wp-admin.
	 * The connectors screen comes next because once ElevenLabs is available as
	 * an AI provider that is the canonical place a site configures it, and a
	 * key entered there should not need to be duplicated here. This plugin's
	 * own field remains as a fallback for sites without a provider installed.
	 *
	 * @return string Empty string when no key is configured.
	 */
	public static function resolve_api_key(): string {
		$from_constant = self::api_key_from_constant();
		if ( '' !== $from_constant ) {
			return $from_constant;
		}

		$from_connector = self::api_key_from_connector();
		if ( '' !== $from_connector ) {
			return $from_connector;
		}

		$option_key = self::get( 'api_key', '' );

		return is_string( $option_key ) ? trim( $option_key ) : '';
	}

	/**
	 * The API key supplied by the core AI connectors screen, if any.
	 *
	 * @return string Empty string when no connector supplies one.
	 */
	public static function api_key_from_connector(): string {
		/**
		 * Filter the options consulted for a connector-supplied ElevenLabs key.
		 *
		 * The exact option name depends on how the ElevenLabs AI provider
		 * registers itself, so it is filterable rather than hard-coded.
		 *
		 * @param string[] $options Option names, in precedence order.
		 */
		$options = (array) apply_filters(
			'prc_audio_narration_connector_key_options',
			self::CONNECTOR_OPTIONS
		);

		foreach ( $options as $option ) {
			$value = get_option( $option, '' );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	/**
	 * The API key supplied by a server constant, if any.
	 *
	 * @return string Empty string when no constant supplies one.
	 */
	private static function api_key_from_constant(): string {
		foreach ( array( 'ELEVENLABS_API_KEY', 'PRC_PLATFORM_ELEVENLABS_API_KEY' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				continue;
			}

			$value = constant( $constant );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return trim( $value );
			}
		}

		return '';
	}

	/**
	 * The site-wide default voice identifier.
	 *
	 * @return string
	 */
	public static function default_voice_id(): string {
		/**
		 * Filter the default narration voice.
		 *
		 * @param string $voice_id The configured voice identifier.
		 */
		return (string) apply_filters( 'prc_audio_narration_default_voice_id', (string) self::get( 'voice_id', '' ) );
	}

	/**
	 * The synthesis model identifier.
	 *
	 * @return string
	 */
	public static function model_id(): string {
		$model = (string) self::get( 'model_id', self::DEFAULT_MODEL );

		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * Register the settings page.
	 *
	 * @return void
	 */
	public function register_page() {
		add_options_page(
			__( 'Audio Narration', 'prc-audio-narration' ),
			__( 'Audio Narration', 'prc-audio-narration' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the settings field group.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE_SLUG,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize submitted settings.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array
	 */
	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$existing = self::all();

		$api_key = isset( $input['api_key'] ) ? trim( sanitize_text_field( $input['api_key'] ) ) : '';
		if ( '' === $api_key ) {
			// An empty submission keeps the stored key rather than wiping it,
			// so saving the page after the field renders masked is not
			// destructive.
			$api_key = (string) $existing['api_key'];
		}

		return array(
			'api_key'  => $api_key,
			'voice_id' => isset( $input['voice_id'] ) ? sanitize_text_field( $input['voice_id'] ) : '',
			'model_id' => isset( $input['model_id'] ) && '' !== $input['model_id']
				? sanitize_text_field( $input['model_id'] )
				: self::DEFAULT_MODEL,
		);
	}

	/**
	 * Whether the API key comes from a constant rather than the database.
	 *
	 * @return bool
	 */
	public static function api_key_is_constant(): bool {
		return '' !== self::api_key_from_constant();
	}

	/**
	 * Enqueue the settings app on its own screen only.
	 *
	 * @param string $hook_suffix The current admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		$asset_path = PRC_AUDIO_NARRATION_DIR . '/build/settings.asset.php';

		if ( ! file_exists( $asset_path ) ) {
			return;
		}

		$asset = require $asset_path;

		wp_enqueue_script(
			'prc-audio-narration-settings',
			PRC_AUDIO_NARRATION_URL . 'build/settings.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( 'prc-audio-narration-settings', 'prc-audio-narration' );

		// The components package ships its own styles; without them the
		// controls render unstyled.
		wp_enqueue_style( 'wp-components' );
	}

	/**
	 * Render the settings page.
	 *
	 * The screen is client-rendered. Settings are read and written through
	 * this plugin's own REST routes rather than the options.php form post, so
	 * the API key is never echoed back into the page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<?php // The visible heading is rendered by the app; this keeps an h1 for screen readers and for where WordPress injects admin notices. ?>
			<h1 class="screen-reader-text"><?php esc_html_e( 'Audio Narration', 'prc-audio-narration' ); ?></h1>
			<div id="prc-audio-narration-settings"></div>
			<noscript>
				<p><?php esc_html_e( 'Audio narration settings require JavaScript.', 'prc-audio-narration' ); ?></p>
			</noscript>
		</div>
		<?php
	}
}
