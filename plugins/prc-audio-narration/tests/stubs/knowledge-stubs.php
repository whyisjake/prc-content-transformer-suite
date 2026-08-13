<?php
/**
 * Minimal stand-ins for Gutenberg's experimental knowledge feature.
 *
 * The Guidelines screen ships in the Gutenberg plugin, which is not part of
 * this plugin's test environment. These stubs supply the two things the
 * integration actually depends on -- the `wp_guideline_scopes` registry and
 * the `wp_knowledge` post type -- so the guideline tests exercise real
 * behaviour instead of being skipped. Both no-op when Gutenberg is present,
 * so the same tests run against the genuine feature where it exists.
 *
 * @package PRC_Audio_Narration
 */

if ( ! function_exists( 'wp_guideline_scopes' ) ) {
	/**
	 * Registered guideline scopes, keyed by slug.
	 *
	 * @return array
	 */
	function wp_guideline_scopes(): array {
		return apply_filters(
			'wp_guideline_scopes',
			array(
				'site'       => array(
					'title' => 'Site',
					'order' => 10,
				),
				'copy'       => array(
					'title' => 'Copy',
					'order' => 20,
				),
				'images'     => array(
					'title' => 'Images',
					'order' => 30,
				),
				'additional' => array(
					'title' => 'Additional',
					'order' => 50,
				),
			)
		);
	}
}

if ( ! function_exists( 'prc_audio_narration_register_knowledge_stub_post_type' ) ) {
	/**
	 * Register the knowledge post type when Gutenberg has not.
	 *
	 * @return void
	 */
	function prc_audio_narration_register_knowledge_stub_post_type() {
		if ( post_type_exists( 'wp_knowledge' ) ) {
			return;
		}

		register_post_type(
			'wp_knowledge',
			array(
				'label'           => 'Guidelines',
				'public'          => false,
				'show_ui'         => false,
				'supports'        => array( 'title', 'editor' ),
				'capability_type' => 'post',
			)
		);
	}

	add_action( 'init', 'prc_audio_narration_register_knowledge_stub_post_type', 1 );
}
