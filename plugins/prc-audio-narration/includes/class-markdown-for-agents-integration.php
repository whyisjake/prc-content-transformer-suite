<?php
/**
 * Integration with prc-markdown-for-agents.
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

use PRC\Platform\Markdown_For_Agents\LLMs_Txt;

/**
 * Exposes narration audio through the agent-facing discovery surfaces.
 *
 * Stale narration is omitted from both surfaces rather than advertised. A
 * crawler has no way to know the audio no longer matches the article it sits
 * beside, so publishing it would be worse than publishing nothing.
 */
class Markdown_For_Agents_Integration {

	/**
	 * Maximum narrated articles listed in llms.txt when the host plugin
	 * does not supply its own cap.
	 */
	const FALLBACK_SECTION_CAP = 25;

	/**
	 * The loader instance.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Narration storage.
	 *
	 * @var Narration_Store
	 */
	protected $store;

	/**
	 * Constructor.
	 *
	 * @param Loader               $loader The hook loader.
	 * @param Narration_Store|null $store  Narration storage.
	 */
	public function __construct( Loader $loader, ?Narration_Store $store = null ) {
		$this->loader = $loader;
		$this->store  = $store ?? new Narration_Store();

		$this->loader->add_filter( 'prc_markdown_for_agents_frontmatter', $this, 'enrich_frontmatter', 10, 2 );
		$this->loader->add_filter( 'prc_markdown_for_agents_llms_txt_sections', $this, 'register_section' );
	}

	/**
	 * Add the narration audio URL to a post's markdown frontmatter.
	 *
	 * @param array    $data Frontmatter data.
	 * @param \WP_Post $post The post being converted.
	 * @return array
	 */
	public function enrich_frontmatter( $data, $post ) {
		if ( ! $post instanceof \WP_Post || ! is_array( $data ) ) {
			return $data;
		}

		$record = $this->store->get( $post->ID );

		if ( ! $this->store->should_publish( $post->ID, $record ) ) {
			return $data;
		}

		$data['audio_url'] = $record['url'];

		if ( null !== $record['duration'] ) {
			$data['audio_duration'] = (int) round( $record['duration'] );
		}

		if ( '' !== $record['provider'] ) {
			$data['audio_provider'] = $record['provider'];
		}

		return $data;
	}

	/**
	 * Contribute the audio narration section to llms.txt.
	 *
	 * @param array $sections Existing sections.
	 * @return array
	 */
	public function register_section( $sections ) {
		if ( ! is_array( $sections ) ) {
			return $sections;
		}

		$links = $this->build_links();

		if ( empty( $links ) ) {
			return $sections;
		}

		$sections[] = array(
			'slug'        => 'audio-narration',
			'title'       => __( 'Audio narration', 'prc-audio-narration' ),
			'description' => __( 'Articles available as narrated audio.', 'prc-audio-narration' ),
			'links'       => $links,
		);

		return $sections;
	}

	/**
	 * Build the link list for the llms.txt section.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function build_links(): array {
		$cap = class_exists( LLMs_Txt::class ) && defined( LLMs_Txt::class . '::SECTION_CAP' )
			? LLMs_Txt::SECTION_CAP
			: self::FALLBACK_SECTION_CAP;

		// Over-fetch so stale entries can be dropped without leaving the
		// section short of its cap.
		$post_ids = $this->store->get_narrated_post_ids(
			array( 'posts_per_page' => $cap * 2 )
		);

		$links = array();

		foreach ( $post_ids as $post_id ) {
			if ( count( $links ) >= $cap ) {
				break;
			}

			$record = $this->store->get( $post_id );

			if ( ! $this->store->should_publish( $post_id, $record ) ) {
				continue;
			}

			$links[] = array(
				'title'       => get_the_title( $post_id ),
				'url'         => $record['url'],
				'description' => $this->describe( $post_id, $record ),
			);
		}

		return $links;
	}

	/**
	 * Describe a narrated article for the llms.txt entry.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $record  The narration record.
	 * @return string
	 */
	private function describe( int $post_id, array $record ): string {
		$parts = array( get_the_date( 'Y-m-d', $post_id ) );

		if ( null !== $record['duration'] ) {
			$minutes = max( 1, (int) round( $record['duration'] / 60 ) );
			$parts[] = sprintf(
				/* translators: %d: duration in minutes */
				_n( '%d minute', '%d minutes', $minutes, 'prc-audio-narration' ),
				$minutes
			);
		}

		return implode( ' — ', array_filter( $parts ) );
	}
}
