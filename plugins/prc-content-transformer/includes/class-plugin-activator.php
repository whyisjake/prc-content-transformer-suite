<?php
/**
 * Fired during plugin activation.
 *
 * @package PRC\Platform\Content_Transformer
 */

namespace PRC\Platform\Content_Transformer;

/**
 * The plugin activator class.
 *
 * @package PRC\Platform\Content_Transformer
 */
class Plugin_Activator {

	/**
	 * Activate the plugin.
	 */
	public static function activate() {
		flush_rewrite_rules();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PRC Content Transformer Activated',
			'The PRC Content Transformer plugin has been activated on ' . get_site_url()
		);
	}
}
