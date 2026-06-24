<?php
/**
 * Fired during plugin deactivation.
 *
 * @package PRC\Platform\Content_Transformer
 */

namespace PRC\Platform\Content_Transformer;

/**
 * The plugin deactivator class.
 *
 * @package PRC\Platform\Content_Transformer
 */
class Plugin_Deactivator {

	/**
	 * Deactivate the plugin.
	 */
	public static function deactivate() {
		flush_rewrite_rules();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PRC Content Transformer Deactivated',
			'The PRC Content Transformer plugin has been deactivated on ' . get_site_url()
		);
	}
}
