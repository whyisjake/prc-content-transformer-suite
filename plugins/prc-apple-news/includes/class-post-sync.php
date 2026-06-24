<?php
/**
 * Post Sync class.
 *
 * @package PRC\Platform\Apple_News
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News;

use PRC\Platform\Apple_News\ANF\ANF_Block_Converter;
use WP_Error;
use WP_Post;

/**
 * Handles publishing posts to Apple News via Action Scheduler.
 *
 * A single push job builds ANF JSON with the deterministic block converter,
 * post-processes it, and pushes to the Apple News API. Blocks without explicit
 * handlers fall back to prc-content-transformer per contiguous fragment.
 */
class Post_Sync {

	const PUSH_ACTION        = 'prc_apple_news_push';
	const AS_GROUP           = 'prc-apple-news';
	const MAX_RETRIES        = 3;
	const LOCK_STALE_MINUTES = 15;

	protected Loader $loader;

	/**
	 * @param Loader $loader Hook registration loader.
	 */
	public function __construct( Loader $loader ) {
		$this->loader = $loader;

		$loader->add_action( 'transition_post_status', $this, 'handle_status_transition', 10, 3 );
		$loader->add_action( self::PUSH_ACTION, $this, 'run_push_job', 10, 1 );
	}

	/**
	 * Enqueues a push job when a supported post type is published on production.
	 *
	 * @hook transition_post_status
	 */
	public function handle_status_transition( string $new_status, string $old_status, WP_Post $post ): void {
		if ( wp_get_environment_type() !== 'production' || $new_status !== 'publish' ) {
			return;
		}

		if ( ! in_array( $post->post_type, array( 'post', 'short-read' ), true ) ) {
			return;
		}

		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			return;
		}

		as_enqueue_async_action( self::PUSH_ACTION, array( 'post_id' => $post->ID ), self::AS_GROUP );
	}

	/**
	 * Build ANF JSON, post-process, and push to Apple News.
	 *
	 * @hook prc_apple_news_push
	 */
	public function run_push_job( int $post_id ): void {
		$this->execute_push( $post_id );
	}

	/**
	 * Core push logic. Used by both the AS job and the synchronous push_now() path.
	 *
	 * @param int  $post_id     The post ID.
	 * @param bool $bypass_lock When true, skips the stale-lock check (for forced pushes).
	 */
	public function execute_push( int $post_id, bool $bypass_lock = false ): void {
		$credentials = Settings::get_credentials();
		if ( null === $credentials ) {
			error_log( "PRC Apple News: credentials not configured for post {$post_id}" );
			return;
		}

		if ( ! $bypass_lock ) {
			$pending = get_post_meta( $post_id, 'apple_news_api_pending', true );
			if ( ! empty( $pending ) ) {
				$lock_age = time() - (int) strtotime( (string) $pending );
				if ( $lock_age < ( self::LOCK_STALE_MINUTES * MINUTE_IN_SECONDS ) ) {
					return;
				}
				error_log( "PRC Apple News: clearing stale lock for post {$post_id}" );
				delete_post_meta( $post_id, 'apple_news_api_pending' );
			}
		}

		update_post_meta( $post_id, 'apple_news_api_pending', gmdate( 'c' ) );

		$anf_json = ( new ANF_Block_Converter() )->build( $post_id );
		if ( empty( $anf_json ) || null === json_decode( $anf_json ) ) {
			$this->handle_push_failure(
				$post_id,
				new WP_Error( 'invalid_anf_json', 'Deterministic ANF build returned invalid JSON.' )
			);
			return;
		}

		$processed_json = ( new ANF\ANF_Post_Processor() )->process( (string) $anf_json, $post_id );

		$validation = ( new ANF\ANF_Validator() )->validate_json( $processed_json );
		if ( is_wp_error( $validation ) ) {
			$raw_errors = $validation->get_error_data( 'anf_schema_error' )['errors'] ?? array();
			update_post_meta( $post_id, '_apple_news_validation_errors', wp_json_encode( $raw_errors ) );
			update_post_meta( $post_id, 'apple_news_api_pending', 'validation_failed' );
			error_log( "PRC Apple News: ANF schema validation failed for post {$post_id}: " . $validation->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}
		delete_post_meta( $post_id, '_apple_news_validation_errors' );

		$referential = ( new ANF\ANF_Referential_Validator() )->validate_json( $processed_json );
		if ( is_wp_error( $referential ) ) {
			$raw_errors = $referential->get_error_data( 'anf_referential_error' )['errors'] ?? array();
			update_post_meta( $post_id, '_apple_news_validation_errors', wp_json_encode( $raw_errors ) );
			update_post_meta( $post_id, 'apple_news_api_pending', 'validation_failed' );
			error_log( "PRC Apple News: ANF referential validation failed for post {$post_id}: " . $referential->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return;
		}
		delete_post_meta( $post_id, '_apple_news_validation_errors' );

		$article_id   = get_post_meta( $post_id, 'apple_news_api_id', true );
		$channel_uuid = Settings::get_channel_uuid();
		$api          = new Apple_News_API\API( $credentials );
		$meta         = $this->build_article_metadata( $post_id );
		$result       = null;

		if ( empty( $article_id ) ) {
			$result = $this->create_with_retries( $api, $processed_json, $article_id, $channel_uuid, $meta, $post_id );
		} else {
			$revision = (string) get_post_meta( $post_id, 'apple_news_api_revision', true );
			$result   = $this->update_with_retries( $api, $article_id, $revision, $processed_json, $meta, $post_id );
		}

		if ( is_wp_error( $result ) ) {
			$this->handle_push_failure( $post_id, $result );
			return;
		}

		$data = $result->data ?? null;
		if ( ! empty( $data->id ) ) {
			update_post_meta( $post_id, 'apple_news_api_id', sanitize_text_field( $data->id ) );
		}
		if ( ! empty( $data->revision ) ) {
			update_post_meta( $post_id, 'apple_news_api_revision', sanitize_text_field( $data->revision ) );
		}
		if ( ! empty( $data->createdAt ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			update_post_meta( $post_id, 'apple_news_api_created_at', sanitize_text_field( $data->createdAt ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		if ( ! empty( $data->modifiedAt ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			update_post_meta( $post_id, 'apple_news_api_modified_at', sanitize_text_field( $data->modifiedAt ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}
		if ( ! empty( $data->shareUrl ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			update_post_meta( $post_id, 'apple_news_api_share_url', esc_url_raw( $data->shareUrl ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		delete_post_meta( $post_id, 'apple_news_api_pending' );
		delete_transient( "_prc_apple_news_last_error_{$post_id}" );
	}

	/**
	 * Build Apple News API article metadata from post meta.
	 *
	 * @param int $post_id The post ID.
	 * @return array{data: array<string, bool>}
	 */
	private function build_article_metadata( int $post_id ): array {
		return array(
			'data' => array(
				'isPreview' => (bool) get_post_meta( $post_id, 'apple_news_is_preview', true ),
				'isHidden'  => (bool) get_post_meta( $post_id, 'apple_news_is_hidden', true ),
			),
		);
	}

	/**
	 * Attempt to create an article, falling back to update on 409 Conflict.
	 *
	 * @param Apple_News_API\API $api
	 * @param string             $anf_json
	 * @param string             $article_id   Passed by reference; populated on conflict resolution.
	 * @param string             $channel_uuid The Apple News channel UUID.
	 * @param array              $meta         Article metadata for the API request.
	 * @param int                $post_id      WordPress post ID.
	 * @return \stdClass|WP_Error
	 */
	private function create_with_retries( Apple_News_API\API $api, string $anf_json, string &$article_id, string $channel_uuid, array $meta, int $post_id ): \stdClass|WP_Error {
		$last_error = null;

		for ( $i = 0; $i < self::MAX_RETRIES; $i++ ) {
			$result = $api->post_article_to_channel( $anf_json, $channel_uuid, $meta, $post_id );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$last_error = $result;

			if ( 'apple_news_conflict' !== $result->get_error_code() ) {
				break;
			}

			if ( ! empty( $article_id ) ) {
				$existing = $api->get_article( $article_id );
				if ( ! is_wp_error( $existing ) && ! empty( $existing->data->revision ) ) {
					return $this->update_with_retries( $api, $article_id, $existing->data->revision, $anf_json, $meta, $post_id );
				}
			}
		}

		return $last_error ?? new WP_Error( 'apple_news_create_failed', 'Failed to create Apple News article.' );
	}

	/**
	 * Attempt to update an article, refreshing revision on 409 Conflict.
	 *
	 * @param Apple_News_API\API $api
	 * @param string             $article_id
	 * @param string             $revision
	 * @param string             $anf_json
	 * @param array              $meta    Article metadata for the API request.
	 * @param int                $post_id WordPress post ID.
	 * @return array|WP_Error
	 */
	private function update_with_retries( Apple_News_API\API $api, string $article_id, string $revision, string $anf_json, array $meta, int $post_id ): array|WP_Error {
		$last_error = null;

		for ( $i = 0; $i < self::MAX_RETRIES; $i++ ) {
			$result = $api->update_article( $article_id, $revision, $anf_json, $meta, $post_id );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$last_error = $result;

			if ( 'apple_news_conflict' !== $result->get_error_code() ) {
				break;
			}

			$existing = $api->get_article( $article_id );
			if ( ! is_wp_error( $existing ) && ! empty( $existing->data->revision ) ) {
				$revision = $existing->data->revision;
			}
		}

		return $last_error ?? new WP_Error( 'apple_news_update_failed', 'Failed to update Apple News article.' );
	}

	/**
	 * Handle a failed push: clear lock, store error transient.
	 */
	protected function handle_push_failure( int $post_id, WP_Error $error ): void {
		delete_post_meta( $post_id, 'apple_news_api_pending' );
		set_transient( "_prc_apple_news_last_error_{$post_id}", $error->get_error_message(), 2 * DAY_IN_SECONDS );
		error_log( "PRC Apple News: push failed for post {$post_id}: " . $error->get_error_message() );
	}

	/**
	 * Synchronous push path for REST and CLI consumers.
	 *
	 * @param int  $post_id The post ID.
	 * @param bool $force   When true, clears any existing pending lock before queuing.
	 */
	public function push_now( int $post_id, bool $force = false ): void {
		if ( $force ) {
			delete_post_meta( $post_id, 'apple_news_api_pending' );
			delete_transient( "_prc_apple_news_last_error_{$post_id}" );
		}

		update_post_meta( $post_id, 'apple_news_api_pending', gmdate( 'c' ) );
		as_enqueue_async_action( self::PUSH_ACTION, array( 'post_id' => $post_id ), self::AS_GROUP );
	}

	/**
	 * Remove an article from Apple News and clear all associated meta.
	 *
	 * @param int $post_id The post ID.
	 * @return true|WP_Error
	 */
	public static function delete_from_apple_news( int $post_id ): true|WP_Error {
		$credentials = Settings::get_credentials();
		if ( null === $credentials ) {
			return new WP_Error( 'no_credentials', 'Apple News credentials are not configured.' );
		}

		$article_id = get_post_meta( $post_id, 'apple_news_api_id', true );
		if ( empty( $article_id ) ) {
			return new WP_Error( 'not_published', 'This post has not been published to Apple News.' );
		}

		$api    = new Apple_News_API\API( $credentials );
		$result = $api->delete_article( (string) $article_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		foreach ( array( 'apple_news_api_id', 'apple_news_api_revision', 'apple_news_api_created_at', 'apple_news_api_modified_at', 'apple_news_api_share_url' ) as $key ) {
			delete_post_meta( $post_id, $key );
		}
		delete_post_meta( $post_id, 'apple_news_api_pending' );
		delete_transient( "_prc_apple_news_last_error_{$post_id}" );

		return true;
	}
}
