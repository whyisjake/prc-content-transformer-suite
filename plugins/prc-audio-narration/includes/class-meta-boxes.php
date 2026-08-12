<?php
/**
 * Editor meta box
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

/**
 * The narration panel on the post editing screen.
 */
class Meta_Boxes {

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

		$this->loader->add_action( 'add_meta_boxes', $this, 'register' );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue' );
	}

	/**
	 * Post types that may be narrated.
	 *
	 * @return string[]
	 */
	public static function supported_post_types(): array {
		/**
		 * Filter the post types offered narration.
		 *
		 * @param string[] $post_types Supported post types.
		 */
		return (array) apply_filters( 'prc_audio_narration_post_types', array( 'post' ) );
	}

	/**
	 * Register the meta box.
	 *
	 * @return void
	 */
	public function register() {
		add_meta_box(
			'prc-audio-narration',
			__( 'Audio Narration', 'prc-audio-narration' ),
			array( $this, 'render' ),
			self::supported_post_types(),
			'side',
			'default'
		);
	}

	/**
	 * Enqueue the panel script on supported editing screens.
	 *
	 * @param string $hook_suffix The admin page.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, self::supported_post_types(), true ) ) {
			return;
		}

		// A false src registers an inline-only script; an empty string would
		// emit a <script src=""> that re-requests the current page.
		wp_register_script( 'prc-audio-narration-panel', false, array( 'wp-api-fetch' ), PRC_AUDIO_NARRATION_VERSION, true );
		wp_enqueue_script( 'prc-audio-narration-panel' );
		wp_add_inline_script( 'prc-audio-narration-panel', $this->panel_script() );

		wp_localize_script(
			'prc-audio-narration-panel',
			'prcAudioNarration',
			array(
				'namespace' => REST_API::NAMESPACE_V1,
				'strings'   => array(
					'generating' => __( 'Generating…', 'prc-audio-narration' ),
					'failed'     => __( 'Generation failed.', 'prc-audio-narration' ),
					'confirm'    => __( 'Remove the narration audio for this post?', 'prc-audio-narration' ),
				),
			)
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post The post being edited.
	 * @return void
	 */
	public function render( $post ) {
		$service  = new Narration_Service();
		$record   = $service->store()->get( $post->ID );
		$provider = $service->orchestrator()->get_active_provider();
		$pending  = Action_Scheduler_Handler::is_pending( $post->ID );
		?>
		<div class="prc-audio-narration-panel" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<?php if ( ! $provider ) : ?>
				<p class="notice notice-warning" style="padding:8px;margin:0 0 8px;">
					<?php
					printf(
						/* translators: %s: settings page URL */
						wp_kses_post( __( 'No speech provider is configured. <a href="%s">Add an API key</a>.', 'prc-audio-narration' ) ),
						esc_url( admin_url( 'options-general.php?page=' . Settings::PAGE_SLUG ) )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( $record && $record['is_stale'] ) : ?>
				<p class="notice notice-warning" style="padding:8px;margin:0 0 8px;">
					<strong><?php esc_html_e( 'Out of date', 'prc-audio-narration' ); ?></strong><br />
					<?php esc_html_e( 'This post changed after the audio was generated. Regenerate to match the current text.', 'prc-audio-narration' ); ?>
				</p>
			<?php endif; ?>

			<p class="prc-audio-narration-state">
				<strong><?php esc_html_e( 'Status:', 'prc-audio-narration' ); ?></strong>
				<span class="prc-audio-narration-state-label">
					<?php
					if ( $pending ) {
						esc_html_e( 'Generating…', 'prc-audio-narration' );
					} elseif ( ! $record ) {
						esc_html_e( 'Not generated', 'prc-audio-narration' );
					} elseif ( $record['is_stale'] ) {
						esc_html_e( 'Out of date', 'prc-audio-narration' );
					} else {
						esc_html_e( 'Ready', 'prc-audio-narration' );
					}
					?>
				</span>
			</p>

			<div class="prc-audio-narration-player">
				<?php if ( $record ) : ?>
					<audio
						controls
						preload="none"
						style="width:100%;"
						src="<?php echo esc_url( $record['url'] ); ?>"
						aria-label="<?php esc_attr_e( 'Narration preview', 'prc-audio-narration' ); ?>"
					></audio>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: provider name, 2: voice identifier */
								__( 'Voice: %2$s (%1$s)', 'prc-audio-narration' ),
								$record['provider'],
								$record['voice']
							)
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<p class="prc-audio-narration-estimate description"></p>

			<p>
				<button type="button" class="button button-primary prc-audio-narration-generate" <?php disabled( ! $provider || $pending ); ?>>
					<?php
					echo $record
						? esc_html__( 'Regenerate audio', 'prc-audio-narration' )
						: esc_html__( 'Generate audio', 'prc-audio-narration' );
					?>
				</button>
				<?php if ( $record ) : ?>
					<button type="button" class="button-link prc-audio-narration-delete" style="color:#b32d2e;">
						<?php esc_html_e( 'Remove', 'prc-audio-narration' ); ?>
					</button>
				<?php endif; ?>
			</p>

			<p class="prc-audio-narration-message" role="status" aria-live="polite"></p>

			<p class="description">
				<?php esc_html_e( 'Narration is generated only when you ask for it, because speech synthesis is billed per character.', 'prc-audio-narration' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * The panel behaviour script.
	 *
	 * @return string
	 */
	private function panel_script(): string {
		return <<<'JS'
( function () {
	var panel = document.querySelector( '.prc-audio-narration-panel' );
	if ( ! panel || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}

	var postId = panel.dataset.postId;
	var base = '/' + window.prcAudioNarration.namespace + '/posts/' + postId + '/narration';
	var strings = window.prcAudioNarration.strings;
	var generate = panel.querySelector( '.prc-audio-narration-generate' );
	var remove = panel.querySelector( '.prc-audio-narration-delete' );
	var label = panel.querySelector( '.prc-audio-narration-state-label' );
	var message = panel.querySelector( '.prc-audio-narration-message' );
	var pollTimer = null;

	function say( text ) {
		message.textContent = text || '';
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearTimeout( pollTimer );
			pollTimer = null;
		}
	}

	function apply( data ) {
		if ( ! data || ! data.state ) {
			return;
		}

		if ( 'pending' === data.state ) {
			label.textContent = strings.generating;
			generate.disabled = true;
			pollTimer = window.setTimeout( poll, 4000 );
			return;
		}

		stopPolling();
		generate.disabled = false;

		// The panel is rendered server-side, so a finished job reloads rather
		// than rebuilding the markup twice in two languages.
		window.location.reload();
	}

	function poll() {
		window.wp.apiFetch( { path: base } ).then( apply ).catch( function ( error ) {
			stopPolling();
			generate.disabled = false;
			say( ( error && error.message ) || strings.failed );
		} );
	}

	generate.addEventListener( 'click', function () {
		generate.disabled = true;
		label.textContent = strings.generating;
		say( '' );

		window.wp.apiFetch( { path: base, method: 'POST' } ).then( apply ).catch( function ( error ) {
			generate.disabled = false;
			say( ( error && error.message ) || strings.failed );
		} );
	} );

	if ( remove ) {
		remove.addEventListener( 'click', function () {
			if ( ! window.confirm( strings.confirm ) ) {
				return;
			}

			window.wp.apiFetch( { path: base, method: 'DELETE' } ).then( function () {
				window.location.reload();
			} ).catch( function ( error ) {
				say( ( error && error.message ) || strings.failed );
			} );
		} );
	}

	if ( label && label.textContent.trim() === strings.generating ) {
		pollTimer = window.setTimeout( poll, 4000 );
	}
}() );
JS;
	}
}
