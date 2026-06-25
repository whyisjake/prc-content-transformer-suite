<?php
/**
 * Extraction Service
 *
 * Shared PDF extraction logic usable from both WP-CLI and Action Scheduler contexts.
 * All methods are WP-CLI-agnostic; logging is done via error_log().
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

use PRC\Platform\PDF_Extraction\OCR\Application\OCR_Orchestrator;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Request;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Response;

/**
 * Extraction_Service provides reusable OCR extraction logic.
 */
class Extraction_Service {
	/**
	 * OCR Orchestrator instance.
	 *
	 * @var OCR_Orchestrator
	 */
	private $orchestrator;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->orchestrator = new OCR_Orchestrator();
	}

	/**
	 * Return the underlying OCR_Orchestrator (for CLI commands that need direct access).
	 *
	 * @return OCR_Orchestrator
	 */
	public function get_orchestrator(): OCR_Orchestrator {
		return $this->orchestrator;
	}

	/**
	 * Get topline materials from a post's reportMaterials meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array of topline materials (re-indexed).
	 */
	public function get_extraction_materials_from_post( int $post_id ): array {
		$materials = get_post_meta( $post_id, 'reportMaterials', true );

		if ( empty( $materials ) || ! is_array( $materials ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$materials,
				function ( $material ) {
					return isset( $material['type'] ) && 'topline' === $material['type'];
				}
			)
		);
	}

	/**
	 * Get an existing extraction post for a topline by its attachment ID.
	 *
	 * @param int $parent_id     Parent post ID.
	 * @param int $attachment_id Attachment ID of the topline PDF.
	 * @return int|null Extraction post ID or null.
	 */
	public function get_extraction_for_material( int $parent_id, int $attachment_id ): ?int {
		$posts = get_posts(
			array(
				'post_type'      => Content_Type::get_post_type(),
				'post_parent'    => $parent_id,
				'meta_key'       => '_pdf_source_attachment_id',
				'meta_value'     => $attachment_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		return ! empty( $posts ) ? (int) $posts[0] : null;
	}

	/**
	 * Get all existing extractions for a parent post, keyed by attachment ID.
	 *
	 * Single query per parent instead of one per topline.
	 *
	 * @param int $parent_id Parent post ID.
	 * @return array<int, int> Map of attachment_id => extraction_post_id.
	 */
	public function get_extractions_for_post( int $parent_id ): array {
		$posts = get_posts(
			array(
				'post_type'      => Content_Type::get_post_type(),
				'post_parent'    => $parent_id,
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$map = array();
		foreach ( $posts as $extraction_id ) {
			$att = (int) get_post_meta( $extraction_id, '_pdf_source_attachment_id', true );
			if ( $att ) {
				$map[ $att ] = (int) $extraction_id;
			}
		}
		return $map;
	}

	/**
	 * Resolve a file path for a topline attachment.
	 *
	 * Checks the WordPress uploads directory first; downloads from the remote
	 * URL in the topline data if no local file is found.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $topline       Topline material data (may contain 'url').
	 * @param bool  $insecure      Allow insecure SSL when downloading.
	 * @return string|false Local file path, or false on failure.
	 */
	public function get_file_path_from_attachment( int $attachment_id, array $topline = array(), bool $insecure = false ) {
		$local_path = get_attached_file( $attachment_id );

		if ( $local_path && file_exists( $local_path ) ) {
			return $local_path;
		}

		if ( ! empty( $topline['url'] ) ) {
			return $this->download_remote_pdf( $topline['url'], $attachment_id, $insecure );
		}

		return false;
	}

	/**
	 * Download a remote PDF to a temporary file.
	 *
	 * @param string $url           PDF URL.
	 * @param int    $attachment_id Attachment ID (used for the temp filename).
	 * @param bool   $insecure      Unused. Kept for backwards compatibility with callers.
	 * @return string|false Temporary file path, or false on failure.
	 */
	public function download_remote_pdf( string $url, int $attachment_id, bool $insecure = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$parsed = wp_parse_url( $url );
		if ( ! in_array( $parsed['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return false;
		}

		$temp_dir  = sys_get_temp_dir();
		$temp_file = $temp_dir . '/prc-pdf-extraction-' . $attachment_id . '.pdf';

		// Swap local-dev URLs for production.
		$production_url = $url;
		if ( strpos( $url, '.vipdev.lndo.site' ) !== false ) {
			$production_url = str_replace(
				array(
					'https://prc-platform.vipdev.lndo.site/pewresearch-org/',
					'http://prc-platform.vipdev.lndo.site/pewresearch-org/',
				),
				'https://www.pewresearch.org/', // pragma: allowlist secret
				$url
			);
			error_log( "prc-pdf-extraction: local dev URL replaced with production URL for attachment {$attachment_id}." );
		}

		$downloaded = download_url( $production_url, 30 );

		if ( is_wp_error( $downloaded ) ) {
			error_log( "prc-pdf-extraction: download_url() failed for attachment {$attachment_id}: " . $downloaded->get_error_message() );
			return false;
		}

		// Move download_url()'s auto-named temp file to our deterministic path.
		if ( ! rename( $downloaded, $temp_file ) ) {
			@unlink( $downloaded ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			error_log( "prc-pdf-extraction: failed to move temp file for attachment {$attachment_id}." );
			return false;
		}

		if ( ! file_exists( $temp_file ) || 0 === filesize( $temp_file ) ) {
			error_log( "prc-pdf-extraction: downloaded file is empty or missing for attachment {$attachment_id}." );
			return false;
		}

		return $temp_file;
	}

	/**
	 * Delete a temporary file created during a remote download.
	 *
	 * Only removes files inside the system temp directory as a safety guard.
	 *
	 * @param string $file_path Path to delete.
	 */
	public function cleanup_temp_file( string $file_path ): void {
		if ( strpos( $file_path, sys_get_temp_dir() ) === 0 && file_exists( $file_path ) ) {
			unlink( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Run OCR extraction on a PDF file.
	 *
	 * Delegates to the OCR_Orchestrator which tries providers by priority.
	 * Each provider owns its extraction strategy (e.g. Gemini uses page-grouped
	 * extraction with validation; Claude uses whole-PDF).
	 *
	 * @param string $file_path     Path to the PDF.
	 * @param int    $attachment_id Optional. WP attachment ID for caching (e.g. Claude file_id).
	 * @return OCR_Response|\WP_Error
	 */
	public function extract_text( string $file_path, int $attachment_id = 0 ) {
		$request = new OCR_Request(
			$file_path,
			array(
				'format'        => 'topline',
				'attachment_id' => $attachment_id,
			)
		);

		return $this->orchestrator->extract_text( $request );
	}

	/**
	 * Save an OCR extraction result as a topline CPT post.
	 *
	 * Creates a new post or updates an existing one, then writes all extraction
	 * meta fields. post_content uses Gutenberg blocks when available, else markdown.
	 *
	 * @param int          $parent_id         Parent post ID.
	 * @param array        $topline           Topline material data.
	 * @param OCR_Response $response          OCR response.
	 * @param int|null     $existing_id       Existing extraction post ID for updates.
	 * @param float|null   $duration_seconds  Optional. Time taken for extraction in seconds.
	 * @return int|\WP_Error Extraction post ID, or WP_Error on failure.
	 */
	public function save_extraction(
		int $parent_id,
		array $topline,
		$response,
		?int $existing_id = null,
		?float $duration_seconds = null
	) {
		$parent       = get_post( $parent_id );
		$plain_text   = $response->get_text();
		$markdown     = $response->get_markdown();
		$gutenberg    = $response->get_gutenberg();
		$post_content = ! empty( $gutenberg ) ? $gutenberg : $markdown;

		$post_data = array(
			'post_type'    => Content_Type::get_post_type(),
			'post_title'   => sprintf( 'Extracted: %s - %s', $parent->post_title, $topline['label'] ),
			'post_content' => $post_content,
			'post_status'  => 'publish',
			'post_parent'  => $parent_id,
		);

		if ( $existing_id ) {
			$post_data['ID'] = $existing_id;
			$extraction_id   = wp_update_post( $post_data, true );
		} else {
			$extraction_id = wp_insert_post( $post_data, true );
		}

		if ( is_wp_error( $extraction_id ) ) {
			return $extraction_id;
		}

		update_post_meta( $extraction_id, '_pdf_source_attachment_id', $topline['attachmentId'] );
		update_post_meta( $extraction_id, '_pdf_source_url', $topline['url'] ?? '' );
		update_post_meta( $extraction_id, '_pdf_source_label', $topline['label'] ?? '' );
		update_post_meta( $extraction_id, '_ocr_provider_used', $response->get_provider() );
		update_post_meta( $extraction_id, '_ocr_confidence_score', $response->get_confidence() );
		update_post_meta( $extraction_id, '_extraction_date', current_time( 'mysql' ) );
		update_post_meta( $extraction_id, '_extraction_version', PRC_PDF_EXTRACTION_VERSION );
		update_post_meta( $extraction_id, '_extracted_text_plain', $plain_text );
		update_post_meta( $extraction_id, '_extracted_text_markdown', $markdown );
		update_post_meta( $extraction_id, '_validation_status', 'passed' );
		update_post_meta( $extraction_id, '_validation_issues', '' );
		update_post_meta( $extraction_id, '_processing_cost_usd', $response->get_cost() );
		update_post_meta( $extraction_id, '_character_count', $response->get_character_count() );
		if ( $duration_seconds !== null ) {
			update_post_meta( $extraction_id, '_extraction_duration_seconds', $duration_seconds );
		}

		// NOTE: Keep this disabled for now while in development.
		// $this->update_parent_report_materials( $parent_id, $topline );

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PDF Extraction Success',
			'PDF Extraction successful, you can view the extraction at ' . get_permalink( $extraction_id )
		);

		return $extraction_id;
	}

	/**
	 * Update the parent post's report materials: remove the topline PDF and add a link to /topline.
	 *
	 * Called after a successful extraction so report materials show the web topline link
	 * instead of the original PDF attachment.
	 *
	 * @param int   $parent_id Parent post ID.
	 * @param array $topline   Topline material data (must include 'attachmentId').
	 */
	private function update_parent_report_materials( int $parent_id, array $topline ): void {
		$attachment_id = isset( $topline['attachmentId'] ) ? (int) $topline['attachmentId'] : 0;
		if ( $attachment_id <= 0 ) {
			error_log( "prc-pdf-extraction: update_parent_report_materials — no attachmentId in topline for post {$parent_id}. Skipping." );
			return;
		}

		$materials = get_post_meta( $parent_id, 'reportMaterials', true );
		if ( empty( $materials ) || ! is_array( $materials ) ) {
			error_log( "prc-pdf-extraction: update_parent_report_materials — no reportMaterials for post {$parent_id}. Skipping." );
			return;
		}

		$url_slug       = Content_Type::get_url_slug();
		$extraction_url = rtrim( get_permalink( $parent_id ), '/' ) . '/' . $url_slug;

		// Remove the extraction PDF entry matching this attachment.
		$updated = array_filter(
			$materials,
			function ( $material ) use ( $attachment_id ) {
				if ( ! is_array( $material ) ) {
					return true;
				}
				$mat_attachment = isset( $material['attachmentId'] ) ? (int) $material['attachmentId'] : 0;
				$is_topline_pdf = isset( $material['type'] ) && 'topline' === $material['type'];
				// Keep entries that are not the topline PDF we just processed.
				return ! ( $is_topline_pdf && $mat_attachment === $attachment_id );
			}
		);

		// Idempotency: skip adding if a link material with extraction URL already exists.
		$has_extraction_link = false;
		$extraction_suffix   = '/' . $url_slug;
		foreach ( $updated as $material ) {
			if ( ! is_array( $material ) ) {
				continue;
			}
			$url = isset( $material['url'] ) ? (string) $material['url'] : '';
			if ( 'link' === ( $material['type'] ?? '' ) && str_ends_with( rtrim( $url, '/' ), $extraction_suffix ) ) {
				$has_extraction_link = true;
				break;
			}
		}

		if ( ! $has_extraction_link ) {
			$updated[] = array(
				'key'   => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'extraction-', true ),
				'type'  => 'link',
				'label' => 'PDF Extraction',
				'url'   => $extraction_url,
				'icon'  => 'clipboard',
			);
		}

		$result = update_post_meta( $parent_id, 'reportMaterials', array_values( $updated ) );
		if ( false !== $result ) {
			error_log( sprintf( 'prc-pdf-extraction: updated report materials for post %d — swapped topline PDF for /topline link.', $parent_id ) );
		} else {
			error_log( "prc-pdf-extraction: failed to update report materials for post {$parent_id}." );
		}
	}
}
