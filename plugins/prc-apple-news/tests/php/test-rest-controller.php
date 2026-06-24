<?php
/**
 * Tests for REST_Controller class.
 *
 * @package PRC\Platform\Apple_News
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\Tests;

use PRC\Platform\Apple_News\REST_Controller;
use PRC\Platform\Apple_News\Loader;
use WP_UnitTestCase;
use WP_REST_Server;
use WP_REST_Request;

/**
 * @covers \PRC\Platform\Apple_News\REST_Controller
 */
class Test_REST_Controller extends WP_UnitTestCase {

	private int $post_id;
	private int $admin_id;

	public function set_up(): void {
		parent::set_up();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_author' => $this->admin_id,
			)
		);

		// Register routes.
		new REST_Controller( new Loader() );
		do_action( 'rest_api_init' );
	}

	// --- /status ---

	public function test_status_returns_not_found_for_missing_post(): void {
		$request  = new WP_REST_Request( 'GET', '/prc-apple-news/v1/status' );
		$request->set_param( 'post_id', 99999999 );
		$response = rest_do_request( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	public function test_status_returns_unpublished_state(): void {
		$request  = new WP_REST_Request( 'GET', '/prc-apple-news/v1/status' );
		$request->set_param( 'post_id', $this->post_id );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertFalse( $data['published'] );
		$this->assertNull( $data['article_id'] );
		$this->assertNull( $data['error'] );
	}

	public function test_status_returns_published_state(): void {
		update_post_meta( $this->post_id, 'apple_news_api_id', 'abc-123' );
		update_post_meta( $this->post_id, 'apple_news_api_share_url', 'https://apple.news/abc' );

		$request  = new WP_REST_Request( 'GET', '/prc-apple-news/v1/status' );
		$request->set_param( 'post_id', $this->post_id );
		$response = rest_do_request( $request );

		$data = $response->get_data();
		$this->assertTrue( $data['published'] );
		$this->assertEquals( 'abc-123', $data['article_id'] );
		$this->assertEquals( 'https://apple.news/abc', $data['share_url'] );
	}

	public function test_status_surfaces_error_transient(): void {
		set_transient( "_prc_apple_news_last_error_{$this->post_id}", 'Rate limit exceeded', HOUR_IN_SECONDS );

		$request  = new WP_REST_Request( 'GET', '/prc-apple-news/v1/status' );
		$request->set_param( 'post_id', $this->post_id );
		$response = rest_do_request( $request );

		$data = $response->get_data();
		$this->assertEquals( 'Rate limit exceeded', $data['error'] );
	}

	// --- DELETE /error ---

	public function test_dismiss_error_clears_transient(): void {
		set_transient( "_prc_apple_news_last_error_{$this->post_id}", 'Some error', HOUR_IN_SECONDS );

		$request  = new WP_REST_Request( 'DELETE', '/prc-apple-news/v1/error' );
		$request->set_param( 'post_id', $this->post_id );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertNull( $response->get_data()['error'] );
		$this->assertFalse( get_transient( "_prc_apple_news_last_error_{$this->post_id}" ) );
	}

	// --- POST /delete ---

	public function test_delete_returns_error_when_not_published(): void {
		$request  = new WP_REST_Request( 'POST', '/prc-apple-news/v1/delete' );
		$request->set_param( 'post_id', $this->post_id );
		$response = rest_do_request( $request );

		$this->assertEquals( 400, $response->get_status() );
	}

	// --- GET /preview ---

	public function test_preview_returns_not_found_for_missing_post(): void {
		$request  = new WP_REST_Request( 'GET', '/prc-apple-news/v1/preview' );
		$request->set_param( 'post_id', 99999999 );
		$response = rest_do_request( $request );

		$this->assertEquals( 404, $response->get_status() );
	}

	public function test_preview_returns_document_and_validation(): void {
		$request  = new WP_REST_Request( 'GET', '/prc-apple-news/v1/preview' );
		$request->set_param( 'post_id', $this->post_id );
		$response = rest_do_request( $request );

		$this->assertEquals( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'document', $data );
		$this->assertArrayHasKey( 'validation', $data );
		$this->assertIsArray( $data['document'] );
		$this->assertArrayHasKey( 'components', $data['document'] );
		$this->assertArrayHasKey( 'valid', $data['validation'] );
	}

	public function test_unauthenticated_preview_is_rejected(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'GET', '/prc-apple-news/v1/preview' );
		$request->set_param( 'post_id', $this->post_id );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	// --- Permission checks ---

	public function test_unauthenticated_push_is_rejected(): void {
		wp_set_current_user( 0 );

		$request  = new WP_REST_Request( 'POST', '/prc-apple-news/v1/push' );
		$request->set_param( 'post_id', $this->post_id );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}

	public function test_subscriber_cannot_push(): void {
		$sub_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $sub_id );

		$request  = new WP_REST_Request( 'POST', '/prc-apple-news/v1/push' );
		$request->set_param( 'post_id', $this->post_id );
		$response = rest_do_request( $request );

		$this->assertEquals( 403, $response->get_status() );
	}
}
