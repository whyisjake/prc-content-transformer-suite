<?php
/**
 * Editor Assets class.
 *
 * Enqueues the Apple News sidebar panel in the block editor.
 *
 * @package PRC\Platform\Apple_News
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News;

/**
 * Enqueues block editor sidebar assets for supported post types.
 */
class Editor_Assets {

	const SCRIPT_HANDLE = 'prc-apple-news-sidebar';

	/**
	 * @param Loader $loader Hook registration loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_sidebar_assets' );
	}

	/**
	 * Enqueue the sidebar script for supported post types.
	 *
	 * @hook enqueue_block_editor_assets
	 */
	public function enqueue_sidebar_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		if ( ! in_array( $screen->post_type, array( 'post', 'short-read' ), true ) ) {
			return;
		}

		$asset_file = PRC_APPLE_NEWS_DIR . '/build/sidebar/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		$registered = wp_register_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/sidebar/index.js', PRC_APPLE_NEWS_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);

		if ( ! $registered ) {
			return;
		}

		if ( file_exists( PRC_APPLE_NEWS_DIR . '/build/sidebar/index.css' ) ) {
			wp_register_style(
				self::SCRIPT_HANDLE,
				plugins_url( 'build/sidebar/index.css', PRC_APPLE_NEWS_FILE ),
				array(),
				$asset['version']
			);
		}

		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_style( self::SCRIPT_HANDLE );
	}
}
