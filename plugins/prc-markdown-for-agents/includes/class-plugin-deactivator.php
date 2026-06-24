<?php

/**
 * Fired during plugin deactivation.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

namespace PRC\Platform\Markdown_For_Agents;

/**
 * The plugin deactivator class.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */
class Plugin_Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
