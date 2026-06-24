<?php
declare(strict_types=1);

/**
 * PRC Icon Library (Font Awesome)
 *
 * @package           PRC_Icon_Library
 * @author            Seth Rubenstein
 * @copyright         2025 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC Icon Library (Font Awesome)
 * Plugin URI:        https://github.com/pewresearch/prc-icon-library
 * Description:       Provides Font Awesome icon library assets for PRC Platform's Icon Loader.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Seth Rubenstein
 * Author URI:        https://pewresearch.org
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-icon-library
 * Requires Plugins:  prc-scripts
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The icon library url. Ends with trailing slash. Simply append the library name to the end of the url.
 * Get specific icon by icon name: {library}.svg#{icon_name}.
 */
define( 'PRC_PLATFORM_ICONS_URL', plugin_dir_url( __FILE__ ) . 'build/icons/sprites/' );
define( 'PRC_PLATFORM_ICONS_PATH', plugin_dir_path( __FILE__ ) . '/build/icons/sprites/' );

/**
 * Disallow path for icon sprite assets in robots.txt.
 */
const PRC_ICON_LIBRARY_ROBOTS_DISALLOW = 'Disallow: /wp-content/plugins/prc-icon-library/';

/**
 * Inject icon-library disallow into the Googlebot group when present.
 *
 * Googlebot does not inherit rules from User-agent: * when a dedicated
 * Googlebot block exists (see prc-elasticpress).
 *
 * @param string $output The robots.txt output.
 * @return string
 */
function prc_icon_library_robots_txt_for_googlebot( string $output ): string {
	if ( preg_match(
		'/^User-agent:\s*Googlebot\s*\r?\n(?:[^\r\n]+\r?\n)*?' . preg_quote( PRC_ICON_LIBRARY_ROBOTS_DISALLOW, '/' ) . '/mi',
		$output
	) ) {
		return $output;
	}

	if ( preg_match( '/^User-agent:\s*Googlebot\s*(?:\r?\n|$)/mi', $output ) ) {
		$updated = preg_replace(
			'/^(User-agent:\s*Googlebot)\s*(?:\r?\n|$)/mi',
			'$1' . "\n" . PRC_ICON_LIBRARY_ROBOTS_DISALLOW . "\n",
			$output,
			1
		);

		return is_string( $updated ) ? $updated : $output;
	}

	return $output;
}

add_filter(
	'robots_txt',
	static function ( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}

		return $output . "\n" . PRC_ICON_LIBRARY_ROBOTS_DISALLOW . "\n";
	},
	10,
	2
);

add_filter(
	'robots_txt',
	static function ( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}

		return prc_icon_library_robots_txt_for_googlebot( $output );
	},
	11,
	2
);
