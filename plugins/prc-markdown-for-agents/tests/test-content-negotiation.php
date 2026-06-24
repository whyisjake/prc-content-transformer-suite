<?php
/**
 * Content negotiation tests.
 *
 * @package PRC\Platform\Markdown_For_Agents
 */

declare( strict_types=1 );

use PRC\Platform\Markdown_For_Agents\Content_Negotiation;

/**
 * Verifies Accept header q-value precedence for markdown negotiation.
 */
class Test_Content_Negotiation extends WP_UnitTestCase {

	/**
	 * @dataProvider accept_header_provider
	 */
	public function test_accept_prefers_markdown( string $accept, bool $expected ): void {
		$this->assertSame( $expected, Content_Negotiation::accept_prefers_markdown( $accept ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function accept_header_provider(): array {
		return array(
			'empty accept' => array( '', false ),
			'markdown only' => array( 'text/markdown', true ),
			'markdown preferred over html' => array( 'text/markdown, text/html;q=0.9', true ),
			'browser-like with injected markdown at equal q' => array(
				'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8,text/markdown',
				false,
			),
			'html preferred over markdown' => array(
				'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8,text/markdown;q=0.5',
				false,
			),
			'wildcard only' => array( '*/*', false ),
			'html only' => array( 'text/html,application/xhtml+xml', false ),
			'xhtml counts as html' => array( 'text/markdown;q=0.8, application/xhtml+xml', false ),
			'markdown strictly above xhtml' => array( 'text/markdown, application/xhtml+xml;q=0.9', true ),
			'zero q markdown' => array( 'text/markdown;q=0', false ),
		);
	}

	public function test_wants_markdown_reads_server_accept_header(): void {
		$negotiation = new Content_Negotiation( new \PRC\Platform\Markdown_For_Agents\Loader() );

		$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
		$this->assertTrue( $negotiation->wants_markdown() );

		$_SERVER['HTTP_ACCEPT'] = 'text/html,text/markdown';
		$this->assertFalse( $negotiation->wants_markdown() );

		unset( $_SERVER['HTTP_ACCEPT'] );
		$this->assertFalse( $negotiation->wants_markdown() );
	}
}
