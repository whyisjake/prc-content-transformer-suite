<?php
/**
 * TTS Provider Interface
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Providers;

use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Request;
use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Response;

/**
 * Contract for all text-to-speech providers.
 */
interface TTS_Provider_Interface {

	/**
	 * Synthesize speech for a request.
	 *
	 * @param TTS_Request $request The synthesis request.
	 * @return TTS_Response The synthesized audio.
	 * @throws \PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\TTS_Exception On failure.
	 */
	public function synthesize( TTS_Request $request ): TTS_Response;

	/**
	 * Estimate the cost of synthesizing a number of characters.
	 *
	 * @param int $characters Character count.
	 * @return float Estimated cost in USD.
	 */
	public function estimate_cost( int $characters ): float;

	/**
	 * Whether the provider is configured and ready.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Selection priority. Lower numbers are preferred.
	 *
	 * @return int
	 */
	public function get_priority(): int;

	/**
	 * Provider name, used in logs and stored alongside generated audio.
	 *
	 * @return string
	 */
	public function get_name(): string;

	/**
	 * Maximum characters this provider accepts in a single request.
	 *
	 * Chunk sizing is a property of the provider rather than a global
	 * constant, so swapping providers cannot silently produce oversized
	 * requests.
	 *
	 * @return int
	 */
	public function get_max_characters(): int;
}
