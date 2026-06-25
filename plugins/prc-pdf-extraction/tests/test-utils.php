<?php
/**
 * Class UtilsTest
 *
 * @package PRC_PDF_Extraction
 */

use PRC\Platform\PDF_Extraction\Content_Type;
use function PRC\Platform\PDF_Extraction\get_extraction_materials;
use function PRC\Platform\PDF_Extraction\get_extraction_material_by_index;
use function PRC\Platform\PDF_Extraction\validate_extraction_material_attachment;
use function PRC\Platform\PDF_Extraction\has_extracted_content;
use function PRC\Platform\PDF_Extraction\get_extracted_content;

/**
 * Test utility functions
 */
class UtilsTest extends WP_UnitTestCase {
	/**
	 * Test get_extraction_materials with no materials
	 */
	public function test_get_extraction_materials_empty() {
		$post_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Post without extraction materials',
			'post_status' => 'publish',
		) );

		$materials = get_extraction_materials( $post_id );
		$this->assertIsArray( $materials );
		$this->assertEmpty( $materials );
	}

	/**
	 * Test get_extraction_materials with mock data
	 */
	public function test_get_extraction_materials_with_data() {
		$post_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Post with extraction materials',
			'post_status' => 'publish',
		) );

		// Add mock reportMaterials
		$materials = array(
			array(
				'type'         => 'report',
				'label'        => 'Main Report',
				'attachmentId' => 123,
				'url'          => 'https://example.com/report.pdf',
			),
			array(
				'type'         => 'topline',
				'label'        => 'Topline Data',
				'attachmentId' => 456,
				'url'          => 'https://example.com/topline.pdf',
			),
			array(
				'type'         => 'topline',
				'label'        => 'Topline Data 2',
				'attachmentId' => 789,
				'url'          => 'https://example.com/topline2.pdf',
			),
		);

		update_post_meta( $post_id, 'reportMaterials', $materials );

		$materials_result = get_extraction_materials( $post_id );
		$this->assertIsArray( $materials_result );
		$this->assertCount( 2, $materials_result );
		$this->assertEquals( 'topline', $materials_result[0]['type'] );
		$this->assertEquals( 456, $materials_result[0]['attachmentId'] );
		$this->assertEquals( 789, $materials_result[1]['attachmentId'] );
	}

	/**
	 * Test get_extraction_material_by_index
	 */
	public function test_get_extraction_material_by_index() {
		$post_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Post with multiple extraction materials',
			'post_status' => 'publish',
		) );

		$materials = array(
			array(
				'type'         => 'topline',
				'label'        => 'First Topline',
				'attachmentId' => 111,
			),
			array(
				'type'         => 'topline',
				'label'        => 'Second Topline',
				'attachmentId' => 222,
			),
		);

		update_post_meta( $post_id, 'reportMaterials', $materials );

		// Test first index
		$material = get_extraction_material_by_index( $post_id, 0 );
		$this->assertIsArray( $material );
		$this->assertEquals( 'First Topline', $material['label'] );

		// Test second index
		$material = get_extraction_material_by_index( $post_id, 1 );
		$this->assertEquals( 'Second Topline', $material['label'] );

		// Test invalid index
		$material = get_extraction_material_by_index( $post_id, 5 );
		$this->assertNull( $material );
	}

	/**
	 * Test validate_extraction_material_attachment with missing attachment ID
	 */
	public function test_validate_extraction_material_attachment_missing_id() {
		$material = array(
			'type'  => 'topline',
			'label' => 'Invalid Topline',
		);

		$result = validate_extraction_material_attachment( $material );
		$this->assertWPError( $result );
		$this->assertEquals( 'missing_attachment_id', $result->get_error_code() );
	}

	/**
	 * Test validate_extraction_material_attachment with invalid attachment ID
	 */
	public function test_validate_extraction_material_attachment_invalid_id() {
		$material = array(
			'type'         => 'topline',
			'attachmentId' => 'not-a-number',
		);

		$result = validate_extraction_material_attachment( $material );
		$this->assertWPError( $result );
		$this->assertEquals( 'invalid_attachment_id', $result->get_error_code() );
	}

	/**
	 * Test validate_extraction_material_attachment with non-existent attachment
	 */
	public function test_validate_extraction_material_attachment_not_found() {
		$material = array(
			'type'         => 'topline',
			'attachmentId' => 99999,
		);

		$result = validate_extraction_material_attachment( $material );
		$this->assertWPError( $result );
		$this->assertEquals( 'attachment_not_found', $result->get_error_code() );
	}

	/**
	 * Test has_extracted_content
	 */
	public function test_has_extracted_content() {
		// Create parent post
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent Post',
			'post_status' => 'publish',
		) );

		// Initially should have no extracted content
		$this->assertFalse( has_extracted_content( $parent_id ) );

		// Add extracted content
		wp_insert_post( array(
			'post_type'   => Content_Type::get_post_type(),
			'post_title'  => 'Extraction',
			'post_parent' => $parent_id,
			'post_status' => 'publish',
		) );

		// Now should have extracted content
		$this->assertTrue( has_extracted_content( $parent_id ) );
	}

	/**
	 * Test get_extracted_content
	 */
	public function test_get_extracted_content() {
		// Create parent post
		$parent_id = wp_insert_post( array(
			'post_type'   => 'post',
			'post_title'  => 'Parent Post',
			'post_status' => 'publish',
		) );

		// Add multiple extractions
		wp_insert_post( array(
			'post_type'   => Content_Type::get_post_type(),
			'post_title'  => 'Extraction 1',
			'post_parent' => $parent_id,
			'post_status' => 'publish',
		) );

		wp_insert_post( array(
			'post_type'   => Content_Type::get_post_type(),
			'post_title'  => 'Extraction 2',
			'post_parent' => $parent_id,
			'post_status' => 'publish',
		) );

		// Get all extractions
		$extractions = get_extracted_content( $parent_id );
		$this->assertCount( 2, $extractions );
	}
}
