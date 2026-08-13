<?php
/**
 * Narration Service
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

use PRC\Platform\Audio_Narration\TTS\Application\Audio_Stitcher;
use PRC\Platform\Audio_Narration\TTS\Application\Script_Chunker;
use PRC\Platform\Audio_Narration\TTS\Application\TTS_Orchestrator;
use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Request;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\TTS_Exception;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Synthesis_Failed_Exception;

/**
 * The single entry point for producing narration.
 *
 * The meta box, REST controller, WP-CLI commands, and the scheduler all call
 * through here, so generation behaviour cannot drift between them.
 */
class Narration_Service {

	/**
	 * Speech provider orchestrator.
	 *
	 * @var TTS_Orchestrator
	 */
	private $orchestrator;

	/**
	 * Narration storage.
	 *
	 * @var Narration_Store
	 */
	private $store;

	/**
	 * Script chunker.
	 *
	 * @var Script_Chunker
	 */
	private $chunker;

	/**
	 * Audio stitcher.
	 *
	 * @var Audio_Stitcher
	 */
	private $stitcher;

	/**
	 * Callable resolving a post ID to a narration script.
	 *
	 * @var callable
	 */
	private $script_resolver;

	/**
	 * Constructor.
	 *
	 * @param TTS_Orchestrator|null $orchestrator    Provider orchestrator.
	 * @param Narration_Store|null  $store           Narration storage.
	 * @param callable|null         $script_resolver Resolves post ID and force
	 *                                               flag to a script string.
	 */
	public function __construct(
		?TTS_Orchestrator $orchestrator = null,
		?Narration_Store $store = null,
		?callable $script_resolver = null
	) {
		$this->orchestrator    = $orchestrator ?? Bootstrap::tts_orchestrator();
		$this->store           = $store ?? new Narration_Store();
		$this->chunker         = new Script_Chunker();
		$this->stitcher        = new Audio_Stitcher();
		$this->script_resolver = $script_resolver ?? array( Script_Resolver::class, 'resolve' );
	}

	/**
	 * The narration store.
	 *
	 * @return Narration_Store
	 */
	public function store(): Narration_Store {
		return $this->store;
	}

	/**
	 * The provider orchestrator.
	 *
	 * @return TTS_Orchestrator
	 */
	public function orchestrator(): TTS_Orchestrator {
		return $this->orchestrator;
	}

	/**
	 * Resolve the narration script for a post.
	 *
	 * @param int  $post_id The post ID.
	 * @param bool $force   Whether to bypass the script cache.
	 * @return string|\WP_Error
	 */
	public function get_script( int $post_id, bool $force = false ) {
		return call_user_func( $this->script_resolver, $post_id, $force );
	}

	/**
	 * Estimate the cost of narrating a post.
	 *
	 * @param int  $post_id The post ID.
	 * @param bool $force   Whether to regenerate the script.
	 * @return array|\WP_Error Characters, chunk count, and estimated cost.
	 */
	public function estimate( int $post_id, bool $force = false ) {
		$script = $this->get_script( $post_id, $force );

		if ( is_wp_error( $script ) ) {
			return $script;
		}

		$post = get_post( $post_id );
		if ( $post ) {
			// Estimate against the script that will actually be synthesized,
			// attribution included, so the figure shown before generating
			// matches what gets billed.
			$script = $this->with_attribution( $post, $script );
		}

		$characters = mb_strlen( $script );
		$max        = $this->orchestrator->get_max_characters();
		$chunks     = $max > 0 ? $this->chunker->chunk( $script, $max ) : array();

		return array(
			'characters'     => $characters,
			'chunks'         => count( $chunks ),
			'estimated_cost' => $this->orchestrator->estimate_cost( $characters ),
			'provider'       => $this->orchestrator->get_active_provider()
				? $this->orchestrator->get_active_provider()->get_name()
				: '',
		);
	}

	/**
	 * Generate and store narration for a post.
	 *
	 * Nothing is written until every chunk has synthesized successfully, so a
	 * failure partway through leaves no partial attachment behind and the
	 * post keeps whatever narration it already had.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $options Options: voice_id, force.
	 * @return array|\WP_Error The stored narration record, or an error.
	 */
	public function generate( int $post_id, array $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'voice_id' => '',
				'force'    => true,
			)
		);

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'prc_audio_narration_missing_post', 'The post no longer exists.' );
		}

		$provider = $this->orchestrator->get_active_provider();
		if ( ! $provider ) {
			return new \WP_Error(
				'prc_audio_narration_no_provider',
				'No text-to-speech provider is configured and available.'
			);
		}

		$script = $this->get_script( $post_id, (bool) $options['force'] );
		if ( is_wp_error( $script ) ) {
			return $script;
		}

		if ( '' === trim( (string) $script ) ) {
			return new \WP_Error(
				'prc_audio_narration_empty_script',
				'The narration script for this post is empty.'
			);
		}

		$script = $this->with_attribution( $post, (string) $script );

		$voice_id = '' !== $options['voice_id'] ? $options['voice_id'] : Settings::default_voice_id();
		$chunks   = $this->chunker->chunk( $script, $provider->get_max_characters() );

		if ( empty( $chunks ) ) {
			return new \WP_Error(
				'prc_audio_narration_no_chunks',
				'The narration script produced no synthesizable chunks.'
			);
		}

		$responses = array();

		foreach ( $chunks as $chunk ) {
			try {
				$responses[] = $this->orchestrator->synthesize(
					new TTS_Request(
						$chunk['text'],
						array(
							'voice_id'       => $voice_id,
							'preceding_text' => $chunk['preceding_text'],
							'following_text' => $chunk['following_text'],
						)
					)
				);
			} catch ( TTS_Exception $e ) {
				// Abandon the whole job. Storing a truncated narration would
				// be worse than none, because nothing downstream can tell it
				// is incomplete.
				return new \WP_Error(
					$e->get_error_identifier(),
					sprintf(
						'Narration failed on chunk %1$d of %2$d: %3$s',
						count( $responses ) + 1,
						count( $chunks ),
						$e->getMessage()
					),
					array( 'retryable' => $e->is_retryable() )
				);
			}
		}

		try {
			$audio = $this->stitcher->stitch( $responses );
		} catch ( Synthesis_Failed_Exception $e ) {
			return new \WP_Error( $e->get_error_identifier(), $e->getMessage() );
		}

		$characters = array_sum(
			array_map(
				static fn( $response ) => $response->get_character_count(),
				$responses
			)
		);

		$attachment_id = $this->store->store(
			$post_id,
			$audio,
			array(
				'provider'   => $provider->get_name(),
				'voice'      => $voice_id,
				'duration'   => $this->stitcher->total_duration( $responses ),
				'characters' => $characters,
				'mime_type'  => $responses[0]->get_mime_type(),
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return $this->store->get( $post_id );
	}

	/**
	 * Remove a post's narration.
	 *
	 * @param int $post_id The post ID.
	 * @return bool
	 */
	public function delete( int $post_id ): bool {
		return $this->store->delete( $post_id );
	}

	/**
	 * Build the spoken attribution line for a post.
	 *
	 * Composed from the post's own title and the site name rather than asked
	 * of the model. The title is data we already hold, and a model asked to
	 * reproduce it will paraphrase, truncate at a colon, or invent a
	 * replacement -- all of which were observed before this moved into code.
	 * A listener hearing only the first sentence must learn what this is and
	 * who published it, and that sentence is too load-bearing to leave to
	 * generation.
	 *
	 * @param \WP_Post $post The post.
	 * @return string Empty string when there is no title to speak.
	 */
	public function attribution_line( \WP_Post $post ): string {
		$title = trim( wp_strip_all_tags( get_the_title( $post ) ) );

		if ( '' === $title ) {
			return '';
		}

		// A colon reads as an abrupt stop; spoken subtitles run on with a
		// comma. "Trust in Local News: Partisan Gaps Widen" becomes
		// "Trust in Local News, Partisan Gaps Widen".
		$title = preg_replace( '/\s*:\s*/', ', ', $title );
		$title = rtrim( $title, ' .' );

		/**
		 * Filter the publication named in the spoken attribution.
		 *
		 * Defaults to the site title, which is not always how an organization
		 * says its own name aloud.
		 *
		 * @param string   $source The publication name.
		 * @param \WP_Post $post   The post being narrated.
		 */
		$source = trim(
			(string) apply_filters(
				'prc_audio_narration_attribution_source',
				get_bloginfo( 'name' ),
				$post
			)
		);

		// "From X. Title." reads as a broadcast intro; the bare site name
		// followed by a title sounds like two disconnected fragments.
		$line = '' !== $source
			? sprintf( 'From %s. %s.', rtrim( $source, ' .' ), $title )
			: sprintf( '%s.', $title );

		/**
		 * Filter the complete spoken attribution line.
		 *
		 * @param string   $line The attribution line.
		 * @param \WP_Post $post The post being narrated.
		 */
		return (string) apply_filters( 'prc_audio_narration_attribution_line', $line, $post );
	}

	/**
	 * Prefix a script with its spoken attribution.
	 *
	 * Idempotent: a script that already opens with the attribution is left
	 * alone, so a cached script generated under an older format spec is not
	 * given a second one.
	 *
	 * @param \WP_Post $post   The post.
	 * @param string   $script The narration script.
	 * @return string
	 */
	public function with_attribution( \WP_Post $post, string $script ): string {
		$line   = $this->attribution_line( $post );
		$script = trim( $script );

		if ( '' === $line ) {
			return $script;
		}

		if ( 0 === strncasecmp( $script, $line, strlen( $line ) ) ) {
			return $script;
		}

		return $line . "\n\n" . $script;
	}

	/**
	 * Describe the narration state of a post.
	 *
	 * The single definition of what the editor panel, the REST payload, and
	 * the CLI status table all mean by these words, so they cannot drift
	 * apart and report a post differently depending on where you look.
	 *
	 * @param int        $post_id The post ID.
	 * @param array|null $record  Pre-fetched narration record, when available.
	 * @return string One of none, pending, stale, ready.
	 */
	public function describe_state( int $post_id, ?array $record = null ): string {
		if ( Action_Scheduler_Handler::is_pending( $post_id ) ) {
			return 'pending';
		}

		if ( null === $record ) {
			$record = $this->store->get( $post_id );
		}

		if ( null === $record ) {
			return 'none';
		}

		return $record['is_stale'] ? 'stale' : 'ready';
	}
}
