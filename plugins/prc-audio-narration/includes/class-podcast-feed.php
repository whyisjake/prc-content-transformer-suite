<?php
/**
 * Podcast RSS feed.
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration;

/**
 * Publishes narrated articles as a podcast feed.
 *
 * Emits RSS 2.0 with the iTunes namespace, which is what Apple Podcasts and
 * Spotify both consume.
 */
class Podcast_Feed {

	/**
	 * Feed slug. The feed lives at /feed/{slug}/.
	 */
	const FEED = 'podcast';

	/**
	 * Transient holding the rendered feed.
	 */
	const CACHE_KEY = 'prc_audio_narration_podcast_feed';

	/**
	 * Option flag set at activation so rewrite rules are flushed once the
	 * feed has actually been registered.
	 */
	const FLUSH_FLAG = 'prc_audio_narration_flush_rewrite';

	/**
	 * Maximum episodes in the feed.
	 */
	const MAX_ITEMS = 300;

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

		$this->loader->add_action( 'init', $this, 'register_feed' );
		$this->loader->add_action( 'prc_audio_narration_stored', $this, 'flush_cache' );
		$this->loader->add_action( 'prc_audio_narration_deleted', $this, 'flush_cache' );

		// A post edit can make narration stale, which removes it from the feed.
		$this->loader->add_action( 'save_post', $this, 'flush_cache' );
	}

	/**
	 * Register the feed and flush rewrite rules once after activation.
	 *
	 * @return void
	 */
	public function register_feed() {
		add_feed( self::FEED, array( $this, 'render' ) );

		// Rules cannot be flushed at activation because the feed is not
		// registered until init; the flag defers it to the first request after.
		if ( get_option( self::FLUSH_FLAG ) ) {
			delete_option( self::FLUSH_FLAG );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * The feed URL.
	 *
	 * @return string
	 */
	public static function url(): string {
		return get_feed_link( self::FEED );
	}

	/**
	 * Clear the cached feed.
	 *
	 * @return void
	 */
	public function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Episodes for the feed, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_episodes(): array {
		$post_ids = $this->store->get_narrated_post_ids(
			array( 'posts_per_page' => self::MAX_ITEMS * 2 )
		);

		$episodes = array();

		foreach ( $post_ids as $post_id ) {
			if ( count( $episodes ) >= self::MAX_ITEMS ) {
				break;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
				continue;
			}

			$record = $this->store->get( $post_id );

			// Stale narration no longer matches the article. A subscriber
			// cannot tell, so it is withheld rather than published.
			if ( null === $record || $record['is_stale'] ) {
				continue;
			}

			// A queued regeneration means the current file is about to be
			// replaced; publishing it now would push an episode that changes
			// underneath subscribers.
			if ( Action_Scheduler_Handler::is_pending( $post_id ) ) {
				continue;
			}

			// Podcast clients reject or mis-scrub an enclosure whose declared
			// length is wrong, so an unreadable file is skipped outright
			// rather than advertised with a length of zero.
			if ( $record['byte_length'] <= 0 ) {
				continue;
			}

			$episodes[] = array(
				'post'   => $post,
				'record' => $record,
			);
		}

		return $episodes;
	}

	/**
	 * Render the feed.
	 *
	 * @return void
	 */
	public function render() {
		$cached = get_transient( self::CACHE_KEY );

		if ( ! is_string( $cached ) || '' === $cached ) {
			$cached = $this->build();
			set_transient( self::CACHE_KEY, $cached, HOUR_IN_SECONDS );
		}

		// Guarded because a notice emitted by any other plugin earlier in the
		// request would otherwise turn a served feed into a fatal.
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/rss+xml; charset=' . get_option( 'blog_charset' ), true );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped in build().
		echo $cached;
	}

	/**
	 * Build the feed XML.
	 *
	 * @return string
	 */
	public function build(): string {
		$episodes = $this->get_episodes();
		$explicit = 'true' === Settings::podcast( 'explicit' ) ? 'true' : 'false';
		$image    = Settings::podcast( 'image' );
		$email    = Settings::podcast( 'owner_email' );

		$xml  = '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>' . "\n";
		$xml .= '<rss version="2.0"' . "\n";
		$xml .= "\t" . 'xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd"' . "\n";
		$xml .= "\t" . 'xmlns:content="http://purl.org/rss/1.0/modules/content/"' . "\n";
		$xml .= "\t" . 'xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
		$xml .= "<channel>\n";

		$xml .= $this->tag( 'title', Settings::podcast( 'title' ) );
		$xml .= $this->tag( 'link', home_url( '/' ), true );
		$xml .= $this->tag( 'description', Settings::podcast( 'description' ) );
		$xml .= $this->tag( 'language', Settings::podcast( 'language' ) );
		$xml .= "\t" . '<atom:link href="' . esc_url( self::url() ) . '" rel="self" type="application/rss+xml" />' . "\n";
		$xml .= $this->tag( 'itunes:author', Settings::podcast( 'author' ) );
		$xml .= $this->tag( 'itunes:summary', Settings::podcast( 'description' ) );
		$xml .= "\t" . '<itunes:explicit>' . esc_html( $explicit ) . '</itunes:explicit>' . "\n";
		$xml .= "\t" . '<itunes:category text="' . esc_attr( Settings::podcast( 'category' ) ) . '" />' . "\n";

		if ( '' !== $image ) {
			$xml .= "\t" . '<itunes:image href="' . esc_url( $image ) . '" />' . "\n";
		}

		if ( '' !== $email ) {
			$xml .= "\t<itunes:owner>\n";
			$xml .= "\t" . $this->tag( 'itunes:name', Settings::podcast( 'author' ) );
			$xml .= "\t" . $this->tag( 'itunes:email', $email );
			$xml .= "\t</itunes:owner>\n";
		}

		foreach ( $episodes as $episode ) {
			$xml .= $this->item( $episode['post'], $episode['record'] );
		}

		$xml .= "</channel>\n</rss>\n";

		/**
		 * Filter the rendered podcast feed.
		 *
		 * @param string $xml      The feed XML.
		 * @param array  $episodes The episodes included.
		 */
		return (string) apply_filters( 'prc_audio_narration_podcast_feed', $xml, $episodes );
	}

	/**
	 * Render a single episode.
	 *
	 * @param \WP_Post $post   The source post.
	 * @param array    $record The narration record.
	 * @return string
	 */
	private function item( \WP_Post $post, array $record ): string {
		$summary = $this->summary( $post );

		$xml  = "\t<item>\n";
		$xml .= "\t" . $this->tag( 'title', get_the_title( $post ) );
		$xml .= "\t" . $this->tag( 'link', get_permalink( $post ), true );
		$xml .= "\t" . $this->tag( 'description', $summary );
		$xml .= "\t" . $this->tag( 'itunes:summary', $summary );
		$xml .= "\t\t" . '<pubDate>' . esc_html( mysql2date( DATE_RFC2822, $post->post_date_gmt, false ) ) . '</pubDate>' . "\n";

		// A stable identifier tied to the post, not the file. Regenerating
		// narration produces a new attachment URL, and a URL-based guid would
		// make clients treat that as a brand new episode.
		$xml .= "\t\t" . '<guid isPermaLink="false">prc-audio-narration-' . (int) $post->ID . '</guid>' . "\n";

		$xml .= "\t\t" . '<enclosure url="' . esc_url( $record['url'] ) . '"'
			. ' length="' . (int) $record['byte_length'] . '"'
			. ' type="' . esc_attr( $record['mime_type'] ? $record['mime_type'] : 'audio/mpeg' ) . '" />' . "\n";

		if ( '' !== $record['duration_formatted'] ) {
			$xml .= "\t\t" . '<itunes:duration>' . esc_html( $record['duration_formatted'] ) . '</itunes:duration>' . "\n";
		}

		$xml .= "\t" . $this->tag( 'itunes:author', Settings::podcast( 'author' ) );
		$xml .= "\t</item>\n";

		return $xml;
	}

	/**
	 * Episode summary, with a link back to the article.
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private function summary( \WP_Post $post ): string {
		$excerpt = has_excerpt( $post )
			? get_the_excerpt( $post )
			: wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 55 );

		$excerpt = trim( wp_strip_all_tags( (string) $excerpt ) );

		return $excerpt . "\n\n" . sprintf(
			/* translators: %s: article permalink */
			__( 'Read the full article: %s', 'prc-audio-narration' ),
			get_permalink( $post )
		);
	}

	/**
	 * Render a tag, wrapping free text in CDATA.
	 *
	 * @param string $name  Tag name.
	 * @param string $value Tag value.
	 * @param bool   $is_url Whether the value is a URL.
	 * @return string
	 */
	private function tag( string $name, string $value, bool $is_url = false ): string {
		$value = (string) $value;

		if ( '' === trim( $value ) ) {
			return '';
		}

		if ( $is_url ) {
			return "\t<{$name}>" . esc_url( $value ) . "</{$name}>\n";
		}

		// Entities are decoded first. WordPress texturizes titles and excerpts
		// into things like &amp; and &#8220;, and an XML parser does not decode
		// entities inside CDATA -- so without this a client would display the
		// literal text "Trust &amp; local news".
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );

		// CDATA rather than entity escaping: titles and excerpts routinely
		// contain ampersands and quotes, and podcast clients handle CDATA
		// more consistently than numeric entities.
		return "\t<{$name}><![CDATA[" . str_replace( ']]>', ']]&gt;', $value ) . "]]></{$name}>\n";
	}
}
