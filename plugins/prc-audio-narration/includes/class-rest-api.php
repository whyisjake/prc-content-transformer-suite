<?php
/**
 * REST API
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

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

		return array(
			'post_id'   => $post_id,
			'state'     => $this->describe_state( $post_id, $record ),
			'narration' => $record,
			'provider'  => $service->orchestrator()->get_active_provider()
				? $service->orchestrator()->get_active_provider()->get_name()
				: '',
		);
	}

	/**
	 * Describe the narration state of a post.
	 *
	 * @param int        $post_id The post ID.
	 * @param array|null $record  The narration record.
	 * @return string One of none, pending, stale, ready.
	 */
	private function describe_state( int $post_id, ?array $record ): string {
		if ( Action_Scheduler_Handler::is_pending( $post_id ) ) {
			return 'pending';
		}

		if ( null === $record ) {
			return 'none';
		}

		return $record['is_stale'] ? 'stale' : 'ready';
	}
}
