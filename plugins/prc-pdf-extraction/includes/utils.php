<?php
/**
 * Helper utility functions for PRC PDF Extraction
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

/**
 * Parse reportMaterials meta array to find extraction PDFs (e.g. type=topline).
 *
 * @param int $post_id Post ID to get extraction materials from
 * @return array Array of extraction materials
 */
function get_extraction_materials( $post_id ) {
	$materials = get_post_meta( $post_id, 'reportMaterials', true );

	if ( empty( $materials ) || ! is_array( $materials ) ) {
		return array();
	}

	// Filter for topline materials
	$toplines = array_filter( $materials, function( $material ) {
		return isset( $material['type'] ) && 'topline' === $material['type'];
	});

	// Reindex array to maintain proper indices
	return array_values( $toplines );
}

/**
 * Get a specific extraction material by index
 *
 * @param int $post_id Post ID
 * @param int $index Index in the extraction materials array
 * @return array|null Extraction material or null if not found
 */
function get_extraction_material_by_index( $post_id, $index = 0 ) {
	$materials = get_extraction_materials( $post_id );

	if ( isset( $materials[ $index ] ) ) {
		return $materials[ $index ];
	}

	return null;
}

/**
 * Validate that an extraction material attachment exists and is accessible
 *
 * @param array $material Extraction material array
 * @return bool|WP_Error True if valid, WP_Error if not
 */
function validate_extraction_material_attachment( $material ) {
	if ( ! isset( $material['attachmentId'] ) ) {
		return new \WP_Error( 'missing_attachment_id', 'Extraction material missing attachment ID' );
	}

	$attachment_id = intval( $material['attachmentId'] );

	if ( ! $attachment_id ) {
		return new \WP_Error( 'invalid_attachment_id', 'Invalid attachment ID' );
	}

	// Check if attachment exists
	$attachment = get_post( $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return new \WP_Error( 'attachment_not_found', sprintf( 'Attachment ID %d not found', $attachment_id ) );
	}

	// Get file path
	$file_path = get_attached_file( $attachment_id );
	if ( ! $file_path || ! file_exists( $file_path ) ) {
		return new \WP_Error( 'file_not_found', sprintf( 'File not found for attachment ID %d', $attachment_id ) );
	}

	// Verify it's a PDF
	$mime_type = get_post_mime_type( $attachment_id );
	if ( 'application/pdf' !== $mime_type ) {
		return new \WP_Error( 'not_pdf', sprintf( 'Attachment is not a PDF (type: %s)', $mime_type ) );
	}

	return true;
}

/**
 * Get the file path for an extraction material attachment
 *
 * @param int $attachment_id Attachment ID
 * @return string|false File path or false if not found
 */
function get_extraction_file_path( $attachment_id ) {
	return get_attached_file( $attachment_id );
}

/**
 * Check if a post has any extracted content
 *
 * @param int $post_id Parent post ID
 * @return bool True if extractions exist
 */
function has_extracted_content( $post_id ) {
	$extractions = get_posts( array(
		'post_type'      => Content_Type::get_post_type(),
		'post_parent'    => $post_id,
		'posts_per_page' => 1,
		'fields'         => 'ids',
	));

	return ! empty( $extractions );
}

/**
 * Get extracted content for a post
 *
 * @param int $post_id Parent post ID
 * @return array Array of extraction post objects
 */
function get_extracted_content( $post_id ) {
	return get_posts(
		array(
			'post_type'      => Content_Type::get_post_type(),
			'post_parent'    => $post_id,
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
}
