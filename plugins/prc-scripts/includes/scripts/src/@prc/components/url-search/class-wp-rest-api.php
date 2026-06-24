<?php
/**
 * URL Search REST API Endpoint.
 *
 * @package PRC\Platform\Scripts\Url_Search
 */

namespace PRC\Platform\Scripts\Url_Search;

use PRC\URL_Helper;
use WP_Error;
use WP_REST_Request;

/**
 * REST API endpoint that resolves a URL to a post ID and post type.
 */
class Rest_API_Endpoint {
	/**
	 * Endpoint.
	 *
	 * @var array
	 */
	public static $endpoint = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		self::$endpoint = array(
			'route'               => '/utils/postid-by-url',
			'methods'             => 'GET',
			'callback'            => array( $this, 'restfully_get_postid_by_url' ),
			'permission_callback' => function () {
				return user_can( get_current_user_id(), 'edit_posts' );
			},
			'args'                => array(
				'url' => array(
					'validate_callback' => function ( $param, $request, $key ) {
						$url = filter_var( $param, FILTER_VALIDATE_URL );
						if ( false === $url ) {
							return false;
						}
						return true;
					},
				),
			),
		);
		add_action( 'rest_api_init', array( $this, 'register_rest_endpoints' ) );
	}

	/**
	 * Get the endpoint.
	 *
	 * @return array
	 */
	public function get_endpoint() {
		return self::$endpoint;
	}

	/**
	 * Gets the post id and post type for a url restfully.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_Error|array The post id and post type.
	 */
	public function restfully_get_postid_by_url( WP_REST_Request $request ) {
		$url = $request->get_param( 'url' );
		if ( empty( $url ) ) {
			return new WP_Error( 'no-url-provided', __( 'No url provided', 'my_textdomain' ), array( 'status' => 400 ) );
		}
		$url_helper = new URL_Helper( $url );
		$post_id    = $url_helper->get_post_id();
		if ( is_wp_error( $post_id ) || empty( $post_id ) ) {
			return new WP_Error( 'no-post-found', __( 'No post found', 'my_textdomain' ), array( 'status' => 404 ) );
		}
		return array(
			'postId'   => $post_id,
			'postType' => get_post_type( $post_id ),
		);
	}

	/**
	 * @hook rest_api_init
	 */
	public function register_rest_endpoints() {
		$endpoint = $this->get_endpoint();
		register_rest_route(
			'prc-api/v3',
			$endpoint['route'],
			array(
				'methods'             => $endpoint['methods'],
				'callback'            => $endpoint['callback'],
				'permission_callback' => $endpoint['permission_callback'],
				'args'                => $endpoint['args'],
			)
		);
	}
}
