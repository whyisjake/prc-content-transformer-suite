<?php
/**
 * Script Resolver
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

use PRC\Platform\Audio_Narration\Providers\Audio_Script_Provider;
use PRC\Platform\Content_Transformer\Pipeline\Transformation_Pipeline;

/**
 * Obtains a narration script by running the content transformer pipeline.
 *
 * Isolated behind its own class so the service can be exercised with a stub
 * resolver, and so the hard dependency on another plugin lives in one place.
 */
class Script_Resolver {

	/**
	 * Resolve a post's narration script.
	 *
	 * @param int  $post_id The post ID.
	 * @param bool $force   Whether to bypass the transformer's cache.
	 * @return string|\WP_Error
	 */
	public static function resolve( int $post_id, bool $force = false ) {
		if ( ! class_exists( Transformation_Pipeline::class ) ) {
			return new \WP_Error(
				'prc_audio_narration_transformer_missing',
				'The prc-content-transformer plugin is required to generate narration scripts.'
			);
		}

		$result = Transformation_Pipeline::transform( $post_id, Audio_Script_Provider::SLUG, $force );

		if ( ! $result->is_success() ) {
			return new \WP_Error(
				'prc_audio_narration_script_failed',
				sprintf(
					'Could not generate a narration script: %s',
					$result->get_error() ? $result->get_error() : 'unknown error'
				)
			);
		}

		return (string) $result->get_output();
	}
}
