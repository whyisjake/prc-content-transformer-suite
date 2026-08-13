<?php
/**
 * REST API
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

use PRC\Platform\Audio_Narration\TTS\Providers\ElevenLabs_Provider;

/**
 * Narration status, generation, and removal endpoints.
 */
class REST_API {

	/**
	 * REST namespace.
	 */
	const NAMESPACE_V1 = 'prc-audio-narration/v1';

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

		$this->loader->add_action( 'rest_api_init', $this, 'register_routes' );
		$this->loader->add_action( 'rest_api_init', $this, 'register_settings_routes' );
	}

	/**
	 * Register the narration routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/posts/(?P<post_id>\d+)/narration',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => array(
						'post_id'  => array(
							'required'          => true,
							'validate_callback' => static fn( $value ) => is_numeric( $value ),
						),
						'estimate' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => false,
							'description' => 'Include a cost estimate. This may generate the narration script, which calls the AI provider.',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'generate' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => array(
						'post_id'  => array(
							'required'          => true,
							'validate_callback' => static fn( $value ) => is_numeric( $value ),
						),
						'voice_id' => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => array(
						'post_id' => array(
							'required'          => true,
							'validate_callback' => static fn( $value ) => is_numeric( $value ),
						),
					),
				),
			)
		);
	}

	/**
	 * Register the settings and voice routes.
	 *
	 * Settings are deliberately not exposed through core's /wp/v2/settings
	 * endpoint. Doing so would require putting the API key in a REST-readable
	 * option schema, and a credential should never be readable back out of an
	 * endpoint -- it is written here and only ever reported as present.
	 *
	 * @return void
	 */
	public function register_settings_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(
						'voice_id' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'model_id' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'api_key'  => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/voices',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_voices' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * Whether the current user may manage plugin settings.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_manage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'prc_audio_narration_forbidden',
				'You are not allowed to manage narration settings.',
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Return the current settings.
	 *
	 * The stored key is never returned. The UI needs to know whether one is
	 * configured and where it came from, not what it is.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_settings() {
		$models = array();
		foreach ( ElevenLabs_Provider::MODEL_MAX_CHARACTERS as $id => $ceiling ) {
			$models[] = array(
				'id'             => $id,
				'max_characters' => $ceiling,
			);
		}

		return rest_ensure_response(
			array(
				'voice_id'    => Settings::get( 'voice_id', '' ),
				'model_id'    => Settings::model_id(),
				'models'      => $models,
				'has_key'     => '' !== Settings::resolve_api_key(),
				'key_source'  => $this->key_source(),
				'key_locked'  => Settings::api_key_is_constant(),
			)
		);
	}

	/**
	 * Where the active API key comes from.
	 *
	 * @return string One of constant, connector, option, none.
	 */
	private function key_source(): string {
		if ( Settings::api_key_is_constant() ) {
			return 'constant';
		}

		if ( '' !== Settings::api_key_from_connector() ) {
			return 'connector';
		}

		return '' !== Settings::resolve_api_key() ? 'option' : 'none';
	}

	/**
	 * Update settings.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function update_settings( $request ) {
		$settings = Settings::all();

		foreach ( array( 'voice_id', 'model_id' ) as $field ) {
			if ( null !== $request->get_param( $field ) ) {
				$settings[ $field ] = (string) $request->get_param( $field );
			}
		}

		// An omitted or blank key leaves the stored one alone, so saving the
		// form without retyping a masked field is not destructive.
		$api_key = $request->get_param( 'api_key' );
		if ( is_string( $api_key ) && '' !== trim( $api_key ) ) {
			$settings['api_key'] = trim( $api_key );
		}

		update_option( Settings::OPTION_KEY, $settings );

		return $this->get_settings();
	}

	/**
	 * List the voices available from the provider.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_voices() {
		$key = Settings::resolve_api_key();

		if ( '' === $key ) {
			return rest_ensure_response( array( 'voices' => array() ) );
		}

		$cached = get_transient( 'prc_audio_narration_voices' );
		if ( is_array( $cached ) ) {
			return rest_ensure_response( array( 'voices' => $cached ) );
		}

		$response = wp_remote_get(
			ElevenLabs_Provider::API_BASE . '/voices?page_size=100',
			array(
				'headers' => array( 'xi-api-key' => $key ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'prc_audio_narration_voices_failed',
				$response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['voices'] ) ) {
			return rest_ensure_response( array( 'voices' => array() ) );
		}

		$voices = array();
		foreach ( $body['voices'] as $voice ) {
			$labels   = isset( $voice['labels'] ) && is_array( $voice['labels'] ) ? $voice['labels'] : array();
			$voices[] = array(
				'id'          => (string) ( $voice['voice_id'] ?? '' ),
				'name'        => (string) ( $voice['name'] ?? '' ),
				'description' => implode( ', ', array_filter( array( $labels['accent'] ?? '', $labels['descriptive'] ?? '' ) ) ),
			);
		}

		// Cached because the settings screen would otherwise call the provider
		// on every render, and the voice list changes rarely.
		set_transient( 'prc_audio_narration_voices', $voices, HOUR_IN_SECONDS );

		return rest_ensure_response( array( 'voices' => $voices ) );
	}

	/**
	 * Whether the current user may manage narration for this post.
	 *
	 * Checked against the specific post rather than a blanket capability, so
	 * a contributor cannot narrate someone else's article.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return bool|\WP_Error
	 */
	public function can_edit_post( $request ) {
		$post_id = (int) $request['post_id'];

		if ( ! get_post( $post_id ) ) {
			return new \WP_Error(
				'prc_audio_narration_missing_post',
				'That post does not exist.',
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'prc_audio_narration_forbidden',
				'You are not allowed to manage narration for this post.',
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Return the narration state for a post.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_status( $request ) {
		$post_id = (int) $request['post_id'];
		$service = new Narration_Service();

		$payload = $this->build_state( $post_id, $service );

		// Estimating resolves the narration script, which is an AI call. It is
		// opt-in so that polling for job progress cannot quietly run one on
		// every tick.
		if ( $request['estimate'] ) {
			$estimate = $service->estimate( $post_id );

			$payload['estimate'] = is_wp_error( $estimate )
				? array( 'error' => $estimate->get_error_message() )
				: $estimate;
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * Queue narration generation for a post.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function generate( $request ) {
		$post_id = (int) $request['post_id'];
		$service = new Narration_Service();

		if ( Action_Scheduler_Handler::is_pending( $post_id ) ) {
			// Report the existing job rather than queueing a second billable
			// synthesis for an impatient second click.
			return rest_ensure_response( $this->build_state( $post_id, $service ) );
		}

		if ( ! $service->orchestrator()->get_active_provider() ) {
			return new \WP_Error(
				'prc_audio_narration_no_provider',
				'No text-to-speech provider is configured. Add an API key in Settings > Audio Narration.',
				array( 'status' => 400 )
			);
		}

		$voice_id = (string) $request['voice_id'];

		if ( Action_Scheduler_Handler::is_available() ) {
			$scheduled = Action_Scheduler_Handler::schedule( $post_id, $voice_id, get_current_user_id() );

			if ( is_wp_error( $scheduled ) ) {
				$scheduled->add_data( array( 'status' => 400 ) );
				return $scheduled;
			}

			return rest_ensure_response( $this->build_state( $post_id, $service ) );
		}

		// Without a scheduler there is nothing to poll, so run inline and
		// return the finished record.
		$result = $service->generate( $post_id, array( 'voice_id' => $voice_id ) );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}

		return rest_ensure_response( $this->build_state( $post_id, $service ) );
	}

	/**
	 * Remove a post's narration.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function delete( $request ) {
		$post_id = (int) $request['post_id'];
		$service = new Narration_Service();

		Action_Scheduler_Handler::cancel( $post_id );
		$service->delete( $post_id );

		return rest_ensure_response( $this->build_state( $post_id, $service ) );
	}

	/**
	 * Build the state payload for a post.
	 *
	 * @param int               $post_id The post ID.
	 * @param Narration_Service $service The narration service.
	 * @return array
	 */
	private function build_state( int $post_id, Narration_Service $service ): array {
		$record = $service->store()->get( $post_id );

		$provider = $service->orchestrator()->get_active_provider();

		return array(
			'post_id'   => $post_id,
			'state'     => $service->describe_state( $post_id, $record ),
			'narration' => $record,
			'provider'  => $provider ? $provider->get_name() : '',
		);
	}
}
