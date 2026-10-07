<?php

namespace Tests\Unit\Ai;

use App\Services\AiTestGeneratorService;
use Tests\TestCase;

class ParseResponseTest extends TestCase
{
    private function parse(string $content)
    {
        $service = new AiTestGeneratorService();
        $m = new \ReflectionMethod($service, 'parseResponse');
        $m->setAccessible(true);

        return $m->invoke($service, ['content' => $content, 'tokens_used' => ['total' => 0]]);
    }

    public function test_extracts_file_blocks_and_explanation(): void
    {
        $r = $this->parse("Here you go.\n```javascript file:cypress/e2e/a.cy.js\ndescribe('a', () => {});\n```\nDone.");

        $this->assertSame(['cypress/e2e/a.cy.js' => "describe('a', () => {});"], $r->files);
        $this->assertStringContainsString('Here you go.', $r->explanation);
        $this->assertStringNotContainsString('describe', $r->explanation);
    }

    public function test_tolerates_crlf_and_multiple_files(): void
    {
        $r = $this->parse("```js file:a.js\r\none\r\n```\r\n```json file:b.json\r\n{}\r\n```");

        $this->assertSame(['a.js', 'b.json'], array_keys($r->files));
    }

    public function test_plain_code_fence_yields_no_files(): void
    {
        $this->assertSame([], $this->parse("```javascript\nfoo()\n```")->files);
    }
}
