<?php
/**
 * Class TTSRequestTest
 *
 * @package PRC_Audio_Narration
 */

use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Request;
use PRC\Platform\Audio_Narration\TTS\Domain\TTS_Response;
use PRC\Platform\Audio_Narration\TTS\Domain\Exceptions\TTS_Exception;

/**
 * Tests for the TTS request and response value objects.
 */
class TTSRequestTest extends WP_UnitTestCase {

	/**
	 * A request exposes the values it was constructed with.
	 */
	public function test_request_exposes_values() {
		$request = new TTS_Request(
			'Seventy-two percent of Americans agree.',
			array(
				'voice_id'      => 'voice-abc',
				'output_format' => 'mp3',
			)
		);

		$this->assertEquals( 'Seventy-two percent of Americans agree.', $request->get_text() );
		$this->assertEquals( 'voice-abc', $request->get_voice_id() );
		$this->assertEquals( 'mp3', $request->get_output_format() );
	}

	/**
	 * Character count reflects the text being billed for.
	 */
	public function test_request_reports_character_count() {
		$request = new TTS_Request( 'Twelve chars' );

		$this->assertEquals( 12, $request->get_character_count() );
	}

	/**
	 * Character count is multibyte-aware.
	 *
	 * Providers bill per character, and a naive strlen() would overcount any
	 * script containing curly quotes or em dashes -- which research prose is
	 * full of -- inflating both the cost estimate and any length guard.
	 */
	public function test_request_character_count_is_multibyte_safe() {
		$request = new TTS_Request( '“Quoted” — dashed' );

		$this->assertEquals( mb_strlen( '“Quoted” — dashed' ), $request->get_character_count() );
	}

	/**
	 * Neighbor context is carried so providers can maintain prosody.
	 */
	public function test_request_carries_neighbor_context() {
		$request = new TTS_Request(
			'The middle chunk.',
			array(
				'preceding_text' => 'The chunk before.',
				'following_text' => 'The chunk after.',
			)
		);

		$this->assertEquals( 'The chunk before.', $request->get_preceding_text() );
		$this->assertEquals( 'The chunk after.', $request->get_following_text() );
	}

	/**
	 * Neighbor context defaults to empty for a standalone request.
	 */
	public function test_request_neighbor_context_defaults_empty() {
		$request = new TTS_Request( 'Only chunk.' );

		$this->assertSame( '', $request->get_preceding_text() );
		$this->assertSame( '', $request->get_following_text() );
	}

	/**
	 * Empty text is rejected at construction.
	 *
	 * A blank request would be billed by the provider and return silence, so
	 * it must fail before it reaches the network.
	 *
	 * @dataProvider blank_text_provider
	 *
	 * @param string $text Blank-ish text.
	 */
	public function test_request_rejects_blank_text( $text ) {
		$this->expectException( TTS_Exception::class );

		new TTS_Request( $text );
	}

	/**
	 * Blank text variants.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function blank_text_provider() {
		return array(
			'empty'    => array( '' ),
			'spaces'   => array( '    ' ),
			'newlines' => array( "\n\n" ),
		);
	}

	/**
	 * A response exposes its audio payload unchanged.
	 */
	public function test_response_exposes_audio() {
		$response = new TTS_Response( 'RAW_AUDIO_BYTES', 'audio/mpeg', 'elevenlabs', 42 );

		$this->assertEquals( 'RAW_AUDIO_BYTES', $response->get_audio() );
		$this->assertEquals( 'audio/mpeg', $response->get_mime_type() );
		$this->assertEquals( 'elevenlabs', $response->get_provider_name() );
		$this->assertEquals( 42, $response->get_character_count() );
	}

	/**
	 * Duration is optional, since not every provider reports it.
	 */
	public function test_response_duration_is_optional() {
		$without = new TTS_Response( 'BYTES', 'audio/mpeg', 'elevenlabs', 10 );
		$with    = new TTS_Response( 'BYTES', 'audio/mpeg', 'elevenlabs', 10, 12.5 );

		$this->assertNull( $without->get_duration() );
		$this->assertEquals( 12.5, $with->get_duration() );
	}

	/**
	 * Byte length is derived from the payload rather than trusted from input.
	 */
	public function test_response_reports_byte_length() {
		$response = new TTS_Response( 'ABCDE', 'audio/mpeg', 'elevenlabs', 5 );

		$this->assertEquals( 5, $response->get_byte_length() );
	}

	/**
	 * An empty audio payload is rejected.
	 *
	 * A provider returning HTTP 200 with no body would otherwise be stored as
	 * a zero-byte attachment that plays as silence.
	 */
	public function test_response_rejects_empty_audio() {
		$this->expectException( TTS_Exception::class );

		new TTS_Response( '', 'audio/mpeg', 'elevenlabs', 10 );
	}
}
