<?php

/**
 * Fired during plugin activation.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

namespace PRC\Platform\Markdown_For_Agents;

/**
 * The plugin activator class.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */
class Plugin_Activator {

	/**
	 * Activate the plugin.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		flush_rewrite_rules();
	}
}
