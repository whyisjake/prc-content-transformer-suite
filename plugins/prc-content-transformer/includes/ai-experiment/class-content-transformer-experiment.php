<?php
/**
 * Content Transformer AI Experiment.
 *
 * @package PRC\Platform\Content_Transformer\AI_Experiment
 */

namespace PRC\Platform\Content_Transformer\AI_Experiment;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the Content Transformer experiment with the WordPress AI
 * Experiments plugin. When enabled, this experiment registers the
 * prc-content-transformer/transform ability.
 */
class Content_Transformer_Experiment extends Abstract_Feature {

	/**
	 * Get the feature identifier.
	 *
	 * @return string
	 */
	public static function get_id(): string {
		return 'content-transformer';
	}

	/**
	 * Load feature metadata.
	 *
	 * @return array{label: string, description: string, category: string}
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Content Transformer', 'prc-content-transformer' ),
			'description' => __( 'Uses AI to transform WordPress post content into provider-specific formats such as Apple News JSON, email HTML, and plain text. Enables the transformation REST API and WP-CLI commands.', 'prc-content-transformer' ),
			'category'    => Experiment_Category::ADMIN,
		);
	}

	/**
	 * Registers the experiment's hooks and functionality.
	 *
	 * Only called when the experiment is enabled.
	 */
	public function register(): void {
		$ability = new Content_Transformer_Ability();
		add_action( 'wp_abilities_api_init', array( $ability, 'register_ability' ) );
	}
}
