<?php
/**
 * Action Scheduler Handler for Content Transformations.
 *
 * @package PRC\Platform\Content_Transformer\Async
 */

namespace PRC\Platform\Content_Transformer\Async;

use PRC\Platform\Content_Transformer\Pipeline\Transformation_Pipeline;

/**
 * Registers and handles async transformation jobs via Action Scheduler.
 */
class Action_Scheduler_Handler {

	/**
	 * Action Scheduler hook name.
	 */
	const ACTION_HOOK = 'prc_content_transformer_process';

	/**
	 * Action Scheduler group name.
	 */
	const ACTION_GROUP = 'prc-content-transformer';

	/**
	 * Hook fired after a successful transformation.
	 */
	const COMPLETE_HOOK = 'prc_content_transformer_complete';

	/**
	 * Hook fired after a transformation failure.
	 */
	const FAILED_HOOK = 'prc_content_transformer_failed';

	/**
	 * Register the action hook so Action Scheduler can invoke the handler.
	 */
	public static function init(): void {
		add_action( self::ACTION_HOOK, array( __CLASS__, 'process' ), 10, 3 );
		add_action( self::FAILED_HOOK, array( __CLASS__, 'notify_failure' ), 10, 4 );
	}

	/**
	 * Enqueue an async Action Scheduler job.
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $provider_slug Provider slug.
	 * @param int    $user_id       User who requested the transformation.
	 * @return int|false Action Scheduler job ID, or false when AS is unavailable.
	 */
	public static function schedule( int $post_id, string $provider_slug, int $user_id = 0 ) {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}

		return as_enqueue_async_action(
			self::ACTION_HOOK,
			array(
				'post_id'  => $post_id,
				'provider' => $provider_slug,
				'user_id'  => $user_id,
			),
			self::ACTION_GROUP
		);
	}

	/**
	 * Check whether a transformation job is already pending.
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $provider_slug Provider slug.
	 * @return bool
	 */
	public static function is_pending( int $post_id, string $provider_slug ): bool {
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
					'post_id'  => $post_id,
					'provider' => $provider_slug,
				),
				'partial_args_matching' => 'like',
				'per_page'              => 1,
				'offset'                => 0,
			),
			'ids'
		);

		return ! empty( $actions );
	}

	/**
	 * Action Scheduler callback: run a single transformation.
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $provider_slug Provider slug.
	 * @param int    $user_id       User who requested the transformation.
	 * @throws \RuntimeException When the transformation fails.
	 */
	public static function process( int $post_id, string $provider_slug, int $user_id = 0 ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( "prc-content-transformer: async process — post {$post_id} not found." );
			return;
		}

		$result = Transformation_Pipeline::transform( $post_id, $provider_slug, true );

		if ( ! $result->is_success() ) {
			$message = sprintf(
				'prc-content-transformer: async process — transformation failed for post %d / provider %s: %s',
				$post_id,
				$provider_slug,
				$result->get_error()
			);
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $message );
			self::fire_failure( $post_id, $provider_slug, $user_id, $result->get_error() );
			throw new \RuntimeException( $result->get_error() );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf(
			'prc-content-transformer: async process — transformation complete for post %d / provider %s. Tokens: %d.',
			$post_id,
			$provider_slug,
			$result->get_tokens_used()
		) );

		/**
		 * Fires after a content transformation completes successfully.
		 *
		 * @param int    $post_id       Post ID.
		 * @param string $provider_slug Provider slug.
		 * @param string $output        The transformed content.
		 * @param int    $user_id       User who requested the transformation.
		 */
		do_action( self::COMPLETE_HOOK, $post_id, $provider_slug, $result->get_output(), $user_id );
	}

	/**
	 * Fire the failure hook.
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $provider_slug Provider slug.
	 * @param int    $user_id       Requesting user ID.
	 * @param string $error_message Error description.
	 */
	private static function fire_failure( int $post_id, string $provider_slug, int $user_id, string $error_message ): void {
		/**
		 * Fires when a content transformation fails.
		 *
		 * @param int    $post_id       Post ID.
		 * @param string $provider_slug Provider slug.
		 * @param int    $user_id       User who requested the transformation.
		 * @param string $error_message Error.
		 */
		do_action( self::FAILED_HOOK, $post_id, $provider_slug, $user_id, $error_message );
	}

	/**
	 * Send an email notification to the requesting user on failure.
	 *
	 * @param int    $post_id       Post ID.
	 * @param string $provider_slug Provider slug.
	 * @param int    $user_id       Requesting user ID.
	 * @param string $error_message Error description.
	 */
	public static function notify_failure( int $post_id, string $provider_slug, int $user_id, string $error_message ): void {
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
			__( 'Content transformation failed: %s', 'prc-content-transformer' ),
			$title
		);

		$body = sprintf(
			"The content transformation for \"%s\" (Post ID: %d, Provider: %s) has failed.\n\nError: %s\n\n",
			$title,
			$post_id,
			$provider_slug,
			$error_message
		);

		if ( $edit_link ) {
			$body .= sprintf( "Edit post: %s\n", $edit_link );
		}

		$body .= "\nYou can try running the transformation again from the editor or via WP-CLI.";

		wp_mail( $user->user_email, $subject, $body );
	}
}
