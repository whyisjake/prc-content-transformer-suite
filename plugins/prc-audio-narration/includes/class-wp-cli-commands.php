<?php
/**
 * WP-CLI commands
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

/**
 * Manage audio narration from the command line.
 */
class WP_CLI_Commands {

	/**
	 * Generate narration for a single post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post to narrate.
	 *
	 * [--voice=<voice_id>]
	 * : Override the configured default voice.
	 *
	 * [--async]
	 * : Queue the job instead of running it now.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc-audio-narration generate 123
	 *     wp prc-audio-narration generate 123 --voice=abc123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function generate( $args, $assoc_args ) {
		$post_id = (int) $args[0];
		$voice   = (string) ( $assoc_args['voice'] ?? '' );

		if ( ! get_post( $post_id ) ) {
			\WP_CLI::error( sprintf( 'Post %d does not exist.', $post_id ) );
		}

		if ( isset( $assoc_args['async'] ) ) {
			$scheduled = Action_Scheduler_Handler::schedule( $post_id, $voice );
			if ( is_wp_error( $scheduled ) ) {
				\WP_CLI::error( $scheduled->get_error_message() );
			}
			\WP_CLI::success( sprintf( 'Queued narration for post %d.', $post_id ) );
			return;
		}

		$result = ( new Narration_Service() )->generate( $post_id, array( 'voice_id' => $voice ) );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success(
			sprintf(
				'Narrated post %1$d: %2$s (%3$s bytes)',
				$post_id,
				$result['url'],
				number_format_i18n( $result['byte_length'] )
			)
		);
	}

	/**
	 * Generate narration for many posts.
	 *
	 * Defaults to a dry run that reports the estimated cost without calling
	 * the provider. Synthesis is billed per character, so spending money is
	 * always an explicit choice.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<post_type>]
	 * : Restrict to a post type. Default: post.
	 *
	 * [--limit=<limit>]
	 * : Maximum posts to process. Default: 20.
	 *
	 * [--skip-existing]
	 * : Skip posts that already have fresh narration.
	 *
	 * [--execute]
	 * : Actually generate audio. Without this flag nothing is synthesized.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc-audio-narration bulk --limit=50
	 *     wp prc-audio-narration bulk --limit=50 --execute
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function bulk( $args, $assoc_args ) {
		$post_type = (string) ( $assoc_args['post_type'] ?? 'post' );
		$limit     = (int) ( $assoc_args['limit'] ?? 20 );
		$execute   = isset( $assoc_args['execute'] );
		$skip      = isset( $assoc_args['skip-existing'] );

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $query->posts ) ) {
			\WP_CLI::warning( 'No posts matched.' );
			return;
		}

		$service    = new Narration_Service();
		$store      = $service->store();
		$total_cost = 0.0;
		$total_chars = 0;
		$processed  = 0;
		$skipped    = 0;
		$failed     = 0;

		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;

			if ( $skip && $store->has_narration( $post_id ) && ! $store->is_stale( $post_id ) ) {
				++$skipped;
				continue;
			}

			$estimate = $service->estimate( $post_id );

			if ( is_wp_error( $estimate ) ) {
				\WP_CLI::warning( sprintf( 'Post %1$d: %2$s', $post_id, $estimate->get_error_message() ) );
				++$failed;
				continue;
			}

			$total_cost  += $estimate['estimated_cost'];
			$total_chars += $estimate['characters'];

			\WP_CLI::log(
				sprintf(
					'Post %1$d: %2$s characters, %3$d chunk(s), about $%4$s',
					$post_id,
					number_format_i18n( $estimate['characters'] ),
					$estimate['chunks'],
					number_format( $estimate['estimated_cost'], 2 )
				)
			);

			if ( ! $execute ) {
				continue;
			}

			$result = $service->generate( $post_id );

			if ( is_wp_error( $result ) ) {
				\WP_CLI::warning( sprintf( 'Post %1$d failed: %2$s', $post_id, $result->get_error_message() ) );
				++$failed;
				continue;
			}

			++$processed;
		}

		\WP_CLI::log(
			sprintf(
				'Total: %1$s characters, estimated $%2$s',
				number_format_i18n( $total_chars ),
				number_format( $total_cost, 2 )
			)
		);

		if ( ! $execute ) {
			\WP_CLI::success(
				sprintf(
					'Dry run complete. %d post(s) would be narrated. Re-run with --execute to generate audio.',
					count( $query->posts ) - $skipped - $failed
				)
			);
			return;
		}

		\WP_CLI::success(
			sprintf( 'Narrated %1$d post(s). Skipped %2$d. Failed %3$d.', $processed, $skipped, $failed )
		);
	}

	/**
	 * Report narration status.
	 *
	 * ## OPTIONS
	 *
	 * [<post_id>]
	 * : Report on a single post instead of all narrated posts.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc-audio-narration status
	 *     wp prc-audio-narration status 123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$store  = new Narration_Store();
		$format = (string) ( $assoc_args['format'] ?? 'table' );

		$post_ids = ! empty( $args )
			? array( (int) $args[0] )
			: $store->get_narrated_post_ids( array( 'posts_per_page' => 500 ) );

		if ( empty( $post_ids ) ) {
			\WP_CLI::warning( 'No posts have narration.' );
			return;
		}

		$rows = array();

		foreach ( $post_ids as $post_id ) {
			$record = $store->get( $post_id );

			$rows[] = array(
				'post_id'    => $post_id,
				'title'      => get_the_title( $post_id ),
				'state'      => $this->describe_state( $post_id, $record ),
				'provider'   => $record['provider'] ?? '',
				'voice'      => $record['voice'] ?? '',
				'characters' => $record['characters'] ?? 0,
				'generated'  => $record['generated'] ?? '',
			);
		}

		\WP_CLI\Utils\format_items(
			$format,
			$rows,
			array( 'post_id', 'title', 'state', 'provider', 'voice', 'characters', 'generated' )
		);
	}

	/**
	 * Delete a post's narration.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The post to clear.
	 *
	 * ## EXAMPLES
	 *
	 *     wp prc-audio-narration delete 123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function delete( $args, $assoc_args ) {
		$post_id = (int) $args[0];

		if ( ( new Narration_Store() )->delete( $post_id ) ) {
			\WP_CLI::success( sprintf( 'Removed narration for post %d.', $post_id ) );
			return;
		}

		\WP_CLI::warning( sprintf( 'Post %d had no narration.', $post_id ) );
	}

	/**
	 * Describe the narration state of a post.
	 *
	 * @param int        $post_id The post ID.
	 * @param array|null $record  The narration record.
	 * @return string
	 */
	private function describe_state( int $post_id, ?array $record ): string {
		if ( Action_Scheduler_Handler::is_pending( $post_id ) ) {
			return 'pending';
		}

		if ( null === $record ) {
			return 'none';
		}

		return $record['is_stale'] ? 'stale' : 'ready';
	}
}
