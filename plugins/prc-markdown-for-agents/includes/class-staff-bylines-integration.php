<?php

/**
 * Integration with prc-staff-bylines plugin.
 *
 * Hooks into the prc_markdown_for_agents_authors filter to supply author data
 * from the Staff Bylines system instead of the default WP post author fallback.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

namespace PRC\Platform\Markdown_For_Agents;

/**
 * Connects prc-markdown-for-agents with prc-staff-bylines.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */
class Staff_Bylines_Integration {

	/**
	 * The loader instance.
	 *
	 * @var Loader
	 */
	protected $loader;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader The loader instance.
	 */
	public function __construct( Loader $loader ) {
		$this->loader = $loader;
		$this->loader->add_filter( 'prc_markdown_for_agents_authors', $this, 'get_authors_from_bylines', 10, 2 );
	}

	/**
	 * Populate authors from prc-staff-bylines when available.
	 *
	 * Returns an array of author entries with `name`, and optionally `job_title`
	 * and `link` when the Staff Bylines plugin is active and the post has bylines
	 * assigned. All assigned bylines are included (active staff, former staff, and
	 * guests). Falls back to the existing value (typically an empty array) so the
	 * Frontmatter class can apply its own WP-user fallback.
	 *
	 * @param array    $authors Existing authors array (empty by default).
	 * @param \WP_Post $post    The post being converted.
	 * @return array
	 */
	public function get_authors_from_bylines( $authors, $post ) {
		if ( ! class_exists( '\PRC\Platform\Staff_Bylines\Bylines' ) ) {
			return $authors;
		}

		$bylines_obj = new \PRC\Platform\Staff_Bylines\Bylines( $post->ID );
		$staff_data  = $bylines_obj->format( 'array' );

		if ( is_wp_error( $staff_data ) || empty( $staff_data ) ) {
			return $authors;
		}

		$result = array();
		foreach ( $staff_data as $staff ) {
			$name = isset( $staff['name'] ) ? $staff['name'] : '';
			if ( empty( $name ) ) {
				continue;
			}

			$entry = array( 'name' => $name );

			if ( ! empty( $staff['job_title'] ) && false !== $staff['job_title'] ) {
				$entry['job_title'] = $staff['job_title'];
			}

			if ( ! empty( $staff['link'] ) && false !== $staff['link'] ) {
				$entry['link'] = $staff['link'];
			}

			$result[] = $entry;
		}

		return ! empty( $result ) ? $result : $authors;
	}
}
