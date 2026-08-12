<?php
/**
 * TTS Orchestrator
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Application;

use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Request;
use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Response;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\TTS_Exception;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Provider_Unavailable_Exception;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Synthesis_Failed_Exception;
use PRC\Platform\Audio_Narration\TTS\Providers\TTS_Provider_Interface;

/**
 * Selects a text-to-speech provider and synthesizes through it.
 *
 * Providers are tried in priority order, lowest number first, skipping any
 * that report themselves unavailable. A provider that throws hands off to the
 * next one, so a transient outage at one vendor does not fail the job.
 */
class TTS_Orchestrator {

	/**
	 * Registered providers.
	 *
	 * @var TTS_Provider_Interface[]
	 */
	private $providers = array();

	/**
	 * Constructor.
	 *
	 * @param TTS_Provider_Interface[] $providers Providers to register.
	 */
	public function __construct( array $providers = array() ) {
		foreach ( $providers as $provider ) {
			$this->register( $provider );
		}
	}

	/**
	 * Register a provider.
	 *
	 * @param TTS_Provider_Interface $provider The provider to register.
	 * @return void
	 */
	public function register( TTS_Provider_Interface $provider ): void {
		$this->providers[] = $provider;
	}

	/**
	 * All registered providers, whether available or not.
	 *
	 * @return TTS_Provider_Interface[]
	 */
	public function get_providers(): array {
		return $this->providers;
	}

	/**
	 * Available providers in selection order.
	 *
	 * @return TTS_Provider_Interface[]
	 */
	public function get_available_providers(): array {
		$available = array_values(
			array_filter(
				$this->providers,
				static fn( TTS_Provider_Interface $provider ) => $provider->is_available()
			)
		);

		usort(
			$available,
			static fn( TTS_Provider_Interface $a, TTS_Provider_Interface $b ) => $a->get_priority() <=> $b->get_priority()
		);

		return $available;
	}

	/**
	 * The provider that would handle the next synthesis.
	 *
	 * @return TTS_Provider_Interface|null Null when none are available.
	 */
	public function get_active_provider(): ?TTS_Provider_Interface {
		$available = $this->get_available_providers();

		return $available[0] ?? null;
	}

	/**
	 * Maximum characters the active provider accepts in one request.
	 *
	 * @return int Zero when no provider is available.
	 */
	public function get_max_characters(): int {
		$provider = $this->get_active_provider();

		return $provider ? $provider->get_max_characters() : 0;
	}

	/**
	 * Estimate the cost of synthesizing a number of characters.
	 *
	 * Returns zero rather than throwing when nothing is configured, because
	 * this runs on admin screens that must still render before a key is set.
	 *
	 * @param int $characters Character count.
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( int $characters ): float {
		$provider = $this->get_active_provider();

		return $provider ? $provider->estimate_cost( $characters ) : 0.0;
	}

	/**
	 * Synthesize a request, falling back through providers on failure.
	 *
	 * @param TTS_Request $request The synthesis request.
	 * @return TTS_Response
	 * @throws Provider_Unavailable_Exception When no provider is available.
	 * @throws TTS_Exception When every available provider fails.
	 */
	public function synthesize( TTS_Request $request ): TTS_Response {
		$available = $this->get_available_providers();

		if ( empty( $available ) ) {
			throw new Provider_Unavailable_Exception(
				'No text-to-speech provider is configured and available. Check that an API key is set.'
			);
		}

		$failures  = array();
		$retryable = false;

		foreach ( $available as $provider ) {
			try {
				return $provider->synthesize( $request );
			} catch ( TTS_Exception $e ) {
				$failures[] = sprintf( '%s: %s', $provider->get_name(), $e->getMessage() );

				// If any provider failed for a transient reason, the job as a
				// whole is worth retrying. Collapsing this to a flat false
				// would make the scheduler give up on a passing rate limit.
				$retryable = $retryable || $e->is_retryable();
			}
		}

		// Name every provider and its reason. A bare "synthesis failed" gives
		// an operator nothing to act on.
		throw new Synthesis_Failed_Exception(
			sprintf(
				'Every text-to-speech provider failed. %s',
				implode( ' | ', $failures )
			),
			$retryable
		);
	}
}
