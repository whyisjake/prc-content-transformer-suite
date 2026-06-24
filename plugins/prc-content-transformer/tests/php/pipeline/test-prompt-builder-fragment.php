<?php
/**
 * Unit tests for fragment prompt building.
 *
 * @package PRC\Platform\Content_Transformer\Pipeline
 */

declare(strict_types=1);

namespace PRC\Platform\Content_Transformer\Pipeline\Tests;

use PRC\Platform\Content_Transformer\Pipeline\Prompt_Builder;
use PRC\Platform\Content_Transformer\Providers\Apple_News_Provider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \PRC\Platform\Content_Transformer\Pipeline\Prompt_Builder::build_fragment_prompt
 */
class Test_Prompt_Builder_Fragment extends TestCase {

	public function test_build_fragment_prompt_requests_json_array_only(): void {
		$provider = new Apple_News_Provider();
		$prompt   = Prompt_Builder::build_fragment_prompt( '## Custom block\n\nSome text.', $provider );

		$this->assertStringContainsString( 'JSON array', $prompt );
		$this->assertStringContainsString( 'ANF component objects', $prompt );
		$this->assertStringContainsString( 'Custom block', $prompt );
		$this->assertStringContainsString( 'Do not wrap the array in a full ANF document', $prompt );
	}
}
