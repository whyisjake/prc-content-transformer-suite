<?php
/**
 * Class ActionSchedulerHandlerTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Action_Scheduler_Handler;
use PRC\Platform\Audio_Narration\Loader;
use PRC\Platform\Audio_Narration\Narration_Store;

/**
 * Tests for background narration jobs.
 *
 * Action Scheduler is not bundled with this plugin, so the scheduling tests
 * are skipped when it is absent. The processing tests run either way, since
 * that is where the failure handling and hook contract live.
 */
class ActionSchedulerHandlerTest extends WP_UnitTestCase {

	/**
	 * Handler under test.
	 *
	 * @var Action_Scheduler_Handler
	 */
	private $handler;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->handler = new Action_Scheduler_Handler( new Loader() );
	}

	/**
	 * Create a published post.
	 *
	 * @return int
	 */
	private function make_post(): int {
		return self::factory()->post->create( array( 'post_status' => 'publish' ) );
	}

	/**
	 * Skip when Action Scheduler is not loaded.
	 */
	private function require_scheduler() {
		if ( ! Action_Scheduler_Handler::is_available() ) {
			$this->markTestSkipped( 'Action Scheduler is not available in this environment.' );
		}
	}

	/**
	 * Scheduling reports a clear error when the scheduler is missing.
	 */
	public function test_scheduling_without_action_scheduler_errors() {
		if ( Action_Scheduler_Handler::is_available() ) {
			$this->markTestSkipped( 'Action Scheduler is present.' );
		}

		$result = Action_Scheduler_Handler::schedule( $this->make_post() );

		$this->assertWPError( $result );
		$this->assertEquals( 'prc_audio_narration_scheduler_missing', $result->get_error_code() );
	}

	/**
	 * Scheduling a nonexistent post is refused.
	 */
	public function test_scheduling_missing_post_errors() {
		$this->require_scheduler();

		$this->assertWPError( Action_Scheduler_Handler::schedule( 999999 ) );
	}

	/**
	 * Scheduling queues exactly one action.
	 */
	public function test_schedule_queues_one_action() {
		$this->require_scheduler();

		$post_id = $this->make_post();

		$this->assertFalse( Action_Scheduler_Handler::is_pending( $post_id ) );

		$action_id = Action_Scheduler_Handler::schedule( $post_id );

		$this->assertIsInt( $action_id );
		$this->assertTrue( Action_Scheduler_Handler::is_pending( $post_id ) );
	}

	/**
	 * A second schedule for the same post is refused.
	 *
	 * Each duplicate is a real billable synthesis, so a double click must not
	 * cost twice.
	 */
	public function test_duplicate_schedule_is_refused() {
		$this->require_scheduler();

		$post_id = $this->make_post();
		Action_Scheduler_Handler::schedule( $post_id );

		$second = Action_Scheduler_Handler::schedule( $post_id );

		$this->assertWPError( $second );
		$this->assertEquals( 'prc_audio_narration_already_queued', $second->get_error_code() );
	}

	/**
	 * Different posts queue independently.
	 */
	public function test_different_posts_queue_independently() {
		$this->require_scheduler();

		$first  = $this->make_post();
		$second = $this->make_post();

		Action_Scheduler_Handler::schedule( $first );

		$this->assertTrue( Action_Scheduler_Handler::is_pending( $first ) );
		$this->assertFalse( Action_Scheduler_Handler::is_pending( $second ) );
		$this->assertIsInt( Action_Scheduler_Handler::schedule( $second ) );
	}

	/**
	 * Cancelling clears a queued job.
	 */
	public function test_cancel_clears_pending_job() {
		$this->require_scheduler();

		$post_id = $this->make_post();
		Action_Scheduler_Handler::schedule( $post_id );

		Action_Scheduler_Handler::cancel( $post_id );

		$this->assertFalse( Action_Scheduler_Handler::is_pending( $post_id ) );
	}

	/**
	 * Pending checks are safe when the scheduler is absent.
	 */
	public function test_is_pending_is_safe_without_scheduler() {
		if ( Action_Scheduler_Handler::is_available() ) {
			$this->markTestSkipped( 'Action Scheduler is present.' );
		}

		$this->assertFalse( Action_Scheduler_Handler::is_pending( $this->make_post() ) );
	}

	/**
	 * Processing a deleted post fires the failure hook rather than fataling.
	 */
	public function test_processing_missing_post_fires_failure_hook() {
		$captured = null;

		add_action(
			Action_Scheduler_Handler::FAILED_HOOK,
			function ( $post_id, $message, $retryable ) use ( &$captured ) {
				$captured = compact( 'post_id', 'message', 'retryable' );
			},
			10,
			3
		);

		$this->handler->process( 999999 );

		$this->assertIsArray( $captured );
		$this->assertFalse( $captured['retryable'], 'A deleted post can never succeed on retry.' );
	}

	/**
	 * A generation failure fires the failure hook with its message.
	 */
	public function test_generation_failure_fires_failure_hook() {
		$post_id  = $this->make_post();
		$captured = null;

		add_action(
			Action_Scheduler_Handler::FAILED_HOOK,
			function ( $failed_post_id, $message, $retryable ) use ( &$captured ) {
				$captured = compact( 'failed_post_id', 'message', 'retryable' );
			},
			10,
			3
		);

		// With no provider configured, generation fails immediately.
		$this->handler->process( $post_id );

		$this->assertIsArray( $captured, 'A failed job must report through the failure hook.' );
		$this->assertEquals( $post_id, $captured['failed_post_id'] );
		$this->assertNotEmpty( $captured['message'] );
	}

	/**
	 * A failed job leaves no narration behind.
	 */
	public function test_failed_job_stores_nothing() {
		$post_id = $this->make_post();

		$this->handler->process( $post_id );

		$this->assertFalse( ( new Narration_Store() )->has_narration( $post_id ) );
	}

	/**
	 * The completion hook does not fire for a failed job.
	 */
	public function test_completion_hook_does_not_fire_on_failure() {
		$post_id   = $this->make_post();
		$completed = false;

		add_action(
			Action_Scheduler_Handler::COMPLETE_HOOK,
			function () use ( &$completed ) {
				$completed = true;
			}
		);

		$this->handler->process( $post_id );

		$this->assertFalse( $completed );
	}
}
