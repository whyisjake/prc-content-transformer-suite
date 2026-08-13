<?php
/**
 * Class NarrationServiceTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\Narration_Service;
use PRC\Platform\Audio_Narration\Narration_Store;
use PRC\Platform\Audio_Narration\Settings;
use PRC\Platform\Audio_Narration\TTS\Application\TTS_Orchestrator;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Rate_Limit_Exception;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Synthesis_Failed_Exception;

require_once __DIR__ . '/helpers/class-fake-tts-provider.php';

use PRC\Platform\Audio_Narration\Tests\Fake_TTS_Provider;

/**
 * End-to-end tests for the narration service with a fake provider.
 */
class NarrationServiceTest extends WP_UnitTestCase {

	/**
	 * Set up a default voice.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Settings::OPTION_KEY, array( 'voice_id' => 'test-voice' ) );
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_KEY );
		parent::tear_down();
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
	 * Build a service backed by a fake provider and a stub script.
	 *
	 * @param string                $script   The narration script.
	 * @param Fake_TTS_Provider|null $provider Provider override.
	 * @return Narration_Service
	 */
	private function service( string $script, ?Fake_TTS_Provider $provider = null ): Narration_Service {
		$provider = $provider ?? new Fake_TTS_Provider( 'fake' );

		return new Narration_Service(
			new TTS_Orchestrator( array( $provider ) ),
			new Narration_Store(),
			static fn( $post_id, $force ) => $script
		);
	}

	/**
	 * A short script produces one stored attachment.
	 */
	public function test_generates_and_stores_narration() {
		$post_id = $this->make_post();
		$service = $this->service( 'A short narration script.' );

		$record = $service->generate( $post_id );

		$this->assertIsArray( $record );
		$this->assertNotEmpty( $record['url'] );
		$this->assertEquals( 'fake', $record['provider'] );
		$this->assertEquals( 'test-voice', $record['voice'] );
		$this->assertEquals( $post_id, wp_get_post_parent_id( $record['attachment_id'] ) );
	}

	/**
	 * A long script is chunked, synthesized per chunk, and stitched.
	 */
	public function test_long_script_is_chunked_and_stitched() {
		$post_id  = $this->make_post();
		$provider = new Fake_TTS_Provider( 'fake', array( 'max_characters' => 60 ) );
		$script   = implode( "\n\n", array_fill( 0, 6, 'A paragraph of narration text here.' ) );

		$service = $this->service( $script, $provider );
		$record  = $service->generate( $post_id );

		$this->assertIsArray( $record );
		$this->assertGreaterThan( 1, $provider->call_count, 'A long script should require several requests.' );
		$this->assertGreaterThan( 0, $record['byte_length'] );
	}

	/**
	 * Neighbour context reaches the provider on a chunked job.
	 */
	public function test_chunked_job_passes_neighbor_context() {
		$post_id  = $this->make_post();
		$provider = new Fake_TTS_Provider( 'fake', array( 'max_characters' => 60 ) );
		$script   = implode( "\n\n", array_fill( 0, 4, 'A paragraph of narration text here.' ) );

		$this->service( $script, $provider )->generate( $post_id );

		$this->assertGreaterThan( 1, count( $provider->requests ) );
		$this->assertSame( '', $provider->requests[0]->get_preceding_text() );
		$this->assertNotSame( '', $provider->requests[1]->get_preceding_text() );
	}

	/**
	 * A per-request voice overrides the site default.
	 */
	public function test_voice_override_is_used_and_recorded() {
		$post_id  = $this->make_post();
		$provider = new Fake_TTS_Provider( 'fake' );

		$record = $this->service( 'A script.', $provider )
			->generate( $post_id, array( 'voice_id' => 'special-voice' ) );

		$this->assertEquals( 'special-voice', $record['voice'] );
		$this->assertEquals( 'special-voice', $provider->last_request->get_voice_id() );
	}

	/**
	 * A failure partway through a chunked job stores nothing.
	 *
	 * A truncated narration is worse than none, because nothing downstream
	 * can tell that it is incomplete.
	 */
	public function test_midjob_failure_stores_nothing() {
		$post_id  = $this->make_post();
		$provider = new Fake_TTS_Provider(
			'fake',
			array(
				'max_characters' => 60,
				'throws'         => new Synthesis_Failed_Exception( 'upstream died' ),
			)
		);
		$script   = implode( "\n\n", array_fill( 0, 5, 'A paragraph of narration text here.' ) );

		$result = $this->service( $script, $provider )->generate( $post_id );

		$this->assertWPError( $result );
		$this->assertFalse( ( new Narration_Store() )->has_narration( $post_id ) );
	}

	/**
	 * A failed regeneration leaves the existing narration in place.
	 */
	public function test_failed_regeneration_preserves_existing_narration() {
		$post_id = $this->make_post();

		$good   = $this->service( 'First good script.' );
		$record = $good->generate( $post_id );
		$this->assertIsArray( $record );

		$failing = $this->service(
			'Second script.',
			new Fake_TTS_Provider( 'fake', array( 'throws' => new Synthesis_Failed_Exception( 'nope' ) ) )
		);

		$this->assertWPError( $failing->generate( $post_id ) );

		$still = ( new Narration_Store() )->get( $post_id );
		$this->assertIsArray( $still );
		$this->assertEquals( $record['attachment_id'], $still['attachment_id'] );
	}

	/**
	 * Retryability is carried through so the scheduler can act on it.
	 */
	public function test_error_carries_retryable_flag() {
		$post_id  = $this->make_post();
		$provider = new Fake_TTS_Provider( 'fake', array( 'throws' => new Rate_Limit_Exception() ) );

		$result = $this->service( 'A script.', $provider )->generate( $post_id );

		$this->assertWPError( $result );
		$this->assertTrue( $result->get_error_data()['retryable'] );
	}

	/**
	 * An empty script is refused before any request is made.
	 */
	public function test_empty_script_is_refused() {
		$post_id  = $this->make_post();
		$provider = new Fake_TTS_Provider( 'fake' );

		$result = $this->service( '   ', $provider )->generate( $post_id );

		$this->assertWPError( $result );
		$this->assertEquals( 'prc_audio_narration_empty_script', $result->get_error_code() );
		$this->assertEquals( 0, $provider->call_count );
	}

	/**
	 * A script error propagates rather than being swallowed.
	 */
	public function test_script_error_propagates() {
		$post_id = $this->make_post();
		$service = new Narration_Service(
			new TTS_Orchestrator( array( new Fake_TTS_Provider( 'fake' ) ) ),
			new Narration_Store(),
			static fn() => new WP_Error( 'script_failed', 'The model refused.' )
		);

		$result = $service->generate( $post_id );

		$this->assertWPError( $result );
		$this->assertEquals( 'script_failed', $result->get_error_code() );
	}

	/**
	 * Generation without a configured provider fails cleanly.
	 */
	public function test_no_provider_fails_cleanly() {
		$post_id = $this->make_post();
		$service = new Narration_Service(
			new TTS_Orchestrator( array() ),
			new Narration_Store(),
			static fn() => 'A script.'
		);

		$result = $service->generate( $post_id );

		$this->assertWPError( $result );
		$this->assertEquals( 'prc_audio_narration_no_provider', $result->get_error_code() );
	}

	/**
	 * Generating for a nonexistent post fails cleanly.
	 */
	public function test_missing_post_fails_cleanly() {
		$this->assertWPError( $this->service( 'A script.' )->generate( 999999 ) );
	}

	/**
	 * Cost estimation reports characters, chunk count, and price.
	 */
	public function test_estimate_reports_cost_and_chunks() {
		$post_id  = $this->make_post();
		$provider = new Fake_TTS_Provider(
			'fake',
			array(
				'max_characters' => 60,
				'rate'           => 0.001,
			)
		);
		$script   = implode( "\n\n", array_fill( 0, 4, 'A paragraph of narration text here.' ) );

		$service  = $this->service( $script, $provider );
		$estimate = $service->estimate( $post_id );

		// The estimate covers the attribution line too, since that is part of
		// what gets synthesized and billed.
		$expected = mb_strlen( $service->with_attribution( get_post( $post_id ), $script ) );

		$this->assertEquals( $expected, $estimate['characters'] );
		$this->assertGreaterThan( 1, $estimate['chunks'] );
		$this->assertEqualsWithDelta( $expected * 0.001, $estimate['estimated_cost'], 0.000001 );
		$this->assertEquals( 'fake', $estimate['provider'] );
	}

	/**
	 * Estimation makes no synthesis calls.
	 *
	 * The editor screen shows a cost before generation; estimating must never
	 * itself be billable.
	 */
	public function test_estimate_does_not_synthesize() {
		$post_id  = $this->make_post();
		$provider = new Fake_TTS_Provider( 'fake' );

		$this->service( 'A script.', $provider )->estimate( $post_id );

		$this->assertEquals( 0, $provider->call_count );
	}

	/**
	 * Generated narration starts fresh, and a later edit makes it stale.
	 */
	public function test_generated_narration_tracks_staleness() {
		$post_id = $this->make_post();
		$this->service( 'A script.' )->generate( $post_id );

		$store = new Narration_Store();
		$this->assertFalse( $store->is_stale( $post_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Different content entirely.',
			)
		);

		$this->assertTrue( $store->is_stale( $post_id ) );
	}

	/**
	 * The spoken attribution names the publication and the full title.
	 */
	public function test_attribution_line_names_source_and_title() {
		$post_id = $this->make_post();
		$line    = $this->service( 'x' )->attribution_line( get_post( $post_id ) );

		$this->assertStringContainsString( get_bloginfo( 'name' ), $line );
		$this->assertStringContainsString( 'Trust in local news', $line );
	}

	/**
	 * A colon in the title is spoken as a comma.
	 *
	 * A colon reads as an abrupt stop; the subtitle should run on.
	 */
	public function test_attribution_line_speaks_colon_as_comma() {
		$post_id = self::factory()->post->create(
			array( 'post_title' => 'Trust in Local News: Partisan Gaps Widen' )
		);

		$line = $this->service( 'x' )->attribution_line( get_post( $post_id ) );

		$this->assertStringContainsString( 'Trust in Local News, Partisan Gaps Widen', $line );
		$this->assertStringNotContainsString( ':', $line );
	}

	/**
	 * The whole title survives, including anything after a colon.
	 *
	 * This is the failure that moved the line out of the prompt: the model
	 * reliably dropped the half of the title carrying the actual finding.
	 */
	public function test_attribution_line_keeps_subtitle() {
		$post_id = self::factory()->post->create(
			array( 'post_title' => 'Trust in Local News: Partisan Gaps Widen' )
		);

		$this->assertStringContainsString(
			'Partisan Gaps Widen',
			$this->service( 'x' )->attribution_line( get_post( $post_id ) )
		);
	}

	/**
	 * A post with no title yields no attribution rather than a stray period.
	 */
	public function test_attribution_line_empty_without_title() {
		$post_id = self::factory()->post->create( array( 'post_title' => '' ) );

		$this->assertSame( '', $this->service( 'x' )->attribution_line( get_post( $post_id ) ) );
	}

	/**
	 * The publication name is filterable.
	 *
	 * The site title is not always how an organization says its name aloud.
	 */
	public function test_attribution_source_is_filterable() {
		$post_id = $this->make_post();

		add_filter( 'prc_audio_narration_attribution_source', fn() => 'the Pew Research Center' );

		$this->assertStringStartsWith(
			'From the Pew Research Center.',
			$this->service( 'x' )->attribution_line( get_post( $post_id ) )
		);
	}

	/**
	 * The generated script opens with the attribution.
	 */
	public function test_generated_script_opens_with_attribution() {
		$post_id  = $this->make_post();
		$provider = new Fake_TTS_Provider( 'fake' );

		$this->service( 'A majority of Americans agree.', $provider )->generate( $post_id );

		$sent = $provider->last_request->get_text();

		$this->assertStringStartsWith( 'From ' . get_bloginfo( 'name' ), $sent );
		$this->assertStringContainsString( 'Trust in local news', $sent );
		$this->assertStringContainsString( 'A majority of Americans agree.', $sent );
	}

	/**
	 * Attribution is not added twice.
	 *
	 * Cached scripts written under an older spec may already open with it.
	 */
	public function test_attribution_is_not_duplicated() {
		$post_id = $this->make_post();
		$service = $this->service( 'x' );
		$line    = $service->attribution_line( get_post( $post_id ) );

		$already = $line . "\n\nThe article body.";
		$result  = $service->with_attribution( get_post( $post_id ), $already );

		$this->assertEquals( 1, substr_count( $result, $line ) );
	}

	/**
	 * The cost estimate covers the attribution that will be synthesized.
	 */
	public function test_estimate_includes_attribution() {
		$post_id = $this->make_post();
		$script  = 'A majority of Americans agree.';

		$service  = $this->service( $script );
		$estimate = $service->estimate( $post_id );

		$this->assertGreaterThan( mb_strlen( $script ), $estimate['characters'] );
	}

	/**
	 * Deleting through the service removes the narration.
	 */
	public function test_delete_removes_narration() {
		$post_id = $this->make_post();
		$service = $this->service( 'A script.' );
		$service->generate( $post_id );

		$this->assertTrue( $service->delete( $post_id ) );
		$this->assertFalse( ( new Narration_Store() )->has_narration( $post_id ) );
	}
}
