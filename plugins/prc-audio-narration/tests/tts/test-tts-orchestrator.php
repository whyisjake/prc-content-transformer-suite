<?php
/**
 * Class TTSOrchestratorTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\TTS\Application\TTS_Orchestrator;
use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Request;
use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Response;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\TTS_Exception;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Provider_Unavailable_Exception;
use PRC\Platform\Audio_Narration\TTS\Providers\TTS_Provider_Interface;

require_once __DIR__ . '/../helpers/class-fake-tts-provider.php';

use PRC\Platform\Audio_Narration\Tests\Fake_TTS_Provider;

/**
 * Tests for provider selection and fallback.
 */
class TTSOrchestratorTest extends WP_UnitTestCase {

	/**
	 * Build a request for synthesis.
	 *
	 * @return TTS_Request
	 */
	private function request() {
		return new TTS_Request( 'A narration chunk.' );
	}

	/**
	 * The lowest priority number wins when several providers are available.
	 */
	public function test_selects_lowest_priority_number() {
		$low  = new Fake_TTS_Provider( 'low', array( 'priority' => 5 ) );
		$high = new Fake_TTS_Provider( 'high', array( 'priority' => 50 ) );

		$orchestrator = new TTS_Orchestrator( array( $high, $low ) );
		$response     = $orchestrator->synthesize( $this->request() );

		$this->assertEquals( 'low', $response->get_provider_name() );
	}

	/**
	 * Unavailable providers are skipped during selection.
	 */
	public function test_skips_unavailable_providers() {
		$unavailable = new Fake_TTS_Provider(
			'unavailable',
			array(
				'priority'  => 1,
				'available' => false,
			)
		);
		$available   = new Fake_TTS_Provider( 'available', array( 'priority' => 10 ) );

		$orchestrator = new TTS_Orchestrator( array( $unavailable, $available ) );

		$this->assertEquals( 'available', $orchestrator->synthesize( $this->request() )->get_provider_name() );
		$this->assertEquals( 0, $unavailable->call_count, 'An unavailable provider must never be called.' );
	}

	/**
	 * When the preferred provider throws, the next available one runs.
	 */
	public function test_falls_back_when_provider_throws() {
		$failing  = new Fake_TTS_Provider(
			'failing',
			array(
				'priority' => 1,
				'throws'   => new TTS_Exception( 'upstream exploded' ),
			)
		);
		$fallback = new Fake_TTS_Provider( 'fallback', array( 'priority' => 2 ) );

		$orchestrator = new TTS_Orchestrator( array( $failing, $fallback ) );
		$response     = $orchestrator->synthesize( $this->request() );

		$this->assertEquals( 'fallback', $response->get_provider_name() );
		$this->assertEquals( 1, $failing->call_count );
	}

	/**
	 * When every provider fails, the surfaced error names all attempts.
	 *
	 * A bare "synthesis failed" gives an operator nothing to act on; naming
	 * each provider and its reason is the difference between a debuggable
	 * failure and a mystery.
	 */
	public function test_reports_all_failures_when_none_succeed() {
		$first  = new Fake_TTS_Provider(
			'first',
			array(
				'priority' => 1,
				'throws'   => new TTS_Exception( 'bad credentials' ),
			)
		);
		$second = new Fake_TTS_Provider(
			'second',
			array(
				'priority' => 2,
				'throws'   => new TTS_Exception( 'rate limited' ),
			)
		);

		$orchestrator = new TTS_Orchestrator( array( $first, $second ) );

		try {
			$orchestrator->synthesize( $this->request() );
			$this->fail( 'Expected a TTS_Exception when all providers fail.' );
		} catch ( TTS_Exception $e ) {
			$this->assertStringContainsString( 'first', $e->getMessage() );
			$this->assertStringContainsString( 'bad credentials', $e->getMessage() );
			$this->assertStringContainsString( 'second', $e->getMessage() );
			$this->assertStringContainsString( 'rate limited', $e->getMessage() );
		}
	}

	/**
	 * An empty registry raises a clear error rather than dereferencing null.
	 */
	public function test_empty_registry_raises() {
		$this->expectException( Provider_Unavailable_Exception::class );

		( new TTS_Orchestrator( array() ) )->synthesize( $this->request() );
	}

	/**
	 * A registry where every provider is unavailable raises the same error.
	 */
	public function test_all_unavailable_raises() {
		$orchestrator = new TTS_Orchestrator(
			array(
				new Fake_TTS_Provider( 'a', array( 'available' => false ) ),
				new Fake_TTS_Provider( 'b', array( 'available' => false ) ),
			)
		);

		$this->expectException( Provider_Unavailable_Exception::class );

		$orchestrator->synthesize( $this->request() );
	}

	/**
	 * The active provider is the one whose limits and costs are reported.
	 */
	public function test_reports_active_provider_metadata() {
		$preferred = new Fake_TTS_Provider(
			'preferred',
			array(
				'priority'       => 1,
				'max_characters' => 2500,
				'rate'           => 0.0001,
			)
		);
		$other     = new Fake_TTS_Provider(
			'other',
			array(
				'priority'       => 9,
				'max_characters' => 99999,
				'rate'           => 0.5,
			)
		);

		$orchestrator = new TTS_Orchestrator( array( $other, $preferred ) );

		$this->assertEquals( 'preferred', $orchestrator->get_active_provider()->get_name() );
		$this->assertEquals( 2500, $orchestrator->get_max_characters() );
		$this->assertEqualsWithDelta( 0.01, $orchestrator->estimate_cost( 100 ), 0.000001 );
	}

	/**
	 * Cost estimation on an empty registry is zero rather than an error.
	 *
	 * Estimation runs on admin screens where no key may be configured yet;
	 * throwing there would break the settings page instead of showing nothing.
	 */
	public function test_estimate_cost_without_providers_is_zero() {
		$orchestrator = new TTS_Orchestrator( array() );

		$this->assertEquals( 0.0, $orchestrator->estimate_cost( 1000 ) );
		$this->assertNull( $orchestrator->get_active_provider() );
	}

	/**
	 * Providers can be registered after construction.
	 */
	public function test_register_adds_provider() {
		$orchestrator = new TTS_Orchestrator( array() );
		$orchestrator->register( new Fake_TTS_Provider( 'late', array( 'priority' => 3 ) ) );

		$this->assertEquals( 'late', $orchestrator->synthesize( $this->request() )->get_provider_name() );
	}

	/**
	 * The request reaches the provider intact.
	 */
	public function test_passes_request_through_to_provider() {
		$provider     = new Fake_TTS_Provider( 'recorder' );
		$orchestrator = new TTS_Orchestrator( array( $provider ) );

		$request = new TTS_Request(
			'The middle chunk.',
			array(
				'voice_id'       => 'voice-xyz',
				'preceding_text' => 'Before.',
			)
		);
		$orchestrator->synthesize( $request );

		$this->assertSame( $request, $provider->last_request );
		$this->assertEquals( 'voice-xyz', $provider->last_request->get_voice_id() );
		$this->assertEquals( 'Before.', $provider->last_request->get_preceding_text() );
	}
}
