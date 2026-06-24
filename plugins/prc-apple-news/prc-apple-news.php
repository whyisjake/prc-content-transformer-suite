<?php
/**
 * PRC Apple News
 *
 * @package           PRC_Apple_News
 * @author            Pew Research Center
 * @copyright         2026 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC Apple News
 * Plugin URI:        https://github.com/pewresearch/prc-platform
 * Description:       Publishes PRC content to Apple News via the Apple News API.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Pew Research Center
 * Author URI:        https://www.pewresearch.org  // pragma: allowlist secret
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-apple-news
 * Requires Plugins:  prc-scripts, prc-content-transformer, prc-markdown-for-agents
 */

namespace PRC\Platform\Apple_News;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRC_APPLE_NEWS_FILE', __FILE__ );
define( 'PRC_APPLE_NEWS_DIR', __DIR__ );
define( 'PRC_APPLE_NEWS_VERSION', '1.0.0' );

if ( ! defined( 'PRC_PLATFORM' ) ) {
	$prc_apple_news_autoloader = __DIR__ . '/vendor/autoload_packages.php';
	if ( file_exists( $prc_apple_news_autoloader ) ) {
		require_once $prc_apple_news_autoloader;
	}
	unset( $prc_apple_news_autoloader );
}

/**
 * The code that runs during plugin activation.
 */
function activate() {
	// Require the Settings class so seed_defaults() and migrate_from_legacy() are available at activation time.
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-settings.php';
	Settings::seed_defaults();
	Settings::migrate_from_legacy();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate() {
	// Deactivation logic placeholder.
}

register_activation_hook( __FILE__, __NAMESPACE__ . '\activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\deactivate' );

require plugin_dir_path( __FILE__ ) . 'includes/class-bootstrap.php';

/**
 * Begins execution of the plugin.
 *
 * @since 1.0.0
 */
function run_prc_apple_news() {
	$plugin = new Bootstrap();
	$plugin->run();
}
run_prc_apple_news();
