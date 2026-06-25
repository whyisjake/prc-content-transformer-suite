<?php
/**
 * Custom post type registration
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

/**
 * Content_Type class for registering the PDF extraction post type
 */
class Content_Type {
	/**
	 * Menu icon
	 *
	 * @var string
	 */
	public static $menu_icon = 'dashicons-text-page';

	/**
	 * Get the post type slug (filterable, statically cached).
	 *
	 * @return string
	 */
	public static function get_post_type(): string {
		static $post_type = null;
		if ( null === $post_type ) {
			$post_type = apply_filters( 'prc_pdf_extraction_post_type', 'pdf_extraction' );
		}
		return $post_type;
	}

	/**
	 * Get the post type labels (filterable, statically cached).
	 *
	 * @return array<string, string>
	 */
	public static function get_labels(): array {
		static $labels = null;
		if ( null === $labels ) {
			$labels = apply_filters(
				'prc_pdf_extraction_labels',
				array(
					'name'                  => 'PDF Extractions',
					'singular_name'         => 'PDF Extraction',
					'add_new'               => 'Add New',
					'add_new_item'          => 'Add New PDF Extraction',
					'edit_item'             => 'Edit PDF Extraction',
					'new_item'              => 'New PDF Extraction',
					'view_item'             => 'View PDF Extraction',
					'view_items'            => 'View PDF Extractions',
					'search_items'          => 'Search PDF Extractions',
					'not_found'             => 'No PDF extractions found',
					'not_found_in_trash'    => 'No PDF extractions found in trash',
					'parent_item_colon'     => 'Parent Article:',
					'all_items'             => 'All PDF Extractions',
					'archives'              => 'PDF Extraction Archives',
					'attributes'            => 'PDF Extraction Attributes',
					'insert_into_item'      => 'Insert into extraction',
					'uploaded_to_this_item' => 'Uploaded to this extraction',
					'filter_items_list'     => 'Filter extractions list',
					'items_list_navigation' => 'PDF Extractions list navigation',
					'items_list'            => 'PDF Extractions list',
					'item_published'        => 'PDF Extraction published',
					'item_updated'          => 'PDF Extraction updated',
				)
			);
		}
		return $labels;
	}

	/**
	 * Get the URL slug for extraction endpoints (filterable, statically cached).
	 *
	 * @return string
	 */
	public static function get_url_slug(): string {
		static $slug = null;
		if ( null === $slug ) {
			$slug = apply_filters( 'prc_pdf_extraction_url_slug', 'extraction' );
		}
		return $slug;
	}

	/**
	 * Get post type args for registration.
	 *
	 * @return array<string, mixed>
	 */
	private static function get_post_object_args(): array {
		$labels = self::get_labels();
		$slug   = self::get_url_slug();
		return array(
			'labels'              => $labels,
			'public'              => true,
			'publicly_queryable'  => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_rest'        => true,
			'hierarchical'        => false,
			'supports'            => array( 'title', 'editor', 'excerpt', 'revisions', 'custom-fields', 'page-attributes' ),
			'capability_type'     => 'post',
			'has_archive'         => false,
			'exclude_from_search' => false,
			'rewrite'             => array(
				'slug'       => $slug,
				'with_front' => false,
			),
			'menu_icon'           => self::$menu_icon,
			'menu_position'       => 20,
		);
	}

	/**
	 * Loader instance
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Constructor
	 *
	 * @param Loader $loader Loader instance
	 */
	public function __construct( $loader = null ) {
		if ( $loader ) {
			$this->loader = $loader;
			$this->register_hooks();
		}
	}

	/**
	 * Register hooks with the loader
	 */
	private function register_hooks() {
		$this->loader->add_action( 'init', $this, 'register_post_type' );
		$this->loader->add_action( 'init', $this, 'register_meta_fields' );
	}

	/**
	 * Register the custom post type
	 */
	public function register_post_type() {
		register_post_type( self::get_post_type(), self::get_post_object_args() );
	}

	/**
	 * Register meta fields for extracted content
	 */
	public function register_meta_fields() {
		$meta_fields = array(
			'_pdf_source_attachment_id' => array(
				'type'              => 'integer',
				'description'       => 'Source PDF attachment ID (from reportMaterials)',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
			),
			'_pdf_source_url'           => array(
				'type'              => 'string',
				'description'       => 'Source PDF URL (from reportMaterials)',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'esc_url_raw',
			),
			'_pdf_source_label'         => array(
				'type'              => 'string',
				'description'       => 'Original label (from reportMaterials)',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'_ocr_provider_used'        => array(
				'type'              => 'string',
				'description'       => 'OCR service used for extraction',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'_ocr_confidence_score'     => array(
				'type'              => 'number',
				'description'       => 'Confidence score (0-1)',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => function ( $value ) {
					return (float) $value;
				},
			),
			'_extraction_date'          => array(
				'type'              => 'string',
				'description'       => 'Extraction timestamp',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'_extraction_version'       => array(
				'type'              => 'string',
				'description'       => 'Plugin version used',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'_extracted_text_plain'     => array(
				'type'              => 'string',
				'description'       => 'Plain text content',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'wp_kses_post',
			),
			'_extracted_text_markdown'  => array(
				'type'              => 'string',
				'description'       => 'Markdown formatted content',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'wp_kses_post',
			),
			'_validation_status'        => array(
				'type'              => 'string',
				'description'       => 'Validation status (passed/failed/warning)',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'_validation_issues'        => array(
				'type'              => 'string',
				'description'       => 'JSON-encoded array of validation issues',
				'single'            => true,
				'default'           => '[]',
				'show_in_rest'      => true,
				'sanitize_callback' => function ( $value ) {
					// Handle array input (convert to JSON)
					if ( is_array( $value ) ) {
						$sanitized = array_map( 'sanitize_text_field', $value );
						return wp_json_encode( $sanitized );
					}
					// Handle string input (validate JSON)
					if ( is_string( $value ) ) {
						$decoded = json_decode( $value, true );
						if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
							$sanitized = array_map( 'sanitize_text_field', $decoded );
							return wp_json_encode( $sanitized );
						}
					}
					return '[]';
				},
			),
			'_processing_cost_usd'      => array(
				'type'              => 'number',
				'description'       => 'Processing cost in USD',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => function ( $value ) {
					return (float) $value;
				},
			),
			'_character_count'          => array(
				'type'              => 'integer',
				'description'       => 'Total character count',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
			),
		);

		foreach ( $meta_fields as $meta_key => $args ) {
			register_post_meta( self::get_post_type(), $meta_key, $args );
		}
	}
}
