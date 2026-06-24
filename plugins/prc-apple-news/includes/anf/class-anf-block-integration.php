<?php
/**
 * ANF Block Integration — core block callbacks.
 *
 * @package PRC\Platform\Apple_News\ANF
 */

declare(strict_types=1);

namespace PRC\Platform\Apple_News\ANF;

/**
 * Registers ANF component callbacks for standard core blocks used in PRC articles.
 */
class ANF_Block_Integration {

	/**
	 * @param \PRC\Platform\Apple_News\Loader $loader Hook registration loader.
	 */
	public function __construct( $loader ) {
		$loader->add_action(
			'prc_apple_news_register_block_callbacks',
			$this,
			'register_block_callbacks'
		);
	}

	/**
	 * @hook prc_apple_news_register_block_callbacks
	 */
	public function register_block_callbacks(): void {
		ANF_Block_Registry::register( 'core/paragraph', array( $this, 'paragraph' ) );
		ANF_Block_Registry::register( 'core/heading', array( $this, 'heading' ) );
		ANF_Block_Registry::register( 'core/list', array( $this, 'list_block' ) );
		ANF_Block_Registry::register( 'core/list-item', array( $this, 'list_item' ) );
		ANF_Block_Registry::register( 'core/quote', array( $this, 'quote' ) );
		ANF_Block_Registry::register( 'core/pullquote', array( $this, 'quote' ) );
		ANF_Block_Registry::register( 'core/image', array( $this, 'image' ) );
		ANF_Block_Registry::register( 'core/separator', array( $this, 'separator' ) );
		ANF_Block_Registry::register( 'core/table', array( $this, 'table' ) );
	}

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 * @return array<int,array<string,mixed>>
	 */
	public function paragraph( array $block, \WP_Post $post ): array {
		$inner = $this->get_inner_html( $block );
		if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
			return array();
		}

		$text_style = false !== stripos( $inner, 'class="bignumber"' ) ? 'body-bignumber' : 'default-body';

		return array(
			$this->body_component( $inner, $text_style ),
		);
	}

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 * @return array<int,array<string,mixed>>
	 */
	public function heading( array $block, \WP_Post $post ): array {
		$attrs = $block['attrs'] ?? array();
		$level = isset( $attrs['level'] ) ? max( 2, min( 6, (int) $attrs['level'] ) ) : 2;
		$inner = $this->get_inner_html( $block );
		if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
			return array();
		}

		$wrapped = $inner;
		if ( ! preg_match( '#<h[1-6][^>]*>#i', $inner ) ) {
			$wrapped = sprintf( '<h%d>%s</h%d>', $level, $inner, $level );
		}

		return array(
			array(
				'role'      => 'heading' . $level,
				'text'      => $wrapped,
				'format'    => 'html',
				'textStyle' => 'default-heading-' . $level,
				'layout'    => 'body-layout',
			),
		);
	}

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_block( array $block, \WP_Post $post ): array {
		$attrs   = $block['attrs'] ?? array();
		$ordered = ! empty( $attrs['ordered'] );
		$inner   = $block['innerBlocks'] ?? array();

		if ( ! empty( $inner ) ) {
			$items = array();
			foreach ( $inner as $item_block ) {
				if ( ( $item_block['blockName'] ?? '' ) !== 'core/list-item' ) {
					continue;
				}
				$item_inner = $this->normalize_list_item_inner( $this->get_inner_html( $item_block ) );
				if ( '' === trim( wp_strip_all_tags( $item_inner ) ) ) {
					continue;
				}
				$items[] = '<li>' . $item_inner . '</li>';
			}

			if ( ! empty( $items ) ) {
				$tag  = $ordered ? 'ol' : 'ul';
				$html = sprintf( '<%1$s>%2$s</%1$s>', $tag, implode( '', $items ) );

				return array(
					$this->body_component( $html ),
				);
			}
		}

		$html = render_block( $block );
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return array();
		}

		return array(
			$this->body_component( $html ),
		);
	}

	/**
	 * List items are rendered by their parent core/list handler.
	 *
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 * @return array<int,array<string,mixed>>
	 */
	public function list_item( array $block, \WP_Post $post ): array {
		return array();
	}

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 * @return array<int,array<string,mixed>>
	 */
	public function quote( array $block, \WP_Post $post ): array {
		$text_parts = array();
		foreach ( ( $block['innerBlocks'] ?? array() ) as $inner ) {
			$t = trim( $inner['innerHTML'] ?? '' );
			if ( '' !== $t ) {
				$text_parts[] = $t;
			}
		}

		if ( empty( $text_parts ) ) {
			$raw = trim( $block['innerHTML'] ?? '' );
			if ( '' !== $raw ) {
				$raw          = preg_replace( '#</?(?:blockquote|figure)[^>]*>#i', '', $raw );
				$text_parts[] = trim( (string) $raw );
			}
		}

		if ( empty( $text_parts ) ) {
			return array();
		}

		$content = implode( '', $text_parts );
		if ( ! preg_match( '#<p\b#i', $content ) ) {
			$content = '<p>' . $content . '</p>';
		}

		return array(
			array(
				'role'      => 'quote',
				'text'      => $content,
				'format'    => 'html',
				'textStyle' => 'default-blockquote-left',
				'layout'    => 'blockquote-layout',
			),
		);
	}

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 * @return array<int,array<string,mixed>>
	 */
	public function image( array $block, \WP_Post $post ): array {
		$parsed = $this->parse_image_block( $block );
		if ( '' === $parsed['url'] ) {
			return array();
		}

		$photo = array(
			'role'   => 'photo',
			'URL'    => $parsed['url'],
			'layout' => 'full-width-image',
		);

		if ( '' === trim( $parsed['caption'] ) ) {
			return array( $photo );
		}

		return array(
			array(
				'role'       => 'container',
				'layout'     => 'full-width-image',
				'components' => array(
					$photo,
					array(
						'role'      => 'caption',
						'text'      => '<p>' . esc_html( $parsed['caption'] ) . '</p>',
						'format'    => 'html',
						'textStyle' => 'default-body',
						'layout'    => 'body-layout',
					),
				),
			),
		);
	}

	/**
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 * @return array<int,array<string,mixed>>
	 */
	public function separator( array $block, \WP_Post $post ): array {
		return array(
			array(
				'role'   => 'divider',
				'layout' => 'body-layout',
			),
		);
	}

	/**
	 * Flatten tables into a body component with rendered HTML.
	 *
	 * @param array    $block Parsed block.
	 * @param \WP_Post $post  Post.
	 * @return array<int,array<string,mixed>>
	 */
	public function table( array $block, \WP_Post $post ): array {
		$html = render_block( $block );
		if ( '' === trim( wp_strip_all_tags( $html ) ) ) {
			return array();
		}

		return array(
			$this->body_component( $html ),
		);
	}

	/**
	 * Build a standard body component.
	 *
	 * @param string $html      HTML content.
	 * @param string $text_style Named text style.
	 * @return array<string,mixed>
	 */
	public function body_component( string $html, string $text_style = 'default-body' ): array {
		$trimmed = trim( $html );
		if ( ! preg_match( '#<(p|ul|ol|table|h[1-6]|blockquote|figure|div)\b#i', $trimmed ) ) {
			$trimmed = '<p>' . $trimmed . '</p>';
		}

		return array(
			'role'      => 'body',
			'text'      => $trimmed,
			'format'    => 'html',
			'textStyle' => $text_style,
			'layout'    => 'body-layout',
		);
	}

	/**
	 * Extract image URL, alt, and caption from a core/image block.
	 *
	 * @param array $block Parsed block.
	 * @return array{url:string,alt:string,caption:string}
	 */
	public function parse_image_block( array $block ): array {
		$attrs   = $block['attrs'] ?? array();
		$url     = '';
		$alt     = '';
		$caption = '';

		if ( ! empty( $attrs['id'] ) ) {
			$attachment_url = wp_get_attachment_url( (int) $attrs['id'] );
			if ( is_string( $attachment_url ) ) {
				$url = $attachment_url;
			}
		}

		if ( isset( $attrs['url'] ) && is_string( $attrs['url'] ) && '' !== $attrs['url'] ) {
			$url = $attrs['url'];
		}

		if ( isset( $attrs['alt'] ) && is_string( $attrs['alt'] ) ) {
			$alt = $attrs['alt'];
		}

		$inner_html = $block['innerHTML'] ?? '';
		if ( is_string( $inner_html ) && '' !== $inner_html ) {
			if ( '' === $url && preg_match( '#<img[^>]+src=["\']([^"\']+)#i', $inner_html, $m ) ) {
				$url = $m[1];
			}
			if ( '' === $alt && preg_match( '#<img[^>]+alt=["\']([^"\']*)#i', $inner_html, $m ) ) {
				$alt = $m[1];
			}
			if ( preg_match( '#<figcaption[^>]*>(.*?)</figcaption>#is', $inner_html, $m ) ) {
				$caption = trim( wp_strip_all_tags( $m[1] ) );
			}
		}

		if ( '' === $caption && isset( $attrs['caption'] ) && is_string( $attrs['caption'] ) ) {
			$caption = trim( wp_strip_all_tags( $attrs['caption'] ) );
		}

		if ( '' === $alt && '' !== $caption ) {
			$alt = $caption;
		}

		return array(
			'url'     => $url,
			'alt'     => $alt,
			'caption' => $caption,
		);
	}

	/**
	 * Strip a single outer list-item wrapper when innerHTML already includes li tags.
	 *
	 * @param string $item_inner List item inner HTML.
	 * @return string
	 */
	private function normalize_list_item_inner( string $item_inner ): string {
		$trimmed = trim( $item_inner );
		if ( preg_match( '#^<li[^>]*>(.*)</li>$#is', $trimmed, $matches ) ) {
			return trim( $matches[1] );
		}

		return $trimmed;
	}

	/**
	 * @param array $block Parsed block.
	 * @return string
	 */
	private function get_inner_html( array $block ): string {
		$inner = trim( $block['innerHTML'] ?? '' );
		if ( '' !== $inner ) {
			return $inner;
		}

		$parts = array();
		foreach ( ( $block['innerContent'] ?? array() ) as $chunk ) {
			if ( is_string( $chunk ) && '' !== trim( $chunk ) ) {
				$parts[] = $chunk;
			}
		}

		return trim( implode( '', $parts ) );
	}
}
