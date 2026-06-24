<?php

/**
 * YAML frontmatter generation for markdown output.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

namespace PRC\Platform\Markdown_For_Agents;

/**
 * Generates YAML frontmatter with post metadata.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */
class Frontmatter {

	/**
	 * Build YAML frontmatter for a post.
	 *
	 * @param int|WP_Post $post   Post ID or post object.
	 * @param string      $markdown Optional. Markdown body for description fallback.
	 * @return string YAML frontmatter (including --- delimiters).
	 */
	public function build( $post, $markdown = '' ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return "---\n---\n\n";
		}

		$data = array(
			'title'       => $this->get_title( $post ),
			'description' => $this->get_description( $post, $markdown ),
			'date'        => get_the_date( 'Y-m-d', $post ),
			'authors'     => $this->get_authors( $post ),
			'url'          => get_permalink( $post ),
			'categories'   => $this->get_categories( $post ),
			'tags'         => $this->get_tags( $post ),
		);

		$data = apply_filters( 'prc_markdown_for_agents_frontmatter', $data, $post );

		// Remove empty values.
		$data = array_filter( $data, fn( $v ) => $v !== '' && $v !== array() && $v !== null );

		$yaml = $this->to_yaml( $data );
		return "---\n" . $yaml . "---\n\n";
	}

	/**
	 * Get post title.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	protected function get_title( $post ) {
		return get_the_title( $post );
	}

	/**
	 * Get description (excerpt or first paragraph of markdown).
	 *
	 * @param WP_Post $post    Post object.
	 * @param string  $markdown Markdown body.
	 * @return string
	 */
	protected function get_description( $post, $markdown = '' ) {
		$excerpt = get_the_excerpt( $post );
		if ( $excerpt ) {
			return $excerpt;
		}
		if ( $markdown ) {
			$first = preg_split( "/\n\n+/", trim( $markdown ), 2 );
			if ( ! empty( $first[0] ) ) {
				return wp_trim_words( $first[0], 40 );
			}
		}
		return '';
	}

	/**
	 * Get authors for the post.
	 *
	 * @param WP_Post $post Post object.
	 * @return array List of author entries.
	 */
	protected function get_authors( $post ) {
		$authors = array();

		// Filters are expected to return arrays of entries with at minimum a `name`
		// key, and optionally `job_title` and `link` (e.g. from prc-staff-bylines).
		$bylines = apply_filters( 'prc_markdown_for_agents_authors', array(), $post );
		if ( ! empty( $bylines ) ) {
			foreach ( $bylines as $byline ) {
				if ( is_string( $byline ) ) {
					$authors[] = array( 'name' => $byline );
					continue;
				}

				$entry = array( 'name' => $byline['name'] ?? '' );

				foreach ( array( 'job_title', 'link' ) as $field ) {
					if ( ! empty( $byline[ $field ] ) ) {
						$entry[ $field ] = $byline[ $field ];
					}
				}

				$authors[] = $entry;
			}
		}

		if ( empty( $authors ) ) {
			$author = get_user_by( 'id', $post->post_author );
			$name  = $author ? $author->display_name : '';
			if ( $name ) {
				$authors[] = array( 'name' => $name );
			} else {
				$authors[] = array( 'name' => 'Pew Research Center' );
			}
		}

		return $authors;
	}

	/**
	 * Get categories for the post.
	 *
	 * @param WP_Post $post Post object.
	 * @return array Category names.
	 */
	protected function get_categories( $post ) {
		$terms = get_the_category( $post->ID );
		return array_map( fn( $t ) => html_entity_decode( $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $terms ?: array() );
	}

	/**
	 * Get tags for the post.
	 *
	 * @param WP_Post $post Post object.
	 * @return array Tag names.
	 */
	protected function get_tags( $post ) {
		$terms = get_the_tags( $post->ID );
		return array_map( fn( $t ) => html_entity_decode( $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $terms ?: array() );
	}

	/**
	 * Convert associative array to simple YAML (subset we need).
	 *
	 * @param array $data Associative array.
	 * @return string YAML string.
	 */
	protected function to_yaml( $data ) {
		$lines = array();

		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) && ! empty( $value ) && isset( $value[0] ) && is_array( $value[0] ) ) {
				// List of objects (e.g. authors). Use block-style mappings so each
				// key appears on its own indented line — flow style without braces
				// is not valid YAML and will be misparsed by standard YAML parsers.
				$lines[] = $key . ':';
				foreach ( $value as $item ) {
					$first = true;
					foreach ( $item as $k => $v ) {
						$val_str = '"' . $this->escape_yaml( (string) $v ) . '"';
						if ( $first ) {
							$lines[] = '  - ' . $k . ': ' . $val_str;
							$first   = false;
						} else {
							$lines[] = '    ' . $k . ': ' . $val_str;
						}
					}
				}
			} elseif ( is_array( $value ) ) {
				// List of scalars (categories, tags).
				$lines[] = $key . ':';
				foreach ( $value as $v ) {
					$lines[] = '  - ' . ( is_numeric( $v ) ? $v : '"' . $this->escape_yaml( (string) $v ) . '"' );
				}
			} else {
				$lines[] = $key . ': "' . $this->escape_yaml( (string) $value ) . '"';
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Escape a string for YAML double-quoted value.
	 *
	 * @param string $str Input string.
	 * @return string Escaped string.
	 */
	protected function escape_yaml( $str ) {
		$str = str_replace( array( "\r\n", "\r", "\n" ), '\n', $str );
		return addcslashes( $str, '"\\' );
	}
}
