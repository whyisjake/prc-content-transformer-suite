<?php
/**
 * PRC Audio Narration
 *
 * @package           PRC_Audio_Narration
 * @author            Pew Research Center
 * @copyright         2026 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC Audio Narration
 * Plugin URI:        https://github.com/pewresearch/prc-audio-narration
 * Description:       Converts articles into narrated audio: rewrites content for the ear, synthesizes speech, and stores the result in the Media Library.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Pew Research Center
 * Author URI:        https://pewresearch.org
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-audio-narration
 * Requires Plugins:  prc-scripts, prc-markdown-for-agents, prc-content-transformer
 */

namespace PRC\Platform\Audio_Narration;

// Security checks
if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// When running inside the PRC Platform monorepo the root autoloader already
// provides every dependency; skip per-plugin Jetpack Autoloader initialization.
if ( ! defined( 'PRC_PLATFORM' ) ) {
	$prc_audio_narration_autoloader = __DIR__ . '/vendor/autoload_packages.php';
	if ( file_exists( $prc_audio_narration_autoloader ) ) {
		require_once $prc_audio_narration_autoloader;
	}
	unset( $prc_audio_narration_autoloader );
}

// Constants
define( 'PRC_AUDIO_NARRATION_FILE', __FILE__ );
define( 'PRC_AUDIO_NARRATION_DIR', __DIR__ );
define( 'PRC_AUDIO_NARRATION_URL', plugin_dir_url( __FILE__ ) );
define( 'PRC_AUDIO_NARRATION_VERSION', '1.0.0' );

/**
 * Run plugin activation routines.
 *
 * @return void
 */
function activate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-activator.php';
	Plugin_Activator::activate();
}

/**
 * Run plugin deactivation routines.
 *
 * @return void
 */
function deactivate() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-plugin-deactivator.php';
	Plugin_Deactivator::deactivate();
}

register_activation_hook( __FILE__, '\PRC\Platform\Audio_Narration\activate' );
register_deactivation_hook( __FILE__, '\PRC\Platform\Audio_Narration\deactivate' );

// Bootstrap
require plugin_dir_path( __FILE__ ) . 'includes/class-bootstrap.php';

/**
 * Boot the plugin.
 *
 * @return void
 */
function run_prc_audio_narration() {
	$plugin = new Bootstrap();
	$plugin->run();
}
run_prc_audio_narration();
