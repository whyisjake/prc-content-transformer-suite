<?php
/**
 * Fired during plugin deactivation.
 *
 * @package    PRC\Platform\Scripts_Plugin
 */

namespace PRC\Platform\Scripts_Plugin;

/**
 * The plugin deactivator class.
 *
 * @package    PRC\Platform\Scripts_Plugin
 */
class Plugin_Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
		flush_rewrite_rules();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PRC Scripts Deactivated',
			'The PRC Scripts plugin has been deactivated on ' . get_site_url()
		);
	}
}
