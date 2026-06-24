<?php
/**
 * PHPUnit bootstrap for PRC Apple News ANF tests.
 *
 * Loads WP function stubs and the plugin classes under test directly,
 * with no WordPress installation or Docker/wp-env required.
 *
 * @package PRC\Platform\Apple_News\ANF\Tests
 */

declare(strict_types=1);

// Satisfy the plugin's WPINC / ABSPATH guards without loading WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'PRC_APPLE_NEWS_FILE' ) ) {
	define( 'PRC_APPLE_NEWS_FILE', dirname( __DIR__ ) . '/prc-apple-news.php' );
}
if ( ! defined( 'PRC_APPLE_NEWS_DIR' ) ) {
	define( 'PRC_APPLE_NEWS_DIR', dirname( __DIR__ ) );
}

// Load WP stubs before any plugin code.
require_once __DIR__ . '/php/wp-stubs.php';
require_once __DIR__ . '/php/markdown-converter-stub.php';

// Load the Jetpack / Composer autoloader (installed via `composer install --dev`).
$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoloader ) ) {
	echo "Autoloader not found. Run: cd plugins/prc-apple-news && composer install --dev\n";
	exit( 1 );
}
require_once $autoloader;

// Load the ANF schema directory constant needed by ANF_Validator.
if ( ! defined( 'PRC_APPLE_NEWS_ANF_SCHEMA_DIR' ) ) {
	define( 'PRC_APPLE_NEWS_ANF_SCHEMA_DIR', dirname( __DIR__ ) . '/includes/anf/schema' );
}

// Directly require the classes under test (avoids loading the full plugin bootstrap).
require_once dirname( __DIR__ ) . '/includes/anf/class-anf-block-registry.php';
require_once dirname( __DIR__ ) . '/includes/anf/class-anf-block-resolver.php';
require_once dirname( __DIR__ ) . '/includes/anf/class-anf-block-converter.php';
require_once dirname( __DIR__ ) . '/includes/anf/class-anf-block-integration.php';
require_once dirname( __DIR__ ) . '/includes/anf/class-anf-post-processor.php';
require_once dirname( __DIR__ ) . '/includes/anf/class-anf-theme-definitions.php';
require_once dirname( __DIR__ ) . '/includes/anf/class-anf-referential-validator.php';
require_once dirname( __DIR__ ) . '/includes/anf/class-anf-validator.php';
