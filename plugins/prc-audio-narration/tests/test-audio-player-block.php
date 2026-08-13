<?php
/**
 * Class AudioPlayerBlockTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Loader;
use PRC\Platform\Audio_Narration\Narration_Store;
use PRC\Platform\Audio_Narration\Player_Block;

/**
 * Tests for the front-end narration player block.
 */
class AudioPlayerBlockTest extends WP_UnitTestCase {

	/**
	 * Block under test.
	 *
	 * @var Player_Block
	 */
	private $block;

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
		$this->block = new Player_Block( new Loader(), $this->store );

		// The plugin registers the block on init. Registering again here would
		// trigger a doing_it_wrong notice, so this only fills in when the test
		// environment has not run that hook.
		if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( Player_Block::BLOCK ) ) {
			$this->block->register();
		}
	}

	/**
	 * Create a published post with narration.
	 *
	 * @return int
	 */
	private function make_narrated_post(): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Trust in local news',
				'post_content' => 'Seventy-two percent of Americans agree.',
				'post_status'  => 'publish',
			)
		);

		$this->store->store(
			$post_id,
			str_repeat( 'AUDIO', 100 ),
			array(
				'provider' => 'elevenlabs',
				'voice'    => 'voice-abc',
				'duration' => 716.0,
			)
		);

		return $post_id;
	}

	/**
	 * The block is registered and therefore available in the inserter.
	 */
	public function test_block_is_registered() {
		$this->assertTrue(
			\WP_Block_Type_Registry::get_instance()->is_registered( Player_Block::BLOCK )
		);
	}

	/**
	 * The block is dynamic, so nothing is saved into post content.
	 */
	public function test_block_is_dynamic() {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( Player_Block::BLOCK );

		$this->assertTrue( $type->is_dynamic() );
	}

	/**
	 * A narrated post renders an audio element pointing at the attachment.
	 */
	public function test_renders_audio_for_narrated_post() {
		$post_id = $this->make_narrated_post();
		$record  = $this->store->get( $post_id );

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$html = $this->block->render();

		$this->assertStringContainsString( '<audio', $html );
		$this->assertStringContainsString( esc_url( $record['url'] ), $html );
		$this->assertStringContainsString( 'controls', $html );
	}

	/**
	 * A post with no narration renders nothing.
	 *
	 * An empty player is worse than no player.
	 */
	public function test_renders_nothing_without_narration() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->assertSame( '', $this->block->render() );
	}

	/**
	 * Stale narration keeps playing by default.
	 *
	 * Editing an article is routine. Pulling the player on every save would
	 * hand an editorial decision to a hash comparison.
	 */
	public function test_stale_narration_still_renders_by_default() {
		$post_id = $this->make_narrated_post();

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Entirely different content.',
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->assertStringContainsString( '<audio', $this->block->render() );
	}

	/**
	 * Sites that opt in can withhold stale narration instead.
	 */
	public function test_stale_narration_hidden_when_configured() {
		add_filter( 'prc_audio_narration_stale_behavior', fn() => 'hide' );

		$post_id = $this->make_narrated_post();

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Entirely different content.',
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->assertSame( '', $this->block->render() );
	}

	/**
	 * Outside a post context the block renders nothing rather than erroring.
	 */
	public function test_renders_nothing_without_a_post() {
		$this->assertSame( '', $this->block->render() );
	}

	/**
	 * The caption defaults to a readable invitation.
	 */
	public function test_default_caption() {
		$post_id = $this->make_narrated_post();
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->assertStringContainsString( 'Listen to this article', $this->block->render() );
	}

	/**
	 * A custom caption replaces the default.
	 */
	public function test_custom_caption() {
		$post_id = $this->make_narrated_post();
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$html = $this->block->render( array( 'label' => 'Hear the findings' ) );

		$this->assertStringContainsString( 'Hear the findings', $html );
		$this->assertStringNotContainsString( 'Listen to this article', $html );
	}

	/**
	 * Duration is shown by default and can be turned off.
	 */
	public function test_duration_can_be_hidden() {
		$post_id = $this->make_narrated_post();
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->assertStringContainsString( '11:56', $this->block->render() );
		$this->assertStringNotContainsString(
			'11:56',
			$this->block->render( array( 'showDuration' => false ) )
		);
	}

	/**
	 * The control is labelled with the article it plays.
	 *
	 * A screen reader user landing on it hears the article, not a bare "audio".
	 */
	public function test_player_has_accessible_label() {
		$post_id = $this->make_narrated_post();
		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$html = $this->block->render();

		$this->assertStringContainsString( 'aria-label=', $html );
		$this->assertStringContainsString( 'Trust in local news', $html );
	}

	/**
	 * Loop context wins over the global post.
	 *
	 * Inside a query loop the global post is not the post being rendered, so
	 * ignoring context would play the wrong article's audio.
	 */
	public function test_uses_loop_context_over_global_post() {
		$narrated = $this->make_narrated_post();
		$other    = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->go_to( get_permalink( $other ) );
		the_post();

		$block = new WP_Block(
			array( 'blockName' => Player_Block::BLOCK ),
			array( 'postId' => $narrated )
		);

		$html = $this->block->render( array(), '', $block );

		$this->assertStringContainsString( esc_url( $this->store->get( $narrated )['url'] ), $html );
	}

	/**
	 * Rendering through the block registry produces the same markup.
	 *
	 * Exercises the registered render callback rather than calling the method
	 * directly, which is what actually runs on the front end.
	 */
	public function test_renders_through_the_block_registry() {
		$post_id = $this->make_narrated_post();

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$html = do_blocks( '<!-- wp:prc-audio-narration/player /-->' );

		$this->assertStringContainsString( '<audio', $html );
		$this->assertStringContainsString( 'wp-block-audio', $html );
	}

	/**
	 * The wrapper carries the sync class however the block is rendered.
	 *
	 * The front-end script finds players by that class. It used to be dropped
	 * on the registry path -- the one that actually runs on the front end --
	 * which left the players unable to see each other.
	 */
	public function test_wrapper_carries_the_sync_class_in_both_render_paths() {
		$post_id = $this->make_narrated_post();

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$this->assertStringContainsString(
			'prc-audio-narration-player',
			$this->block->render(),
			'Direct render should carry the sync class.'
		);

		$this->assertStringContainsString(
			'prc-audio-narration-player',
			do_blocks( '<!-- wp:prc-audio-narration/player /-->' ),
			'Registry render should carry the sync class.'
		);
	}

	/**
	 * A post may carry more than one player.
	 *
	 * A long article wants one near the top and another at the end, so the
	 * block must not declare supports.multiple false -- that greys it out in
	 * the inserter once a single copy exists anywhere in the post.
	 */
	public function test_a_post_may_carry_more_than_one_player() {
		$type = \WP_Block_Type_Registry::get_instance()->get_registered( 'prc-audio-narration/player' );

		$this->assertNotNull( $type, 'The block should be registered.' );
		$this->assertNotFalse(
			$type->supports['multiple'] ?? true,
			'The inserter should allow a second player.'
		);

		$post_id = $this->make_narrated_post();

		$this->go_to( get_permalink( $post_id ) );
		the_post();

		$html = do_blocks(
			'<!-- wp:prc-audio-narration/player /-->' .
			'<!-- wp:prc-audio-narration/player /-->'
		);

		$this->assertSame( 2, substr_count( $html, '<audio' ) );
	}

	/**
	 * A post whose narration is removed stops rendering the player.
	 */
	public function test_stops_rendering_after_narration_is_deleted() {
		$post_id = $this->make_narrated_post();

		$this->go_to( get_permalink( $post_id ) );
		the_post();
		$this->assertStringContainsString( '<audio', $this->block->render() );

		$this->store->delete( $post_id );

		$this->assertSame( '', $this->block->render() );
	}
}
