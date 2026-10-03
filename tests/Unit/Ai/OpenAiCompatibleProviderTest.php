<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\OpenAiCompatibleProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiCompatibleProviderTest extends TestCase
{
    private const SYSTEM = [['type' => 'text', 'text' => 'You write tests.']];
    private const MESSAGES = [['role' => 'user', 'content' => 'hi']];

    public function test_complete_returns_content_and_usage(): void
    {
        Http::fake(['localhost:11434/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'hello']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
        ])]);

        $result = (new OpenAiCompatibleProvider('http://localhost:11434/v1/', 'qwen'))
            ->complete(self::SYSTEM, self::MESSAGES, 100);

        $this->assertSame('hello', $result['content']);
        $this->assertSame(15, $result['tokens_used']['total']);

        Http::assertSent(fn ($r) => $r['model'] === 'qwen'
            && $r['messages'][0] === ['role' => 'system', 'content' => 'You write tests.']
            && ! $r->hasHeader('Authorization'));
    }

    public function test_api_key_is_sent_as_bearer_token(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'x']]]])]);

        (new OpenAiCompatibleProvider('https://api.groq.com/openai/v1', 'm', 'secret'))
            ->complete(self::SYSTEM, self::MESSAGES, 10);

        Http::assertSent(fn ($r) => $r->header('Authorization') === ['Bearer secret']);
    }

    public function test_failed_response_throws(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        $this->expectException(\RuntimeException::class);
        (new OpenAiCompatibleProvider('http://x/v1', 'm'))->complete(self::SYSTEM, self::MESSAGES, 10);
    }

    public function test_is_configured_requires_url_and_model(): void
    {
        $this->assertFalse((new OpenAiCompatibleProvider('', 'm'))->isConfigured());
        $this->assertFalse((new OpenAiCompatibleProvider('http://x', ''))->isConfigured());
        $this->assertTrue((new OpenAiCompatibleProvider('http://x', 'm'))->isConfigured());
    }

    public function test_stream_yields_deltas_then_usage(): void
    {
        $sse = "data: " . json_encode(['choices' => [['delta' => ['content' => 'Hel']]]]) . "\n\n"
            . "data: " . json_encode(['choices' => [['delta' => ['content' => 'lo']]]]) . "\n\n"
            . "data: " . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2]]) . "\n\n"
            . "data: [DONE]\n\n";

        Http::fake(['*' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream'])]);

        $events = iterator_to_array(
            (new OpenAiCompatibleProvider('http://x/v1', 'm'))->stream(self::SYSTEM, self::MESSAGES, 10),
            false
        );

        $this->assertSame('Hello', implode('', array_column(array_filter($events, fn ($e) => $e['type'] === 'delta'), 'text')));
        $this->assertSame('usage', end($events)['type']);
        $this->assertSame(5, end($events)['tokens_used']['total']);
    }

    public function test_model_returns_configured_model(): void
    {
        $this->assertSame('qwen2.5-coder:14b', (new OpenAiCompatibleProvider('http://x/v1', 'qwen2.5-coder:14b'))->model());
    }
}
