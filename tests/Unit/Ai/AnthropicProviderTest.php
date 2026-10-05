<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AnthropicProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicProviderTest extends TestCase
{
    public function test_complete_maps_usage_including_cache_fields(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'hi']],
            'usage' => ['input_tokens' => 7, 'output_tokens' => 3, 'cache_read_input_tokens' => 100, 'cache_creation_input_tokens' => 20],
        ])]);

        $result = (new AnthropicProvider('key', 'claude-haiku-4-5-20251001'))
            ->complete([['type' => 'text', 'text' => 's']], [['role' => 'user', 'content' => 'u']], 50);

        $this->assertSame('hi', $result['content']);
        $this->assertSame(100, $result['tokens_used']['cache_read']);
        $this->assertSame(20, $result['tokens_used']['cache_creation']);
        $this->assertSame(10, $result['tokens_used']['total']);

        Http::assertSent(fn ($r) => $r->header('x-api-key') === ['key']);
    }

    public function test_is_configured_requires_key(): void
    {
        $this->assertFalse((new AnthropicProvider('', 'm'))->isConfigured());
        $this->assertTrue((new AnthropicProvider('k', 'm'))->isConfigured());
    }

    public function test_model_returns_configured_model(): void
    {
        $this->assertSame('claude-haiku-4-5-20251001', (new AnthropicProvider('key', 'claude-haiku-4-5-20251001'))->model());
    }
}
