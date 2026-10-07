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

class AnthropicProvider implements AiProvider
{
    use ReadsServerSentEvents;

    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function complete(array $systemBlocks, array $messages, int $maxTokens): array
    {
        $response = $this->request()->post(self::API_URL, $this->payload($systemBlocks, $messages, $maxTokens));

        if ($response->failed()) {
            Log::error('Anthropic API call failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException($this->failureMessage($response));
        }

        $data = $response->json();

        $content = collect($data['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        return [
            'content' => $content,
            'tokens_used' => $this->tokens($data['usage'] ?? []),
        ];
    }

    public function stream(array $systemBlocks, array $messages, int $maxTokens): \Generator
    {
        $response = $this->request()
            ->withOptions(['stream' => true])
            ->post(self::API_URL, $this->payload($systemBlocks, $messages, $maxTokens) + ['stream' => true]);

        if ($response->failed()) {
            Log::error('Anthropic streaming API call failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException($this->failureMessage($response));
        }

        $usage = [];

        foreach ($this->sseDataLines($response->getBody()) as $payload) {
            $data = json_decode($payload, true);
            if (!is_array($data)) {
                continue;
            }

            switch ($data['type'] ?? '') {
                case 'message_start':
                    $usage = array_merge($usage, $data['message']['usage'] ?? []);
                    break;
                case 'content_block_delta':
                    if (isset($data['delta']['text'])) {
                        yield ['type' => 'delta', 'text' => $data['delta']['text']];
                    }
                    break;
                case 'message_delta':
                    $usage = array_merge($usage, $data['usage'] ?? []);
                    break;
                case 'message_stop':
                    break 2;
            }
        }

        yield ['type' => 'usage', 'tokens_used' => $this->tokens($usage)];
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        set_time_limit(120);

        return Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ])->timeout(120);
    }

    private function payload(array $systemBlocks, array $messages, int $maxTokens): array
    {
        return [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'system' => $systemBlocks,
            'messages' => $messages,
        ];
    }

    private function tokens(array $usage): array
    {
        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);

        return [
            'input' => $input,
            'output' => $output,
            'total' => $input + $output,
            'cache_read' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cache_creation' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
        ];
    }
}
