<?php
/**
 * Front-end audio player block.
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

/**
 * Registers the narration player block.
 *
 * The block is dynamic. Saving an audio URL into post content would leave it
 * pointing at a deleted attachment the first time narration is regenerated,
 * so the URL is resolved at render time instead.
 */
class Player_Block {

	/**
	 * Block name.
	 */
	const BLOCK = 'prc-audio-narration/player';

	/**
	 * Editor script handle, referenced by block.json.
	 */
	const HANDLE = 'prc-audio-narration-player';

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

		$this->loader->add_action( 'init', $this, 'register' );
	}

	/**
	 * Register the editor script and the block.
	 *
	 * @return void
	 */
	public function register() {
		$asset_path = PRC_AUDIO_NARRATION_DIR . '/build/index.asset.php';

		// The build copies block.json alongside the compiled script; fall back
		// to source so a checkout that has not been built still registers.
		$block_path = PRC_AUDIO_NARRATION_DIR . '/build/audio-player';

		if ( ! file_exists( $block_path . '/block.json' ) ) {
			$block_path = PRC_AUDIO_NARRATION_DIR . '/src/audio-player';
		}

		if ( ! file_exists( $block_path . '/block.json' ) ) {
			return;
		}

		if ( file_exists( $asset_path ) ) {
			$asset = require $asset_path;

			wp_register_script(
				self::HANDLE,
				PRC_AUDIO_NARRATION_URL . 'build/index.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			wp_set_script_translations( self::HANDLE, 'prc-audio-narration' );
		}

		register_block_type(
			$block_path,
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Render the player.
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $content    Block content.
	 * @param \WP_Block $block      Block instance.
	 * @return string Empty string when there is nothing playable.
	 */
	public function render( $attributes = array(), $content = '', $block = null ) {
		$post_id = 0;

		// Prefer the loop context so the block works inside a query loop,
		// where the global post is not the post being rendered.
		if ( $block instanceof \WP_Block && ! empty( $block->context['postId'] ) ) {
			$post_id = (int) $block->context['postId'];
		}

		if ( ! $post_id ) {
			$post_id = (int) get_the_ID();
		}

		if ( ! $post_id ) {
			return '';
		}

		$record = $this->store->get( $post_id );

		// Nothing to play, or the audio no longer matches the article. A
		// reader cannot tell that narration is out of date, so it is withheld
		// rather than played alongside text it does not match.
		if ( null === $record || $record['is_stale'] || '' === $record['url'] ) {
			return '';
		}

		$attributes = wp_parse_args(
			is_array( $attributes ) ? $attributes : array(),
			array(
				'label'        => '',
				'showDuration' => true,
			)
		);

		$label = '' !== trim( (string) $attributes['label'] )
			? (string) $attributes['label']
			: __( 'Listen to this article', 'prc-audio-narration' );

		$duration = ( $attributes['showDuration'] && '' !== $record['duration_formatted'] )
			? $record['duration_formatted']
			: '';

		$wrapper = $this->wrapper_attributes();

		ob_start();
		?>
		<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by get_block_wrapper_attributes(). ?>
		<figure <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<figcaption class="wp-element-caption">
				<?php echo esc_html( $label ); ?>
				<?php if ( '' !== $duration ) : ?>
					<span class="prc-audio-narration-duration"> · <?php echo esc_html( $duration ); ?></span>
				<?php endif; ?>
			</figcaption>
			<audio
				controls
				preload="none"
				src="<?php echo esc_url( $record['url'] ); ?>"
				aria-label="<?php echo esc_attr( $this->aria_label( $post_id, $label ) ); ?>"
			></audio>
		</figure>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Wrapper attributes for the figure element.
	 *
	 * get_block_wrapper_attributes() reads state the block renderer sets up,
	 * and warns when there is none. That happens whenever the callback is
	 * invoked outside a render pass -- from a template, a shortcode bridge, or
	 * a test -- so the plain class list is used in that case.
	 *
	 * @return string
	 */
	private function wrapper_attributes(): string {
		$in_render_pass = class_exists( '\WP_Block_Supports' )
			&& isset( \WP_Block_Supports::$block_to_render )
			&& null !== \WP_Block_Supports::$block_to_render;

		if ( $in_render_pass ) {
			return get_block_wrapper_attributes( array( 'class' => 'wp-block-audio' ) );
		}

		return 'class="wp-block-audio prc-audio-narration-player"';
	}

	/**
	 * Accessible label for the player.
	 *
	 * A screen reader user landing on the control hears the article it plays,
	 * not a bare "audio".
	 *
	 * @param int    $post_id The post ID.
	 * @param string $label   The visible caption.
	 * @return string
	 */
	private function aria_label( int $post_id, string $label ): string {
		$title = get_the_title( $post_id );

		if ( '' === $title ) {
			return $label;
		}

		return sprintf(
			/* translators: %s: article title */
			__( 'Listen to “%s”', 'prc-audio-narration' ),
			wp_strip_all_tags( $title )
		);
	}
}
