<?php
/**
 * Action Scheduler Handler
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

/**
 * Runs narration generation in the background.
 *
 * Synthesis of a report-length script takes far longer than a request should,
 * so generation is queued and an editor polls for the result.
 */
class Action_Scheduler_Handler {

	/**
	 * Hook fired to process one narration job.
	 */
	const ACTION_HOOK = 'prc_audio_narration_generate';

	/**
	 * Action Scheduler group.
	 */
	const ACTION_GROUP = 'prc-audio-narration';

	/**
	 * Fired after a job completes successfully.
	 */
	const COMPLETE_HOOK = 'prc_audio_narration_generate_complete';

	/**
	 * Fired after a job fails.
	 */
	const FAILED_HOOK = 'prc_audio_narration_generate_failed';

	/**
	 * The loader instance.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The hook loader.
	 */
	public function __construct( Loader $loader ) {
		$this->loader = $loader;

		$this->loader->add_action( self::ACTION_HOOK, $this, 'process', 10, 3 );
	}

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_get_scheduled_actions' );
	}

	/**
	 * Queue a narration job for a post.
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $voice_id Optional voice override.
	 * @param int    $user_id  User who requested generation.
	 * @return int|\WP_Error The action ID, or an error.
	 */
	public static function schedule( int $post_id, string $voice_id = '', int $user_id = 0 ) {
		if ( ! self::is_available() ) {
			return new \WP_Error(
				'prc_audio_narration_scheduler_missing',
				'Action Scheduler is not available, so narration cannot be queued.'
			);
		}

		if ( ! get_post( $post_id ) ) {
			return new \WP_Error( 'prc_audio_narration_missing_post', 'The post no longer exists.' );
		}

		if ( self::is_pending( $post_id ) ) {
			// Every duplicate job is a real, billable synthesis. Returning the
			// existing action rather than queueing a second one is what stops
			// a double click from costing twice.
			return new \WP_Error(
				'prc_audio_narration_already_queued',
				'Narration for this post is already queued.'
			);
		}

		$action_id = as_schedule_single_action(
			time(),
			self::ACTION_HOOK,
			array(
				'post_id'  => $post_id,
				'voice_id' => $voice_id,
				'user_id'  => $user_id,
			),
			self::ACTION_GROUP
		);

		return (int) $action_id;
	}

	/**
	 * Whether a narration job is queued or running for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return bool
	 */
	public static function is_pending( int $post_id ): bool {
		return ! empty( self::pending_action_ids( $post_id ) );
	}

	/**
	 * Action IDs of queued or running narration jobs for a post.
	 *
	 * Jobs are matched on the post_id argument alone, because the voice and
	 * requesting user vary between otherwise duplicate requests -- which is
	 * exactly why Action Scheduler's own exact-args matching cannot be used
	 * for either the duplicate guard or cancellation.
	 *
	 * @param int $post_id The post ID.
	 * @return int[]
	 */
	private static function pending_action_ids( int $post_id ): array {
		if ( ! self::is_available() ) {
			return array();
		}

		// Deliberately the object return format, not ARRAY_A: Action Scheduler
		// builds the array form with get_object_vars() against a class whose
		// properties are all private, so every entry comes back empty and the
		// args check below would never match.
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::ACTION_HOOK,
				'status'   => array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ),
				'per_page' => 100,
				'group'    => self::ACTION_GROUP,
			)
		);

		if ( empty( $actions ) || ! is_array( $actions ) ) {
			return array();
		}

		$matching = array();

		foreach ( $actions as $action_id => $action ) {
			if ( ! is_object( $action ) || ! method_exists( $action, 'get_args' ) ) {
				continue;
			}

			$args = $action->get_args();
			if ( isset( $args['post_id'] ) && (int) $args['post_id'] === $post_id ) {
				$matching[] = (int) $action_id;
			}
		}

		return $matching;
	}

	/**
	 * Cancel any queued narration job for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return int Number of jobs cancelled.
	 */
	public static function cancel( int $post_id ): int {
		$action_ids = self::pending_action_ids( $post_id );

		if ( empty( $action_ids ) || ! class_exists( '\ActionScheduler' ) ) {
			return 0;
		}

		$store     = \ActionScheduler::store();
		$cancelled = 0;

		foreach ( $action_ids as $action_id ) {
			$store->cancel_action( $action_id );
			++$cancelled;
		}

		return $cancelled;
	}

	/**
	 * Process one narration job.
	 *
	 * @param int    $post_id  The post ID.
	 * @param string $voice_id Optional voice override.
	 * @param int    $user_id  User who requested generation.
	 * @return void
	 */
	public function process( $post_id = 0, $voice_id = '', $user_id = 0 ): void {
		$post_id = (int) $post_id;

		if ( ! get_post( $post_id ) ) {
			$this->fail( $post_id, (int) $user_id, 'The post no longer exists.', false );
			return;
		}

		$service = new Narration_Service();
		$result  = $service->generate(
			$post_id,
			array(
				'voice_id' => (string) $voice_id,
				'force'    => true,
			)
		);

		if ( is_wp_error( $result ) ) {
			$data      = $result->get_error_data();
			$retryable = is_array( $data ) && ! empty( $data['retryable'] );

			$this->fail( $post_id, (int) $user_id, $result->get_error_message(), $retryable );
			return;
		}

		/**
		 * Fires after narration is generated successfully.
		 *
		 * @param int   $post_id The post ID.
		 * @param array $result  The stored narration record.
		 * @param int   $user_id User who requested generation.
		 */
		do_action( self::COMPLETE_HOOK, $post_id, $result, (int) $user_id );
	}

	/**
	 * Report a failed job.
	 *
	 * @param int    $post_id   The post ID.
	 * @param int    $user_id   User who requested generation.
	 * @param string $message   Failure message.
	 * @param bool   $retryable Whether a retry could succeed.
	 * @return void
	 */
	private function fail( int $post_id, int $user_id, string $message, bool $retryable ): void {
		/**
		 * Fires after narration generation fails.
		 *
		 * @param int    $post_id   The post ID.
		 * @param string $message   Failure message.
		 * @param bool   $retryable Whether a retry could succeed.
		 * @param int    $user_id   User who requested generation.
		 */
		do_action( self::FAILED_HOOK, $post_id, $message, $retryable, $user_id );
	}
}
