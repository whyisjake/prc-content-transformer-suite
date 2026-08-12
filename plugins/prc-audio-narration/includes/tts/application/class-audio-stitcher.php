<?php
/**
 * Audio Stitcher
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Application;

use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Response;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Synthesis_Failed_Exception;

/**
 * Combines per-chunk audio segments into one continuous file.
 *
 * MP3 is a frame-based container: concatenating the frame streams of two
 * files encoded with identical settings produces a valid, playable file, which
 * is why every request pins the same output format. Any ID3 tag a provider
 * prepends to a segment would otherwise land in the middle of the stream, so
 * tags are stripped from all but the first segment.
 */
class Audio_Stitcher {

	/**
	 * Combine synthesis responses into a single audio payload.
	 *
	 * @param TTS_Response[] $responses Responses in playback order.
	 * @return string The combined audio payload.
	 * @throws Synthesis_Failed_Exception When there is nothing to stitch or
	 *                                    the segments are not compatible.
	 */
	public function stitch( array $responses ): string {
		$responses = array_values( $responses );

		if ( empty( $responses ) ) {
			throw new Synthesis_Failed_Exception( 'There are no audio segments to combine.', false );
		}

		if ( 1 === count( $responses ) ) {
			return $responses[0]->get_audio();
		}

		$mime_types = array_unique(
			array_map(
				static fn( TTS_Response $response ) => $response->get_mime_type(),
				$responses
			)
		);

		if ( count( $mime_types ) > 1 ) {
			// Concatenating differently encoded segments yields a file that
			// plays only up to the first format change, which is worse than
			// failing outright.
			throw new Synthesis_Failed_Exception(
				sprintf(
					'Cannot combine audio segments with mixed formats: %s.',
					implode( ', ', $mime_types )
				),
				false
			);
		}

		$combined = '';

		foreach ( $responses as $index => $response ) {
			$audio = $response->get_audio();

			if ( $index > 0 ) {
				$audio = $this->strip_leading_id3( $audio );
			}

			$combined .= $audio;
		}

		return $combined;
	}

	/**
	 * Total duration across segments, when every segment reports one.
	 *
	 * @param TTS_Response[] $responses Responses.
	 * @return float|null Null when any segment has no duration.
	 */
	public function total_duration( array $responses ): ?float {
		$total = 0.0;

		foreach ( $responses as $response ) {
			$duration = $response->get_duration();
			if ( null === $duration ) {
				return null;
			}
			$total += $duration;
		}

		return $total;
	}

	/**
	 * Remove a leading ID3v2 tag from an audio payload.
	 *
	 * @param string $audio Audio payload.
	 * @return string
	 */
	private function strip_leading_id3( string $audio ): string {
		if ( strlen( $audio ) < 10 || 'ID3' !== substr( $audio, 0, 3 ) ) {
			return $audio;
		}

		// ID3v2 size is four synchsafe bytes: seven bits of each.
		$bytes = unpack( 'C4', substr( $audio, 6, 4 ) );
		if ( ! is_array( $bytes ) || count( $bytes ) < 4 ) {
			return $audio;
		}

		$size = ( ( $bytes[1] & 0x7F ) << 21 )
			| ( ( $bytes[2] & 0x7F ) << 14 )
			| ( ( $bytes[3] & 0x7F ) << 7 )
			| ( $bytes[4] & 0x7F );

		$header_length = 10 + $size;

		return strlen( $audio ) > $header_length ? substr( $audio, $header_length ) : $audio;
	}
}
