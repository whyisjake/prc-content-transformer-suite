<?php
/**
 * Template for topline markdown output
 *
 * Optimized markdown format for topline data with metadata header.
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

// Get extracted markdown from meta, fallback to post_content
$markdown = get_post_meta( $extraction->ID, '_extracted_text_markdown', true );

if ( empty( $markdown ) ) {
	// Fallback to post content if meta not available
	$markdown = $extraction->post_content;
}

if ( empty( $markdown ) ) {
	status_header( 404 );
	die( 'No extracted markdown available' );
}

// Get metadata
$provider      = get_post_meta( $extraction->ID, '_ocr_provider_used', true );
$confidence    = get_post_meta( $extraction->ID, '_ocr_confidence_score', true );
$date          = get_post_meta( $extraction->ID, '_extraction_date', true );
$source_label  = get_post_meta( $extraction->ID, '_pdf_source_label', true );
$source_url    = get_post_meta( $extraction->ID, '_pdf_source_url', true );

// Get parent post for context
$parent_post = get_post( $post_id );

// Set headers
header( 'Content-Type: text/markdown; charset=UTF-8' );
header( 'Cache-Control: public, max-age=86400' ); // 24 hours
header( 'X-Content-Type-Options: nosniff' );
header( 'X-OCR-Provider: ' . esc_attr( $provider ) );
header( 'X-OCR-Confidence: ' . esc_attr( $confidence ) );
header( 'X-Extraction-Date: ' . esc_attr( $date ) );

// Build topline markdown with metadata header
$output = array();

$output[] = '# ' . get_the_title( $parent_post );
$output[] = '';
$output[] = '## Topline Data';
$output[] = '';
$output[] = '**Source:** ' . esc_html( $source_label );
if ( ! empty( $source_url ) ) {
	$output[] = '**URL:** ' . esc_url( $source_url );
}
if ( ! empty( $date ) ) {
	$output[] = '**Extracted:** ' . esc_html( $date );
}
if ( ! empty( $confidence ) && is_numeric( $confidence ) ) {
	$output[] = '**Confidence:** ' . round( $confidence * 100, 2 ) . '%';
}
$output[] = '';
$output[] = '---';
$output[] = '';
$output[] = $markdown;

// Output topline markdown
echo implode( "\n", $output ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
exit;
