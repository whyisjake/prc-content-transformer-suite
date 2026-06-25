<?php
/**
 * Plugin bootstrap class
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

/**
 * Bootstrap class for plugin initialization
 */
class Bootstrap {
	/**
	 * The loader responsible for maintaining and registering all hooks
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Plugin name
	 *
	 * @var string
	 */
	protected $plugin_name;

	/**
	 * Plugin version
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Initialize the plugin
	 */
	public function __construct() {
		$this->plugin_name = 'prc-pdf-extraction';
		$this->version     = PRC_PDF_EXTRACTION_VERSION;

		$this->load_dependencies();
		$this->register_modules();
	}

	/**
	 * Load required dependencies
	 */
	private function load_dependencies() {
		// Core classes
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-loader.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-content-type.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-meta-boxes.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-rewrite-rules.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-sitemap-integration.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-content-discovery.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-markdown-for-agents-integration.php';

		// OCR Infrastructure
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/infrastructure/interface-http-client.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/infrastructure/class-http-client.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/infrastructure/class-file-encoder.php';

		// OCR Domain
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/domain/class-ocr-request.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/domain/class-ocr-response.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/domain/exceptions/class-ocr-exception.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/domain/exceptions/class-provider-unavailable-exception.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/domain/exceptions/class-authentication-exception.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/domain/exceptions/class-rate-limit-exception.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/domain/exceptions/class-invalid-file-exception.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/domain/exceptions/class-extraction-failed-exception.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/domain/exceptions/class-validation-failed-exception.php';

		// OCR Providers
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/providers/interface-ocr-provider.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/providers/trait-shared-extraction-prompts.php';

		// OCR Application
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/application/class-quality-validator.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/application/class-ocr-orchestrator.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/application/class-page-analyzer.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/application/class-result-merger.php';
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/ocr/application/class-output-validator.php';

		// Shared extraction service (used by both CLI and Action Scheduler).
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-extraction-service.php';

		// Action Scheduler handler (must be loaded on every request so the hook is registered).
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-action-scheduler-handler.php';

		// REST API for block editor integration.
		require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-rest-api.php';

		// WP-CLI Commands
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-wp-cli-commands.php';
			require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-bulk-cli-command.php';
		}

		// Initialize loader
		$this->loader = new Loader();
	}

	/**
	 * Register all plugin modules
	 */
	private function register_modules() {
		// Register custom post type
		$content_type = new Content_Type( $this->get_loader() );

		// Register meta boxes
		$meta_boxes = new Meta_Boxes( $this->get_loader() );

		// Register rewrite rules for content endpoints
		$rewrite_rules = new Rewrite_Rules( $this->get_loader() );

		// Register sitemap integration
		$sitemap = new Sitemap_Integration( $this->get_loader() );

		// Register content discovery (meta tags, JSON-LD, robots.txt)
		$content_discovery = new Content_Discovery( $this->get_loader() );

		if ( class_exists( 'PRC\Platform\Markdown_For_Agents\LLMs_Txt' ) ) {
			require_once PRC_PDF_EXTRACTION_DIR . '/includes/class-llms-txt-section.php';
			new Llms_Txt_Section( $this->get_loader() );
		}

		// Wire up prc-markdown-for-agents integration when that plugin is active.
		if ( class_exists( 'PRC\Platform\Markdown_For_Agents\Markdown_Response' ) ) {
			new Markdown_For_Agents_Integration( $this->get_loader() );
		}

		// OCR Orchestrator is registered but doesn't need loader.
		// It will be instantiated when needed via new OCR\Application\OCR_Orchestrator().

		// Register the Action Scheduler handler hook (must run on every request).
		Action_Scheduler_Handler::init();

		// Register REST API routes for block editor integration.
		$rest_api = new REST_API( $this->get_loader() );

		// Register WP-CLI commands
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'prc-pdf-extraction', WP_CLI_Commands::class );
			if ( class_exists( 'WPCOM_VIP_CLI_Command' ) ) {
				\WP_CLI::add_command( 'prc-pdf-extraction', Bulk_CLI_Command::class );
			}
		}
	}

	/**
	 * Run the loader to register hooks
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * Get the plugin name
	 *
	 * @return string
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Get the loader instance
	 *
	 * @return Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Get the plugin version
	 *
	 * @return string
	 */
	public function get_version() {
		return $this->version;
	}
}
