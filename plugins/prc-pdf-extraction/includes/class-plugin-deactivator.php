<?php
/**
 * Plugin deactivation handler
 *
 * @package PRC_PDF_Extraction
 */

namespace PRC\Platform\PDF_Extraction;

/**
 * Plugin Deactivator class
 */
class Plugin_Deactivator {
	/**
	 * Deactivate the plugin
	 *
	 * @return void
	 */
	public static function deactivate() {
		// Flush rewrite rules to remove custom endpoints
		flush_rewrite_rules();
	}
}
