<?php
/**
 * REST Controller.
 *
 * @package PRC\Platform\Content_Transformer\API
 */

namespace PRC\Platform\Content_Transformer\API;

use PRC\Platform\Content_Transformer\Loader;
use PRC\Platform\Content_Transformer\Pipeline\Transformation_Pipeline;
use PRC\Platform\Content_Transformer\Cache\Transformation_Cache;
use PRC\Platform\Content_Transformer\Async\Action_Scheduler_Handler;
use PRC\Platform\Content_Transformer\Providers\Provider_Registry;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Registers REST API routes under prc-content-transformer/v1.
 */
class REST_Controller {

	const NAMESPACE = 'prc-content-transformer/v1';

	/**
	 * @param Loader $loader Hook loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Register REST routes.
	 *
	 * @hook rest_api_init
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/transform',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_transform' ),
				'permission_callback' => array( $this, 'edit_post_permission_check' ),
				'args'                => array(
					'post_id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && $value > 0;
						},
					),
					'provider' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'async'    => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
					'force'    => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'edit_post_permission_check' ),
				'args'                => array(
					'post_id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'provider' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/result',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_result' ),
				'permission_callback' => array( $this, 'edit_post_permission_check' ),
				'args'                => array(
					'post_id'  => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'provider' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/providers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_providers' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Permission check: current user must be able to edit the target post.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function edit_post_permission_check( WP_REST_Request $request ) {
		$post_id = $request->get_param( 'post_id' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to transform content for this post.', 'prc-content-transformer' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * POST /transform -- trigger a content transformation.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_transform( WP_REST_Request $request ) {
		$post_id       = $request->get_param( 'post_id' );
		$provider_slug = $request->get_param( 'provider' );
		$async         = $request->get_param( 'async' );
		$force         = $request->get_param( 'force' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error(
				'post_not_found',
				__( 'Post not found.', 'prc-content-transformer' ),
				array( 'status' => 404 )
			);
		}

		if ( ! Provider_Registry::has( $provider_slug ) ) {
			return new WP_Error(
				'provider_not_found',
				sprintf(
					/* translators: %s: provider slug */
					__( 'Provider "%s" is not registered.', 'prc-content-transformer' ),
					$provider_slug
				),
				array( 'status' => 400 )
			);
		}

		if ( $async ) {
			if ( Action_Scheduler_Handler::is_pending( $post_id, $provider_slug ) ) {
				return rest_ensure_response(
					array(
						'success' => true,
						'message' => __( 'Transformation is already queued.', 'prc-content-transformer' ),
						'status'  => 'pending',
					)
				);
			}

			$job_id = Action_Scheduler_Handler::schedule( $post_id, $provider_slug, get_current_user_id() );
			if ( false === $job_id ) {
				return new WP_Error(
					'scheduler_unavailable',
					__( 'Action Scheduler is not available.', 'prc-content-transformer' ),
					array( 'status' => 500 )
				);
			}

			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Transformation queued.', 'prc-content-transformer' ),
					'job_id'  => $job_id,
					'status'  => 'queued',
				)
			);
		}

		// Synchronous transformation.
		$result = Transformation_Pipeline::transform( $post_id, $provider_slug, $force );

		return rest_ensure_response( $result->to_array() );
	}

	/**
	 * GET /status -- check transformation status.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_status( WP_REST_Request $request ) {
		$post_id       = $request->get_param( 'post_id' );
		$provider_slug = $request->get_param( 'provider' );

		$status = Transformation_Cache::get_status( $post_id, $provider_slug );

		return rest_ensure_response(
			array(
				'post_id'  => $post_id,
				'provider' => $provider_slug,
				'status'   => $status,
			)
		);
	}

	/**
	 * GET /result -- retrieve cached transformed content.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_result( WP_REST_Request $request ) {
		$post_id       = $request->get_param( 'post_id' );
		$provider_slug = $request->get_param( 'provider' );

		$cached = Transformation_Cache::get( $post_id, $provider_slug );
		if ( null === $cached ) {
			return new WP_Error(
				'no_result',
				__( 'No transformation result found. Trigger a transformation first.', 'prc-content-transformer' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $cached->to_array() );
	}

	/**
	 * GET /providers -- list all registered providers.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_providers( WP_REST_Request $request ) {
		$providers = array();
		foreach ( Provider_Registry::get_all() as $provider ) {
			$providers[] = array(
				'name'        => $provider->get_name(),
				'slug'        => $provider->get_slug(),
				'output_type' => $provider->get_output_type(),
				'available'   => $provider->is_available(),
			);
		}

		return rest_ensure_response( $providers );
	}
}
