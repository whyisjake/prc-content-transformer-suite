<?php
/**
 * Plugin deactivation handler
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

/**
 * Plugin Deactivator class
 */
class Plugin_Deactivator {
	/**
	 * Deactivate the plugin
	 *
	 * Cancels any queued narration jobs so a deactivated plugin cannot leave
	 * billable work sitting in the scheduler. Generated audio attachments are
	 * deliberately left in place -- deactivation is not deletion.
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), 'prc-audio-narration' );
		}

		do_action( 'prc_audio_narration_deactivated' );
	}
}
