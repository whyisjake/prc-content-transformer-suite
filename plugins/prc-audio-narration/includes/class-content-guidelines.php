<?php
/**
 * Content guidelines integration.
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

/**
 * Feeds site content guidelines into the narration script prompt.
 *
 * Gutenberg's experimental knowledge feature stores editorial guidelines as
 * `wp_knowledge` posts, one per registered scope, editable at
 * Settings > Guidelines. Registering a scope here gives that screen an
 * "Audio narration" section, so narration style is edited where every other
 * editorial standard is edited rather than in a plugin-specific field.
 */
class Content_Guidelines {

	/**
	 * Our scope slug. The backing post is `guideline-{scope}`.
	 */
	const SCOPE = 'audio-narration';

	/**
	 * Post type used by the knowledge feature.
	 */
	const POST_TYPE = 'wp_knowledge';

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

		$this->loader->add_filter( 'wp_guideline_scopes', $this, 'register_scope' );
	}

	/**
	 * Whether the knowledge feature is available on this site.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_guideline_scopes' ) && post_type_exists( self::POST_TYPE );
	}

	/**
	 * Add an audio narration section to the Guidelines screen.
	 *
	 * @param array $scopes Registered scopes.
	 * @return array
	 */
	public function register_scope( $scopes ) {
		if ( ! is_array( $scopes ) ) {
			return $scopes;
		}

		$scopes[ self::SCOPE ] = array(
			'title'       => __( 'Audio narration', 'prc-audio-narration' ),
			'description' => __( 'Guidance for rewriting articles to be read aloud: pronunciation, how to introduce the publication, how much detail to speak from charts and tables.', 'prc-audio-narration' ),
			'order'       => 60,
		);

		return $scopes;
	}

	/**
	 * Scopes whose guidelines apply to narration, in order of specificity.
	 *
	 * Site and copy come from the shared editorial standards; the narration
	 * scope is ours. General first, specific last, so the most targeted
	 * guidance is read closest to the task.
	 *
	 * @return string[]
	 */
	public static function applicable_scopes(): array {
		/**
		 * Filter which guideline scopes are fed into the narration prompt.
		 *
		 * @param string[] $scopes Scope slugs, general to specific.
		 */
		return (array) apply_filters(
			'prc_audio_narration_guideline_scopes',
			array( 'site', 'copy', self::SCOPE )
		);
	}

	/**
	 * Get the guideline text for a single scope.
	 *
	 * @param string $scope Scope slug.
	 * @return string Empty string when the scope has no guideline.
	 */
	public static function get( string $scope ): string {
		if ( ! self::is_available() ) {
			return '';
		}

		$post = get_page_by_path( 'guideline-' . $scope, OBJECT, self::POST_TYPE );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return '';
		}

		// Guideline rows are plain prose, but strip any markup defensively --
		// this text is going into a prompt, not into a page.
		return trim( wp_strip_all_tags( $post->post_content ) );
	}

	/**
	 * Build the guidelines block appended to the narration system instruction.
	 *
	 * Framed as subordinate to the format rules on purpose. Guidelines are
	 * editorial preferences; they must not be able to license summarizing a
	 * report or altering a statistic, which the rules above forbid.
	 *
	 * @return string Empty string when no applicable guidelines exist.
	 */
	public static function prompt_section(): string {
		if ( ! self::is_available() ) {
			return '';
		}

		$sections = array();
		$scopes   = self::applicable_scopes();
		$titles   = function_exists( 'wp_guideline_scopes' ) ? wp_guideline_scopes() : array();

		foreach ( $scopes as $scope ) {
			$content = self::get( (string) $scope );

			if ( '' === $content ) {
				continue;
			}

			$title      = $titles[ $scope ]['title'] ?? ucfirst( (string) $scope );
			$sections[] = sprintf( "%s:\n%s", $title, $content );
		}

		if ( empty( $sections ) ) {
			return '';
		}

		return "\n\nSITE CONTENT GUIDELINES:\n\n"
			. "Apply the editorial guidance below wherever it does not conflict with the rules above. "
			. "It governs tone, phrasing, and emphasis. It does not license summarizing the article, "
			. "omitting findings, or altering any statistic.\n\n"
			. implode( "\n\n", $sections );
	}
}
