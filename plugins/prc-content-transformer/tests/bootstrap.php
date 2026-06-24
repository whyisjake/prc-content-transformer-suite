<?php
/**
 * PHPUnit bootstrap for PRC Content Transformer provider tests.
 *
 * Loads WP function stubs and the classes under test directly,
 * with no WordPress installation or Docker/wp-env required.
 *
 * @package PRC\Platform\Content_Transformer\Providers\Tests
 */

declare(strict_types=1);

// Satisfy any ABSPATH guards.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/' );
}
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}

// Define the plugin dir constant used by Apple_News_Provider::get_format_spec().
if ( ! defined( 'PRC_CONTENT_TRANSFORMER_DIR' ) ) {
	define( 'PRC_CONTENT_TRANSFORMER_DIR', dirname( __DIR__ ) );
}

// Load WP stubs before any plugin code.
require_once __DIR__ . '/php/wp-stubs.php';

// Load the Composer autoloader (installed via `composer install --dev`).
$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoloader ) ) {
	echo "Autoloader not found. Run: cd plugins/prc-content-transformer && composer install --dev\n";
	exit( 1 );
}
require_once $autoloader;

// Load the Provider interface and Apple_News_Provider class directly.
require_once dirname( __DIR__ ) . '/includes/providers/interface-provider.php';
require_once dirname( __DIR__ ) . '/includes/providers/class-apple-news-provider.php';
require_once dirname( __DIR__ ) . '/includes/pipeline/class-prompt-builder.php';
