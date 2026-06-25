<?php
/**
 * WordPress Test Suite Configuration for VIP Environment
 *
 * This file configures the WordPress test suite to use the wordpress_test database
 * in the VIP dev-env container.
 *
 * @package PRC_PDF_Extraction
 */

// Test database credentials (from VIP dev-env)
define( 'DB_NAME', 'wordpress_test' );
define( 'DB_USER', 'wordpress' );
define( 'DB_PASSWORD', 'wordpress' );
define( 'DB_HOST', 'database' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

// Test table prefix
$table_prefix = 'wptests_';

// Use the WordPress installation from VIP container
define( 'ABSPATH', '/wp/' );

// WordPress debug mode
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );

// Multisite configuration (optional)
define( 'WP_TESTS_MULTISITE', false );

// Domain and email for tests
define( 'WP_TESTS_DOMAIN', 'prc-platform.vipdev.lndo.site' );
define( 'WP_TESTS_EMAIL', 'admin@prc-platform.vipdev.lndo.site' );
define( 'WP_TESTS_TITLE', 'PRC PDF Extraction Tests' );

// PHP binary path
define( 'WP_PHP_BINARY', 'php' );

// Skip some tests that don't apply in VIP environment
define( 'WP_RUN_CORE_TESTS', false );

// PRC Platform constants (required by mu-plugins)
if ( ! defined( 'PRC\Platform\PRC_PRIMARY_SITE_ID' ) ) {
	define( 'PRC\Platform\PRC_PRIMARY_SITE_ID', 20 );
}
