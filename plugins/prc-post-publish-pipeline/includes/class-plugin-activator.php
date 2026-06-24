<?php
/**
 * Fired during plugin activation.
 *
 * @package    PRC\Platform\Post_Publish_Pipeline
 */

namespace PRC\Platform\Post_Publish_Pipeline;

/**
 * The plugin activator class.
 *
 * @package    PRC\Platform\Post_Publish_Pipeline
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
			'PRC Post Publish Pipeline Activated',
			'The PRC Post Publish Pipeline plugin has been activated on ' . get_site_url()
		);
	}
}
