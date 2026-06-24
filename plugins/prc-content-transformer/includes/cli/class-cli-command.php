<?php
/**
 * WP-CLI Commands for Content Transformer.
 *
 * @package PRC\Platform\Content_Transformer\CLI
 */

namespace PRC\Platform\Content_Transformer\CLI;

use PRC\Platform\Content_Transformer\Pipeline\Transformation_Pipeline;
use PRC\Platform\Content_Transformer\Cache\Transformation_Cache;
use PRC\Platform\Content_Transformer\Providers\Provider_Registry;
use WP_CLI;

if ( ! class_exists( 'WPCOM_VIP_CLI_Command' ) ) {
	return;
}

/**
 * Transform WordPress content into provider-specific formats using AI.
 */
class CLI_Command extends \WPCOM_VIP_CLI_Command {

	/**
	 * Register the WP-CLI command.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		WP_CLI::add_command( 'prc content-transformer', self::class );
	}

	/**
	 * Transform a single post's content.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID to transform.
	 *
	 * --provider=<provider>
	 * : The provider slug (e.g. plain-text, apple-news, email).
	 *
	 * [--force]
	 * : Bypass the cache and force a fresh transformation.
	 *
	 * [--dry-run]
	 * : Show what would happen without actually running the transformation.
	 *
	 * [--output-file=<file>]
	 * : Write the transformed content to a file instead of stdout.
	 *
	 * ## EXAMPLES
	 *
	 *     # Transform post 123 to plain text
	 *     $ wp prc content-transformer transform 123 --provider=plain-text
	 *
	 *     # Force re-transform and save to file
	 *     $ wp prc content-transformer transform 123 --provider=apple-news --force --output-file=article.json
	 *
	 *     # Dry run
	 *     $ wp prc content-transformer transform 123 --provider=email --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function transform( $args, $assoc_args ) {
		$post_id       = (int) $args[0];
		$provider_slug = $assoc_args['provider'];
		$force         = isset( $assoc_args['force'] );
		$dry_run       = isset( $assoc_args['dry-run'] );
		$output_file   = $assoc_args['output-file'] ?? null;

		$post = get_post( $post_id );
		if ( ! $post ) {
			WP_CLI::error( "Post {$post_id} not found." );
		}

		if ( ! Provider_Registry::has( $provider_slug ) ) {
			$available = implode( ', ', array_keys( Provider_Registry::get_all() ) );
			WP_CLI::error( "Provider \"{$provider_slug}\" is not registered. Available: {$available}" );
		}

		if ( $dry_run ) {
			WP_CLI::log( "Dry run: Would transform post {$post_id} (\"{$post->post_title}\") using provider \"{$provider_slug}\"." );
			$status = Transformation_Cache::get_status( $post_id, $provider_slug );
			WP_CLI::log( "Current cache status: {$status}" );
			WP_CLI::log( "Force: " . ( $force ? 'yes' : 'no' ) );
			return;
		}

		WP_CLI::log( "Transforming post {$post_id} (\"{$post->post_title}\") using provider \"{$provider_slug}\"..." );

		$result = Transformation_Pipeline::transform( $post_id, $provider_slug, $force );

		if ( ! $result->is_success() ) {
			WP_CLI::error( "Transformation failed: " . $result->get_error() );
		}

		WP_CLI::success( sprintf(
			'Transformation complete. Status: %s. Tokens: %d.',
			$result->get_status(),
			$result->get_tokens_used()
		) );

		if ( $output_file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $output_file, $result->get_output() );
			WP_CLI::log( "Output written to: {$output_file}" );
		} else {
			WP_CLI::log( "\n--- Output ---\n" );
			WP_CLI::log( $result->get_output() );
		}
	}

	/**
	 * List registered providers.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp prc content-transformer providers
	 *
	 * @subcommand providers
	 */
	public function providers( $args, $assoc_args ) {
		$providers = Provider_Registry::get_all();

		if ( empty( $providers ) ) {
			WP_CLI::log( 'No providers registered.' );
			return;
		}

		$rows = array();
		foreach ( $providers as $provider ) {
			$rows[] = array(
				'slug'        => $provider->get_slug(),
				'name'        => $provider->get_name(),
				'output_type' => $provider->get_output_type(),
				'available'   => $provider->is_available() ? 'yes' : 'no',
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'slug', 'name', 'output_type', 'available' ) );
	}

	/**
	 * Check the transformation status for a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID.
	 *
	 * --provider=<provider>
	 * : The provider slug.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp prc content-transformer status 123 --provider=plain-text
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		$post_id       = (int) $args[0];
		$provider_slug = $assoc_args['provider'];

		$post = get_post( $post_id );
		if ( ! $post ) {
			WP_CLI::error( "Post {$post_id} not found." );
		}

		$status = Transformation_Cache::get_status( $post_id, $provider_slug );

		WP_CLI::log( sprintf(
			'Post %d (%s) — Provider: %s — Status: %s',
			$post_id,
			$post->post_title,
			$provider_slug,
			$status
		) );
	}

	/**
	 * Clear cached transformation results for a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID.
	 *
	 * [--provider=<provider>]
	 * : Clear only a specific provider's cache. Omit to clear all.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear all caches for post 123
	 *     $ wp prc content-transformer clear-cache 123
	 *
	 *     # Clear only apple-news cache
	 *     $ wp prc content-transformer clear-cache 123 --provider=apple-news
	 *
	 * @subcommand clear-cache
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear_cache( $args, $assoc_args ) {
		$post_id       = (int) $args[0];
		$provider_slug = $assoc_args['provider'] ?? null;

		$post = get_post( $post_id );
		if ( ! $post ) {
			WP_CLI::error( "Post {$post_id} not found." );
		}

		if ( $provider_slug ) {
			Transformation_Cache::clear( $post_id, $provider_slug );
			WP_CLI::success( "Cleared {$provider_slug} cache for post {$post_id}." );
		} else {
			Transformation_Cache::clear_all( $post_id );
			WP_CLI::success( "Cleared all transformation caches for post {$post_id}." );
		}
	}
}
