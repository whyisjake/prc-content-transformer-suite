<?php
/**
 * Class PodcastFeedTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Action_Scheduler_Handler;
use PRC\Platform\Audio_Narration\Loader;
use PRC\Platform\Audio_Narration\Narration_Store;
use PRC\Platform\Audio_Narration\Podcast_Feed;
use PRC\Platform\Audio_Narration\Settings;

/**
 * Tests for the podcast RSS feed.
 */
class PodcastFeedTest extends WP_UnitTestCase {

	/**
	 * Feed under test.
	 *
	 * @var Podcast_Feed
	 */
	private $feed;

	/**
	 * Narration storage.
	 *
	 * @var Narration_Store
	 */
	private $store;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();

		$this->store = new Narration_Store();
		$this->feed  = new Podcast_Feed( new Loader(), $this->store );

		delete_transient( Podcast_Feed::CACHE_KEY );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		delete_transient( Podcast_Feed::CACHE_KEY );
		delete_option( Settings::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * Create a published post with narration.
	 *
	 * @param string $title Post title.
	 * @param string $date  Post date.
	 * @return int
	 */
	private function make_narrated_post( string $title = 'A narrated article', string $date = '' ): int {
		$args = array(
			'post_title'   => $title,
			'post_content' => 'Seventy-two percent of Americans agree.',
			'post_status'  => 'publish',
		);

		if ( '' !== $date ) {
			$args['post_date']     = $date;
			$args['post_date_gmt'] = $date;
		}

		$post_id = self::factory()->post->create( $args );

		$this->store->store(
			$post_id,
			str_repeat( 'AUDIO', 200 ),
			array(
				'provider' => 'elevenlabs',
				'voice'    => 'voice-abc',
				'duration' => 716.0,
			)
		);

		return $post_id;
	}

	/**
	 * Parse the feed, failing the test if it is not well-formed XML.
	 *
	 * @param string $xml Feed XML.
	 * @return SimpleXMLElement
	 */
	private function parse( string $xml ): SimpleXMLElement {
		$previous = libxml_use_internal_errors( true );
		$parsed   = simplexml_load_string( $xml );
		$errors   = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$this->assertNotFalse(
			$parsed,
			'Feed is not well-formed XML: ' . ( $errors ? $errors[0]->message : 'unknown' )
		);

		return $parsed;
	}

	/**
	 * The feed is registered so /feed/podcast/ resolves.
	 */
	public function test_feed_is_registered() {
		global $wp_rewrite;

		$this->feed->register_feed();

		$this->assertContains( Podcast_Feed::FEED, $wp_rewrite->feeds );
	}

	/**
	 * The feed is well-formed RSS 2.0 with the iTunes namespace.
	 */
	public function test_feed_is_valid_rss() {
		$this->make_narrated_post();

		$rss = $this->parse( $this->feed->build() );

		$this->assertEquals( '2.0', (string) $rss['version'] );
		$this->assertNotEmpty( $rss->channel );
		$this->assertArrayHasKey( 'itunes', $rss->getNamespaces( true ) );
	}

	/**
	 * The rendered feed carries the RSS content type.
	 */
	public function test_render_sets_content_type() {
		$this->make_narrated_post();

		ob_start();
		$this->feed->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<rss', $output );

		// Headers cannot be asserted directly under PHPUnit; xdebug exposes
		// them where available, so this is checked opportunistically.
		if ( function_exists( 'xdebug_get_headers' ) ) {
			$this->assertStringContainsString(
				'application/rss+xml',
				implode( ' ', xdebug_get_headers() )
			);
		}
	}

	/**
	 * Each narrated article appears once, with an enclosure.
	 */
	public function test_narrated_article_appears_once_with_enclosure() {
		$this->make_narrated_post( 'Trust in local news' );

		$rss = $this->parse( $this->feed->build() );

		$this->assertCount( 1, $rss->channel->item );
		$this->assertEquals( 'Trust in local news', (string) $rss->channel->item[0]->title );
		$this->assertNotEmpty( (string) $rss->channel->item[0]->enclosure['url'] );
	}

	/**
	 * The enclosure length matches the file on disk.
	 *
	 * Podcast clients reject or mis-scrub an item whose declared length is
	 * wrong, so this must be the real byte count rather than an estimate.
	 */
	public function test_enclosure_length_matches_file() {
		$post_id = $this->make_narrated_post();
		$record  = $this->store->get( $post_id );

		$rss = $this->parse( $this->feed->build() );

		$this->assertEquals(
			$record['byte_length'],
			(int) $rss->channel->item[0]->enclosure['length']
		);
		$this->assertGreaterThan( 0, (int) $rss->channel->item[0]->enclosure['length'] );
	}

	/**
	 * The channel carries the iTunes tags Apple requires.
	 */
	public function test_channel_has_required_itunes_tags() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'podcast_title'       => 'PRC Narrated',
				'podcast_description' => 'Research, read aloud.',
				'podcast_author'      => 'Pew Research Center',
				'podcast_owner_email' => 'podcasts@example.org',
				'podcast_category'    => 'News',
				'podcast_explicit'    => 'false',
				'podcast_image'       => 'https://example.org/art.jpg',
			)
		);

		$this->make_narrated_post();

		$xml     = $this->feed->build();
		$rss     = $this->parse( $xml );
		$itunes  = $rss->channel->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );

		$this->assertEquals( 'PRC Narrated', (string) $rss->channel->title );
		$this->assertEquals( 'Pew Research Center', (string) $itunes->author );
		$this->assertEquals( 'false', (string) $itunes->explicit );
		$this->assertEquals( 'News', (string) $itunes->category->attributes()->text );
		$this->assertEquals( 'https://example.org/art.jpg', (string) $itunes->image->attributes()->href );
		$this->assertEquals( 'podcasts@example.org', (string) $itunes->owner->email );
	}

	/**
	 * Channel metadata falls back to site data.
	 *
	 * A feed missing a title or owner email fails validation at Apple, so it
	 * has to be submittable before anyone visits the settings screen.
	 */
	public function test_channel_defaults_to_site_data() {
		$this->make_narrated_post();

		$rss = $this->parse( $this->feed->build() );

		$this->assertEquals( get_bloginfo( 'name' ), (string) $rss->channel->title );

		$itunes = $rss->channel->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
		$this->assertEquals( get_option( 'admin_email' ), (string) $itunes->owner->email );
	}

	/**
	 * An empty feed is still valid RSS.
	 */
	public function test_empty_feed_is_valid() {
		$rss = $this->parse( $this->feed->build() );

		$this->assertEquals( '2.0', (string) $rss['version'] );
		$this->assertCount( 0, $rss->channel->item );
		$this->assertNotEmpty( (string) $rss->channel->title );
	}

	/**
	 * Stale narration stays in the feed by default.
	 *
	 * Removing an episode a subscriber has already downloaded is worse than
	 * leaving audio that trails a light edit to the article.
	 */
	public function test_stale_narration_stays_by_default() {
		$post_id = $this->make_narrated_post( 'Stale article' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Entirely different content.',
			)
		);

		$rss = $this->parse( $this->feed->build() );

		$this->assertCount( 1, $rss->channel->item );
	}

	/**
	 * Sites that opt in can withhold stale episodes instead.
	 */
	public function test_stale_narration_excluded_when_configured() {
		add_filter( 'prc_audio_narration_stale_behavior', fn() => 'hide' );

		$post_id = $this->make_narrated_post( 'Stale article' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Entirely different content.',
			)
		);

		$rss = $this->parse( $this->feed->build() );

		$this->assertCount( 0, $rss->channel->item );
	}

	/**
	 * A queued regeneration withholds the episode.
	 *
	 * The current file is about to be replaced; publishing it now would push
	 * an episode that changes underneath subscribers.
	 */
	public function test_pending_narration_is_excluded() {
		if ( ! Action_Scheduler_Handler::is_available() ) {
			$this->markTestSkipped( 'Action Scheduler is not available.' );
		}

		$post_id = $this->make_narrated_post();
		Action_Scheduler_Handler::schedule( $post_id );

		$rss = $this->parse( $this->feed->build() );

		$this->assertCount( 0, $rss->channel->item );
	}

	/**
	 * Items are ordered newest first.
	 */
	public function test_items_are_newest_first() {
		$this->make_narrated_post( 'Older article', '2026-01-01 09:00:00' );
		$this->make_narrated_post( 'Newer article', '2026-06-01 09:00:00' );

		$rss = $this->parse( $this->feed->build() );

		$this->assertCount( 2, $rss->channel->item );
		$this->assertEquals( 'Newer article', (string) $rss->channel->item[0]->title );
		$this->assertEquals( 'Older article', (string) $rss->channel->item[1]->title );
	}

	/**
	 * An article whose audio file is gone is skipped.
	 *
	 * Emitting an enclosure with a zero length is worse than omitting the
	 * episode, because clients treat it as a broken download.
	 */
	public function test_missing_audio_file_is_skipped() {
		$post_id = $this->make_narrated_post();
		$path    = get_attached_file( $this->store->get( $post_id )['attachment_id'] );

		// Remove the file but leave the attachment row behind.
		if ( $path && file_exists( $path ) ) {
			wp_delete_file( $path );
		}

		$rss = $this->parse( $this->feed->build() );

		$this->assertCount( 0, $rss->channel->item );
	}

	/**
	 * Drafts are excluded even when they carry narration.
	 */
	public function test_unpublished_posts_are_excluded() {
		$post_id = $this->make_narrated_post();
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		$rss = $this->parse( $this->feed->build() );

		$this->assertCount( 0, $rss->channel->item );
	}

	/**
	 * The episode guid is stable across regeneration.
	 *
	 * A guid derived from the file URL would make every regeneration look
	 * like a brand new episode to subscribers.
	 */
	public function test_guid_is_stable_across_regeneration() {
		$post_id = $this->make_narrated_post();

		$first = (string) $this->parse( $this->feed->build() )->channel->item[0]->guid;

		$this->store->store( $post_id, str_repeat( 'NEWAUDIO', 200 ) );
		$second = (string) $this->parse( $this->feed->build() )->channel->item[0]->guid;

		$this->assertEquals( $first, $second );
		$this->assertStringContainsString( (string) $post_id, $first );
	}

	/**
	 * Episodes carry a duration for the client scrubber.
	 */
	public function test_episode_has_duration() {
		$this->make_narrated_post();

		$rss    = $this->parse( $this->feed->build() );
		$itunes = $rss->channel->item[0]->children( 'http://www.itunes.com/dtds/podcast-1.0.dtd' );

		$this->assertNotEmpty( (string) $itunes->duration );
	}

	/**
	 * The episode summary links back to the article.
	 */
	public function test_episode_summary_links_to_article() {
		$post_id = $this->make_narrated_post();

		$rss = $this->parse( $this->feed->build() );

		$this->assertStringContainsString(
			get_permalink( $post_id ),
			(string) $rss->channel->item[0]->description
		);
	}

	/**
	 * Titles containing XML-significant characters do not break the feed.
	 *
	 * WordPress texturizes titles into entities like &amp; and &#8220;, and an
	 * XML parser does not decode entities inside CDATA. Without decoding them
	 * first, a client would display the literal text "Trust &amp; local news".
	 */
	public function test_special_characters_do_not_break_feed() {
		$this->make_narrated_post( 'Trust & "local" news' );

		$rss   = $this->parse( $this->feed->build() );
		$title = (string) $rss->channel->item[0]->title;

		$this->assertStringContainsString( 'Trust & ', $title );
		$this->assertStringNotContainsString( '&amp;', $title );
		$this->assertStringNotContainsString( '&#8220;', $title );
	}

	/**
	 * Missing artwork is reported.
	 *
	 * Apple rejects a feed without channel artwork, and nothing else catches
	 * it: the feed is structurally valid either way, so the failure would
	 * otherwise surface at submission.
	 */
	public function test_artwork_issue_when_unset() {
		$issues = Podcast_Feed::artwork_issues( '' );

		$this->assertNotEmpty( $issues );
		$this->assertStringContainsString( 'No artwork', $issues[0] );
	}

	/**
	 * An unreachable artwork URL is reported.
	 */
	public function test_artwork_issue_when_unreachable() {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array( 'code' => 404 ),
					'body'     => '',
				);
			}
		);

		$issues = Podcast_Feed::artwork_issues( 'https://example.org/missing-' . wp_rand() . '.png' );

		$this->assertNotEmpty( $issues );
		$this->assertStringContainsString( '404', $issues[0] );
	}

	/**
	 * Artwork that is too small is reported.
	 *
	 * Apple requires at least 1400 pixels square.
	 */
	public function test_artwork_issue_when_too_small() {
		$png = $this->square_png( 100 );

		add_filter(
			'pre_http_request',
			static function () use ( $png ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => $png,
				);
			}
		);

		$issues = Podcast_Feed::artwork_issues( 'https://example.org/small-' . wp_rand() . '.png' );

		$this->assertNotEmpty( $issues );
		$this->assertStringContainsString( '1400', implode( ' ', $issues ) );
	}

	/**
	 * Conforming artwork reports nothing.
	 */
	public function test_artwork_accepted_when_conforming() {
		$png = $this->square_png( 1400 );

		add_filter(
			'pre_http_request',
			static function () use ( $png ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => $png,
				);
			}
		);

		$this->assertSame(
			array(),
			Podcast_Feed::artwork_issues( 'https://example.org/ok-' . wp_rand() . '.png' )
		);
	}

	/**
	 * Build a square PNG of the given size.
	 *
	 * @param int $size Width and height in pixels.
	 * @return string Raw PNG bytes.
	 */
	private function square_png( int $size ): string {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			$this->markTestSkipped( 'GD is not available to build a test image.' );
		}

		$image = imagecreatetruecolor( $size, $size );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		imagedestroy( $image );

		return $bytes;
	}

	/**
	 * The feed is cached between renders.
	 */
	public function test_feed_is_cached() {
		$this->make_narrated_post();

		ob_start();
		$this->feed->render();
		ob_end_clean();

		$this->assertIsString( get_transient( Podcast_Feed::CACHE_KEY ) );
	}

	/**
	 * Storing narration invalidates the cache.
	 *
	 * Without this a new episode would not appear for up to an hour.
	 */
	public function test_storing_narration_flushes_cache() {
		set_transient( Podcast_Feed::CACHE_KEY, '<rss>stale</rss>', HOUR_IN_SECONDS );

		$this->feed->flush_cache();

		$this->assertFalse( get_transient( Podcast_Feed::CACHE_KEY ) );
	}

	/**
	 * A newly narrated post appears in the feed once the cache clears.
	 */
	public function test_new_narration_appears_after_invalidation() {
		$this->make_narrated_post( 'First article' );

		ob_start();
		$this->feed->render();
		ob_end_clean();

		$this->make_narrated_post( 'Second article' );
		$this->feed->flush_cache();

		ob_start();
		$this->feed->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Second article', $output );
	}
}
