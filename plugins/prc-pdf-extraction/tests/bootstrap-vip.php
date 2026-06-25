<?php
/**
 * PHPUnit bootstrap file for VIP environment
 *
 * This bootstrap uses the wp-phpunit package with the wordpress_test database
 * to ensure tests run in isolation and don't pollute the development database.
 *
 * @package PRC_PDF_Extraction
 */

// Set the test config path
$_tests_dir = dirname( __FILE__ );
putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $_tests_dir . '/wp-tests-config.php' );

// Composer autoloader
$autoload_path = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoload_path ) ) {
	die( "Composer autoload not found. Run 'composer install' first.\n" );
}
require_once $autoload_path;

// Load the WordPress test suite bootstrap
$_bootstrap_file = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit/includes/bootstrap.php';
if ( ! file_exists( $_bootstrap_file ) ) {
	die( "WordPress test suite not found. Run 'composer install' to install wp-phpunit.\n" );
}

// Start the WordPress test suite
// Note: This will load WordPress and set up the test environment
require $_bootstrap_file;

// Now load our plugin (after WordPress is loaded)
require dirname( __DIR__ ) . '/prc-pdf-extraction.php';
