<?php
/**
 * REST API for PRC PDF Extraction
 *
 * Exposes endpoints for triggering topline PDF conversion from the block editor.
 *
 * @package PRC\Platform\PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

/**
 * Registers REST API routes for topline conversion.
 */
class REST_API {

	const NAMESPACE = 'prc-pdf-extraction/v1';

	/**
	 * Loader instance.
	 *
	 * @var Loader
	 */
	private $loader;

	/**
	 * @param Loader $loader Hook loader.
	 */
	public function __construct( $loader ) {
		$this->loader = $loader;
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 */
	private function register_hooks(): void {
		$this->loader->add_action( 'rest_api_init', $this, 'register_routes' );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/convert',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_convert' ),
				'permission_callback' => array( $this, 'convert_permission_check' ),
				'args'                => array(
					'post_id'       => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && $value > 0;
						},
					),
					'attachment_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && $value > 0;
						},
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
				'permission_callback' => array( $this, 'convert_permission_check' ),
				'args'                => array(
					'post_id'       => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'attachment_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Permission check: current user must be able to edit the target post.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return bool|\WP_Error
	 */
	public function convert_permission_check( \WP_REST_Request $request ) {
		$post_id = $request->get_param( 'post_id' );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to convert PDF for this post.', 'prc-pdf-extraction' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle POST /convert — schedule an async extraction job.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_convert( \WP_REST_Request $request ) {
		$post_id       = $request->get_param( 'post_id' );
		$attachment_id = $request->get_param( 'attachment_id' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'prc-pdf-extraction' ),
				array( 'status' => 404 )
			);
		}

		// Verify the attachment exists.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new \WP_Error(
				'attachment_not_found',
				__( 'Attachment not found.', 'prc-pdf-extraction' ),
				array( 'status' => 404 )
			);
		}

		if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			return new \WP_Error( 'invalid_mime_type', __( 'Attachment must be a PDF file.', 'prc-pdf-extraction' ), array( 'status' => 400 ) );
		}

		// Idempotency: don't double-schedule.
		if ( Action_Scheduler_Handler::is_pending( $post_id, $attachment_id ) ) {
			return rest_ensure_response(
				array(
					'success' => true,
					'message' => __( 'Topline conversion is already queued.', 'prc-pdf-extraction' ),
					'status'  => 'pending',
				)
			);
		}

		$user_id = get_current_user_id();
		$job_id  = Action_Scheduler_Handler::schedule( $post_id, $attachment_id, $user_id );

		if ( false === $job_id ) {
			return new \WP_Error(
				'scheduler_unavailable',
				__( 'Action Scheduler is not available. Cannot queue conversion.', 'prc-pdf-extraction' ),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Topline conversion queued. You will be notified when it is complete.', 'prc-pdf-extraction' ),
				'job_id'  => $job_id,
				'status'  => 'queued',
			)
		);
	}

	/**
	 * Handle GET /status — check whether a conversion is pending or complete.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response
	 */
	public function handle_status( \WP_REST_Request $request ) {
		$post_id       = $request->get_param( 'post_id' );
		$attachment_id = $request->get_param( 'attachment_id' );

		$is_pending = Action_Scheduler_Handler::is_pending( $post_id, $attachment_id );

		$service     = new Extraction_Service();
		$existing_id = $service->get_extraction_for_material( $post_id, $attachment_id );

		$status = 'none';
		if ( $is_pending ) {
			$status = 'pending';
		} elseif ( $existing_id ) {
			$status = 'complete';
		}

		return rest_ensure_response(
			array(
				'status'        => $status,
				'extraction_id' => $existing_id,
			)
		);
	}
}
