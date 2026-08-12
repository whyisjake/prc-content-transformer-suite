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
	 * Resolve the ElevenLabs API key.
	 *
	 * Extends the constant-then-option chain the rest of the suite already
	 * uses for Anthropic, rather than introducing a second configuration
	 * mechanism. Constants win so a server-level key cannot be overridden
	 * from the admin screen.
	 *
	 * @return string Empty string when no key is configured.
	 */
	public static function resolve_api_key(): string {
		$from_constant = self::api_key_from_constant();
		if ( '' !== $from_constant ) {
			return $from_constant;
		}

		$option_key = self::get( 'api_key', '' );

		return is_string( $option_key ) ? trim( $option_key ) : '';
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
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings     = self::all();
		$from_constant = self::api_key_is_constant();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Audio Narration', 'prc-audio-narration' ); ?></h1>
			<form action="options.php" method="post">
				<?php settings_fields( self::PAGE_SLUG ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="prc-audio-narration-api-key"><?php esc_html_e( 'ElevenLabs API key', 'prc-audio-narration' ); ?></label>
						</th>
						<td>
							<?php if ( $from_constant ) : ?>
								<p><em><?php esc_html_e( 'Set by a server constant. The value below is ignored.', 'prc-audio-narration' ); ?></em></p>
							<?php endif; ?>
							<input
								type="password"
								class="regular-text"
								id="prc-audio-narration-api-key"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]"
								value=""
								autocomplete="off"
								placeholder="<?php echo '' !== $settings['api_key'] ? esc_attr__( 'A key is saved. Leave blank to keep it.', 'prc-audio-narration' ) : ''; ?>"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="prc-audio-narration-voice-id"><?php esc_html_e( 'Default voice ID', 'prc-audio-narration' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								class="regular-text"
								id="prc-audio-narration-voice-id"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[voice_id]"
								value="<?php echo esc_attr( $settings['voice_id'] ); ?>"
							/>
							<p class="description"><?php esc_html_e( 'Used for all narration unless a post overrides it.', 'prc-audio-narration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="prc-audio-narration-model-id"><?php esc_html_e( 'Model', 'prc-audio-narration' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								class="regular-text"
								id="prc-audio-narration-model-id"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[model_id]"
								value="<?php echo esc_attr( $settings['model_id'] ); ?>"
							/>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
