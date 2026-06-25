<?php
/**
 * Plugin activation handler
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

/**
 * Plugin Activator class
 */
class Plugin_Activator {
	/**
	 * Activate the plugin
	 *
	 * @return void
	 */
	public static function activate() {
		// Trigger post type registration
		require_once plugin_dir_path( __FILE__ ) . 'class-content-type.php';
		$content_type = new Content_Type();
		$content_type->register_post_type();

		// Register custom rewrite rules
		require_once plugin_dir_path( __FILE__ ) . 'class-rewrite-rules.php';
		Rewrite_Rules::flush_rules();
	}
}
