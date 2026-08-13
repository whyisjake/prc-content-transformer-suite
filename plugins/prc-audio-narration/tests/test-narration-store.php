<?php
/**
 * Class NarrationStoreTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Narration_Store;

/**
 * Tests for narration attachment storage and staleness tracking.
 */
class NarrationStoreTest extends WP_UnitTestCase {

	/**
	 * Store under test.
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
	}

	/**
	 * Create a published post.
	 *
	 * @return int
	 */
	private function make_post(): int {
		return self::factory()->post->create(
			array(
				'post_title'   => 'Trust in local news',
				'post_content' => 'Seventy-two percent of Americans agree.',
				'post_status'  => 'publish',
			)
		);
	}

	/**
	 * A post with no narration reports none.
	 */
	public function test_reports_no_narration_initially() {
		$post_id = $this->make_post();

		$this->assertFalse( $this->store->has_narration( $post_id ) );
		$this->assertNull( $this->store->get( $post_id ) );
	}

	/**
	 * Storing creates an attachment parented to the post.
	 */
	public function test_store_creates_attachment_parented_to_post() {
		$post_id       = $this->make_post();
		$attachment_id = $this->store->store( $post_id, 'AUDIO_BYTES' );

		$this->assertIsInt( $attachment_id );
		$this->assertEquals( $post_id, wp_get_post_parent_id( $attachment_id ) );
		$this->assertEquals( 'attachment', get_post_type( $attachment_id ) );
	}

	/**
	 * Stored metadata is retrievable.
	 */
	public function test_store_records_metadata() {
		$post_id = $this->make_post();

		$this->store->store(
			$post_id,
			'AUDIO_BYTES',
			array(
				'provider'   => 'elevenlabs',
				'voice'      => 'voice-abc',
				'duration'   => 12.5,
				'characters' => 400,
			)
		);

		$record = $this->store->get( $post_id );

		$this->assertEquals( 'elevenlabs', $record['provider'] );
		$this->assertEquals( 'voice-abc', $record['voice'] );
		$this->assertEqualsWithDelta( 12.5, $record['duration'], 0.001 );
		$this->assertEquals( 400, $record['characters'] );
		$this->assertNotEmpty( $record['url'] );
		$this->assertNotEmpty( $record['generated'] );
	}

	/**
	 * Byte length is read from the file on disk.
	 *
	 * The podcast feed's enclosure length must match the real file exactly.
	 */
	public function test_byte_length_matches_file() {
		$post_id = $this->make_post();
		$audio   = str_repeat( 'a', 2048 );

		$this->store->store( $post_id, $audio );

		$this->assertEquals( 2048, $this->store->get( $post_id )['byte_length'] );
	}

	/**
	 * A duration is null when neither the provider nor the file supplies one.
	 */
	public function test_duration_may_be_absent() {
		$post_id = $this->make_post();

		$this->store->store( $post_id, 'AUDIO', array( 'duration' => null ) );

		$this->assertNull( $this->store->get( $post_id )['duration'] );
		$this->assertSame( '', $this->store->get( $post_id )['duration_formatted'] );
	}

	/**
	 * Duration falls back to what WordPress extracted from the file.
	 *
	 * ElevenLabs does not report a duration, so without this fallback the
	 * field would be permanently empty -- and the podcast feed needs it.
	 * getID3 already computes it when the file is attached, which is more
	 * trustworthy than deriving it from byte length.
	 */
	public function test_duration_falls_back_to_attachment_metadata() {
		$post_id       = $this->make_post();
		$attachment_id = $this->store->store( $post_id, 'AUDIO', array( 'duration' => null ) );

		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'length'           => 716,
				'length_formatted' => '11:56',
				'mime_type'        => 'audio/mpeg',
			)
		);

		$record = $this->store->get( $post_id );

		$this->assertEqualsWithDelta( 716.0, $record['duration'], 0.001 );
		$this->assertEquals( '11:56', $record['duration_formatted'] );
	}

	/**
	 * A provider-reported duration wins over the extracted one.
	 */
	public function test_provider_duration_takes_precedence() {
		$post_id       = $this->make_post();
		$attachment_id = $this->store->store( $post_id, 'AUDIO', array( 'duration' => 120.5 ) );

		wp_update_attachment_metadata( $attachment_id, array( 'length' => 716 ) );

		$this->assertEqualsWithDelta( 120.5, $this->store->get( $post_id )['duration'], 0.001 );
	}

	/**
	 * Duration is formatted for podcast clients when the file lacks a string.
	 *
	 * @dataProvider duration_format_provider
	 *
	 * @param float  $seconds  Duration in seconds.
	 * @param string $expected Expected formatting.
	 */
	public function test_duration_formatting( $seconds, $expected ) {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO', array( 'duration' => $seconds ) );

		$this->assertEquals( $expected, $this->store->get( $post_id )['duration_formatted'] );
	}

	/**
	 * Durations and their expected rendering.
	 *
	 * @return array<string, array{0: float, 1: string}>
	 */
	public function duration_format_provider() {
		return array(
			'under a minute' => array( 45.0, '0:45' ),
			'minutes'        => array( 716.0, '11:56' ),
			'rounds up'      => array( 716.6, '11:57' ),
			'over an hour'   => array( 3725.0, '1:02:05' ),
		);
	}

	/**
	 * Storing an empty payload is refused.
	 */
	public function test_refuses_empty_audio() {
		$post_id = $this->make_post();

		$result = $this->store->store( $post_id, '' );

		$this->assertWPError( $result );
		$this->assertFalse( $this->store->has_narration( $post_id ) );
	}

	/**
	 * Storing against a nonexistent post is refused.
	 */
	public function test_refuses_missing_post() {
		$this->assertWPError( $this->store->store( 999999, 'AUDIO' ) );
	}

	/**
	 * Fresh narration is not stale.
	 */
	public function test_fresh_narration_is_not_stale() {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO' );

		$this->assertFalse( $this->store->is_stale( $post_id ) );
		$this->assertFalse( $this->store->get( $post_id )['is_stale'] );
	}

	/**
	 * Editing the content makes narration stale.
	 */
	public function test_content_edit_makes_narration_stale() {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Sixty-two percent of Americans agree.',
			)
		);

		$this->assertTrue( $this->store->is_stale( $post_id ) );
	}

	/**
	 * Editing only the title also makes narration stale.
	 *
	 * The title is spoken in the opening attribution line, so a title change
	 * genuinely invalidates the audio.
	 */
	public function test_title_edit_makes_narration_stale() {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO' );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'A different headline',
			)
		);

		$this->assertTrue( $this->store->is_stale( $post_id ) );
	}

	/**
	 * A post with no narration is not reported stale.
	 */
	public function test_unnarrated_post_is_not_stale() {
		$this->assertFalse( $this->store->is_stale( $this->make_post() ) );
	}

	/**
	 * Regenerating replaces the attachment rather than accumulating files.
	 */
	public function test_regeneration_deletes_previous_attachment() {
		$post_id = $this->make_post();

		$first = $this->store->store( $post_id, 'FIRST_AUDIO' );
		$this->assertInstanceOf( WP_Post::class, get_post( $first ) );

		$second = $this->store->store( $post_id, 'SECOND_AUDIO' );

		$this->assertNotEquals( $first, $second );
		$this->assertNull( get_post( $first ), 'The previous attachment should have been deleted.' );
		$this->assertEquals( $second, $this->store->get( $post_id )['attachment_id'] );
	}

	/**
	 * Out-of-date narration is still publishable by default.
	 *
	 * Editing an article is routine; withholding audio on every save would
	 * hand an editorial decision to a hash comparison.
	 */
	public function test_stale_narration_is_publishable_by_default() {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Changed.',
			)
		);

		$this->assertTrue( $this->store->is_stale( $post_id ) );
		$this->assertTrue( $this->store->should_publish( $post_id ) );
	}

	/**
	 * Sites can opt into withholding it instead.
	 */
	public function test_stale_narration_withheld_when_configured() {
		add_filter( 'prc_audio_narration_stale_behavior', fn() => 'hide' );

		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Changed.',
			)
		);

		$this->assertFalse( $this->store->should_publish( $post_id ) );
	}

	/**
	 * A post with no narration is never publishable.
	 */
	public function test_unnarrated_post_is_not_publishable() {
		$this->assertFalse( $this->store->should_publish( $this->make_post() ) );
	}

	/**
	 * Accepting current content clears the out-of-date state.
	 *
	 * The common newsroom case: an edit that does not change what is spoken.
	 */
	public function test_acknowledge_clears_stale_state() {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'A small correction.',
			)
		);
		$this->assertTrue( $this->store->is_stale( $post_id ) );

		$this->assertTrue( $this->store->acknowledge( $post_id ) );

		$this->assertFalse( $this->store->is_stale( $post_id ) );
	}

	/**
	 * Accepting does not touch the audio itself.
	 */
	public function test_acknowledge_keeps_the_same_attachment() {
		$post_id       = $this->make_post();
		$attachment_id = $this->store->store( $post_id, 'AUDIO' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'A small correction.',
			)
		);
		$this->store->acknowledge( $post_id );

		$this->assertEquals( $attachment_id, $this->store->get( $post_id )['attachment_id'] );
	}

	/**
	 * A later edit makes it stale again.
	 *
	 * Accepting one edit must not permanently silence the warning.
	 */
	public function test_acknowledge_only_covers_the_current_content() {
		$post_id = $this->make_post();
		$this->store->store( $post_id, 'AUDIO' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'First edit.',
			)
		);
		$this->store->acknowledge( $post_id );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Second edit.',
			)
		);

		$this->assertTrue( $this->store->is_stale( $post_id ) );
	}

	/**
	 * There is nothing to accept without narration.
	 */
	public function test_acknowledge_without_narration_is_a_no_op() {
		$this->assertFalse( $this->store->acknowledge( $this->make_post() ) );
	}

	/**
	 * Deleting removes the attachment and all metadata.
	 */
	public function test_delete_removes_attachment_and_meta() {
		$post_id       = $this->make_post();
		$attachment_id = $this->store->store( $post_id, 'AUDIO' );

		$this->assertTrue( $this->store->delete( $post_id ) );

		$this->assertNull( get_post( $attachment_id ) );
		$this->assertNull( $this->store->get( $post_id ) );
		$this->assertSame( '', get_post_meta( $post_id, Narration_Store::META_HASH, true ) );
	}

	/**
	 * Deleting a post that has no narration is a harmless no-op.
	 */
	public function test_delete_without_narration_is_safe() {
		$this->assertFalse( $this->store->delete( $this->make_post() ) );
	}

	/**
	 * An attachment deleted directly from the Media Library reads as absent.
	 *
	 * Otherwise the meta box and feed would advertise a dead URL.
	 */
	public function test_orphaned_meta_reads_as_no_narration() {
		$post_id       = $this->make_post();
		$attachment_id = $this->store->store( $post_id, 'AUDIO' );

		wp_delete_attachment( $attachment_id, true );

		$this->assertNull( $this->store->get( $post_id ) );
		$this->assertFalse( $this->store->has_narration( $post_id ) );
	}

	/**
	 * Narrated posts are discoverable for the feed and status reports.
	 */
	public function test_lists_narrated_posts() {
		$narrated   = $this->make_post();
		$unnarrated = $this->make_post();

		$this->store->store( $narrated, 'AUDIO' );

		$ids = $this->store->get_narrated_post_ids();

		$this->assertContains( $narrated, $ids );
		$this->assertNotContains( $unnarrated, $ids );
	}

	/**
	 * Storing fires an action other modules can observe.
	 */
	public function test_store_fires_action() {
		$fired   = array();
		$post_id = $this->make_post();

		add_action(
			'prc_audio_narration_stored',
			function ( $stored_post_id, $attachment_id ) use ( &$fired ) {
				$fired = array( $stored_post_id, $attachment_id );
			},
			10,
			2
		);

		$attachment_id = $this->store->store( $post_id, 'AUDIO' );

		$this->assertEquals( array( $post_id, $attachment_id ), $fired );
	}
}
