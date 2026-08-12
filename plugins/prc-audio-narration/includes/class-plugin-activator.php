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
		// Narration is stored against existing posts and needs no custom post
		// type or rewrite rules at activation. The podcast feed registers its
		// own rewrite rules and flushes them when that module lands.
		do_action( 'prc_audio_narration_activated' );
	}
}
