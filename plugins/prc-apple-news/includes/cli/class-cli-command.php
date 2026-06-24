<?php
/**
 * WP-CLI Commands for PRC Apple News.
 *
 * @package PRC\Platform\Apple_News\CLI
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\CLI;

use PRC\Platform\Apple_News\ANF\ANF_Block_Converter;
use PRC\Platform\Apple_News\ANF\ANF_Post_Processor;
use PRC\Platform\Apple_News\ANF\ANF_Validator;
use PRC\Platform\Apple_News\Post_Sync;
use PRC\Platform\Apple_News\Loader;
use WP_CLI;

if ( ! class_exists( 'WPCOM_VIP_CLI_Command' ) ) {
	return;
}

require_once dirname( __DIR__ ) . '/anf/class-anf-post-processor.php';
require_once dirname( __DIR__ ) . '/anf/class-anf-validator.php';

/**
 * Manage Apple News publishing for PRC posts.
 */
class CLI_Command extends \WPCOM_VIP_CLI_Command {

	/**
	 * Register the WP-CLI command.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		WP_CLI::add_command( 'prc apple-news', self::class );
	}

	/**
	 * Push a single post to Apple News.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID to push.
	 *
	 * [--force]
	 * : Bypass the pending lock check and force re-push.
	 *
	 * [--preview]
	 * : Publish as a preview/draft (sets apple_news_is_preview post meta).
	 *
	 * [--hidden]
	 * : Publish hidden from feeds (sets apple_news_is_hidden post meta).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp prc apple-news push 123
	 *     $ wp prc apple-news push 123 --force
	 *     $ wp prc apple-news push 123 --preview
	 *     $ wp prc apple-news push 123 --preview --hidden
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function push( $args, $assoc_args ): void {
		$post_id = (int) $args[0];
		$force   = isset( $assoc_args['force'] );

		$post = get_post( $post_id );
		if ( ! $post ) {
			WP_CLI::error( "Post {$post_id} not found." );
		}

		if ( isset( $assoc_args['preview'] ) ) {
			update_post_meta( $post_id, 'apple_news_is_preview', true );
		}

		if ( isset( $assoc_args['hidden'] ) ) {
			update_post_meta( $post_id, 'apple_news_is_hidden', true );
		}

		WP_CLI::log( "Pushing post {$post_id} (\"{$post->post_title}\") to Apple News..." );

		( new Post_Sync( new Loader() ) )->push_now( $post_id, $force );

		$article_id = get_post_meta( $post_id, 'apple_news_api_id', true );
		$error      = get_transient( "_prc_apple_news_last_error_{$post_id}" );

		if ( $error ) {
			WP_CLI::error( "Push failed: {$error}" );
		}

		if ( $article_id ) {
			WP_CLI::success( "Post {$post_id} published to Apple News (article ID: {$article_id})." );
		} else {
			WP_CLI::warning( "Push completed but no article ID was stored. Check error logs." );
		}
	}

	/**
	 * Delete a post's article from Apple News.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID whose article should be deleted.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp prc apple-news delete 123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ): void {
		$post_id = (int) $args[0];

		$post = get_post( $post_id );
		if ( ! $post ) {
			WP_CLI::error( "Post {$post_id} not found." );
		}

		WP_CLI::log( "Deleting Apple News article for post {$post_id} (\"{$post->post_title}\")..." );

		$result = Post_Sync::delete_from_apple_news( $post_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( "Article deleted from Apple News." );
	}

	/**
	 * Show the current Apple News status for a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID to check.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp prc apple-news status 123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ): void {
		$post_id = (int) $args[0];

		$post = get_post( $post_id );
		if ( ! $post ) {
			WP_CLI::error( "Post {$post_id} not found." );
		}

		$article_id  = get_post_meta( $post_id, 'apple_news_api_id', true );
		$revision    = get_post_meta( $post_id, 'apple_news_api_revision', true );
		$share_url   = get_post_meta( $post_id, 'apple_news_api_share_url', true );
		$modified_at = get_post_meta( $post_id, 'apple_news_api_modified_at', true );
		$pending     = get_post_meta( $post_id, 'apple_news_api_pending', true );
		$error       = get_transient( "_prc_apple_news_last_error_{$post_id}" );

		$rows = array(
			array( 'key' => 'Post ID', 'value' => (string) $post_id ),
			array( 'key' => 'Title', 'value' => $post->post_title ),
			array( 'key' => 'Published', 'value' => ! empty( $article_id ) ? 'yes' : 'no' ),
			array( 'key' => 'Article ID', 'value' => $article_id ?: '—' ),
			array( 'key' => 'Revision', 'value' => $revision ?: '—' ),
			array( 'key' => 'Modified At', 'value' => $modified_at ?: '—' ),
			array( 'key' => 'Share URL', 'value' => $share_url ?: '—' ),
			array( 'key' => 'Pending Lock', 'value' => $pending ?: 'none' ),
			array( 'key' => 'Last Error', 'value' => $error ?: 'none' ),
		);

		WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * Generate an ANF preview for a post and write article.json for News Preview.app.
	 *
	 * Builds ANF JSON with the deterministic block converter, applies the PRC ANF
	 * post-processor, validates the result against the ANF JSON Schema, and
	 * writes the result to a file inside the container. Pair with
	 * bin/content/apple-news-preview.sh on the host to copy the file out and open it
	 * in News Preview automatically.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post ID to preview.
	 *
	 * [--output=<path>]
	 * : Container path to write article.json. Default: /tmp/article.json
	 *
	 * [--force]
	 * : Reserved for future cache-bypass support (currently unused).
	 *
	 * [--no-validate]
	 * : Skip ANF schema validation (validation runs by default).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp prc apple-news preview 123
	 *     $ wp prc apple-news preview 123 --force --output=/tmp/article.json
	 *     $ wp prc apple-news preview 123 --no-validate
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function preview( $args, $assoc_args ): void {
		$post_id     = (int) $args[0];
		$output_path = $assoc_args['output'] ?? '/tmp/article.json';
		$force       = isset( $assoc_args['force'] );
		$validate    = ! isset( $assoc_args['no-validate'] );

		$post = get_post( $post_id );
		if ( ! $post ) {
			WP_CLI::error( "Post {$post_id} not found." );
		}

		WP_CLI::log( "Building ANF preview for post {$post_id} (\"{$post->post_title}\")..." );

		$raw_output = ( new ANF_Block_Converter() )->build( $post_id );
		if ( '' === $raw_output || null === json_decode( $raw_output ) ) {
			WP_CLI::error( 'Deterministic ANF build returned invalid JSON.' );
		}

		WP_CLI::log( 'Applying PRC ANF post-processor...' );

		$processed = ( new ANF_Post_Processor() )->process( $raw_output, $post_id );

		if ( $validate ) {
			WP_CLI::log( 'Validating ANF schema...' );
			$validation = ( new ANF_Validator() )->validate_json( $processed );
			if ( is_wp_error( $validation ) ) {
				$errors = $validation->get_error_data( 'anf_schema_error' )['errors'] ?? array();
				foreach ( $errors as $err ) {
					$prop = $err['property'] ?? 'document';
					WP_CLI::warning( "[{$prop}] {$err['message']}" );
				}
				WP_CLI::warning( sprintf( 'ANF validation failed (%d error(s)). Document may not open in News Preview.', count( $errors ) ) );
			} else {
				WP_CLI::success( 'ANF schema valid.' );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $output_path, $processed );

		WP_CLI::success( "article.json written to {$output_path}" );
	}

	/**
	 * Create (or idempotently re-create) the kitchen-sink test fixture post.
	 *
	 * Reads the static HTML fixture from tests/php/fixtures/kitchen-sink-post-content.html,
	 * creates a published post with a fixed slug, and sets the sub_title post meta so the
	 * ANF intro-component injection path is exercised during preview testing.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Delete any existing kitchen-sink post and recreate it from scratch.
	 *
	 * [--porcelain]
	 * : Output only the post ID (integer). Useful for shell composition:
	 *   bin/content/apple-news-preview.sh "$(wp prc apple-news create-fixture --porcelain ...)"
	 *
	 * ## EXAMPLES
	 *
	 *     # Create once and get the ID
	 *     $ wp prc apple-news create-fixture --allow-root --url=https://example.com
	 *
	 *     # Shell-composable form for preview testing
	 *     $ bin/content/apple-news-preview.sh "$(wp prc apple-news create-fixture --allow-root --url=https://example.com --porcelain)"
	 *
	 *     # Wipe and recreate (e.g. after changing the fixture HTML)
	 *     $ wp prc apple-news create-fixture --force --allow-root --url=https://example.com
	 *
	 * NOTE: bin/content/apple-news-preview.sh requires the Lando/VIP Docker stack. It is not
	 * compatible with WordPress Playground. In Playground, add this command as a wp-cli
	 * blueprint step so the fixture post is recreated on each boot.
	 *
	 * NOTE: This fixture post has no featured image. The post-processor's featured-image
	 * path will not be exercised; all images in the fixture take the non-featured path.
	 *
	 * @subcommand create-fixture
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function create_fixture( $args, $assoc_args ): void {
		$force    = isset( $assoc_args['force'] );
		$porcelain = isset( $assoc_args['porcelain'] );
		$slug     = 'prc-apple-news-kitchen-sink';

		// Detect an existing fixture post using get_posts() — get_page_by_path() is deprecated in WP 6.7.
		$existing = get_posts(
			array(
				'name'           => $slug,
				'post_type'      => 'post',
				'post_status'    => 'any',
				'numberposts'    => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$existing_id = ! empty( $existing ) ? (int) $existing[0] : 0;

		if ( $existing_id && ! $force ) {
			if ( $porcelain ) {
				WP_CLI::line( (string) $existing_id );
			} else {
				WP_CLI::success( "Fixture post already exists (ID: {$existing_id}). Use --force to delete and recreate." );
			}
			return;
		}

		if ( $existing_id && $force ) {
			WP_CLI::log( "Deleting existing fixture post (ID: {$existing_id})..." );
			wp_delete_post( $existing_id, true );
		}

		$fixture_path = __DIR__ . '/../../tests/php/fixtures/kitchen-sink-post-content.html';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$post_content = file_get_contents( $fixture_path );
		if ( false === $post_content ) {
			WP_CLI::error( "Could not read fixture file: {$fixture_path}" );
		}

		WP_CLI::log( 'Creating kitchen-sink fixture post...' );

		$post_id = wp_insert_post(
			array(
				'post_title'   => '[Kitchen Sink] PRC Apple News Test Article',
				'post_name'    => $slug,
				'post_content' => $post_content,
				'post_status'  => 'publish',
				'post_type'    => 'post',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			WP_CLI::error( 'wp_insert_post() failed: ' . $post_id->get_error_message() );
		}

		update_post_meta( $post_id, 'sub_title', 'A subtitle that exercises the intro component injection in the ANF post-processor.' );

		if ( $porcelain ) {
			WP_CLI::line( (string) $post_id );
			return;
		}

		WP_CLI::success( "Fixture post created (ID: {$post_id})." );
		WP_CLI::log( "  Slug: {$slug}" );
		WP_CLI::log( "  sub_title meta set." );
		WP_CLI::log( "  Preview: bin/content/apple-news-preview.sh \"{$post_id}\"" );
	}

	/**
	 * Bulk push published posts to Apple News.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<type>]
	 * : Post type to process. Default: post.
	 *
	 * [--limit=<number>]
	 * : Maximum number of posts to process. Default: 100.
	 *
	 * [--force]
	 * : Bypass pending lock check for each post.
	 *
	 * [--dry-run]
	 * : List posts that would be pushed without actually pushing.
	 *
	 * ## EXAMPLES
	 *
	 *     # Dry run to see what would be pushed
	 *     $ wp prc apple-news bulk-push --dry-run
	 *
	 *     # Push up to 50 posts of type short-read
	 *     $ wp prc apple-news bulk-push --post-type=short-read --limit=50
	 *
	 * @subcommand bulk-push
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function bulk_push( $args, $assoc_args ): void {
		$post_type = $assoc_args['post-type'] ?? 'post';
		$limit     = (int) ( $assoc_args['limit'] ?? 100 );
		$force     = isset( $assoc_args['force'] );
		$dry_run   = isset( $assoc_args['dry-run'] );

		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
			)
		);

		if ( empty( $posts ) ) {
			WP_CLI::log( 'No published posts found.' );
			return;
		}

		WP_CLI::log( sprintf( 'Found %d post(s) to process.', count( $posts ) ) );

		if ( $dry_run ) {
			WP_CLI::log( 'Dry run — no changes made.' );
			foreach ( $posts as $post_id ) {
				$post       = get_post( $post_id );
				$article_id = get_post_meta( $post_id, 'apple_news_api_id', true );
				WP_CLI::log( sprintf(
					'  [%d] %s — %s',
					$post_id,
					$post->post_title,
					$article_id ? "published (ID: {$article_id})" : 'not published'
				) );
			}
			return;
		}

		$sync    = new Post_Sync( new Loader() );
		$success = 0;
		$failed  = 0;

		foreach ( $posts as $post_id ) {
			$post = get_post( $post_id );
			WP_CLI::log( "Pushing [{$post_id}] {$post->post_title}..." );

			$sync->push_now( $post_id, $force );
			$this->stop_the_insanity();

			$error = get_transient( "_prc_apple_news_last_error_{$post_id}" );
			if ( $error ) {
				WP_CLI::warning( "  Failed: {$error}" );
				$failed++;
			} else {
				$success++;
			}
		}

		WP_CLI::success( "Bulk push complete. Success: {$success}, Failed: {$failed}." );
	}
}
