<?php
/**
 * Plugin activation handler
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

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
		// The podcast feed is registered on init, which has not run yet, so
		// rewrite rules cannot be flushed here. Flag it instead and let the
		// feed flush once on the first request after activation.
		require_once plugin_dir_path( __FILE__ ) . 'class-podcast-feed.php';
		update_option( Podcast_Feed::FLUSH_FLAG, 1 );

		do_action( 'prc_audio_narration_activated' );
	}
}
