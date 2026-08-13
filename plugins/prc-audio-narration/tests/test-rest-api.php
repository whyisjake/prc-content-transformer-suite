<?php
/**
 * Class RestApiTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Action_Scheduler_Handler;
use PRC\Platform\Audio_Narration\Narration_Store;
use PRC\Platform\Audio_Narration\REST_API;

/**
 * Tests for the narration REST endpoints.
 */
class RestApiTest extends WP_UnitTestCase {

	/**
	 * Editor user ID.
	 *
	 * @var int
	 */
	private $editor;

	/**
	 * Subscriber user ID.
	 *
	 * @var int
	 */
	private $subscriber;

	/**
	 * Set up the REST server and users.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$this->editor     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Create a published post.
	 *
	 * @return int
	 */
	private function make_post(): int {
		return self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Trust in local news',
				'post_content' => 'Seventy-two percent of Americans agree.',
			)
		);
	}

	/**
	 * Dispatch a narration request.
	 *
	 * @param string $method  HTTP method.
	 * @param int    $post_id Post ID.
	 * @param array  $params  Query or body params.
	 * @return WP_REST_Response
	 */
	private function dispatch( string $method, int $post_id, array $params = array() ) {
		$request = new WP_REST_Request( $method, '/' . REST_API::NAMESPACE_V1 . "/posts/{$post_id}/narration" );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The routes are registered.
	 */
	public function test_routes_are_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey(
			'/' . REST_API::NAMESPACE_V1 . '/posts/(?P<post_id>\d+)/narration',
			$routes
		);
	}

	/**
	 * A post with no narration reports the none state.
	 */
	public function test_status_reports_none() {
		wp_set_current_user( $this->editor );
		$post_id = $this->make_post();

		$response = $this->dispatch( 'GET', $post_id );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'none', $response->get_data()['state'] );
		$this->assertNull( $response->get_data()['narration'] );
	}

	/**
	 * A narrated post reports ready with its URL.
	 */
	public function test_status_reports_ready() {
		wp_set_current_user( $this->editor );
		$post_id = $this->make_post();
		( new Narration_Store() )->store( $post_id, 'AUDIO', array( 'provider' => 'fake' ) );

		$data = $this->dispatch( 'GET', $post_id )->get_data();

		$this->assertEquals( 'ready', $data['state'] );
		$this->assertNotEmpty( $data['narration']['url'] );
	}

	/**
	 * An edited post reports stale.
	 */
	public function test_status_reports_stale() {
		wp_set_current_user( $this->editor );
		$post_id = $this->make_post();
		( new Narration_Store() )->store( $post_id, 'AUDIO' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Entirely different content.',
			)
		);

		$this->assertEquals( 'stale', $this->dispatch( 'GET', $post_id )->get_data()['state'] );
	}

	/**
	 * A queued job reports pending.
	 */
	public function test_status_reports_pending() {
		if ( ! Action_Scheduler_Handler::is_available() ) {
			$this->markTestSkipped( 'Action Scheduler is not available.' );
		}

		wp_set_current_user( $this->editor );
		$post_id = $this->make_post();
		Action_Scheduler_Handler::schedule( $post_id );

		$this->assertEquals( 'pending', $this->dispatch( 'GET', $post_id )->get_data()['state'] );
	}

	/**
	 * Status omits the estimate unless it is asked for.
	 *
	 * Estimating resolves the narration script, which is an AI call. Polling
	 * for job progress must not quietly run one on every tick.
	 */
	public function test_status_omits_estimate_by_default() {
		wp_set_current_user( $this->editor );
		$post_id = $this->make_post();

		$this->assertArrayNotHasKey( 'estimate', $this->dispatch( 'GET', $post_id )->get_data() );
	}

	/**
	 * Requesting an estimate includes one.
	 */
	public function test_status_includes_estimate_on_request() {
		wp_set_current_user( $this->editor );
		$post_id = $this->make_post();

		$data = $this->dispatch( 'GET', $post_id, array( 'estimate' => true ) )->get_data();

		$this->assertArrayHasKey( 'estimate', $data );
	}

	/**
	 * A user without edit rights on the post is refused.
	 *
	 * @dataProvider method_provider
	 *
	 * @param string $method HTTP method.
	 */
	public function test_forbidden_for_non_editor( $method ) {
		wp_set_current_user( $this->subscriber );
		$post_id = $this->make_post();

		$response = $this->dispatch( $method, $post_id );

		$this->assertEquals( 403, $response->get_status() );
	}

	/**
	 * A logged-out request is refused.
	 *
	 * @dataProvider method_provider
	 *
	 * @param string $method HTTP method.
	 */
	public function test_forbidden_for_logged_out( $method ) {
		wp_set_current_user( 0 );
		$post_id = $this->make_post();

		$this->assertEquals( 401, $this->dispatch( $method, $post_id )->get_status() );
	}

	/**
	 * Methods guarded by the permission callback.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function method_provider() {
		return array(
			'read'   => array( 'GET' ),
			'create' => array( 'POST' ),
			'delete' => array( 'DELETE' ),
		);
	}

	/**
	 * A nonexistent post is a 404, not a 403.
	 */
	public function test_missing_post_is_404() {
		wp_set_current_user( $this->editor );

		$this->assertEquals( 404, $this->dispatch( 'GET', 999999 )->get_status() );
	}

	/**
	 * Generation without a configured provider is refused with a clear error.
	 *
	 * The provider list is emptied through its own filter rather than relying
	 * on the environment having no API key. A developer machine with a real
	 * key configured would otherwise see this test fail for the wrong reason.
	 */
	public function test_generate_without_provider_is_rejected() {
		add_filter( 'prc_audio_narration_tts_providers', '__return_empty_array' );

		wp_set_current_user( $this->editor );
		$post_id = $this->make_post();

		$response = $this->dispatch( 'POST', $post_id );

		$this->assertEquals( 400, $response->get_status() );
		$this->assertEquals( 'prc_audio_narration_no_provider', $response->get_data()['code'] );
	}

	/**
	 * Generating while a job is queued returns the pending state.
	 *
	 * A second click must not queue a second billable synthesis.
	 */
	public function test_generate_while_pending_returns_pending() {
		if ( ! Action_Scheduler_Handler::is_available() ) {
			$this->markTestSkipped( 'Action Scheduler is not available.' );
		}

		wp_set_current_user( $this->editor );
		$post_id = $this->make_post();
		Action_Scheduler_Handler::schedule( $post_id );

		$response = $this->dispatch( 'POST', $post_id );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'pending', $response->get_data()['state'] );
	}

	/**
	 * Deleting removes the narration and returns the none state.
	 */
	public function test_delete_removes_narration() {
		wp_set_current_user( $this->editor );
		$post_id = $this->make_post();
		( new Narration_Store() )->store( $post_id, 'AUDIO' );

		$response = $this->dispatch( 'DELETE', $post_id );

		$this->assertEquals( 200, $response->get_status() );
		$this->assertEquals( 'none', $response->get_data()['state'] );
		$this->assertFalse( ( new Narration_Store() )->has_narration( $post_id ) );
	}

	/**
	 * Deleting also cancels any queued job.
	 *
	 * Otherwise the job would run after removal and silently resurrect audio
	 * the editor just deleted.
	 */
	public function test_delete_cancels_pending_job() {
		if ( ! Action_Scheduler_Handler::is_available() ) {
			$this->markTestSkipped( 'Action Scheduler is not available.' );
		}

		wp_set_current_user( $this->editor );
		$post_id = $this->make_post();
		Action_Scheduler_Handler::schedule( $post_id );

		$this->dispatch( 'DELETE', $post_id );

		$this->assertFalse( Action_Scheduler_Handler::is_pending( $post_id ) );
	}

	/**
	 * Deleting a post with no narration is harmless.
	 */
	public function test_delete_without_narration_is_safe() {
		wp_set_current_user( $this->editor );
		$post_id = $this->make_post();

		$this->assertEquals( 200, $this->dispatch( 'DELETE', $post_id )->get_status() );
	}
}
