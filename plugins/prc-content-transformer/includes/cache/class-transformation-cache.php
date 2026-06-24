<?php
/**
 * Transformation Cache.
 *
 * @package PRC\Platform\Content_Transformer\Cache
 */

namespace PRC\Platform\Content_Transformer\Cache;

use PRC\Platform\Content_Transformer\Loader;
use PRC\Platform\Content_Transformer\Pipeline\Transformation_Result;
use PRC\Platform\Content_Transformer\Providers\Provider_Registry;

/**
 * Caches transformation results in post meta, keyed by provider slug.
 * Invalidates automatically when post_content changes.
 */
class Transformation_Cache {

	/**
	 * Meta key prefix for cached results.
	 */
	const META_PREFIX = '_prc_ct_';

	/**
	 * @param Loader $loader The hook loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'save_post', $this, 'maybe_invalidate', 10, 2 );
	}

	/**
	 * Build the meta key for a provider's cached result.
	 *
	 * @param string $provider_slug Provider slug.
	 * @return string
	 */
	private static function result_key( string $provider_slug ): string {
		return self::META_PREFIX . $provider_slug . '_result';
	}

	/**
	 * Build the meta key for the content hash associated with a cached result.
	 *
	 * @param string $provider_slug Provider slug.
	 * @return string
	 */
	private static function hash_key( string $provider_slug ): string {
		return self::META_PREFIX . $provider_slug . '_hash';
	}

	/**
	 * Get a cached transformation result if the content hash still matches.
	 *
	 * @param int    $post_id       The post ID.
	 * @param string $provider_slug The provider slug.
	 * @return Transformation_Result|null Cached result, or null if stale/missing.
	 */
	public static function get( int $post_id, string $provider_slug ): ?Transformation_Result {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$stored_hash = get_post_meta( $post_id, self::hash_key( $provider_slug ), true );
		if ( empty( $stored_hash ) ) {
			return null;
		}

		$current_hash = md5( $post->post_content );
		if ( $stored_hash !== $current_hash ) {
			self::clear( $post_id, $provider_slug );
			return null;
		}

		$data = get_post_meta( $post_id, self::result_key( $provider_slug ), true );
		if ( empty( $data ) || ! is_array( $data ) ) {
			return null;
		}

		$result = Transformation_Result::from_array( $data );

		return new Transformation_Result(
			'cached',
			$result->get_output(),
			$result->get_provider(),
			$result->get_timestamp(),
			$result->get_tokens_used(),
			$result->get_error()
		);
	}

	/**
	 * Store a transformation result in post meta.
	 *
	 * @param int                   $post_id       The post ID.
	 * @param string                $provider_slug The provider slug.
	 * @param string                $post_content  The post_content at time of transformation.
	 * @param Transformation_Result $result        The result to store.
	 */
	public static function set(
		int $post_id,
		string $provider_slug,
		string $post_content,
		Transformation_Result $result
	): void {
		// wp_slash() counteracts wp_unslash() inside update_post_meta so backslashes
		// in the JSON output (e.g. \" escaping quotes in href attributes) survive the round-trip.
		update_post_meta( $post_id, self::result_key( $provider_slug ), wp_slash( $result->to_array() ) );
		update_post_meta( $post_id, self::hash_key( $provider_slug ), md5( $post_content ) );
	}

	/**
	 * Clear cached result for a specific provider on a post.
	 *
	 * @param int    $post_id       The post ID.
	 * @param string $provider_slug The provider slug.
	 */
	public static function clear( int $post_id, string $provider_slug ): void {
		delete_post_meta( $post_id, self::result_key( $provider_slug ) );
		delete_post_meta( $post_id, self::hash_key( $provider_slug ) );
	}

	/**
	 * Clear all cached transformation results for a post.
	 *
	 * @param int $post_id The post ID.
	 */
	public static function clear_all( int $post_id ): void {
		foreach ( Provider_Registry::get_all() as $provider ) {
			self::clear( $post_id, $provider->get_slug() );
		}
	}

	/**
	 * Invalidate stale caches when a post is saved.
	 *
	 * Compares the current content hash to each stored hash and clears
	 * any provider cache whose hash no longer matches.
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 * @hook save_post
	 */
	public function maybe_invalidate( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$current_hash = md5( $post->post_content );

		foreach ( Provider_Registry::get_all() as $provider ) {
			$stored_hash = get_post_meta( $post_id, self::hash_key( $provider->get_slug() ), true );
			if ( ! empty( $stored_hash ) && $stored_hash !== $current_hash ) {
				self::clear( $post_id, $provider->get_slug() );
			}
		}
	}

	/**
	 * Get the transformation status for a post and provider.
	 *
	 * @param int    $post_id       The post ID.
	 * @param string $provider_slug The provider slug.
	 * @return string 'complete', 'pending', or 'none'.
	 */
	public static function get_status( int $post_id, string $provider_slug ): string {
		$data = get_post_meta( $post_id, self::result_key( $provider_slug ), true );
		if ( ! empty( $data ) && is_array( $data ) ) {
			return 'complete';
		}

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$actions = as_get_scheduled_actions(
				array(
					'hook'   => 'prc_content_transformer_process',
					'status' => array(
						\ActionScheduler_Store::STATUS_PENDING,
						\ActionScheduler_Store::STATUS_RUNNING,
					),
					'group'  => 'prc-content-transformer',
					'args'   => array(
						'post_id'  => $post_id,
						'provider' => $provider_slug,
					),
					'partial_args_matching' => 'like',
					'per_page'              => 1,
				),
				'ids'
			);
			if ( ! empty( $actions ) ) {
				return 'pending';
			}
		}

		return 'none';
	}
}
