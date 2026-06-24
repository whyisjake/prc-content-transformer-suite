<?php
/**
 * PRC Markdown for Agents
 *
 * @package           PRC_Markdown_For_Agents
 * @author            Pew Research Center
 * @copyright         2025 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC Markdown for Agents
 * Plugin URI:        https://github.com/pewresearch/prc-platform
 * Description:       Serve articles as markdown via .md and /markdown URLs for AI agents and crawlers.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Pew Research Center
 * Author URI:        https://pewresearch.org  // pragma: allowlist secret
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-markdown-for-agents
 * Requires Plugins:  prc-scripts
 */

namespace PRC\Platform\Markdown_For_Agents;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRC_MARKDOWN_FOR_AGENTS_FILE', __FILE__ );
define( 'PRC_MARKDOWN_FOR_AGENTS_DIR', __DIR__ );
define( 'PRC_MARKDOWN_FOR_AGENTS_VERSION', '1.0.0' );

if ( ! defined( 'PRC_PLATFORM' ) ) {
	$prc_markdown_for_agents_autoloader = __DIR__ . '/vendor/autoload_packages.php';
	if ( file_exists( $prc_markdown_for_agents_autoloader ) ) {
		require_once $prc_markdown_for_agents_autoloader;
	}
	unset( $prc_markdown_for_agents_autoloader );
}

/**
 * Accept: text/markdown content negotiation on canonical URLs.
 *
 * TEMPORARILY DISABLED (default false) — see Linear PRC-466.
 *
 * Issue observed on VIP alpha (Jun 2026): edge page cache on the canonical
 * article URL does not reliably partition by Vary: Accept. Once a markdown
 * response is cached (e.g. from Accept: text/markdown or a crawler), subsequent
 * requests with Accept: text/html receive the cached markdown body
 * (content-type: text/markdown, x-cache: HIT). Explicit .md and /markdown
 * endpoints are unaffected (separate cache keys). Agents should use rel=alternate
 * discovery links until this is resolved.
 *
 * Re-enable after VIP cache partitioning is verified or class-vip-compatibility
 * ships (X-Batcache: no for negotiated markdown, early Vary: Accept, etc.).
 */
if ( ! defined( 'PRC_MARKDOWN_FOR_AGENTS_ENABLE_ACCEPT_NEGOTIATION' ) ) {
	define( 'PRC_MARKDOWN_FOR_AGENTS_ENABLE_ACCEPT_NEGOTIATION', false );
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
function run_prc_markdown_for_agents() {
	$plugin = new Bootstrap();
	$plugin->run();
}
run_prc_markdown_for_agents();
