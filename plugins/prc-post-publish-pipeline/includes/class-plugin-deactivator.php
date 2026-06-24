<?php
/**
 * Fired during plugin deactivation.
 *
 * @package    PRC\Platform\Post_Publish_Pipeline
 */

namespace PRC\Platform\Post_Publish_Pipeline;

/**
 * The plugin deactivator class.
 *
 * @package    PRC\Platform\Post_Publish_Pipeline
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
			'PRC Post Publish Pipeline Deactivated',
			'The PRC Post Publish Pipeline plugin has been deactivated on ' . get_site_url()
		);
	}
}
