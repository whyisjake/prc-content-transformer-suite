<?php
/**
 * Editor sidebar panel.
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

/**
 * Registers the block editor document setting panel.
 *
 * Replaces the classic meta box. A document setting panel lives with the rest
 * of the post's settings and, unlike a meta box, exists in every block-based
 * editing context rather than only the classic post screen.
 */
class Editor_Panel {

	/**
	 * Script handle.
	 */
	const HANDLE = 'prc-audio-narration-editor';

	/**
	 * The loader instance.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The hook loader.
	 */
	public function __construct( Loader $loader ) {
		$this->loader = $loader;

		$this->loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue' );
	}

	/**
	 * Post types offered narration.
	 *
	 * @return string[]
	 */
	public static function supported_post_types(): array {
		/**
		 * Filter the post types offered narration.
		 *
		 * @param string[] $post_types Supported post types.
		 */
		return (array) apply_filters( 'prc_audio_narration_post_types', array( 'post' ) );
	}

	/**
	 * Enqueue the panel on supported editing screens.
	 *
	 * @return void
	 */
	public function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && ! in_array( $screen->post_type, self::supported_post_types(), true ) ) {
			return;
		}

		$asset_path = PRC_AUDIO_NARRATION_DIR . '/build/editor.asset.php';

		if ( ! file_exists( $asset_path ) ) {
			// The plugin ships built assets; a missing build means the panel
			// simply does not appear rather than throwing in the editor.
			return;
		}

		$asset = require $asset_path;

		wp_enqueue_script(
			self::HANDLE,
			PRC_AUDIO_NARRATION_URL . 'build/editor.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_set_script_translations( self::HANDLE, 'prc-audio-narration' );
	}
}
