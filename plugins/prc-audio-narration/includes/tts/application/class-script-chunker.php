<?php
/**
 * Script Chunker
 *
 * @package PRC_Audio_Narration
 */

namespace PRC\Platform\Audio_Narration\TTS\Application;

/**
 * Splits a narration script into provider-sized chunks.
 *
 * Report-length content exceeds every provider's per-request ceiling, so
 * chunking is a normal part of synthesis rather than an edge case. Boundaries
 * are chosen to be inaudible: paragraph breaks first, sentence breaks second,
 * and a mid-sentence split only when a single sentence is itself too long.
 */
class Script_Chunker {

	/**
	 * Characters of neighbouring text handed to the provider for prosody.
	 */
	const CONTEXT_LENGTH = 300;

	/**
	 * Split a script into chunks.
	 *
	 * @param string $script         The narration script.
	 * @param int    $max_characters Maximum characters per chunk.
	 * @return array<int, array{text: string, preceding_text: string, following_text: string}>
	 */
	public function chunk( string $script, int $max_characters ): array {
		$script = trim( $script );

		if ( '' === $script ) {
			return array();
		}

		if ( $max_characters <= 0 ) {
			$max_characters = 1;
		}

		$texts = mb_strlen( $script ) <= $max_characters
			? array( $script )
			: $this->split( $script, $max_characters );

		return $this->attach_context( $texts );
	}

	/**
	 * Split a script that exceeds the ceiling.
	 *
	 * @param string $script         The narration script.
	 * @param int    $max_characters Maximum characters per chunk.
	 * @return string[]
	 */
	private function split( string $script, int $max_characters ): array {
		$chunks  = array();
		$current = '';

		foreach ( $this->paragraphs( $script ) as $paragraph ) {
			$candidate = '' === $current ? $paragraph : $current . "\n\n" . $paragraph;

			if ( mb_strlen( $candidate ) <= $max_characters ) {
				$current = $candidate;
				continue;
			}

			// The accumulated text is as large as it can get; bank it.
			if ( '' !== $current ) {
				$chunks[] = $current;
				$current  = '';
			}

			if ( mb_strlen( $paragraph ) <= $max_characters ) {
				$current = $paragraph;
				continue;
			}

			// A single paragraph over the ceiling falls back to sentences.
			foreach ( $this->split_sentences( $paragraph, $max_characters ) as $piece ) {
				$chunks[] = $piece;
			}
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Split an oversized paragraph on sentence boundaries.
	 *
	 * @param string $paragraph      The paragraph.
	 * @param int    $max_characters Maximum characters per chunk.
	 * @return string[]
	 */
	private function split_sentences( string $paragraph, int $max_characters ): array {
		$chunks  = array();
		$current = '';

		foreach ( $this->sentences( $paragraph ) as $sentence ) {
			$candidate = '' === $current ? $sentence : $current . ' ' . $sentence;

			if ( mb_strlen( $candidate ) <= $max_characters ) {
				$current = $candidate;
				continue;
			}

			if ( '' !== $current ) {
				$chunks[] = $current;
				$current  = '';
			}

			if ( mb_strlen( $sentence ) <= $max_characters ) {
				$current = $sentence;
				continue;
			}

			// A single sentence over the ceiling has no inaudible boundary
			// left; split on whitespace so at least no word is cut in half.
			foreach ( $this->split_hard( $sentence, $max_characters ) as $piece ) {
				$chunks[] = $piece;
			}
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Last-resort split of a single oversized sentence.
	 *
	 * @param string $sentence       The sentence.
	 * @param int    $max_characters Maximum characters per chunk.
	 * @return string[]
	 */
	private function split_hard( string $sentence, int $max_characters ): array {
		$chunks  = array();
		$current = '';

		foreach ( preg_split( '/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
			$candidate = '' === $current ? $word : $current . ' ' . $word;

			if ( mb_strlen( $candidate ) <= $max_characters ) {
				$current = $candidate;
				continue;
			}

			if ( '' !== $current ) {
				$chunks[] = $current;
			}

			// A single word longer than the ceiling is pathological, but it
			// must still be emitted rather than dropped or looped on.
			if ( mb_strlen( $word ) > $max_characters ) {
				foreach ( $this->split_fixed( $word, $max_characters ) as $piece ) {
					$chunks[] = $piece;
				}
				$current = '';
				continue;
			}

			$current = $word;
		}

		if ( '' !== $current ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Split a string at fixed character intervals.
	 *
	 * @param string $text           The text.
	 * @param int    $max_characters Maximum characters per chunk.
	 * @return string[]
	 */
	private function split_fixed( string $text, int $max_characters ): array {
		$chunks = array();
		$length = mb_strlen( $text );

		for ( $offset = 0; $offset < $length; $offset += $max_characters ) {
			$chunks[] = mb_substr( $text, $offset, $max_characters );
		}

		return $chunks;
	}

	/**
	 * Split a script into paragraphs.
	 *
	 * @param string $script The script.
	 * @return string[]
	 */
	private function paragraphs( string $script ): array {
		$parts = preg_split( '/\n\s*\n/u', $script, -1, PREG_SPLIT_NO_EMPTY );

		return array_values(
			array_filter(
				array_map( 'trim', $parts ?: array() ),
				static fn( $paragraph ) => '' !== $paragraph
			)
		);
	}

	/**
	 * Split a paragraph into sentences.
	 *
	 * Splits after terminal punctuation followed by whitespace. Abbreviations
	 * can fool this, but a chunk boundary landing after "Dr." is a slightly
	 * early pause rather than a defect -- and the audio-script format spec
	 * already expands most abbreviations away.
	 *
	 * @param string $paragraph The paragraph.
	 * @return string[]
	 */
	private function sentences( string $paragraph ): array {
		$parts = preg_split( '/(?<=[.!?])\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY );

		return array_values(
			array_filter(
				array_map( 'trim', $parts ?: array() ),
				static fn( $sentence ) => '' !== $sentence
			)
		);
	}

	/**
	 * Attach neighbouring context to each chunk.
	 *
	 * @param string[] $texts Chunk texts in order.
	 * @return array<int, array{text: string, preceding_text: string, following_text: string}>
	 */
	private function attach_context( array $texts ): array {
		$texts  = array_values( $texts );
		$chunks = array();
		$count  = count( $texts );

		foreach ( $texts as $index => $text ) {
			$preceding = $index > 0
				? mb_substr( $texts[ $index - 1 ], -self::CONTEXT_LENGTH )
				: '';
			$following = $index < $count - 1
				? mb_substr( $texts[ $index + 1 ], 0, self::CONTEXT_LENGTH )
				: '';

			$chunks[] = array(
				'text'           => $text,
				'preceding_text' => $preceding,
				'following_text' => $following,
			);
		}

		return $chunks;
	}
}
