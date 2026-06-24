<?php
/**
 * Fired during plugin activation.
 *
 * @package    PRC\Platform\Scripts_Plugin
 */

namespace PRC\Platform\Scripts_Plugin;

/**
 * The plugin activator class.
 *
 * @package    PRC\Platform\Scripts_Plugin
 */
class Plugin_Activator {

	/**
	 * Activate the plugin.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		flush_rewrite_rules();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PRC Scripts Activated',
			'The PRC Scripts plugin has been activated on ' . get_site_url()
		);
	}
}
