<?php
/**
 * Template for plain text output
 *
 * @package PRC_PDF_Extraction
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get post ID from query var
$post_id = get_query_var( 'extraction_post' );

if ( ! $post_id ) {
	status_header( 404 );
	die( 'Post ID required' );
}

// Get the extraction post
$extraction_post = get_posts(
	array(
		'post_type'   => \PRC\Platform\PDF_Extraction\Content_Type::get_post_type(),
		'post_parent' => $post_id,
		'numberposts' => 1,
		'post_status' => 'publish',
	)
);

if ( empty( $extraction_post ) ) {
	status_header( 404 );
	die( 'No extraction found for this post' );
}

$extraction = $extraction_post[0];

// Get extracted text from meta, fallback to post_content
$text = get_post_meta( $extraction->ID, '_extracted_text_plain', true );

if ( empty( $text ) ) {
	// Fallback to post content if meta not available
	$text = $extraction->post_content;
}

if ( empty( $text ) ) {
	status_header( 404 );
	die( 'No extracted text available' );
}

// Get metadata for headers
$provider   = get_post_meta( $extraction->ID, '_ocr_provider_used', true );
$confidence = get_post_meta( $extraction->ID, '_ocr_confidence_score', true );
$date       = get_post_meta( $extraction->ID, '_extraction_date', true );

// Set headers
header( 'Content-Type: text/plain; charset=UTF-8' );
header( 'Cache-Control: public, max-age=86400' ); // 24 hours
header( 'X-Content-Type-Options: nosniff' );
header( 'X-OCR-Provider: ' . esc_attr( $provider ) );
header( 'X-OCR-Confidence: ' . esc_attr( $confidence ) );
header( 'X-Extraction-Date: ' . esc_attr( $date ) );

// Output text
echo esc_html( $text );
exit;
