<?php
/**
 * Class ScriptChunkerTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\TTS\Application\Script_Chunker;

/**
 * Tests for narration script chunking.
 */
class ScriptChunkerTest extends WP_UnitTestCase {

	/**
	 * Chunker under test.
	 *
	 * @var Script_Chunker
	 */
	private $chunker;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->chunker = new Script_Chunker();
	}

	/**
	 * Assert no chunk exceeds the ceiling.
	 *
	 * @param array $chunks Chunks.
	 * @param int   $max    Ceiling.
	 */
	private function assertWithinCeiling( array $chunks, int $max ) {
		foreach ( $chunks as $index => $chunk ) {
			$this->assertLessThanOrEqual(
				$max,
				mb_strlen( $chunk['text'] ),
				sprintf( 'Chunk %d exceeds the %d character ceiling.', $index, $max )
			);
		}
	}

	/**
	 * A script under the ceiling stays whole.
	 */
	public function test_short_script_is_one_chunk() {
		$chunks = $this->chunker->chunk( 'A short narration script.', 5000 );

		$this->assertCount( 1, $chunks );
		$this->assertEquals( 'A short narration script.', $chunks[0]['text'] );
	}

	/**
	 * An empty script produces no chunks rather than one empty chunk.
	 *
	 * @dataProvider blank_script_provider
	 *
	 * @param string $script Blank-ish script.
	 */
	public function test_blank_script_produces_no_chunks( $script ) {
		$this->assertCount( 0, $this->chunker->chunk( $script, 5000 ) );
	}

	/**
	 * Blank scripts.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function blank_script_provider() {
		return array(
			'empty'    => array( '' ),
			'spaces'   => array( '   ' ),
			'newlines' => array( "\n\n\n" ),
		);
	}

	/**
	 * An oversized script splits into several chunks within the ceiling.
	 */
	public function test_long_script_splits_within_ceiling() {
		$paragraph = str_repeat( 'This is a sentence of narration. ', 10 );
		$script    = implode( "\n\n", array_fill( 0, 12, trim( $paragraph ) ) );

		$chunks = $this->chunker->chunk( $script, 500 );

		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertWithinCeiling( $chunks, 500 );
	}

	/**
	 * Paragraph boundaries are preferred when they fit.
	 *
	 * Splitting mid-paragraph is audible; splitting between paragraphs is not.
	 */
	public function test_prefers_paragraph_boundaries() {
		$a = str_repeat( 'Alpha sentence here. ', 5 );
		$b = str_repeat( 'Bravo sentence here. ', 5 );

		$chunks = $this->chunker->chunk( trim( $a ) . "\n\n" . trim( $b ), 130 );

		$this->assertCount( 2, $chunks );
		$this->assertStringStartsWith( 'Alpha', $chunks[0]['text'] );
		$this->assertStringStartsWith( 'Bravo', $chunks[1]['text'] );
		$this->assertStringNotContainsString( 'Bravo', $chunks[0]['text'] );
	}

	/**
	 * Several small paragraphs pack into one chunk rather than one each.
	 *
	 * Every extra chunk is an extra request and an extra seam, so packing
	 * them tightly matters for both cost and audio quality.
	 */
	public function test_packs_multiple_paragraphs_into_one_chunk() {
		$script = "First short one.\n\nSecond short one.\n\nThird short one.";

		$chunks = $this->chunker->chunk( $script, 5000 );

		$this->assertCount( 1, $chunks );
		$this->assertStringContainsString( 'First', $chunks[0]['text'] );
		$this->assertStringContainsString( 'Third', $chunks[0]['text'] );
	}

	/**
	 * A paragraph over the ceiling falls back to sentence boundaries.
	 */
	public function test_oversized_paragraph_splits_on_sentences() {
		$script = 'Alpha sentence one. Bravo sentence two. Charlie sentence three. Delta sentence four.';

		$chunks = $this->chunker->chunk( $script, 45 );

		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertWithinCeiling( $chunks, 45 );

		// No chunk should begin mid-sentence, i.e. with a lowercase word.
		foreach ( $chunks as $chunk ) {
			$this->assertMatchesRegularExpression(
				'/^[A-Z]/',
				$chunk['text'],
				'A chunk began mid-sentence: ' . $chunk['text']
			);
		}
	}

	/**
	 * A single sentence over the ceiling still splits, on word boundaries.
	 */
	public function test_oversized_sentence_splits_on_words() {
		$script = str_repeat( 'word ', 100 ) . 'end.';

		$chunks = $this->chunker->chunk( $script, 50 );

		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertWithinCeiling( $chunks, 50 );

		foreach ( $chunks as $chunk ) {
			$this->assertStringNotContainsString( '  ', $chunk['text'] );
		}
	}

	/**
	 * A single word longer than the ceiling is emitted rather than dropped.
	 *
	 * Pathological, but an infinite loop or silent data loss here would be
	 * much worse than an ugly split.
	 */
	public function test_word_longer_than_ceiling_is_not_dropped() {
		$word   = str_repeat( 'x', 250 );
		$chunks = $this->chunker->chunk( $word, 50 );

		$this->assertWithinCeiling( $chunks, 50 );

		$rejoined = implode( '', array_column( $chunks, 'text' ) );
		$this->assertEquals( $word, $rejoined );
	}

	/**
	 * Every chunk carries its neighbours' text for prosody continuity.
	 */
	public function test_chunks_carry_neighbor_context() {
		$a = str_repeat( 'Alpha sentence here. ', 5 );
		$b = str_repeat( 'Bravo sentence here. ', 5 );
		$c = str_repeat( 'Charlie sentence here. ', 5 );

		$chunks = $this->chunker->chunk(
			trim( $a ) . "\n\n" . trim( $b ) . "\n\n" . trim( $c ),
			130
		);

		$this->assertCount( 3, $chunks );

		// First chunk has nothing before it, last has nothing after it.
		$this->assertSame( '', $chunks[0]['preceding_text'] );
		$this->assertSame( '', $chunks[2]['following_text'] );

		// The middle chunk sees both neighbours.
		$this->assertStringContainsString( 'Alpha', $chunks[1]['preceding_text'] );
		$this->assertStringContainsString( 'Charlie', $chunks[1]['following_text'] );
	}

	/**
	 * Neighbour context is bounded so it cannot dominate the request.
	 */
	public function test_neighbor_context_is_bounded() {
		$long = str_repeat( 'Sentence of text here. ', 200 );

		$chunks = $this->chunker->chunk( trim( $long ), 900 );

		$this->assertGreaterThan( 1, count( $chunks ) );

		foreach ( $chunks as $chunk ) {
			$this->assertLessThanOrEqual( Script_Chunker::CONTEXT_LENGTH, mb_strlen( $chunk['preceding_text'] ) );
			$this->assertLessThanOrEqual( Script_Chunker::CONTEXT_LENGTH, mb_strlen( $chunk['following_text'] ) );
		}
	}

	/**
	 * No content is lost across a split.
	 */
	public function test_no_content_is_lost() {
		$script = implode(
			"\n\n",
			array(
				'Alpha sentence one. Alpha sentence two.',
				'Bravo sentence one. Bravo sentence two.',
				'Charlie sentence one. Charlie sentence two.',
			)
		);

		$chunks = $this->chunker->chunk( $script, 60 );

		$rejoined = preg_replace( '/\s+/', ' ', implode( ' ', array_column( $chunks, 'text' ) ) );
		$expected = preg_replace( '/\s+/', ' ', $script );

		$this->assertEquals( trim( $expected ), trim( $rejoined ) );
	}

	/**
	 * A nonsensical ceiling does not hang or produce empty chunks.
	 */
	public function test_non_positive_ceiling_is_survivable() {
		$chunks = $this->chunker->chunk( 'Some narration text.', 0 );

		$this->assertNotEmpty( $chunks );
		foreach ( $chunks as $chunk ) {
			$this->assertNotSame( '', $chunk['text'] );
		}
	}

	/**
	 * Multibyte text is measured in characters, not bytes.
	 */
	public function test_multibyte_is_measured_in_characters() {
		$script = str_repeat( '“Quoted” — dashed sentence. ', 20 );

		$chunks = $this->chunker->chunk( trim( $script ), 100 );

		$this->assertWithinCeiling( $chunks, 100 );
	}
}
