<?php
/**
 * REST Controller class.
 *
 * @package PRC\Platform\Apple_News
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News;

use PRC\Platform\Apple_News\ANF\ANF_Block_Converter;
use PRC\Platform\Apple_News\ANF\ANF_Post_Processor;
use PRC\Platform\Apple_News\ANF\ANF_Validator;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Registers REST routes for Apple News post management.
 *
 * Routes (all under prc-apple-news/v1):
 *   POST   /push          — trigger a push for a given post
 *   POST   /delete        — delete an article from Apple News
 *   GET    /status        — return the current Apple News status for a post
 *   GET    /preview       — return the processed ANF document for in-editor preview
 *   DELETE /error         — dismiss the stored error for a post
 *
 * GET+POST /settings and GET /test are registered by Settings.
 */
class REST_Controller {

	const REST_NAMESPACE = 'prc-apple-news/v1';

	protected Loader $loader;

	/**
	 * @param Loader $loader Hook registration loader.
	 */
	public function __construct( Loader $loader ) {
		$this->loader = $loader;
		$loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * @hook rest_api_init
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/push',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_push' ),
				'permission_callback' => array( $this, 'can_manage_post' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'force'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/delete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_delete' ),
				'permission_callback' => array( $this, 'can_manage_post' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_status' ),
				'permission_callback' => array( $this, 'can_read_post' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/preview',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_preview' ),
				'permission_callback' => array( $this, 'can_read_post' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/error',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'handle_dismiss_error' ),
				'permission_callback' => array( $this, 'can_manage_post' ),
				'args'                => array(
					'post_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * POST /push
	 *
	 * Triggers a synchronous push for the given post. Passes $force to
	 * Post_Sync::push_now() to optionally bypass the pending lock.
	 */
	public function handle_push( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );
		$force   = (bool) $request->get_param( 'force' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		( new Post_Sync( new Loader() ) )->push_now( $post_id, $force );

		return new WP_REST_Response( $this->get_status_data( $post_id ), 200 );
	}

	/**
	 * POST /delete
	 *
	 * Deletes the article from Apple News and clears all associated meta.
	 */
	public function handle_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		$result = Post_Sync::delete_from_apple_news( $post_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $this->get_status_data( $post_id ), 200 );
	}

	/**
	 * GET /status
	 *
	 * Returns the current Apple News sync status for a post.
	 */
	public function handle_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $this->get_status_data( $post_id ), 200 );
	}

	/**
	 * GET /preview
	 *
	 * Builds and returns the processed ANF document for in-editor preview.
	 * Does not contact the Apple News API.
	 */
	public function handle_preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		$raw_output = ( new ANF_Block_Converter() )->build( $post_id );
		if ( '' === $raw_output || null === json_decode( $raw_output ) ) {
			return new WP_Error(
				'anf_build_failed',
				'Deterministic ANF build returned invalid JSON.',
				array( 'status' => 500 )
			);
		}

		$processed = ( new ANF_Post_Processor() )->process( $raw_output, $post_id );
		$document  = json_decode( $processed, true );

		if ( ! is_array( $document ) ) {
			return new WP_Error(
				'anf_process_failed',
				'ANF post-processor returned invalid JSON.',
				array( 'status' => 500 )
			);
		}

		$validation_result = ( new ANF_Validator() )->validate_json( $processed );
		$validation        = is_wp_error( $validation_result )
			? array(
				'valid'   => false,
				'message' => $validation_result->get_error_message(),
				'errors'  => $validation_result->get_error_data()['errors'] ?? array(),
			)
			: array( 'valid' => true );

		return new WP_REST_Response(
			array(
				'document'   => $document,
				'validation' => $validation,
			),
			200
		);
	}

	/**
	 * DELETE /error
	 *
	 * Dismisses the stored error transient for a post.
	 */
	public function handle_dismiss_error( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', 'Post not found.', array( 'status' => 404 ) );
		}

		delete_transient( "_prc_apple_news_last_error_{$post_id}" );

		return new WP_REST_Response( $this->get_status_data( $post_id ), 200 );
	}

	/**
	 * Aggregate current Apple News status meta for a post.
	 *
	 * @param int $post_id
	 * @return array<string, mixed>
	 */
	private function get_status_data( int $post_id ): array {
		$article_id = get_post_meta( $post_id, 'apple_news_api_id', true );
		$pending    = get_post_meta( $post_id, 'apple_news_api_pending', true );
		$error      = get_transient( "_prc_apple_news_last_error_{$post_id}" );

		return array(
			'post_id'    => $post_id,
			'article_id' => $article_id ?: null,
			'share_url'  => get_post_meta( $post_id, 'apple_news_api_share_url', true ) ?: null,
			'pending'    => ! empty( $pending ) ? $pending : null,
			'error'      => $error ?: null,
			'published'  => ! empty( $article_id ),
		);
	}

	/**
	 * Permission callback: user must be able to edit the target post.
	 */
	public function can_manage_post( WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Permission callback: user must be able to read the target post.
	 */
	public function can_read_post( WP_REST_Request $request ): bool {
		$post_id = (int) $request->get_param( 'post_id' );
		return current_user_can( 'read_post', $post_id );
	}
}
