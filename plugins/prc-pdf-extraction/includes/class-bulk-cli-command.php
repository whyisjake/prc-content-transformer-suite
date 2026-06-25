<?php
/**
 * Bulk CLI Command for Topline Processing
 *
 * Discovers all posts that have topline report materials and schedules an
 * individual async Action Scheduler job for each topline PDF.
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

use WP_CLI;

// Bail when running outside VIP infrastructure (wp-env, Playground): the parent class is unavailable.
if ( ! class_exists( 'WPCOM_VIP_CLI_Command' ) ) {
	return;
}

/**
 * Bulk_CLI_Command extends WPCOM_VIP_CLI_Command and registers the
 * `wp prc-pdf-extraction bulk-process` subcommand.
 */
class Bulk_CLI_Command extends \WPCOM_VIP_CLI_Command {
	/**
	 * Discover all posts with topline report materials and schedule async extraction jobs.
	 *
	 * Defaults to dry-run mode — pass --dry-run=false to actually enqueue jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run=<bool>]
	 * : Preview what would be scheduled without enqueuing jobs. Default: true.
	 *
	 * [--batch-size=<number>]
	 * : Number of posts to process per DB query. Max 100. Default: 100.
	 *
	 * [--start-id=<id>]
	 * : Resume from this post ID (exclusive). Useful for restarting after an interruption. Default: 0.
	 *
	 * [--force]
	 * : Schedule jobs even if an extraction already exists for a topline.
	 *
	 * [--post-type=<type>]
	 * : Post type to scan for reportMaterials. Default: post.
	 *
	 * [--total-batch-size=<number>]
	 * : Maximum number of posts to process in total. Omit for no limit.
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview (dry run — default)
	 *     wp prc-pdf-extraction bulk-process
	 *
	 *     # Enqueue all extractions
	 *     wp prc-pdf-extraction bulk-process --dry-run=false
	 *
	 *     # Resume after interruption from post ID 12345
	 *     wp prc-pdf-extraction bulk-process --dry-run=false --start-id=12345
	 *
	 *     # Force re-extraction (overwrite existing extractions)
	 *     wp prc-pdf-extraction bulk-process --dry-run=false --force
	 *
	 *     # Process at most 50 posts
	 *     wp prc-pdf-extraction bulk-process --dry-run=false --total-batch-size=50
	 *
	 * @subcommand bulk-process
	 * @synopsis [--dry-run=<bool>] [--batch-size=<number>] [--start-id=<id>] [--force] [--post-type=<type>] [--total-batch-size=<number>]
	 * @when after_wp_load
	 */
	public function bulk_process( array $args, array $assoc_args ): void {
		$dry_run         = $this->parse_dry_run( $assoc_args );
		$batch_size      = min( (int) ( $assoc_args['batch-size'] ?? 100 ), 100 );
		$min_id          = (int) ( $assoc_args['start-id'] ?? 0 );
		$force           = isset( $assoc_args['force'] );
		$post_type       = sanitize_key( $assoc_args['post-type'] ?? 'post' );
		$total_batch_cap = isset( $assoc_args['total-batch-size'] ) ? max( 1, (int) $assoc_args['total-batch-size'] ) : 0;

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			WP_CLI::error( 'Action Scheduler is not available. Ensure the action-scheduler plugin is active.' );
		}

		WP_CLI::line(
			$dry_run
			? 'Mode: dry-run (pass --dry-run=false to enqueue jobs).'
			: 'Mode: live — enqueuing extraction jobs.'
		);
		$cap_str = $total_batch_cap > 0 ? sprintf( ' | Max posts: %d', $total_batch_cap ) : '';
		WP_CLI::line(
			sprintf(
				'Post type: %s | Batch size: %d | Start ID: %d | Force: %s%s',
				$post_type,
				$batch_size,
				$min_id,
				$force ? 'yes' : 'no',
				$cap_str
			)
		);
		WP_CLI::line( '' );

		$service         = new Extraction_Service();
		$total_posts     = 0;
		$total_materials  = 0;
		$total_scheduled = 0;
		$total_skipped   = 0;

		$this->start_bulk_operation();

		// Pre-load all pending jobs once — O(1) lookups instead of a DB query per topline.
		$pending_set = array();
		if ( ! $force ) {
			$pending_set = Action_Scheduler_Handler::get_all_pending();
			WP_CLI::line( sprintf( 'Pre-loaded %d pending job(s) from Action Scheduler.', count( $pending_set ) ) );
		}

		do {
			// Cursor-based pagination via posts_where to support --start-id restarts.
			$cursor        = $min_id;
			$cursor_filter = function ( $where ) use ( $cursor ) {
				global $wpdb;
				$where .= $wpdb->prepare( ' AND ' . $wpdb->posts . '.ID > %d', $cursor );
				return $where;
			};

			add_filter( 'posts_where', $cursor_filter );

			$posts_to_fetch = $batch_size;
			if ( $total_batch_cap > 0 ) {
				$remaining      = $total_batch_cap - $total_posts;
				$posts_to_fetch = min( $batch_size, $remaining );
			}

			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => $posts_to_fetch,
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array(
						array(
							'key'     => 'reportMaterials',
							'compare' => 'EXISTS',
						),
					),
				)
			);

			remove_filter( 'posts_where', $cursor_filter );

			foreach ( $posts as $post_id ) {
				++$total_posts;
				$min_id = $post_id;

				$materials = $service->get_extraction_materials_from_post( $post_id );

				if ( empty( $materials ) ) {
					continue;
				}

				// One query per parent post instead of one per material.
				$extractions_map = $force ? array() : $service->get_extractions_for_post( $post_id );

				foreach ( $materials as $material ) {
					++$total_materials;

					$attachment_id = isset( $material['attachmentId'] ) ? (int) $material['attachmentId'] : 0;

					if ( ! $attachment_id ) {
						WP_CLI::warning(
							sprintf(
								'Post %d: extraction material "%s" has no attachmentId — skipping.',
								$post_id,
								$material['label'] ?? '(unknown)'
							)
						);
						++$total_skipped;
						continue;
					}

					if ( ! $force ) {
						$existing = $extractions_map[ $attachment_id ] ?? null;
						if ( $existing ) {
							WP_CLI::line(
								sprintf(
									'  Post %d: "%s" already extracted (ID: %d) — skipping.',
									$post_id,
									$material['label'] ?? '(unknown)',
									$existing
								)
							);
							++$total_skipped;
							continue;
						}

						if ( isset( $pending_set[ $post_id . ':' . $attachment_id ] ) ) {
							WP_CLI::line(
								sprintf(
									'  Post %d: "%s" already queued — skipping.',
									$post_id,
									$material['label'] ?? '(unknown)'
								)
							);
							++$total_skipped;
							continue;
						}
					}

					if ( $dry_run ) {
						WP_CLI::line(
							sprintf(
								'  [dry-run] Post %d: would queue "%s" (attachment %d).',
								$post_id,
								$material['label'] ?? '(unknown)',
								$attachment_id
							)
						);
					} else {
						$job_id = Action_Scheduler_Handler::schedule( $post_id, $attachment_id );

						if ( $job_id ) {
							WP_CLI::line(
								sprintf(
									'  Post %d: queued "%s" (job ID: %d).',
									$post_id,
									$material['label'] ?? '(unknown)',
									$job_id
								)
							);
							// Keep pending_set in sync for newly enqueued jobs.
							$pending_set[ $post_id . ':' . $attachment_id ] = true;
						} else {
							WP_CLI::warning(
								sprintf(
									'  Post %d: failed to queue "%s".',
									$post_id,
									$material['label'] ?? '(unknown)'
								)
							);
							++$total_skipped;
							continue;
						}
					}

					++$total_scheduled;
				}
			}

			WP_CLI::line(
				sprintf(
					'Batch done. Last ID: %d | Posts scanned: %d | Jobs queued: %d | Skipped: %d',
					$min_id,
					$total_posts,
					$total_scheduled,
					$total_skipped
				)
			);

			sleep( 2 );
			$this->vip_inmemory_cleanup();

			$hit_limit = $total_batch_cap > 0 && $total_posts >= $total_batch_cap;
			$no_more   = count( $posts ) < $posts_to_fetch;
		} while ( ! $hit_limit && ! $no_more );

		$this->end_bulk_operation();

		WP_CLI::line( '' );

		if ( $dry_run ) {
			WP_CLI::success(
				sprintf(
					'Dry run complete. Would schedule %d extraction job(s) from %d post(s). %d skipped.',
					$total_scheduled,
					$total_posts,
					$total_skipped
				)
			);
		} else {
			WP_CLI::success(
				sprintf(
					'Done. Scheduled %d extraction job(s) from %d post(s). %d skipped. Last ID: %d.',
					$total_scheduled,
					$total_posts,
					$total_skipped,
					$min_id
				)
			);
		}
	}

	/**
	 * Parse --dry-run from assoc_args safely.
	 *
	 * WP-CLI passes flag values as strings. Casting (bool) 'false' === true,
	 * so we must compare the string value explicitly.
	 *
	 * @param array $assoc_args CLI arguments.
	 * @return bool
	 */
	private function parse_dry_run( array $assoc_args ): bool {
		if ( ! isset( $assoc_args['dry-run'] ) ) {
			return true;
		}
		if ( 'false' === $assoc_args['dry-run'] ) {
			return false;
		}
		return (bool) $assoc_args['dry-run'];
	}
}
