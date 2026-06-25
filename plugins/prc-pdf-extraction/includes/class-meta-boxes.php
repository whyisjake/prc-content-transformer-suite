<?php
/**
 * Meta Boxes for PDF Extraction
 *
 * Provides custom meta boxes for easier editing of extraction metadata.
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

/**
 * Meta_Boxes class
 */
class Meta_Boxes {
	/**
	 * Loader instance
	 *
	 * @var Loader
	 */
	private $loader;

	/**
	 * Constructor
	 *
	 * @param Loader $loader The loader instance.
	 */
	public function __construct( $loader ) {
		$this->loader = $loader;
		$this->register_hooks();
	}

	/**
	 * Register hooks
	 */
	private function register_hooks() {
		$this->loader->add_action( 'add_meta_boxes', $this, 'add_meta_boxes' );
		$this->loader->add_action( 'save_post', $this, 'save_meta_boxes', 10, 2 );
		$this->loader->add_action( 'page_attributes_misc_attributes', $this, 'display_parent_article_info' );
	}

	/**
	 * Display parent article info in Page Attributes meta box
	 *
	 * This filter adds parent article display for cross-post-type relationships
	 * since the default parent dropdown only shows same-type posts.
	 *
	 * @param \WP_Post $post The post object.
	 */
	public function display_parent_article_info( $post ) {
		// Only for our post type
		if ( Content_Type::get_post_type() !== $post->post_type ) {
			return;
		}

		$parent_id = $post->post_parent;

		if ( ! $parent_id ) {
			return;
		}

		$parent = get_post( $parent_id );

		if ( ! $parent ) {
			return;
		}

		$edit_link       = get_edit_post_link( $parent_id );
		$view_link       = get_permalink( $parent_id );
		$post_type_obj   = get_post_type_object( $parent->post_type );
		$post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $parent->post_type;
		?>
		<p class="post-attributes-label-wrapper parent-article-label-wrapper">
			<label class="post-attributes-label" for="parent_article_display">
				<?php esc_html_e( 'Parent Article', 'prc-pdf-extraction' ); ?>
			</label>
		</p>
		<div id="parent_article_display" style="background: #f0f0f1; padding: 10px; border-radius: 4px; margin-bottom: 12px;">
			<strong style="display: block; margin-bottom: 5px;"><?php echo esc_html( $parent->post_title ); ?></strong>
			<span style="color: #646970; font-size: 12px;">
				<?php echo esc_html( $post_type_label ); ?> (ID: <?php echo esc_html( $parent_id ); ?>)
			</span>
			<div style="margin-top: 8px;">
				<?php if ( $edit_link ) : ?>
					<a href="<?php echo esc_url( $edit_link ); ?>" style="margin-right: 10px;">
						<?php esc_html_e( 'Edit', 'prc-pdf-extraction' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $view_link ) : ?>
					<a href="<?php echo esc_url( $view_link ); ?>" target="_blank">
						<?php esc_html_e( 'View', 'prc-pdf-extraction' ); ?> ↗
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Add meta boxes
	 */
	public function add_meta_boxes() {
		$post_type = Content_Type::get_post_type();

		// PDF Source Information
		add_meta_box(
			'prc_pdf_source_info',
			__( 'PDF Source Information', 'prc-pdf-extraction' ),
			array( $this, 'render_source_info_meta_box' ),
			$post_type,
			'normal',
			'high'
		);

		// OCR Results
		add_meta_box(
			'prc_ocr_results',
			__( 'OCR Results', 'prc-pdf-extraction' ),
			array( $this, 'render_ocr_results_meta_box' ),
			$post_type,
			'normal',
			'high'
		);

		// Validation Results
		add_meta_box(
			'prc_validation_results',
			__( 'Validation Results', 'prc-pdf-extraction' ),
			array( $this, 'render_validation_results_meta_box' ),
			$post_type,
			'side'
		);

		// Processing Metadata
		add_meta_box(
			'prc_processing_metadata',
			__( 'Processing Metadata', 'prc-pdf-extraction' ),
			array( $this, 'render_processing_metadata_meta_box' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Render PDF Source Information meta box
	 *
	 * @param \WP_Post $post The post object.
	 */
	public function render_source_info_meta_box( $post ) {
		wp_nonce_field( 'prc_pdf_extraction_meta_box', 'prc_pdf_extraction_nonce' );

		$attachment_id = get_post_meta( $post->ID, '_pdf_source_attachment_id', true );
		$source_url    = get_post_meta( $post->ID, '_pdf_source_url', true );
		$source_label  = get_post_meta( $post->ID, '_pdf_source_label', true );
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="pdf_source_attachment_id"><?php esc_html_e( 'Attachment ID', 'prc-pdf-extraction' ); ?></label>
				</th>
				<td>
					<input type="number" id="pdf_source_attachment_id" name="pdf_source_attachment_id" value="<?php echo esc_attr( $attachment_id ); ?>" class="regular-text" />
					<?php if ( $attachment_id ) : ?>
						<p class="description">
							<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $attachment_id . '&action=edit' ) ); ?>" target="_blank">
								<?php esc_html_e( 'View Attachment', 'prc-pdf-extraction' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="pdf_source_url"><?php esc_html_e( 'Source URL', 'prc-pdf-extraction' ); ?></label>
				</th>
				<td>
					<input type="url" id="pdf_source_url" name="pdf_source_url" value="<?php echo esc_attr( $source_url ); ?>" class="large-text" />
					<?php if ( $source_url ) : ?>
						<p class="description">
							<a href="<?php echo esc_url( $source_url ); ?>" target="_blank">
								<?php esc_html_e( 'Open PDF', 'prc-pdf-extraction' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="pdf_source_label"><?php esc_html_e( 'Label', 'prc-pdf-extraction' ); ?></label>
				</th>
				<td>
					<input type="text" id="pdf_source_label" name="pdf_source_label" value="<?php echo esc_attr( $source_label ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Display label from reportMaterials', 'prc-pdf-extraction' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render OCR Results meta box
	 *
	 * @param \WP_Post $post The post object.
	 */
	public function render_ocr_results_meta_box( $post ) {
		$provider        = get_post_meta( $post->ID, '_ocr_provider_used', true );
		$confidence      = get_post_meta( $post->ID, '_ocr_confidence_score', true );
		$text_plain      = get_post_meta( $post->ID, '_extracted_text_plain', true );
		$text_markdown   = get_post_meta( $post->ID, '_extracted_text_markdown', true );
		$character_count = get_post_meta( $post->ID, '_character_count', true );
		?>
		<table class="form-table">
			<tr>
				<th scope="row">
					<label for="ocr_provider_used"><?php esc_html_e( 'OCR Provider', 'prc-pdf-extraction' ); ?></label>
				</th>
				<td>
					<select id="ocr_provider_used" name="ocr_provider_used">
						<option value=""><?php esc_html_e( 'Select provider...', 'prc-pdf-extraction' ); ?></option>
						<option value="claude" <?php selected( $provider, 'claude' ); ?>>Claude (Anthropic)</option>
						<option value="gemini" <?php selected( $provider, 'gemini' ); ?>>Google Gemini (AI)</option>
						<option value="wp-ai" <?php selected( $provider, 'wp-ai' ); ?>>WP AI</option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="ocr_confidence_score"><?php esc_html_e( 'Confidence Score', 'prc-pdf-extraction' ); ?></label>
				</th>
				<td>
					<input type="number" id="ocr_confidence_score" name="ocr_confidence_score" value="<?php echo esc_attr( $confidence ); ?>" step="0.01" min="0" max="1" class="small-text" />
					<p class="description"><?php esc_html_e( 'Value between 0 and 1', 'prc-pdf-extraction' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="character_count"><?php esc_html_e( 'Character Count', 'prc-pdf-extraction' ); ?></label>
				</th>
				<td>
					<input type="number" id="character_count" name="character_count" value="<?php echo esc_attr( $character_count ); ?>" class="small-text" readonly />
					<p class="description"><?php esc_html_e( 'Automatically calculated', 'prc-pdf-extraction' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="extracted_text_plain"><?php esc_html_e( 'Plain Text', 'prc-pdf-extraction' ); ?></label>
				</th>
				<td>
					<textarea id="extracted_text_plain" name="extracted_text_plain" rows="10" class="large-text code"><?php echo esc_textarea( $text_plain ); ?></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="extracted_text_markdown"><?php esc_html_e( 'Markdown', 'prc-pdf-extraction' ); ?></label>
				</th>
				<td>
					<textarea id="extracted_text_markdown" name="extracted_text_markdown" rows="10" class="large-text code"><?php echo esc_textarea( $text_markdown ); ?></textarea>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Render Validation Results meta box
	 *
	 * @param \WP_Post $post The post object.
	 */
	public function render_validation_results_meta_box( $post ) {
		$status = get_post_meta( $post->ID, '_validation_status', true );
		$issues = get_post_meta( $post->ID, '_validation_issues', true );

		// Parse JSON if stored as string
		if ( is_string( $issues ) ) {
			$issues = json_decode( $issues, true );
		}
		?>
		<div class="prc-validation-results">
			<p>
				<label for="validation_status"><strong><?php esc_html_e( 'Status', 'prc-pdf-extraction' ); ?></strong></label><br>
				<select id="validation_status" name="validation_status" style="width: 100%;">
					<option value=""><?php esc_html_e( 'Not validated', 'prc-pdf-extraction' ); ?></option>
					<option value="passed" <?php selected( $status, 'passed' ); ?>><?php esc_html_e( 'Passed', 'prc-pdf-extraction' ); ?></option>
					<option value="warning" <?php selected( $status, 'warning' ); ?>><?php esc_html_e( 'Warning', 'prc-pdf-extraction' ); ?></option>
					<option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'prc-pdf-extraction' ); ?></option>
				</select>
			</p>
			<p>
				<label for="validation_issues"><strong><?php esc_html_e( 'Issues', 'prc-pdf-extraction' ); ?></strong></label><br>
				<textarea id="validation_issues" name="validation_issues" rows="5" style="width: 100%;" class="code"><?php echo esc_textarea( is_array( $issues ) ? wp_json_encode( $issues, JSON_PRETTY_PRINT ) : $issues ); ?></textarea>
				<span class="description"><?php esc_html_e( 'JSON array of validation issues', 'prc-pdf-extraction' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render Processing Metadata meta box
	 *
	 * @param \WP_Post $post The post object.
	 */
	public function render_processing_metadata_meta_box( $post ) {
		$extraction_date     = get_post_meta( $post->ID, '_extraction_date', true );
		$extraction_version  = get_post_meta( $post->ID, '_extraction_version', true );
		$processing_cost     = get_post_meta( $post->ID, '_processing_cost_usd', true );
		$extraction_duration = get_post_meta( $post->ID, '_extraction_duration_seconds', true );
		?>
		<div class="prc-processing-metadata">
			<p>
				<label for="extraction_date"><strong><?php esc_html_e( 'Extraction Date', 'prc-pdf-extraction' ); ?></strong></label><br>
				<input type="datetime-local" id="extraction_date" name="extraction_date" value="<?php echo esc_attr( $extraction_date ? gmdate( 'Y-m-d\TH:i', strtotime( $extraction_date ) ) : '' ); ?>" style="width: 100%;" />
			</p>
			<p>
				<label for="extraction_duration"><strong><?php esc_html_e( 'Extraction Duration', 'prc-pdf-extraction' ); ?></strong></label><br>
				<input type="text" id="extraction_duration" value="<?php echo $extraction_duration !== '' && $extraction_duration !== false ? esc_attr( sprintf( '%.2f seconds', (float) $extraction_duration ) ) : ''; ?>" style="width: 100%;" readonly />
				<span class="description"><?php esc_html_e( 'Time taken for extraction', 'prc-pdf-extraction' ); ?></span>
			</p>
			<p>
				<label for="extraction_version"><strong><?php esc_html_e( 'Plugin Version', 'prc-pdf-extraction' ); ?></strong></label><br>
				<input type="text" id="extraction_version" name="extraction_version" value="<?php echo esc_attr( $extraction_version ); ?>" style="width: 100%;" readonly />
				<span class="description"><?php esc_html_e( 'Plugin version used', 'prc-pdf-extraction' ); ?></span>
			</p>
			<p>
				<label for="processing_cost_usd"><strong><?php esc_html_e( 'Processing Cost', 'prc-pdf-extraction' ); ?></strong></label><br>
				<input type="number" id="processing_cost_usd" name="processing_cost_usd" value="<?php echo esc_attr( $processing_cost ); ?>" step="0.0001" min="0" style="width: 100%;" />
				<span class="description"><?php esc_html_e( 'Cost in USD', 'prc-pdf-extraction' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Save meta boxes
	 *
	 * @param int      $post_id The post ID.
	 * @param \WP_Post $post    The post object.
	 */
	public function save_meta_boxes( $post_id, $post ) {
		// Verify nonce
		if ( ! isset( $_POST['prc_pdf_extraction_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prc_pdf_extraction_nonce'] ) ), 'prc_pdf_extraction_meta_box' ) ) {
			return;
		}

		// Check autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check post type
		if ( Content_Type::get_post_type() !== $post->post_type ) {
			return;
		}

		// Check permissions
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save PDF Source Information
		$this->save_meta_field( $post_id, 'pdf_source_attachment_id', 'intval' );
		$this->save_meta_field( $post_id, 'pdf_source_url', 'esc_url_raw' );
		$this->save_meta_field( $post_id, 'pdf_source_label', 'sanitize_text_field' );

		// Save OCR Results
		$this->save_meta_field( $post_id, 'ocr_provider_used', 'sanitize_text_field' );
		$this->save_meta_field( $post_id, 'ocr_confidence_score', 'floatval' );
		$this->save_meta_field( $post_id, 'extracted_text_plain', 'wp_kses_post' );
		$this->save_meta_field( $post_id, 'extracted_text_markdown', 'wp_kses_post' );

		// Calculate and save character count
		if ( isset( $_POST['extracted_text_plain'] ) ) {
			$plain_text = sanitize_textarea_field( wp_unslash( $_POST['extracted_text_plain'] ) );
			update_post_meta( $post_id, '_character_count', strlen( $plain_text ) );
		}

		// Save Validation Results
		$this->save_meta_field( $post_id, 'validation_status', 'sanitize_text_field' );
		if ( isset( $_POST['validation_issues'] ) ) {
			$issues = sanitize_textarea_field( wp_unslash( $_POST['validation_issues'] ) );
			// Validate JSON
			$decoded = json_decode( $issues, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				update_post_meta( $post_id, '_validation_issues', $issues );
			}
		}

		// Save Processing Metadata
		if ( isset( $_POST['extraction_date'] ) ) {
			$date = sanitize_text_field( wp_unslash( $_POST['extraction_date'] ) );
			if ( $date ) {
				update_post_meta( $post_id, '_extraction_date', gmdate( 'Y-m-d H:i:s', strtotime( $date ) ) );
			}
		}
		$this->save_meta_field( $post_id, 'processing_cost_usd', 'floatval' );
		// extraction_version is readonly, don't save from form
	}

	/**
	 * Save a meta field
	 *
	 * @param int    $post_id   The post ID.
	 * @param string $field     The field name (without underscore prefix).
	 * @param string $sanitizer The sanitization function.
	 */
	private function save_meta_field( $post_id, $field, $sanitizer = 'sanitize_text_field' ) {
		if ( ! isset( $_POST[ $field ] ) ) {
			return;
		}

		$value = call_user_func( $sanitizer, wp_unslash( $_POST[ $field ] ) );
		update_post_meta( $post_id, '_' . $field, $value );
	}
}
