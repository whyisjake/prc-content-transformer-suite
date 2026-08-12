<?php
/**
 * PHPUnit bootstrap file for PRC Audio Narration plugin
 *
 * @package PRC_Audio_Narration
 */

// Composer autoloader
$prc_audio_narration_composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $prc_audio_narration_composer_autoload ) ) {
	require_once $prc_audio_narration_composer_autoload;
}

// Load WordPress test environment
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php\n";
	echo "Please run: bash bin/install-wp-tests.sh wordpress_test root '' localhost latest\n";
	exit( 1 );
}

// Give access to tests_add_filter() function
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested
 *
 * Cross-plugin contract stubs load first so the audio script provider can be
 * declared when prc-content-transformer is not part of the test environment.
 * The stubs no-op when the real plugin is present.
 */
function _manually_load_plugin() {
	require_once __DIR__ . '/stubs/content-transformer-stubs.php';

	// Action Scheduler ships with prc-content-transformer in production. It is
	// pulled in as a dev dependency here so the scheduling tests -- including
	// the duplicate-job guard that stops a double click costing twice -- run
	// against the real implementation rather than being skipped.
	$prc_audio_narration_action_scheduler = dirname( __DIR__ ) . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
	if ( file_exists( $prc_audio_narration_action_scheduler ) ) {
		require_once $prc_audio_narration_action_scheduler;
	}

	require dirname( __DIR__ ) . '/prc-audio-narration.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment
require "{$_tests_dir}/includes/bootstrap.php";
