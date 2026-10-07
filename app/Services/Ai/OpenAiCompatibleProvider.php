<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Talks to any server exposing OpenAI's /chat/completions API: Ollama, Groq,
 * OpenRouter, Gemini's OpenAI-compatible endpoint, OpenAI itself, etc.
 */
class OpenAiCompatibleProvider implements AiProvider
{
    use ReadsServerSentEvents;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $apiKey = '',
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function name(): string
    {
        return 'openai_compatible';
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->model !== '';
    }

    public function complete(array $systemBlocks, array $messages, int $maxTokens): array
    {
        $response = $this->request()->post($this->endpoint(), $this->payload($systemBlocks, $messages, $maxTokens));

        if ($response->failed()) {
            Log::error('OpenAI-compatible API call failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException($this->failureMessage($response));
        }

        $data = $response->json();

        return [
            'content' => (string) ($data['choices'][0]['message']['content'] ?? ''),
            'tokens_used' => $this->tokens($data['usage'] ?? []),
        ];
    }

    public function stream(array $systemBlocks, array $messages, int $maxTokens): \Generator
    {
        $response = $this->request()
            ->withOptions(['stream' => true])
            ->post($this->endpoint(), $this->payload($systemBlocks, $messages, $maxTokens) + [
                'stream' => true,
                'stream_options' => ['include_usage' => true],
            ]);

        if ($response->failed()) {
            Log::error('OpenAI-compatible streaming call failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException($this->failureMessage($response));
        }

        $usage = [];

        foreach ($this->sseDataLines($response->getBody()) as $payload) {
            if ($payload === '[DONE]') {
                break;
            }

            $data = json_decode($payload, true);
            if (!is_array($data)) {
                continue;
            }

            $text = $data['choices'][0]['delta']['content'] ?? null;
            if (is_string($text) && $text !== '') {
                yield ['type' => 'delta', 'text' => $text];
            }

            if (!empty($data['usage'])) {
                $usage = $data['usage'];
            }
        }

        yield ['type' => 'usage', 'tokens_used' => $this->tokens($usage)];
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl, '/') . '/chat/completions';
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        set_time_limit(300); // local models can be slow

        $request = Http::acceptJson()->timeout(300);

        return $this->apiKey !== '' ? $request->withToken($this->apiKey) : $request;
    }

    private function payload(array $systemBlocks, array $messages, int $maxTokens): array
    {
        $system = collect($systemBlocks)->pluck('text')->implode("\n\n");

        return [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'messages' => array_merge([['role' => 'system', 'content' => $system]], $messages),
        ];
    }

    private function tokens(array $usage): array
    {
        $input = (int) ($usage['prompt_tokens'] ?? 0);
        $output = (int) ($usage['completion_tokens'] ?? 0);

        return [
            'input' => $input,
            'output' => $output,
            'total' => $input + $output,
            'cache_read' => 0,
            'cache_creation' => 0,
        ];
    }
}
