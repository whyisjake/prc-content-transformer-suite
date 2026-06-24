<?php
/**
 * Tests for Post_Sync class.
 *
 * @package PRC\Platform\Apple_News
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\Tests;

use PRC\Platform\Apple_News\Post_Sync;
use PRC\Platform\Apple_News\Loader;
use WP_UnitTestCase;
use WP_Post;
use WP_Error;

/**
 * @covers \PRC\Platform\Apple_News\Post_Sync
 */
class Test_Post_Sync extends WP_UnitTestCase {

	private Post_Sync $sync;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->sync    = new Post_Sync( new Loader() );
		$this->post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'post',
			)
		);
	}

	// --- handle_status_transition ---

	public function test_skips_on_non_production_environment(): void {
		// wp_get_environment_type() returns 'local' in test suite.
		$enqueued = false;
		add_filter(
			'pre_option_as_version',
			function() use ( &$enqueued ) {
				$enqueued = true;
				return false;
			}
		);

		$post           = get_post( $this->post_id );
		$this->sync->handle_status_transition( 'publish', 'draft', $post );

		$this->assertFalse( $enqueued, 'Should not enqueue on non-production environments.' );
	}

	public function test_skips_on_wrong_post_type(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_type'   => 'page',
			)
		);
		$page = get_post( $page_id );

		$actions_before = as_get_scheduled_actions(
			array( 'hook' => Post_Sync::PUSH_ACTION ),
			'ARRAY_A'
		);

		$this->sync->handle_status_transition( 'publish', 'draft', $page );

		$actions_after = as_get_scheduled_actions(
			array( 'hook' => Post_Sync::PUSH_ACTION ),
			'ARRAY_A'
		);

		$this->assertCount(
			count( $actions_before ),
			$actions_after,
			'Should not enqueue for non-supported post types.'
		);
	}

	// --- execute_push: stale lock ---

	public function test_clears_stale_lock_and_proceeds(): void {
		// Set a lock timestamp from 20 minutes ago.
		$stale_time = gmdate( 'c', time() - 20 * MINUTE_IN_SECONDS );
		update_post_meta( $this->post_id, 'apple_news_api_pending', $stale_time );

		// No credentials configured — execute_push should clear the lock and then bail
		// at the credentials check (which returns null in test env).
		$this->sync->execute_push( $this->post_id );

		$pending = get_post_meta( $this->post_id, 'apple_news_api_pending', true );
		// The stale lock should have been cleared; the method bails at credentials check
		// before setting a new lock.
		$this->assertEmpty( $pending, 'Stale lock should be cleared.' );
	}

	public function test_skips_when_fresh_lock_exists(): void {
		// Set a lock timestamp from 5 minutes ago (< 15-minute threshold).
		$fresh_time = gmdate( 'c', time() - 5 * MINUTE_IN_SECONDS );
		update_post_meta( $this->post_id, 'apple_news_api_pending', $fresh_time );

		$log_messages = array();
		add_filter(
			'wp_error_handler',
			function( $message ) use ( &$log_messages ) {
				$log_messages[] = $message;
			}
		);

		$this->sync->execute_push( $this->post_id );

		// The fresh lock should remain untouched.
		$pending = get_post_meta( $this->post_id, 'apple_news_api_pending', true );
		$this->assertEquals( $fresh_time, $pending, 'Fresh lock should not be disturbed.' );
	}

	// --- execute_push: error transient ---

	public function test_stores_error_transient_on_api_failure(): void {
		// Simulate a failure by filtering the AS action to use a mock that injects an error.
		$error_message = 'Apple News API unreachable';

		// Directly call handle_push_failure (protected via closure trick).
		$reflection = new \ReflectionMethod( $this->sync, 'handle_push_failure' );
		$reflection->setAccessible( true );
		$reflection->invoke( $this->sync, $this->post_id, new WP_Error( 'api_error', $error_message ) );

		$stored = get_transient( "_prc_apple_news_last_error_{$this->post_id}" );
		$this->assertEquals( $error_message, $stored, 'Error transient should be stored on push failure.' );
	}

	public function test_clears_pending_on_api_failure(): void {
		update_post_meta( $this->post_id, 'apple_news_api_pending', gmdate( 'c' ) );

		$reflection = new \ReflectionMethod( $this->sync, 'handle_push_failure' );
		$reflection->setAccessible( true );
		$reflection->invoke( $this->sync, $this->post_id, new WP_Error( 'api_error', 'err' ) );

		$pending = get_post_meta( $this->post_id, 'apple_news_api_pending', true );
		$this->assertEmpty( $pending, 'Pending lock should be cleared on failure.' );
	}

	// --- delete_from_apple_news ---

	public function test_delete_returns_error_when_not_published(): void {
		$result = Post_Sync::delete_from_apple_news( $this->post_id );

		$this->assertWPError( $result );
		$this->assertEquals( 'not_published', $result->get_error_code() );
	}

	// --- push_now with force ---

	public function test_push_now_with_force_bypasses_lock(): void {
		// Set a fresh lock.
		$fresh_time = gmdate( 'c', time() - 2 * MINUTE_IN_SECONDS );
		update_post_meta( $this->post_id, 'apple_news_api_pending', $fresh_time );

		// With force=true, execute_push runs with bypass_lock=true.
		// No credentials → it should bail at credentials check, not at lock check.
		// That means the original lock value will be overwritten by the new pending timestamp,
		// then cleared when credentials check bails.
		$this->sync->push_now( $this->post_id, true );

		// The fresh lock should have been replaced then cleared (bailed at credentials).
		$pending = get_post_meta( $this->post_id, 'apple_news_api_pending', true );
		$this->assertEmpty( $pending, 'Force push should bypass lock; no-credentials bail clears pending.' );
	}

	// --- build_article_metadata ---

	public function test_build_article_metadata_reflects_post_meta(): void {
		update_post_meta( $this->post_id, 'apple_news_is_preview', true );
		update_post_meta( $this->post_id, 'apple_news_is_hidden', false );

		$reflection = new \ReflectionMethod( $this->sync, 'build_article_metadata' );
		$reflection->setAccessible( true );
		$meta = $reflection->invoke( $this->sync, $this->post_id );

		$this->assertSame(
			array(
				'data' => array(
					'isPreview' => true,
					'isHidden'  => false,
				),
			),
			$meta
		);
	}

	public function test_build_article_metadata_defaults_to_false(): void {
		$reflection = new \ReflectionMethod( $this->sync, 'build_article_metadata' );
		$reflection->setAccessible( true );
		$meta = $reflection->invoke( $this->sync, $this->post_id );

		$this->assertSame(
			array(
				'data' => array(
					'isPreview' => false,
					'isHidden'  => false,
				),
			),
			$meta
		);
	}
}
