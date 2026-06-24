<?php
/**
 * ANF Post Processor class.
 *
 * Takes AI-generated Apple News Format JSON from prc-content-transformer,
 * applies all PRC-specific layout and component customizations, and returns
 * the enriched JSON string.
 *
 * @package PRC\Platform\Apple_News\ANF
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\ANF;

use PRC\Platform\Staff_Bylines\Bylines;

/**
 * Applies all PRC customizations to AI-generated ANF JSON before push to Apple News.
 *
 * Ported from:
 * plugins/prc-external-channels/includes/apple-news/alley-integration/class-integration.php
 * generate_json() method.
 */
class ANF_Post_Processor {

	/**
	 * Process a raw ANF JSON string and apply all PRC customizations.
	 *
	 * Returns the input unchanged (fail-open) if the JSON cannot be decoded.
	 *
	 * @param string $anf_json  Raw ANF JSON string from prc-content-transformer.
	 * @param int    $post_id   The post ID being published.
	 * @return string Enriched ANF JSON string.
	 */
	public function process( string $anf_json, int $post_id ): string {
		$json = json_decode( $anf_json, true );

		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $json ) ) {
			// fail-open: return input unchanged
			error_log( "prc-apple-news: ANF_Post_Processor — invalid JSON for post {$post_id}" ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return $anf_json;
		}

		// Ensure required top-level keys are initialised so array assignments below
		// never silently create a non-array where an array is expected.
		$json['componentLayouts']    = $json['componentLayouts']    ?? array();
		$json['componentStyles']     = $json['componentStyles']     ?? array();
		$json['componentTextStyles'] = $json['componentTextStyles'] ?? array();
		$json['textStyles']          = $json['textStyles']          ?? array();
		$json['metadata']            = $json['metadata']            ?? array();
		$json['components']          = $json['components']          ?? array();

		// ------------------------------------------------------------------
		// 0. Canonical identifier — override the AI placeholder with the slug.
		//    ANF requires: ^[a-zA-Z0-9_-]{1,64}$ so we must stay ≤ 64 chars.
		//    post-{id} is always safe; slug-based only when it fits.
		// ------------------------------------------------------------------
		$post_slug          = get_post_field( 'post_name', $post_id );
		$slug_candidate     = $post_slug ? 'post-' . $post_slug : '';
		$json['identifier'] = ( $slug_candidate && strlen( $slug_candidate ) <= 64 )
			? $slug_candidate
			: 'post-' . $post_id;

		// ------------------------------------------------------------------
		// 0b. Title component — inject at index 0 when the AI omits it.
		//     Steps 3 and 4 (intro + byline) assume components[0] is the title.
		// ------------------------------------------------------------------
		$has_title = ! empty( $json['components'] ) && ( $json['components'][0]['role'] ?? '' ) === 'title';
		if ( ! $has_title ) {
			array_unshift(
				$json['components'],
				array(
					'role'      => 'title',
					'text'      => get_the_title( $post_id ),
					'layout'    => 'title-layout',
					'textStyle' => 'default-title',
				)
			);
		}

		// ------------------------------------------------------------------
		// 1. Force layout columns to 15.
		// ------------------------------------------------------------------
		$json['layout']['columns'] = 15;

		// ------------------------------------------------------------------
		// 2. Conditional anchor margin (reused across several layouts).
		// ------------------------------------------------------------------
		$conditional_anchor_margin = array(
			array(
				'margin'     => array(
					'top'    => 12,
					'bottom' => 12,
				),
				'conditions' => array(
					array(
						'minViewportWidth' => 0,
					),
				),
			),
			array(
				'margin'     => array(
					'top'    => 0,
					'bottom' => 0,
				),
				'conditions' => array(
					array(
						'minViewportWidth' => 769,
					),
				),
			),
		);

		// ------------------------------------------------------------------
		// 3. Sub-title / sub-headline intro component.
		//    Read sub_title first (canonical); fall back to sub_headline.
		// ------------------------------------------------------------------
		$sub_title = get_post_meta( $post_id, 'sub_title', true );
		if ( empty( $sub_title ) ) {
			$sub_title = get_post_meta( $post_id, 'sub_headline', true );
		}

		// Track the splice offset so we know where to insert the byline below.
		$byline_insert_offset = 1; // default: right after title (index 0)

		if ( ! empty( $sub_title ) ) {
			$intro = array(
				'role'      => 'intro',
				'text'      => $sub_title,
				'textStyle' => array(
					'fontName'    => 'Georgia-Italic',
					'fontSize'    => 40,
					'tracking'    => 0,
					'lineHeight'  => 50,
					'textColor'   => '#2a2a2a',
					'hyphenation' => false,
					'fontScaling' => false,
					'conditional' => array(
						array(
							'fontSize'   => 24,
							'lineHeight' => 32,
							'conditions' => array(
								array(
									'minViewportWidth' => 0,
								),
							),
						),
						array(
							'fontSize'   => 40,
							'lineHeight' => 50,
							'conditions' => array(
								array(
									'minViewportWidth' => 769,
								),
							),
						),
					),
				),
				'layout'    => array(
					'margin' => array(
						'top'    => 12,
						'bottom' => 12,
					),
				),
			);
			// Splice intro after the title component (index 0 → insert at 1).
			array_splice( $json['components'], 1, 0, array( $intro ) );

			// Byline goes after the intro, so at offset 2.
			$byline_insert_offset = 2;
		}

		// ------------------------------------------------------------------
		// 4. Byline injection.
		//    Order: title → intro (if present) → byline → rest of content.
		//    The AI does NOT generate a byline; we always inject it here.
		// ------------------------------------------------------------------
		$byline_text = '';
		if ( class_exists( 'PRC\\Platform\\Staff_Bylines\\Bylines' ) ) {
			$bylines     = new Bylines( $post_id );
			$byline_text = 'By ' . $bylines->format( 'string' ) . ' | ' . get_the_time( 'F j, Y', $post_id );
		}

		if ( ! empty( $byline_text ) ) {
			$byline_component = array(
				'role'      => 'byline',
				'text'      => $byline_text,
				'layout'    => 'byline-layout',
				'textStyle' => 'default-byline',
			);
			array_splice( $json['components'], $byline_insert_offset, 0, array( $byline_component ) );
		}

		// ------------------------------------------------------------------
		// 5. Child posts "Also in This Report" section (idempotent).
		// ------------------------------------------------------------------
		$child_links = $this->get_child_post_links( $post_id );
		if ( ! empty( $child_links ) && ! $this->component_tree_contains_url( $json['components'], $child_links[0]['url'] ) ) {
			$child_links_html = implode(
				"\n",
				array_map(
					static fn( array $link ) => '<a href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['title'] ) . '</a>',
					$child_links
				)
			);

			$json['components'] = array_merge(
				$json['components'],
				array(
					array(
						'role'      => 'heading2',
						'text'      => 'Also in This Report',
						'textStyle' => array(
							'fontName'      => 'Helvetica-Bold',
							'fontSize'      => 18,
							'tracking'      => 0,
							'lineHeight'    => 20,
							'textColor'     => '#2a2a2a',
							'textAlignment' => 'center',
						),
						'layout'    => array(
							'margin' => array(
								'top'    => 20,
								'bottom' => 5,
							),
						),
					),
					array(
						'role'      => 'body',
						'text'      => $child_links_html,
						'format'    => 'html',
						'layout'    => 'child-posts-layout',
						'textStyle' => array(
							'fontName'   => 'Helvetica',
							'fontSize'   => 16,
							'tracking'   => 0,
							'lineHeight' => 24,
							'textColor'  => '#333',
							'linkStyle'  => array(
								'textColor' => '#C6252B',
							),
						),
					),
				)
			);

			$json['componentLayouts']['child-posts-layout'] = array(
				'columnStart' => 0,
				'columnSpan'  => 15,
				'margin'      => array(
					'top'    => 0,
					'bottom' => 20,
				),
			);
		}

		// ------------------------------------------------------------------
		// 6. Newsletter signup components (idempotent append).
		// ------------------------------------------------------------------
		$newsletter_url = 'mailchi.mp/pewresearch/weekly-newsletter';

		if ( ! $this->component_tree_contains_url( $json['components'], $newsletter_url ) ) {
			$newsletter_components = array(
				array(
					'role'      => 'heading2',
					'text'      => '<a href="https://mailchi.mp/pewresearch/weekly-newsletter">Sign up for our weekly newsletter</a>',
					'format'    => 'html',
					'textStyle' => array(
						'fontName'      => 'Helvetica-Bold',
						'fontSize'      => 18,
						'tracking'      => 0,
						'lineHeight'    => 20,
						'textColor'     => '#2a2a2a',
						'textAlignment' => 'center',
						'linkStyle'     => array(
							'textColor' => '#2a2a2a',
							'underline' => false,
						),
					),
					'layout'    => array(
						'margin' => array(
							'top'    => 0,
							'bottom' => 5,
						),
					),
				),
				array(
					'role'      => 'heading4',
					'text'      => 'Our latest data, delivered Saturdays',
					'format'    => 'html',
					'textStyle' => array(
						'fontName'      => 'Helvetica',
						'fontSize'      => 18,
						'tracking'      => 0,
						'lineHeight'    => 20,
						'textColor'     => '#888',
						'textAlignment' => 'center',
					),
					'layout'    => array(
						'margin' => array(
							'top'    => 0,
							'bottom' => 15,
						),
					),
				),
				array(
					'role'      => 'link_button',
					'text'      => 'Sign Up',
					'URL'       => 'https://mailchi.mp/pewresearch/weekly-newsletter',
					'style'     => 'default-link-button',
					'layout'    => 'link-button-layout',
					'textStyle' => 'default-link-button-text-style',
				),
			);

			$json['components'] = array_merge( $json['components'], $newsletter_components );
		}

		// ------------------------------------------------------------------
		// 7–10. Theme definitions — layouts, styles, and text styles.
		// ------------------------------------------------------------------
		ANF_Theme_Definitions::merge_into_document( $json, $conditional_anchor_margin );

		// ------------------------------------------------------------------
		// 11. Text styles.
		// ------------------------------------------------------------------
		$json['textStyles']['default-tag-bignumstrong'] = array(
			'fontName'   => 'Helvetica-Bold',
			'fontSize'   => 22,
			'lineHeight' => 32,
			'tracking'   => 0,
			'linkStyle'  => array(
				'textColor' => '#346ead',
				'underline' => true,
			),
		);

		// ------------------------------------------------------------------
		// 12. Metadata.
		//     ANF metadata only allows specific keys (dateCreated, dateModified,
		//     datePublished, generatorIdentifier, generatorName, generatorVersion,
		//     excerpt, thumbnailURL, etc.) — 'title' is not a valid metadata key.
		// ------------------------------------------------------------------
		$json['metadata']['excerpt'] = preg_replace(
			'/\&hellip\;/i',
			'...',
			get_the_excerpt( $post_id )
		);

		// ------------------------------------------------------------------
		// 13. Per-component processing loop.
		//     - Pull quotes → full-width layout.
		//     - Non-featured images → respect document margins.
		//     - Anchor-right image width → column adjustment (200px → 2-col, 420px → 4-col).
		//     - Strip query strings from image URLs.
		// ------------------------------------------------------------------
		$processed_components = array();

		for ( $i = 0, $count = count( $json['components'] ); $i < count( $json['components'] ); $i++ ) {
			$new       = array();
			$component = $json['components'][ $i ];

			// Make pull quotes full width.
			if (
				isset( $component['textStyle'] ) &&
				in_array(
					$component['textStyle'],
					array( 'default-pullquote', 'default-pullquote-left', 'default-pullquote-right' ),
					true
				)
			) {
				$component['layout'] = 'anchor-layout-pullquote';
			}

			if (
				isset( $component['textStyle'] ) &&
				(
					$component['textStyle'] === 'body-bignumber' ||
					( isset( $component['text'] ) && stripos( $component['text'], 'class="bignumber"' ) !== false )
				)
			) {
				// Split component if more than one big-number paragraph.
				$m = preg_split(
					'/<p class=\"bignumber\">/',
					$component['text'],
					-1,
					PREG_SPLIT_NO_EMPTY
				);

				if ( count( $m ) > 1 ) {
					for ( $j = 0; $j < count( $m ); $j++ ) {
						if ( 0 === $j ) {
							$component['text'] = wpautop( $m[ $j ] );
						} else {
							$new_component = array(
								'role'      => 'body',
								'layout'    => 'body-layout',
								'textStyle' => 'body-bignumber',
								'text'      => wpautop( $m[ $j ] ),
								'format'    => 'html',
							);
							$new[]         = $new_component;
						}
					}
				}
			} elseif (
				isset( $component['role'] ) &&
				(
					( $component['components'][0]['role'] ?? $component['role'] ) === 'photo'
				)
			) {
				// Make non-featured images respect document margins.
				$featured_url = get_the_post_thumbnail_url( $post_id, 'full' );
				$layout       = $component['components'][0]['layout'] ?? $component['layout'] ?? '';
				$image_url    = $component['components'][0]['URL'] ?? $component['URL'] ?? '';

				if (
					$featured_url !== $image_url &&
					'full-width-image' === $layout
				) {
					if ( isset( $component['role'] ) && 'container' === $component['role'] ) {
						if ( is_array( $component['layout'] ) ) {
							$component['layout']['ignoreDocumentMargin'] = true;
						} else {
							// Named layout string — switch to the no-bleed variant.
							$component['layout'] = 'full-width-image-no-bleed';
						}
					} else {
						$component['layout'] = 'full-width-image-no-bleed';
					}
				}

				// Adjust column span for various image sizes based on URL width suffix.
				if ( isset( $component['role'] ) && 'photo' === $component['role'] && ( $component['layout'] ?? '' ) === 'anchor-layout-right' ) {
					$width = isset( $component['URL'] ) ? substr( $component['URL'], -3 ) : '';
					if ( 200 === intval( $width ) ) {
						$component['layout'] = 'anchor-layout-right-2-col';
					}
					if ( 420 === intval( $width ) ) {
						$component['layout'] = 'anchor-layout-right-4-col';
					}
				}

				// Strip query strings from image URLs (use full-resolution image).
				if ( isset( $component['role'] ) && 'container' === $component['role'] && isset( $component['components'][0]['URL'] ) ) {
					$component['components'][0]['URL'] = preg_replace(
						'/\.(jpg|jpeg|png|gif|svg).*/i',
						'.${1}',
						$component['components'][0]['URL']
					);
				} elseif ( isset( $component['URL'] ) ) {
					$component['URL'] = preg_replace(
						'/\.(jpg|jpeg|png|gif|svg).*/i',
						'.${1}',
						$component['URL']
					);
				}
			}

			// Normalize border: remove invalid boolean side flags; only 'all' is valid.
			if ( isset( $component['style']['border'] ) && is_array( $component['style']['border'] ) ) {
				$border = $component['style']['border'];
				if ( isset( $border['all'] ) ) {
					$component['style']['border'] = array( 'all' => $border['all'] );
				}
			}

			// Strip <table> HTML from body text — tables are not in ANF's supported HTML subset.
			if (
				isset( $component['role'] ) &&
				'body' === $component['role'] &&
				isset( $component['text'] ) &&
				str_contains( $component['text'], '<table' )
			) {
				$component['text'] = preg_replace( '/<table[\s\S]*?<\/table>/i', '', $component['text'] );
				$component['text'] = trim( $component['text'] );
				if ( '' === $component['text'] ) {
					// Entire component was just a table — skip it.
					continue;
				}
			}

			// Normalize embedwebvideo URLs and strip the invalid `caption` property.
			//
			// ANF requires an embed URL (e.g. youtube.com/embed/ID), not a watch URL
			// (youtube.com/watch?v=ID). The AI often copies the watch URL verbatim from
			// the source markdown. Normalise here so the rule is enforced structurally.
			//
			// The `caption` property is also not a valid ANF EmbedWebVideo property; the
			// content transformer sometimes emits it as a component-level key — strip it.
			if ( isset( $component['role'] ) && 'embedwebvideo' === $component['role'] ) {
				if ( isset( $component['URL'] ) && is_string( $component['URL'] ) ) {
					$component['URL'] = $this->normalize_embed_url( $component['URL'] );
				}
				unset( $component['caption'] );
			}

			// A standalone `caption` component at the document root is invalid per ANF —
			// captions must be children of a container or figure. When the preceding component
			// is a media element, wrap both in a container (correct ANF structure).
			// Otherwise convert to body so the text still renders.
			if ( isset( $component['role'] ) && 'caption' === $component['role'] ) {
				$media_roles = array( 'photo', 'video', 'embedwebvideo', 'audio', 'figure' );
				$last_index  = count( $processed_components ) - 1;
				$last        = $last_index >= 0 ? $processed_components[ $last_index ] : null;

				if ( $last && in_array( $last['role'] ?? '', $media_roles, true ) ) {
					// Pop the media component, hoist its layout to the container.
					array_pop( $processed_components );
					$container_layout = $last['layout'] ?? 'body-layout';
					$media_no_layout  = $last;
					unset( $media_no_layout['layout'] );

					$processed_components[] = array(
						'role'       => 'container',
						'layout'     => $container_layout,
						'components' => array( $media_no_layout, $component ),
					);
					continue;
				}

				// When the AI wraps a photo in a container and then emits a standalone
				// caption, the preceding component is a container rather than bare media.
				// Inject the caption into the container's components if its first child
				// is a media role — do NOT inject for text containers (e.g. body containers).
				if (
					$last &&
					'container' === ( $last['role'] ?? '' ) &&
					isset( $last['components'] ) &&
					is_array( $last['components'] ) &&
					! empty( $last['components'] )
				) {
					$first_child_role = $last['components'][0]['role'] ?? '';
					if ( in_array( $first_child_role, $media_roles, true ) ) {
						$processed_components[ $last_index ]['components'][] = $component;
						continue;
					}
				}

				// No preceding media — render caption text as body.
				$component['role'] = 'body';
			}

			$processed_components[] = $component;
			array_splice( $processed_components, count( $processed_components ) + 1, 0, $new );
		}

		$json['components'] = $processed_components;

		// ------------------------------------------------------------------
		// 14. URL normalization — replace non-production media URLs so that
		//     external consumers (News Preview, third-party renderers) can
		//     fetch images.  Runs only in non-production environments; in
		//     production, home_url() already resolves to the public domain.
		// ------------------------------------------------------------------
		$this->normalize_image_urls( $json );

		// ------------------------------------------------------------------
		// 15. Font name normalization — ensure all fontName values use valid
		//     PostScript names.  The AI sometimes generates bare family names
		//     (e.g. "IowanOldStyle") that are not valid PostScript identifiers;
		//     News Preview rejects them.  Map to the correct Roman/regular variant.
		//     Uses a recursive tree-walk on the decoded array so that JSON key
		//     spacing and encoding order do not affect the replacement.
		// ------------------------------------------------------------------
		$font_map = array(
			'IowanOldStyle'       => 'Georgia',
			'IowanOldStyle-Roman' => 'Georgia',
		);
		$this->normalize_font_names( $json, $font_map );
		$json_str = wp_json_encode( $json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return $json_str ?: $anf_json;
	}

	/**
	 * Replace non-public WP media URLs with the production domain.
	 *
	 * Only runs outside production — in production, home_url() is already the
	 * public domain and no replacement is needed.
	 *
	 * Uses the `prc_apple_news_production_media_url` filter to allow overrides.
	 *
	 * @param array $json Decoded ANF array passed by reference.
	 */
	private function normalize_image_urls( array &$json ): void {
		if ( 'production' === wp_get_environment_type() ) {
			return;
		}

		$production_base = (string) apply_filters( 'prc_apple_news_production_media_url', 'https://www.pewresearch.org' );

		if ( ! empty( $json['components'] ) ) {
			$this->normalize_urls_in_components( $json['components'], $production_base );
		}
	}

	/**
	 * Recursively walk the component tree and normalize WP media URLs.
	 *
	 * @param array  $components    Component array passed by reference.
	 * @param string $production_base  Production base URL (scheme + host, no trailing slash).
	 * @param int    $depth         Current recursion depth — stops at 4.
	 */
	private function normalize_urls_in_components( array &$components, string $production_base, int $depth = 0 ): void {
		if ( $depth >= 4 ) {
			return;
		}

		foreach ( $components as &$component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}

			if ( isset( $component['URL'] ) && is_string( $component['URL'] ) ) {
				$component['URL'] = $this->normalize_media_url( $component['URL'], $production_base );
			}

			if ( ! empty( $component['components'] ) && is_array( $component['components'] ) ) {
				$this->normalize_urls_in_components( $component['components'], $production_base, $depth + 1 );
			}
		}
	}

	/**
	 * Replace the origin of a WP uploads URL with the production base URL.
	 *
	 * Non-WP-media URLs (external CDNs, mailto links, etc.) are returned unchanged.
	 * Matches URLs containing /wp-content/ so that only WordPress media is rewritten.
	 *
	 * @param string $url             Component URL to inspect.
	 * @param string $production_base Production base URL (scheme + host, no trailing slash).
	 * @return string Normalized URL.
	 */
	private function normalize_media_url( string $url, string $production_base ): string {
		$wp_content_marker = '/wp-content/';
		$pos               = strpos( $url, $wp_content_marker );

		if ( false === $pos ) {
			return $url;
		}

		$wp_path = substr( $url, $pos ); // e.g. /wp-content/uploads/sites/20/2026/04/file.png

		return rtrim( $production_base, '/' ) . $wp_path;
	}

	/**
	 * Convert a video URL to its embeddable form required by ANF EmbedWebVideo.
	 *
	 * ANF only accepts embed URLs from YouTube, Vimeo, and Dailymotion. The AI
	 * frequently copies watch/share URLs from the source content unchanged.
	 *
	 * Transformations applied:
	 *   YouTube watch  https://www.youtube.com/watch?v=ID → https://www.youtube.com/embed/ID
	 *   YouTube short  https://youtu.be/ID                → https://www.youtube.com/embed/ID
	 *   Vimeo          https://vimeo.com/ID               → https://player.vimeo.com/video/ID
	 *   Dailymotion    https://www.dailymotion.com/video/ID → https://geo.dailymotion.com/player.html?video=ID
	 *
	 * URLs already in embed format are returned unchanged.
	 *
	 * @param string $url Raw video URL.
	 * @return string Embed URL suitable for ANF.
	 */
	private function normalize_embed_url( string $url ): string {
		$parsed = wp_parse_url( $url );
		$host   = strtolower( $parsed['host'] ?? '' );
		$path   = $parsed['path'] ?? '';

		// YouTube watch URL: youtube.com/watch?v=ID
		if ( ( 'www.youtube.com' === $host || 'youtube.com' === $host ) && '/watch' === $path ) {
			wp_parse_str( $parsed['query'] ?? '', $query );
			if ( ! empty( $query['v'] ) ) {
				return 'https://www.youtube.com/embed/' . rawurlencode( $query['v'] );
			}
		}

		// YouTube short URL: youtu.be/ID
		if ( 'youtu.be' === $host && '' !== ltrim( $path, '/' ) ) {
			$video_id = ltrim( $path, '/' );
			return 'https://www.youtube.com/embed/' . rawurlencode( $video_id );
		}

		// Vimeo: vimeo.com/ID (not player.vimeo.com, which is already embed)
		if ( 'vimeo.com' === $host || 'www.vimeo.com' === $host ) {
			$video_id = ltrim( $path, '/' );
			if ( '' !== $video_id && is_numeric( $video_id ) ) {
				return 'https://player.vimeo.com/video/' . rawurlencode( $video_id );
			}
		}

		// Dailymotion: dailymotion.com/video/ID
		if ( ( 'www.dailymotion.com' === $host || 'dailymotion.com' === $host )
			&& str_starts_with( $path, '/video/' )
		) {
			$video_id = substr( $path, strlen( '/video/' ) );
			if ( '' !== $video_id ) {
				return 'https://geo.dailymotion.com/player.html?video=' . rawurlencode( $video_id );
			}
		}

		return $url;
	}

	/**
	 * Recursively walk a decoded ANF array and replace fontName values per the given map.
	 *
	 * Operates on the decoded PHP array before JSON encoding, which is robust to
	 * key-ordering and whitespace variations that str_replace on encoded strings
	 * would miss (e.g. "fontName": "IowanOldStyle" with a space after the colon).
	 *
	 * @param array $node     Array node to walk, passed by reference.
	 * @param array $font_map Map of bare font names to corrected PostScript names.
	 */
	private function normalize_font_names( array &$node, array $font_map ): void {
		foreach ( $node as $key => &$value ) {
			if ( 'fontName' === $key && is_string( $value ) && isset( $font_map[ $value ] ) ) {
				$value = $font_map[ $value ];
			} elseif ( is_array( $value ) ) {
				$this->normalize_font_names( $value, $font_map );
			}
		}
		unset( $value );
	}

	/**
	 * Return published child posts from multiSectionReport meta as link data.
	 *
	 * @param int $post_id The parent post ID.
	 * @return array<array{title: string, url: string}> Ordered list of published children.
	 */
	private function get_child_post_links( int $post_id ): array {
		$meta = get_post_meta( $post_id, 'multiSectionReport', true );
		if ( empty( $meta ) || ! is_array( $meta ) ) {
			return array();
		}

		$links = array();
		foreach ( $meta as $entry ) {
			$child_id = isset( $entry['postId'] ) ? (int) $entry['postId'] : 0;
			if ( $child_id <= 0 ) {
				continue;
			}
			if ( 'publish' !== get_post_status( $child_id ) ) {
				continue;
			}
			$links[] = array(
				'title' => get_the_title( $child_id ),
				'url'   => get_permalink( $child_id ),
			);
		}

		return $links;
	}

	/**
	 * Recursively checks if any component in the tree contains the given URL in its URL or text.
	 *
	 * Traversal is limited to 4 levels to avoid unbounded recursion on malformed data.
	 *
	 * @param array  $components Array of Apple News component arrays (may have nested 'components').
	 * @param string $needle_url URL substring to search for (e.g. newsletter signup URL).
	 * @param int    $depth      Current recursion depth (internal use; call with no third argument).
	 * @return bool True if the URL appears in any component at any depth up to the limit.
	 */
	private function component_tree_contains_url( array $components, string $needle_url, int $depth = 0 ): bool {
		if ( $depth >= 4 ) {
			return false;
		}

		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}

			if (
				( isset( $component['URL'] ) && str_contains( (string) $component['URL'], $needle_url ) ) ||
				( isset( $component['text'] ) && str_contains( (string) $component['text'], $needle_url ) )
			) {
				return true;
			}

			if ( ! empty( $component['components'] ) && is_array( $component['components'] ) ) {
				if ( $this->component_tree_contains_url( $component['components'], $needle_url, $depth + 1 ) ) {
					return true;
				}
			}
		}

		return false;
	}
}
