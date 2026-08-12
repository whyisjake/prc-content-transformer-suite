<?php
/**
 * Class AudioStitcherTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\TTS\Application\Audio_Stitcher;
use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Response;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\Synthesis_Failed_Exception;

/**
 * Tests for combining per-chunk audio into one file.
 */
class AudioStitcherTest extends WP_UnitTestCase {

	/**
	 * Stitcher under test.
	 *
	 * @var Audio_Stitcher
	 */
	private $stitcher;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->stitcher = new Audio_Stitcher();
	}

	/**
	 * Build a response.
	 *
	 * @param string     $audio     Audio payload.
	 * @param string     $mime      MIME type.
	 * @param float|null $duration  Duration.
	 * @return TTS_Response
	 */
	private function response( string $audio, string $mime = 'audio/mpeg', ?float $duration = null ): TTS_Response {
		return new TTS_Response( $audio, $mime, 'fake', mb_strlen( $audio ), $duration );
	}

	/**
	 * A single segment passes through untouched.
	 */
	public function test_single_segment_is_unchanged() {
		$this->assertEquals( 'ONLY', $this->stitcher->stitch( array( $this->response( 'ONLY' ) ) ) );
	}

	/**
	 * Segments are concatenated in order.
	 */
	public function test_segments_are_concatenated_in_order() {
		$combined = $this->stitcher->stitch(
			array(
				$this->response( 'AAA' ),
				$this->response( 'BBB' ),
				$this->response( 'CCC' ),
			)
		);

		$this->assertEquals( 'AAABBBCCC', $combined );
	}

	/**
	 * The combined length is the sum of its parts.
	 */
	public function test_combined_length_is_sum_of_parts() {
		$segments = array(
			$this->response( str_repeat( 'a', 100 ) ),
			$this->response( str_repeat( 'b', 250 ) ),
		);

		$this->assertEquals( 350, strlen( $this->stitcher->stitch( $segments ) ) );
	}

	/**
	 * An empty segment list fails rather than producing an empty file.
	 */
	public function test_empty_segment_list_raises() {
		$this->expectException( Synthesis_Failed_Exception::class );

		$this->stitcher->stitch( array() );
	}

	/**
	 * Mixed formats fail rather than producing a file that stops early.
	 */
	public function test_mixed_formats_raise() {
		$this->expectException( Synthesis_Failed_Exception::class );

		$this->stitcher->stitch(
			array(
				$this->response( 'AAA', 'audio/mpeg' ),
				$this->response( 'BBB', 'audio/wav' ),
			)
		);
	}

	/**
	 * An ID3 tag on a later segment is stripped.
	 *
	 * A tag left mid-stream is interpreted as audio data by some decoders and
	 * produces an audible click at every chunk boundary.
	 */
	public function test_strips_id3_from_later_segments() {
		// ID3v2 header: "ID3", version, flags, then a 4-byte synchsafe size.
		$tag    = "ID3\x03\x00\x00\x00\x00\x00\x05" . str_repeat( "\x00", 5 );
		$first  = $this->response( $tag . 'FIRST' );
		$second = $this->response( $tag . 'SECOND' );

		$combined = $this->stitcher->stitch( array( $first, $second ) );

		// The leading tag on the first segment is preserved; the second's is not.
		$this->assertEquals( $tag . 'FIRST' . 'SECOND', $combined );
	}

	/**
	 * Segments without an ID3 tag are concatenated verbatim.
	 */
	public function test_untagged_segments_are_untouched() {
		$combined = $this->stitcher->stitch(
			array(
				$this->response( "\xFF\xFBFIRST" ),
				$this->response( "\xFF\xFBSECOND" ),
			)
		);

		$this->assertEquals( "\xFF\xFBFIRST\xFF\xFBSECOND", $combined );
	}

	/**
	 * Durations sum when every segment reports one.
	 */
	public function test_total_duration_sums() {
		$total = $this->stitcher->total_duration(
			array(
				$this->response( 'A', 'audio/mpeg', 10.5 ),
				$this->response( 'B', 'audio/mpeg', 4.5 ),
			)
		);

		$this->assertEqualsWithDelta( 15.0, $total, 0.0001 );
	}

	/**
	 * A missing duration on any segment makes the total unknown.
	 *
	 * Reporting a partial sum as the whole would understate the length in the
	 * podcast feed, where clients use it to draw the scrubber.
	 */
	public function test_total_duration_is_null_when_any_segment_lacks_one() {
		$total = $this->stitcher->total_duration(
			array(
				$this->response( 'A', 'audio/mpeg', 10.5 ),
				$this->response( 'B', 'audio/mpeg', null ),
			)
		);

		$this->assertNull( $total );
	}
}
