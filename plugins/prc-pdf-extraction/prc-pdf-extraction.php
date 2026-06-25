<?php
/**
 * PRC PDF Extraction
 *
 * @package           PRC_PDF_Extraction
 * @author            Pew Research Center
 * @copyright         2026 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC PDF Extraction
 * Plugin URI:        https://github.com/pewresearch/prc-pdf-extraction
 * Description:       Extracts text from PDF documents using OCR services, making data accessible to LLMs via custom endpoints.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Pew Research Center
 * Author URI:        https://pewresearch.org
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-pdf-extraction
 * Requires Plugins:  prc-scripts, prc-markdown-for-agents
 */

namespace PRC\Platform\PDF_Extraction;

// Security checks
if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DEFAULT_TECHNICAL_CONTACT' ) ) {
	define( 'DEFAULT_TECHNICAL_CONTACT', 'webdev@pewresearch.org' );
}

// When running inside the PRC Platform monorepo the root autoloader already
// provides every dependency; skip per-plugin Jetpack Autoloader initialization.
if ( ! defined( 'PRC_PLATFORM' ) ) {
	$prc_pdf_extraction_autoloader = __DIR__ . '/vendor/autoload_packages.php';
	if ( file_exists( $prc_pdf_extraction_autoloader ) ) {
		require_once $prc_pdf_extraction_autoloader;
	}
	unset( $prc_pdf_extraction_autoloader );
}

// Constants
define( 'PRC_PDF_EXTRACTION_FILE', __FILE__ );
define( 'PRC_PDF_EXTRACTION_DIR', __DIR__ );
define( 'PRC_PDF_EXTRACTION_URL', plugin_dir_url( __FILE__ ) );
define( 'PRC_PDF_EXTRACTION_VERSION', '1.0.0' );

// Activation/Deactivation
function activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-activator.php';
	Plugin_Activator::activate();
}

function deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-deactivator.php';
	Plugin_Deactivator::deactivate();
}

register_activation_hook( __FILE__, '\PRC\Platform\PDF_Extraction\activate' );
register_deactivation_hook( __FILE__, '\PRC\Platform\PDF_Extraction\deactivate' );

// Helper utilities
require plugin_dir_path( __FILE__ ) . 'includes/utils.php';

// Bootstrap
require plugin_dir_path( __FILE__ ) . 'includes/class-bootstrap.php';

function run_prc_pdf_extraction() {
	$plugin = new Bootstrap();
	$plugin->run();
}
run_prc_pdf_extraction();
