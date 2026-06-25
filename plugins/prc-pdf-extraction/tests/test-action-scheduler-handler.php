<?php
/**
 * Class ActionSchedulerHandlerTest
 *
 * @package PRC_PDF_Extraction
 */

use PRC\Platform\PDF_Extraction\Action_Scheduler_Handler;
use PRC\Platform\PDF_Extraction\Content_Type;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Response;

/**
 * Tests for the Action_Scheduler_Handler class.
 *
 * Tests that can be exercised without live OCR providers cover:
 * - Hook registration
 * - AS function guards (schedule/is_pending when AS not loaded)
 * - process() early-exit paths: missing post, missing topline, already extracted
 *
 * The full OCR path (download → extract → save) requires live provider
 * credentials and is covered by integration tests run against Playground.
 */
class ActionSchedulerHandlerTest extends WP_UnitTestCase {
	// -------------------------------------------------------------------------
	// Constants
	// -------------------------------------------------------------------------

	/**
	 * Test that the hook name and group constants are defined correctly.
	 */
	public function test_constants_are_defined() {
		$this->assertEquals( 'prc_pdf_extraction_process_single', Action_Scheduler_Handler::ACTION_HOOK );
		$this->assertEquals( 'prc-pdf-extraction', Action_Scheduler_Handler::ACTION_GROUP );
	}

	// -------------------------------------------------------------------------
	// init() — hook registration
	// -------------------------------------------------------------------------

	/**
	 * init() registers the process callback on the correct hook with priority 10.
	 */
	public function test_init_registers_action_hook() {
		// Re-run init to ensure it's registered in this test run.
		Action_Scheduler_Handler::init();

		$priority = has_action(
			Action_Scheduler_Handler::ACTION_HOOK,
			array( Action_Scheduler_Handler::class, 'process' )
		);

		$this->assertEquals( 10, $priority );
	}

	// -------------------------------------------------------------------------
	// schedule() — AS availability guard
	// -------------------------------------------------------------------------

	/**
	 * schedule() returns false when as_enqueue_async_action() is not defined.
	 *
	 * In the WP unit-test environment Action Scheduler is not loaded, so this
	 * exercises the guard branch.
	 */
	public function test_schedule_returns_false_when_action_scheduler_unavailable() {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is loaded in this environment; guard branch cannot be tested.' );
		}

		$result = Action_Scheduler_Handler::schedule( 1, 2 );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// is_pending() — AS availability guard
	// -------------------------------------------------------------------------

	/**
	 * is_pending() returns false when as_get_scheduled_actions() is not defined.
	 */
	public function test_is_pending_returns_false_when_action_scheduler_unavailable() {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler is loaded in this environment; guard branch cannot be tested.' );
		}

		$result = Action_Scheduler_Handler::is_pending( 1, 2 );

		$this->assertFalse( $result );
	}

	// -------------------------------------------------------------------------
	// process() — early-exit paths
	// -------------------------------------------------------------------------

	/**
	 * process() returns early (without throwing) when the parent post does not exist.
	 */
	public function test_process_returns_early_for_missing_post() {
		$non_existent_post_id = 999999;

		// Should not throw.
		Action_Scheduler_Handler::process( $non_existent_post_id, 1 );

		// Verify no topline post was inadvertently created.
		$extractions = get_posts( array(
			'post_type'      => Content_Type::get_post_type(),
			'post_parent'    => $non_existent_post_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		) );

		$this->assertEmpty( $extractions );
	}

	/**
	 * process() returns early (without throwing) when the attachment ID is not
	 * present in the post's extraction materials list.
	 */
	public function test_process_returns_early_when_extraction_material_not_found() {
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent for extraction-material-not-found test',
			'post_status' => 'publish',
		) );

		// Add reportMaterials but with a different attachment ID.
		update_post_meta( $parent_id, 'reportMaterials', array(
			array(
				'type'         => 'topline',
				'label'        => 'Real Topline',
				'attachmentId' => 100,
				'url'          => 'https://example.com/topline.pdf',
			),
		) );

		$wrong_attachment_id = 999;

		// Should not throw.
		Action_Scheduler_Handler::process( $parent_id, $wrong_attachment_id );

		// Verify no extraction post was created.
		$extractions = get_posts( array(
			'post_type'      => Content_Type::get_post_type(),
			'post_parent'    => $parent_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		$this->assertEmpty( $extractions );
	}

	/**
	 * process() returns early (without throwing) when an extraction already
	 * exists for the given post + attachment (idempotency guard).
	 */
	public function test_process_is_idempotent_when_extraction_exists() {
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent for idempotency test',
			'post_status' => 'publish',
		) );

		$attachment_id = 200;

		update_post_meta( $parent_id, 'reportMaterials', array(
			array(
				'type'         => 'topline',
				'label'        => 'Idempotency Topline',
				'attachmentId' => $attachment_id,
				'url'          => 'https://example.com/topline.pdf',
			),
		) );

		// Create a pre-existing extraction.
		$existing_id = wp_insert_post( array(
			'post_type'   => Content_Type::get_post_type(),
			'post_title'  => 'Pre-existing Extraction',
			'post_parent' => $parent_id,
			'post_status' => 'publish',
		) );
		update_post_meta( $existing_id, '_pdf_source_attachment_id', $attachment_id );

		// Should not throw and should not create a second extraction.
		Action_Scheduler_Handler::process( $parent_id, $attachment_id );

		$extractions = get_posts( array(
			'post_type'      => Content_Type::get_post_type(),
			'post_parent'    => $parent_id,
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );

		// Still exactly one extraction — the pre-existing one.
		$this->assertCount( 1, $extractions );
		$this->assertEquals( $existing_id, $extractions[0] );
	}

	/**
	 * process() throws a RuntimeException when the PDF file path cannot be
	 * resolved (no local file and no URL provided).
	 *
	 * Action Scheduler catches and records exceptions, then retries the action.
	 */
	public function test_process_throws_when_file_path_cannot_be_resolved() {
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent for file-not-found test',
			'post_status' => 'publish',
		) );

		$attachment_id = 300;

		// Topline with no URL and no local attachment file.
		update_post_meta( $parent_id, 'reportMaterials', array(
			array(
				'type'         => 'topline',
				'label'        => 'No-file Topline',
				'attachmentId' => $attachment_id,
				// Deliberately omitting 'url'.
			),
		) );

		$this->expectException( \RuntimeException::class );

		Action_Scheduler_Handler::process( $parent_id, $attachment_id );
	}

	// -------------------------------------------------------------------------
	// schedule() — when Action Scheduler IS available
	// -------------------------------------------------------------------------

	/**
	 * schedule() returns an integer job ID when Action Scheduler is available.
	 */
	public function test_schedule_returns_job_id_when_action_scheduler_available() {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded in this environment.' );
		}

		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent for schedule test',
			'post_status' => 'publish',
		) );

		$job_id = Action_Scheduler_Handler::schedule( $parent_id, 400 );

		$this->assertIsInt( $job_id );
		$this->assertGreaterThan( 0, $job_id );
	}

	/**
	 * is_pending() returns true for a job that was just scheduled.
	 */
	public function test_is_pending_returns_true_after_scheduling() {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded in this environment.' );
		}

		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent for is_pending test',
			'post_status' => 'publish',
		) );

		Action_Scheduler_Handler::schedule( $parent_id, 500 );

		$this->assertTrue( Action_Scheduler_Handler::is_pending( $parent_id, 500 ) );
	}

	/**
	 * is_pending() returns false for post/attachment pairs that were never scheduled.
	 */
	public function test_is_pending_returns_false_when_not_scheduled() {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded in this environment.' );
		}

		$this->assertFalse( Action_Scheduler_Handler::is_pending( 888888, 999999 ) );
	}
}
