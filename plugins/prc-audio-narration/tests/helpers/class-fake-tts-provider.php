<?php
/**
 * Configurable fake TTS provider for tests.
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\Tests;

use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Request;
use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Response;
use PRC\Platform\Audio_Narration\TTS\Providers\TTS_Provider_Interface;

/**
 * A TTS provider that synthesizes nothing and records how it was called.
 *
 * Lets orchestration, chunking, and storage be tested end to end without a
 * network call or an API key.
 */
class Fake_TTS_Provider implements TTS_Provider_Interface {

	/**
	 * Provider name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Behaviour configuration.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Number of times synthesize() was called.
	 *
	 * @var int
	 */
	public $call_count = 0;

	/**
	 * The most recent request received.
	 *
	 * @var TTS_Request|null
	 */
	public $last_request = null;

	/**
	 * Every request received, in order.
	 *
	 * @var TTS_Request[]
	 */
	public $requests = array();

	/**
	 * Constructor.
	 *
	 * @param string $name   Provider name.
	 * @param array  $config Behaviour overrides: priority, available, throws,
	 *                       max_characters, rate, audio.
	 */
	public function __construct( string $name = 'fake', array $config = array() ) {
		$this->name   = $name;
		$this->config = array_merge(
			array(
				'priority'       => 10,
				'available'      => true,
				'throws'         => null,
				'max_characters' => 5000,
				'rate'           => 0.0001,
				'audio'          => null,
			),
			$config
		);
	}

	/**
	 * Synthesize speech, or fail if configured to.
	 *
	 * @param TTS_Request $request The synthesis request.
	 * @return TTS_Response
	 * @throws \Throwable When configured to throw.
	 */
	public function synthesize( TTS_Request $request ): TTS_Response {
		++$this->call_count;
		$this->last_request = $request;
		$this->requests[]   = $request;

		if ( $this->config['throws'] instanceof \Throwable ) {
			throw $this->config['throws'];
		}

		$audio = $this->config['audio'];
		if ( null === $audio ) {
			// Deterministic stand-in payload derived from the text, so stitched
			// output can be asserted against its inputs.
			$audio = '[' . $this->name . ':' . $request->get_text() . ']';
		}

		return new TTS_Response(
			$audio,
			'audio/mpeg',
			$this->name,
			$request->get_character_count()
		);
	}

	/**
	 * Estimate cost for a character count.
	 *
	 * @param int $characters Character count.
	 * @return float
	 */
	public function estimate_cost( int $characters ): float {
		return $characters * (float) $this->config['rate'];
	}

	/**
	 * Whether the provider is configured and ready.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return (bool) $this->config['available'];
	}

	/**
	 * Selection priority; lower wins.
	 *
	 * @return int
	 */
	public function get_priority(): int {
		return (int) $this->config['priority'];
	}

	/**
	 * Provider name.
	 *
	 * @return string
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Maximum characters accepted in a single request.
	 *
	 * @return int
	 */
	public function get_max_characters(): int {
		return (int) $this->config['max_characters'];
	}
}
