<?php
/**
 * Action Scheduler Handler for Topline Extraction
 *
 * Registers the async action hook and processes individual topline
 * extractions in the background via Action Scheduler. Uses the V2
 * per-page-image pipeline when available, with email notifications
 * on failure.
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

/**
 * Action_Scheduler_Handler registers and handles the async extraction job.
 */
class Action_Scheduler_Handler {
	/**
	 * Action Scheduler hook name.
	 */
	const ACTION_HOOK = 'prc_pdf_extraction_process_single';

	/**
	 * Action Scheduler group name.
	 */
	const ACTION_GROUP = 'prc-pdf-extraction';

	/**
	 * Hook fired after a successful extraction.
	 */
	const COMPLETE_HOOK = 'prc_pdf_extraction_process_complete';

	/**
	 * Hook fired after an extraction failure.
	 */
	const FAILED_HOOK = 'prc_pdf_extraction_process_failed';

	/**
	 * Register the action hook so Action Scheduler can invoke the handler.
	 *
	 * Must be called on every request (not just WP-CLI) so the hook is
	 * available when Action Scheduler processes queued jobs.
	 */
	public static function init(): void {
		add_action( self::ACTION_HOOK, array( __CLASS__, 'process' ), 10, 3 );
		add_action( self::FAILED_HOOK, array( __CLASS__, 'notify_failure' ), 10, 4 );
	}

	/**
	 * Enqueue an async Action Scheduler job for a single topline extraction.
	 *
	 * @param int $post_id       Parent post ID.
	 * @param int $attachment_id Topline PDF attachment ID.
	 * @param int $user_id       User who requested the conversion (for failure emails).
	 * @return int|false Action Scheduler job ID, or false when AS is unavailable.
	 */
	public static function schedule( int $post_id, int $attachment_id, int $user_id = 0 ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		return as_enqueue_async_action(
			self::ACTION_HOOK,
			array(
				'post_id'       => $post_id,
				'attachment_id' => $attachment_id,
				'user_id'       => $user_id,
			),
			self::ACTION_GROUP
		);
	}

	/**
	 * Check whether an extraction job is already pending in the queue.
	 *
	 * Uses a single-argument query against Action Scheduler instead of
	 * get_all_pending(), so REST endpoints (handle_convert, handle_status)
	 * avoid scanning the entire pending queue.
	 *
	 * @param int $post_id       Parent post ID.
	 * @param int $attachment_id Topline PDF attachment ID.
	 * @return bool
	 */
	public static function is_pending( int $post_id, int $attachment_id ): bool {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}

		$actions = as_get_scheduled_actions(
			array(
				'hook'                  => self::ACTION_HOOK,
				'status'                => array(
					\ActionScheduler_Store::STATUS_PENDING,
					\ActionScheduler_Store::STATUS_RUNNING,
				),
				'group'                 => self::ACTION_GROUP,
				'args'                  => array(
					'post_id'       => $post_id,
					'attachment_id' => $attachment_id,
				),
				'partial_args_matching' => 'like',
				'per_page'              => 1,
				'offset'                 => 0,
			),
			'ids'
		);

		return ! empty( $actions );
	}

	/**
	 * Build a lookup set of all pending topline extraction jobs.
	 *
	 * Paginates through every pending action in the group so nothing is missed.
	 * Returns an associative array keyed by "post_id:attachment_id" for O(1) lookups.
	 *
	 * @return array<string, true> Keys are "post_id:attachment_id".
	 */
	public static function get_all_pending(): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return array();
		}

		$pending  = array();
		$per_page = 100;
		$offset   = 0;

		do {
			$actions = as_get_scheduled_actions(
				array(
					'hook'     => self::ACTION_HOOK,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'group'    => self::ACTION_GROUP,
					'per_page' => $per_page,
					'offset'   => $offset,
				)
			);

			$action_count = count( $actions );

			foreach ( $actions as $action ) {
				$args = $action->get_args();
				if ( isset( $args['post_id'], $args['attachment_id'] ) ) {
					$pending[ (int) $args['post_id'] . ':' . (int) $args['attachment_id'] ] = true;
				}
			}

			$offset += $per_page;
		} while ( $action_count === $per_page );

		return $pending;
	}

	/**
	 * Action Scheduler callback: extract a single topline and persist the result.
	 *
	 * Throwing an exception causes Action Scheduler to mark the job as failed
	 * and schedule an automatic retry.
	 *
	 * @param int $post_id       Parent post ID.
	 * @param int $attachment_id Topline PDF attachment ID.
	 * @param int $user_id       User who requested the conversion.
	 * @throws \RuntimeException When OCR extraction or post-save fails.
	 */
	public static function process( int $post_id, int $attachment_id, int $user_id = 0 ): void {
		$service = new Extraction_Service();

		// Validate parent post.
		$post = get_post( $post_id );
		if ( ! $post ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "prc-pdf-extraction: process action — post {$post_id} not found." );
			return;
		}

		// Locate the extraction material that matches the scheduled attachment ID.
		$materials = $service->get_extraction_materials_from_post( $post_id );
		$material  = null;
		foreach ( $materials as $candidate ) {
			if ( isset( $candidate['attachmentId'] ) && (int) $candidate['attachmentId'] === $attachment_id ) {
				$material = $candidate;
				break;
			}
		}

		if ( null === $material ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "prc-pdf-extraction: process action — attachment {$attachment_id} not found in extraction materials for post {$post_id}." );
			return;
		}

		// Check for existing extraction — update it if present.
		$existing_id = $service->get_extraction_for_material( $post_id, $attachment_id );

		// Resolve the PDF file path.
		$file_path = $service->get_file_path_from_attachment( $attachment_id, $material );
		if ( ! $file_path ) {
			$message = "prc-pdf-extraction: process action — could not resolve PDF for attachment {$attachment_id} on post {$post_id}.";
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $message );
			self::fire_failure( $post_id, $attachment_id, $user_id, $message );
			throw new \RuntimeException( $message );
		}

		$is_temp = strpos( $file_path, sys_get_temp_dir() ) === 0;

		$start_time       = microtime( true );
		$response        = $service->extract_text( $file_path, $attachment_id );
		$duration_seconds = microtime( true ) - $start_time;

		if ( $is_temp ) {
			$service->cleanup_temp_file( $file_path );
		}

		if ( is_wp_error( $response ) ) {
			$message = "prc-pdf-extraction: process action — OCR failed for post {$post_id} / attachment {$attachment_id}: " . $response->get_error_message();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $message );
			self::fire_failure( $post_id, $attachment_id, $user_id, $response->get_error_message() );
			throw new \RuntimeException( $response->get_error_message() );
		}

		// Persist the extraction.
		$result = $service->save_extraction( $post_id, $material, $response, $existing_id, $duration_seconds );

		if ( is_wp_error( $result ) ) {
			$message = "prc-pdf-extraction: process action — save failed for post {$post_id} / attachment {$attachment_id}: " . $result->get_error_message();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $message );
			self::fire_failure( $post_id, $attachment_id, $user_id, $result->get_error_message() );
			throw new \RuntimeException( $result->get_error_message() );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'prc-pdf-extraction: process action — extraction %d saved for post %d / attachment %d. Duration: %.2fs. Cost: $%.4f.',
			$result,
			$post_id,
			$attachment_id,
			$duration_seconds,
			$response->get_cost()
		) );

		/**
		 * Fires after a topline extraction completes successfully.
		 *
		 * @param int $extraction_id The topline CPT post ID.
		 * @param int $post_id       Parent post ID.
		 * @param int $attachment_id PDF attachment ID.
		 * @param int $user_id       User who requested the conversion.
		 */
		do_action( self::COMPLETE_HOOK, $result, $post_id, $attachment_id, $user_id );
	}

	/**
	 * Fire the failure hook.
	 *
	 * @param int    $post_id       Parent post ID.
	 * @param int    $attachment_id Attachment ID.
	 * @param int    $user_id       Requesting user ID.
	 * @param string $error_message Error description.
	 */
	private static function fire_failure( int $post_id, int $attachment_id, int $user_id, string $error_message ): void {
		/**
		 * Fires when a topline extraction fails.
		 *
		 * @param int    $post_id       Parent post ID.
		 * @param int    $attachment_id PDF attachment ID.
		 * @param int    $user_id       User who requested the conversion.
		 * @param string $error_message Error description.
		 */
		do_action( self::FAILED_HOOK, $post_id, $attachment_id, $user_id, $error_message );
	}

	/**
	 * Send an email notification to the requesting user on failure.
	 *
	 * @param int    $post_id       Parent post ID.
	 * @param int    $attachment_id PDF attachment ID.
	 * @param int    $user_id       Requesting user ID.
	 * @param string $error_message Error description.
	 */
	public static function notify_failure( int $post_id, int $attachment_id, int $user_id, string $error_message ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}

		$post      = get_post( $post_id );
		$title     = $post ? $post->post_title : "Post #{$post_id}";
		$edit_link = $post ? get_edit_post_link( $post_id, 'raw' ) : '';

		$subject = sprintf(
			/* translators: %s: post title */
			__( 'Topline conversion failed: %s', 'prc-pdf-extraction' ),
			$title
		);

		$body = sprintf(
			"The topline PDF conversion for \"%s\" (Post ID: %d, Attachment ID: %d) has failed.\n\nError: %s\n\n",
			$title,
			$post_id,
			$attachment_id,
			$error_message
		);

		if ( $edit_link ) {
			$body .= sprintf( "Edit post: %s\n", $edit_link );
		}

		$body .= "\nYou can try running the conversion again from the Report Package panel in the block editor.";

		wp_mail( $user->user_email, $subject, $body );
	}
}
