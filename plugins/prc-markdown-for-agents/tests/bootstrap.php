<?php
/**
 * PHPUnit bootstrap file for PRC Markdown for Agents plugin.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

require_once dirname( __DIR__, 3 ) . '/plugins/prc-pdf-extraction/vendor/autoload.php';

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php\n";
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

function _manually_load_markdown_for_agents_plugin() {
	require dirname( __DIR__ ) . '/prc-markdown-for-agents.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_markdown_for_agents_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";
