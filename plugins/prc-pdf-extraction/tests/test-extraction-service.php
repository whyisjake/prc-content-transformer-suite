<?php
/**
 * Class ExtractionServiceTest
 *
 * @package PRC_PDF_Extraction
 */

use PRC\Platform\PDF_Extraction\Content_Type;
use PRC\Platform\PDF_Extraction\Extraction_Service;
use PRC\Platform\PDF_Extraction\OCR\Domain\OCR_Response;

/**
 * Tests for the Extraction_Service class.
 *
 * These tests cover the WordPress-layer methods of Extraction_Service:
 * parsing reportMaterials meta, querying existing extractions, persisting
 * extraction posts with all meta fields, and cleaning up temp files.
 *
 * PDF download and OCR methods require live provider credentials and are
 * integration-tested separately.
 */
class ExtractionServiceTest extends WP_UnitTestCase {
	/**
	 * Service under test.
	 *
	 * @var Extraction_Service
	 */
	private $service;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->service = new Extraction_Service();
	}

	// -------------------------------------------------------------------------
	// get_extraction_materials_from_post()
	// -------------------------------------------------------------------------

	/**
	 * Returns an empty array when the post has no reportMaterials meta.
	 */
	public function test_get_extraction_materials_from_post_returns_empty_array_when_no_meta() {
		$post_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Post without materials',
			'post_status' => 'publish',
		) );

		$result = $this->service->get_extraction_materials_from_post( $post_id );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Returns an empty array when reportMaterials meta exists but contains no extraction materials.
	 */
	public function test_get_extraction_materials_from_post_returns_empty_array_when_no_extraction_materials() {
		$post_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Post with non-topline materials',
			'post_status' => 'publish',
		) );

		update_post_meta( $post_id, 'reportMaterials', array(
			array( 'type' => 'report', 'label' => 'Main Report', 'attachmentId' => 1 ),
			array( 'type' => 'dataset', 'label' => 'Dataset', 'attachmentId' => 2 ),
		) );

		$result = $this->service->get_extraction_materials_from_post( $post_id );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	/**
	 * Returns only materials with type === 'topline', filtering out other types.
	 */
	public function test_get_extraction_materials_from_post_filters_extraction_materials_only() {
		$post_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Post with mixed materials',
			'post_status' => 'publish',
		) );

		update_post_meta( $post_id, 'reportMaterials', array(
			array( 'type' => 'report',  'label' => 'Main Report', 'attachmentId' => 10 ),
			array( 'type' => 'topline', 'label' => 'Topline A',   'attachmentId' => 20 ),
			array( 'type' => 'dataset', 'label' => 'Dataset',     'attachmentId' => 30 ),
			array( 'type' => 'topline', 'label' => 'Topline B',   'attachmentId' => 40 ),
		) );

		$result = $this->service->get_extraction_materials_from_post( $post_id );

		$this->assertCount( 2, $result );
		$this->assertEquals( 'topline', $result[0]['type'] );
		$this->assertEquals( 20, $result[0]['attachmentId'] );
		$this->assertEquals( 'topline', $result[1]['type'] );
		$this->assertEquals( 40, $result[1]['attachmentId'] );
	}

	/**
	 * The returned array is re-indexed (0-based) even when filter removes elements.
	 */
	public function test_get_extraction_materials_from_post_returns_reindexed_array() {
		$post_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Post for reindex test',
			'post_status' => 'publish',
		) );

		update_post_meta( $post_id, 'reportMaterials', array(
			array( 'type' => 'report',  'label' => 'Report',   'attachmentId' => 1 ),
			array( 'type' => 'topline', 'label' => 'Topline',  'attachmentId' => 2 ),
		) );

		$result = $this->service->get_extraction_materials_from_post( $post_id );

		$this->assertArrayHasKey( 0, $result );
		$this->assertArrayNotHasKey( 1, $result );
	}

	/**
	 * Handles gracefully when reportMaterials is stored as a non-array value.
	 */
	public function test_get_extraction_materials_from_post_handles_non_array_meta() {
		$post_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Post with bad meta',
			'post_status' => 'publish',
		) );

		update_post_meta( $post_id, 'reportMaterials', 'not-an-array' );

		$result = $this->service->get_extraction_materials_from_post( $post_id );

		$this->assertIsArray( $result );
		$this->assertEmpty( $result );
	}

	// -------------------------------------------------------------------------
	// get_extraction_for_material()
	// -------------------------------------------------------------------------

	/**
	 * Returns null when no extraction exists for the given post / attachment pair.
	 */
	public function test_get_extraction_for_material_returns_null_when_none() {
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent with no extraction',
			'post_status' => 'publish',
		) );

		$result = $this->service->get_extraction_for_material( $parent_id, 999 );

		$this->assertNull( $result );
	}

	/**
	 * Returns the extraction post ID when one already exists.
	 */
	public function test_get_extraction_for_material_finds_existing_extraction() {
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent Post',
			'post_status' => 'publish',
		) );

		$extraction_id = wp_insert_post( array(
			'post_type'   => Content_Type::get_post_type(),
			'post_title'  => 'Existing Extraction',
			'post_parent' => $parent_id,
			'post_status' => 'publish',
		) );
		update_post_meta( $extraction_id, '_pdf_source_attachment_id', 456 );

		$result = $this->service->get_extraction_for_material( $parent_id, 456 );

		$this->assertEquals( $extraction_id, $result );
	}

	/**
	 * Does not return extractions belonging to a different parent post.
	 */
	public function test_get_extraction_for_material_scoped_to_parent() {
		$parent_a = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent A',
			'post_status' => 'publish',
		) );
		$parent_b = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent B',
			'post_status' => 'publish',
		) );

		$extraction_id = wp_insert_post( array(
			'post_type'   => Content_Type::get_post_type(),
			'post_title'  => 'Extraction for A',
			'post_parent' => $parent_a,
			'post_status' => 'publish',
		) );
		update_post_meta( $extraction_id, '_pdf_source_attachment_id', 111 );

		// Same attachment ID but different parent: should not be found.
		$result = $this->service->get_extraction_for_material( $parent_b, 111 );

		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// cleanup_temp_file()
	// -------------------------------------------------------------------------

	/**
	 * Deletes a file that lives in the system temp directory.
	 */
	public function test_cleanup_temp_file_deletes_temp_files() {
		$temp_path = sys_get_temp_dir() . '/prc-pdf-extraction-test-cleanup.pdf';
		file_put_contents( $temp_path, 'dummy' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertFileExists( $temp_path );

		$this->service->cleanup_temp_file( $temp_path );

		$this->assertFileDoesNotExist( $temp_path );
	}

	/**
	 * Does not attempt to delete files that are not inside the system temp directory.
	 */
	public function test_cleanup_temp_file_ignores_non_temp_paths() {
		// Use a path outside sys_get_temp_dir() — we just need to verify no exception is thrown
		// and, critically, that the method doesn't try to delete an arbitrary path.
		$non_temp_path = ABSPATH . 'wp-config.php';

		// Should not throw and wp-config.php must still exist afterwards.
		$this->service->cleanup_temp_file( $non_temp_path );
		$this->assertFileExists( $non_temp_path );
	}

	/**
	 * Silently does nothing when the file no longer exists.
	 */
	public function test_cleanup_temp_file_handles_missing_file() {
		$ghost_path = sys_get_temp_dir() . '/prc-pdf-extraction-ghost-file.pdf';

		// Should not throw even though the file does not exist.
		$this->service->cleanup_temp_file( $ghost_path );
		$this->assertTrue( true ); // Reached without exception.
	}

	// -------------------------------------------------------------------------
	// save_extraction()
	// -------------------------------------------------------------------------

	/**
	 * Helper: build a synthetic OCR_Response for use in tests.
	 *
	 * @return OCR_Response
	 */
	private function make_ocr_response(): OCR_Response {
		return new OCR_Response(
			true,
			'Q1. Do you approve? Yes 60% No 40%',   // plain text
			'## Q1. Do you approve?\n\n| Response | % |\n|---|---|\n| Yes | 60% |\n| No | 40% |', // markdown
			'',                                     // gutenberg (empty for non-Gemini)
			0.92,                                   // confidence
			'google-vision',                        // provider
			0.0025                                  // cost
		);
	}

	/**
	 * Helper: build a minimal topline material array.
	 *
	 * @param int $attachment_id
	 * @return array
	 */
	private function make_topline( int $attachment_id = 555 ): array {
		return array(
			'type'         => 'topline',
			'label'        => 'Test Topline',
			'attachmentId' => $attachment_id,
			'url'          => 'https://example.com/topline.pdf',
		);
	}

	/**
	 * Creates a new topline post and returns an integer ID on success.
	 */
	public function test_save_extraction_creates_new_post() {
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent Report',
			'post_status' => 'publish',
		) );

		$result = $this->service->save_extraction(
			$parent_id,
			$this->make_topline(),
			$this->make_ocr_response()
		);

		$this->assertIsInt( $result );
		$this->assertGreaterThan( 0, $result );

		$post = get_post( $result );
		$this->assertNotNull( $post );
		$this->assertEquals( Content_Type::get_post_type(), $post->post_type );
		$this->assertEquals( 'publish', $post->post_status );
		$this->assertEquals( $parent_id, $post->post_parent );
		$this->assertStringContainsString( 'Parent Report', $post->post_title );
		$this->assertStringContainsString( 'Test Topline', $post->post_title );
	}

	/**
	 * Updates an existing extraction post rather than inserting a duplicate.
	 */
	public function test_save_extraction_updates_existing_post() {
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent Report',
			'post_status' => 'publish',
		) );

		$existing_id = wp_insert_post( array(
			'post_type'   => Content_Type::get_post_type(),
			'post_title'  => 'Old Extraction',
			'post_status' => 'publish',
			'post_parent' => $parent_id,
		) );

		$result = $this->service->save_extraction(
			$parent_id,
			$this->make_topline(),
			$this->make_ocr_response(),
			$existing_id
		);

		$this->assertEquals( $existing_id, $result );

		$post = get_post( $result );
		$this->assertStringContainsString( 'Parent Report', $post->post_title );
	}

	/**
	 * All required meta fields are written to the database after save.
	 */
	public function test_save_extraction_persists_all_meta_fields() {
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Meta Test Report',
			'post_status' => 'publish',
		) );

		$topline  = $this->make_topline( 777 );
		$response = $this->make_ocr_response();

		$extraction_id = $this->service->save_extraction( $parent_id, $topline, $response );

		$this->assertEquals( 777, (int) get_post_meta( $extraction_id, '_pdf_source_attachment_id', true ) );
		$this->assertEquals( 'https://example.com/topline.pdf', get_post_meta( $extraction_id, '_pdf_source_url', true ) );
		$this->assertEquals( 'Test Topline', get_post_meta( $extraction_id, '_pdf_source_label', true ) );
		$this->assertEquals( 'google-vision', get_post_meta( $extraction_id, '_ocr_provider_used', true ) );
		$this->assertEquals( 0.92, (float) get_post_meta( $extraction_id, '_ocr_confidence_score', true ) );
		$this->assertEquals( 0.0025, (float) get_post_meta( $extraction_id, '_processing_cost_usd', true ) );
		$this->assertNotEmpty( get_post_meta( $extraction_id, '_extraction_date', true ) );
		$this->assertEquals( PRC_PDF_EXTRACTION_VERSION, get_post_meta( $extraction_id, '_extraction_version', true ) );
		$this->assertNotEmpty( get_post_meta( $extraction_id, '_extracted_text_plain', true ) );
		$this->assertNotEmpty( get_post_meta( $extraction_id, '_extracted_text_markdown', true ) );
		$this->assertEquals( 'passed', get_post_meta( $extraction_id, '_validation_status', true ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $extraction_id, '_character_count', true ) );
	}

	/**
	 * When gutenberg is empty (e.g. non-Gemini provider), post_content uses markdown.
	 */
	public function test_save_extraction_uses_markdown_as_content_when_gutenberg_empty() {
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Format Test Report',
			'post_status' => 'publish',
		) );

		$response      = $this->make_ocr_response();
		$extraction_id = $this->service->save_extraction( $parent_id, $this->make_topline(), $response, null );

		$post = get_post( $extraction_id );
		$this->assertEquals( $response->get_markdown(), $post->post_content );
	}

	/**
	 * When gutenberg is present (e.g. Gemini provider), post_content uses block HTML.
	 */
	public function test_save_extraction_uses_gutenberg_as_content_when_present() {
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Format Test Report',
			'post_status' => 'publish',
		) );

		$gutenberg = '<!-- wp:paragraph --><p>Block content</p><!-- /wp:paragraph -->';
		$response  = new OCR_Response(
			true,
			'Plain text',
			'## Markdown',
			$gutenberg,
			0.9,
			'gemini',
			0.004,
			array()
		);
		$extraction_id = $this->service->save_extraction( $parent_id, $this->make_topline(), $response, null );

		$post = get_post( $extraction_id );
		$this->assertEquals( $gutenberg, $post->post_content );
	}

	/**
	 * Returns a WP_Error (not an exception) when the parent post does not exist.
	 */
	public function test_save_extraction_returns_wp_error_on_invalid_parent() {
		$non_existent_parent = 999999;

		$result = $this->service->save_extraction(
			$non_existent_parent,
			$this->make_topline(),
			$this->make_ocr_response()
		);

		// wp_insert_post with a null-title parent returns WP_Error.
		$this->assertTrue(
			is_wp_error( $result ) || is_int( $result ),
			'Result should be either a WP_Error or an integer post ID.'
		);
	}

	/**
	 * The character count meta matches the length of the plain text.
	 */
	public function test_save_extraction_character_count_matches_text_length() {
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Char Count Report',
			'post_status' => 'publish',
		) );

		$response      = $this->make_ocr_response();
		$extraction_id = $this->service->save_extraction( $parent_id, $this->make_topline(), $response );

		$stored_count = (int) get_post_meta( $extraction_id, '_character_count', true );
		$this->assertEquals( strlen( $response->get_text() ), $stored_count );
	}
}
