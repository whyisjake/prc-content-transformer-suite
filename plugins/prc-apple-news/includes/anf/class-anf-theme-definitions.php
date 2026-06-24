<?php
/**
 * PRC Apple News theme definitions for the deterministic ANF pipeline.
 *
 * Supplies componentLayouts, componentTextStyles, and componentStyles that
 * deterministic block emitters reference. Values mirror apple-news-theme.json.
 *
 * @package PRC\Platform\Apple_News\ANF
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\ANF;

/**
 * Canonical ANF layout and style registries for deterministic publishing.
 */
class ANF_Theme_Definitions {

	/**
	 * Merge base theme definitions into a decoded ANF document.
	 *
	 * Existing document registries are preserved unless a key is listed in the
	 * canonical override sets (headings, title, body, core layouts).
	 *
	 * @param array $json                      Decoded ANF document (by reference).
	 * @param array $conditional_anchor_margin   Conditional margin blocks for anchor layouts.
	 */
	public static function merge_into_document( array &$json, array $conditional_anchor_margin ): void {
		$json['componentLayouts']    = $json['componentLayouts'] ?? array();
		$json['componentStyles']     = $json['componentStyles'] ?? array();
		$json['componentTextStyles'] = $json['componentTextStyles'] ?? array();

		$json['componentLayouts'] = array_merge(
			self::component_layouts( $conditional_anchor_margin ),
			$json['componentLayouts']
		);

		$json['componentStyles'] = array_merge(
			self::component_styles(),
			$json['componentStyles']
		);

		$preserved_text_styles = array();
		foreach ( array( 'default-blockquote-left', 'default-pullquote' ) as $optional_key ) {
			if ( array_key_exists( $optional_key, $json['componentTextStyles'] ) ) {
				$preserved_text_styles[ $optional_key ] = $json['componentTextStyles'][ $optional_key ];
			}
		}

		$json['componentTextStyles'] = array_merge(
			self::component_text_styles(),
			$json['componentTextStyles']
		);

		foreach ( $preserved_text_styles as $key => $style ) {
			$json['componentTextStyles'][ $key ] = $style;
		}

		foreach ( self::canonical_layout_overrides( $conditional_anchor_margin ) as $key => $layout ) {
			$json['componentLayouts'][ $key ] = $layout;
		}

		foreach ( self::canonical_text_style_overrides() as $key => $style ) {
			$json['componentTextStyles'][ $key ] = $style;
		}

		if ( array_key_exists( 'anchor-layout-right', $json['componentLayouts'] ) ) {
			$json['componentLayouts']['anchor-layout-right']['columnStart'] = 8;
			$json['componentLayouts']['anchor-layout-right']['columnSpan']  = 7;
			$json['componentLayouts']['anchor-layout-right']['conditional'] = $conditional_anchor_margin;
		}
	}

	/**
	 * Layout identifiers referenced by deterministic block emitters and post-processor injections.
	 *
	 * @param array $conditional_anchor_margin Conditional margin blocks for anchor layouts.
	 * @return array<string, array<string, mixed>>
	 */
	public static function component_layouts( array $conditional_anchor_margin ): array {
		return array_merge(
			self::canonical_layout_overrides( $conditional_anchor_margin ),
			array(
				'link-button-layout'         => array(
					'columnStart'  => 0,
					'columnSpan'   => 15,
					'margin'       => array(
						'bottom' => 20,
					),
					'contentInset' => true,
				),
				'anchor-layout-pullquote'    => array(
					'columnStart'  => 0,
					'columnSpan'   => 15,
					'contentInset' => array(
						'left'   => true,
						'bottom' => true,
					),
					'margin'       => array(
						'top'    => 12,
						'bottom' => 12,
					),
				),
				'full-width-image-no-bleed'  => array(
					'columnStart'          => 0,
					'columnSpan'           => 15,
					'margin'               => array(
						'top'    => 24,
						'bottom' => 24,
					),
					'ignoreDocumentMargin' => false,
				),
				'anchor-layout-right-4-col'  => array(
					'columnStart' => 5,
					'columnSpan'  => 10,
					'conditional' => $conditional_anchor_margin,
				),
				'anchor-layout-right-2-col'  => array(
					'columnStart' => 10,
					'columnSpan'  => 5,
					'conditional' => $conditional_anchor_margin,
				),
				'anchor-layout-left-4-col'   => array(
					'columnStart' => 0,
					'columnSpan'  => 10,
					'conditional' => $conditional_anchor_margin,
				),
				'anchor-layout-left-2-col'   => array(
					'columnStart' => 0,
					'columnSpan'  => 5,
					'conditional' => $conditional_anchor_margin,
				),
				'anchor-layout-left'         => array(
					'columnStart' => 0,
					'columnSpan'  => 7,
					'conditional' => $conditional_anchor_margin,
				),
				'callout-layout-full'        => array(
					'columnStart'  => 0,
					'columnSpan'   => 15,
					'margin'       => array(
						'bottom' => 20,
						'top'    => 20,
					),
					'contentInset' => array(
						'bottom' => true,
						'left'   => true,
						'right'  => true,
						'top'    => false,
					),
				),
				'callout-layout-right-2-col' => array(
					'columnStart'  => 10,
					'columnSpan'   => 5,
					'margin'       => array(
						'bottom' => 20,
						'top'    => 20,
					),
					'contentInset' => array(
						'bottom' => true,
						'left'   => true,
						'right'  => true,
						'top'    => false,
					),
				),
				'blockquote-layout'          => array(
					'columnStart' => 2,
					'columnSpan'  => 11,
					'margin'      => array(
						'top'    => 16,
						'bottom' => 16,
					),
				),
			)
		);
	}

	/**
	 * Layouts that must always use PRC canonical values.
	 *
	 * @param array $conditional_anchor_margin Conditional margin blocks for anchor layouts.
	 * @return array<string, array<string, mixed>>
	 */
	public static function canonical_layout_overrides( array $conditional_anchor_margin ): array {
		unset( $conditional_anchor_margin );

		return array(
			'title-layout'      => array(
				'columnStart' => 0,
				'columnSpan'  => 15,
				'margin'      => array(
					'top'    => 0,
					'bottom' => 8,
				),
			),
			'byline-layout'     => array(
				'columnStart' => 0,
				'columnSpan'  => 15,
				'margin'      => array(
					'top'    => 0,
					'bottom' => 16,
				),
			),
			'body-layout'       => array(
				'columnStart' => 0,
				'columnSpan'  => 15,
				'margin'      => array(
					'top'    => 12,
					'bottom' => 12,
				),
			),
			'full-width-image'  => array(
				'columnStart' => 0,
				'columnSpan'  => 15,
				'margin'      => array(
					'top'    => 25,
					'bottom' => 25,
				),
			),
		);
	}

	/**
	 * Component style identifiers referenced by deterministic emitters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function component_styles(): array {
		return array(
			'default-link-button' => array(
				'backgroundColor' => '#000',
			),
		);
	}

	/**
	 * Text style identifiers referenced by deterministic emitters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function component_text_styles(): array {
		return array_merge(
			self::canonical_text_style_overrides(),
			array(
				'default-link-button-text-style' => array(
					'textColor' => '#FFF',
					'fontName'  => 'Helvetica-Bold',
					'fontSize'  => 18,
				),
				'body-bignumber'                 => array(
					'textAlignment'          => 'left',
					'fontName'               => 'Georgia',
					'fontSize'               => 18,
					'tracking'               => 0,
					'lineHeight'             => 32,
					'textColor'              => '#2a2a2a',
					'linkStyle'              => array(
						'textColor' => '#346ead',
						'underline' => true,
					),
					'dropCapStyle'           => array(
						'numberOfLines'      => 2,
						'fontName'           => 'Helvetica-Bold',
						'textColor'          => '#ec9f2e',
						'padding'            => 1,
						'numberOfCharacters' => 1,
					),
					'paragraphSpacingBefore' => 22,
					'paragraphSpacingAfter'  => 22,
				),
				'body-bignumber-2-digit'         => array(
					'textAlignment'          => 'left',
					'fontName'               => 'Georgia',
					'fontSize'               => 18,
					'tracking'               => 0,
					'lineHeight'             => 32,
					'textColor'              => '#2a2a2a',
					'linkStyle'              => array(
						'textColor' => '#346ead',
						'underline' => true,
					),
					'dropCapStyle'           => array(
						'numberOfLines'      => 3,
						'fontName'           => 'Helvetica-Bold',
						'textColor'          => '#ec9f2e',
						'padding'            => 0,
						'numberOfCharacters' => 2,
					),
					'paragraphSpacingBefore' => 22,
					'paragraphSpacingAfter'  => 22,
				),
				'default-blockquote-left'        => array(
					'fontName'               => 'Helvetica',
					'fontSize'               => 17,
					'textColor'              => '#2a2a2a',
					'lineHeight'             => 28,
					'textAlignment'          => 'left',
					'tracking'               => 0,
					'linkStyle'              => array(
						'textColor' => '#346ead',
						'underline' => true,
					),
					'paragraphSpacingBefore' => 16,
					'paragraphSpacingAfter'  => 16,
				),
				'default-pullquote'              => array(
					'fontName'      => 'Marion-Regular',
					'fontSize'      => 32,
					'textColor'     => '#2a2a2a',
					'lineHeight'    => 45,
					'textAlignment' => 'left',
					'tracking'      => 0,
					'fontScaling'   => false,
					'conditional'   => array(
						array(
							'fontSize'   => 28,
							'lineHeight' => 36,
							'conditions' => array(
								array(
									'minViewportWidth' => 0,
								),
							),
						),
						array(
							'fontSize'   => 32,
							'lineHeight' => 45,
							'conditions' => array(
								array(
									'minViewportWidth' => 769,
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * Text styles that must always use PRC canonical values (override AI output).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function canonical_text_style_overrides(): array {
		return array(
			'default-title'       => array(
				'fontName'      => 'Marion-Bold',
				'fontSize'      => 50,
				'lineHeight'    => 53,
				'tracking'      => 0,
				'textColor'     => '#2a2a2a',
				'textAlignment' => 'left',
			),
			'default-byline'      => array(
				'fontName'      => 'Helvetica-Bold',
				'fontSize'      => 14,
				'lineHeight'    => 19,
				'tracking'      => 0,
				'textColor'     => '#5c5c5c',
				'textTransform' => 'uppercase',
			),
			'default-body'        => array(
				'textAlignment'          => 'left',
				'fontName'               => 'Georgia',
				'fontSize'               => 18,
				'tracking'               => 0,
				'lineHeight'             => 32,
				'textColor'              => '#2a2a2a',
				'linkStyle'              => array(
					'textColor' => '#346ead',
					'underline' => true,
				),
				'paragraphSpacingBefore' => 22,
				'paragraphSpacingAfter'  => 22,
			),
			'default-heading-2'   => array(
				'fontName'   => 'Helvetica-Bold',
				'fontSize'   => 25,
				'lineHeight' => 35,
				'tracking'   => 0,
				'textColor'  => '#2a2a2a',
			),
			'default-heading-3'   => array(
				'fontName'   => 'Helvetica-Bold',
				'fontSize'   => 23,
				'lineHeight' => 32,
				'tracking'   => 0,
				'textColor'  => '#2a2a2a',
			),
			'default-heading-4'   => array(
				'fontName'   => 'Helvetica-Bold',
				'fontSize'   => 21,
				'lineHeight' => 29,
				'tracking'   => 0,
				'textColor'  => '#2a2a2a',
			),
			'default-heading-5'   => array(
				'fontName'   => 'Helvetica',
				'fontSize'   => 19,
				'lineHeight' => 27,
				'tracking'   => 0,
				'textColor'  => '#2a2a2a',
			),
			'default-heading-6'   => array(
				'fontName'   => 'Helvetica',
				'fontSize'   => 17,
				'lineHeight' => 24,
				'tracking'   => 0,
				'textColor'  => '#2a2a2a',
			),
		);
	}
}
