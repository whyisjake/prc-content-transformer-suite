<?php
/**
 * PRC Content Transformer
 *
 * @package           PRC_Content_Transformer
 * @author            Seth Rubenstein
 * @copyright         2026 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC Content Transformer
 * Plugin URI:        https://github.com/pewresearch/prc-platform
 * Description:       AI-powered middleware that transforms WordPress content into provider-specific formats (Apple News, email HTML, plain text).
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Pew Research Center
 * Author URI:        https://www.pewresearch.org  // pragma: allowlist secret
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-content-transformer
 * Requires Plugins:  prc-scripts, prc-markdown-for-agents
 */

namespace PRC\Platform\Content_Transformer;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DEFAULT_TECHNICAL_CONTACT' ) ) {
	define( 'DEFAULT_TECHNICAL_CONTACT', 'webdev@pewresearch.org' );
}

define( 'PRC_CONTENT_TRANSFORMER_FILE', __FILE__ );
define( 'PRC_CONTENT_TRANSFORMER_DIR', __DIR__ );
define( 'PRC_CONTENT_TRANSFORMER_VERSION', '1.0.0' );

if ( ! defined( 'PRC_PLATFORM' ) ) {
	$prc_content_transformer_autoloader = __DIR__ . '/vendor/autoload_packages.php';
	if ( file_exists( $prc_content_transformer_autoloader ) ) {
		require_once $prc_content_transformer_autoloader;
	}
	unset( $prc_content_transformer_autoloader );
}

/**
 * The code that runs during plugin activation.
 */
function activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-activator.php';
	Plugin_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-deactivator.php';
	Plugin_Deactivator::deactivate();
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );

require plugin_dir_path( __FILE__ ) . 'includes/class-bootstrap.php';

/**
 * Begins execution of the plugin.
 *
 * @since 1.0.0
 */
function run_prc_content_transformer() {
	$plugin = new Bootstrap();
	$plugin->run();
}
run_prc_content_transformer();
