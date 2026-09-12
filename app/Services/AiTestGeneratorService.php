<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services;

use App\DTOs\AiGenerationResult;
use App\Models\AiConversation;
use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

class AiTestGeneratorService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MAX_TOKENS = 4096;
    private const MAX_RECENT_MESSAGES = 4; // Keep last N messages (2 exchanges) for context

    private static array $offTopicPatterns = [
        '/write me a (poem|story|essay|song|letter)/i',
        '/translate .+ (to|into) /i',
        '/what is the (capital|population|history) of/i',
        '/tell me (a joke|about yourself)/i',
        '/generate (an? )?(image|picture|logo|design)/i',
        '/help me with (my )?(homework|resume|cover letter)/i',
    ];

    public function generate(AiConversation $conversation, string $userMessage, string $framework = 'cypress'): AiGenerationResult
    {
        $this->validateInput($userMessage);

        $systemBlocks = $this->buildSystemBlocks($framework, $conversation->crawl_data, $conversation->recording_data);

        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => $userMessage, 'timestamp' => now()->toIso8601String()];

        $response = $this->callApi($systemBlocks, $messages);

        $messages[] = ['role' => 'assistant', 'content' => $response['content'], 'timestamp' => now()->toIso8601String()];
        $conversation->update(['messages' => $messages]);

        return $this->parseResponse($response);
    }

    public function refine(AiConversation $conversation, string $feedback, string $framework = 'cypress'): AiGenerationResult
    {
        $this->validateInput($feedback);

        $systemBlocks = $this->buildSystemBlocks($framework, $conversation->crawl_data, $conversation->recording_data);

        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => $feedback, 'timestamp' => now()->toIso8601String()];

        $response = $this->callApi($systemBlocks, $messages);

        $messages[] = ['role' => 'assistant', 'content' => $response['content'], 'timestamp' => now()->toIso8601String()];
        $conversation->update(['messages' => $messages]);

        return $this->parseResponse($response);
    }

    public function regenerateForFramework(AiConversation $conversation, string $targetFramework): AiGenerationResult
    {
        $systemBlocks = $this->buildSystemBlocks($targetFramework, $conversation->crawl_data, $conversation->recording_data);

        $messages = $conversation->messages ?? [];
        $messages[] = [
            'role' => 'user',
            'content' => "Convert the previously generated tests to {$targetFramework} format. Keep the same test scenarios and assertions, but use {$targetFramework} conventions and APIs.",
            'timestamp' => now()->toIso8601String(),
        ];

        $response = $this->callApi($systemBlocks, $messages);

        $messages[] = ['role' => 'assistant', 'content' => $response['content'], 'timestamp' => now()->toIso8601String()];
        $conversation->update(['messages' => $messages]);

        return $this->parseResponse($response);
    }

    public function validateInput(string $message): void
    {
        foreach (self::$offTopicPatterns as $pattern) {
            if (preg_match($pattern, $message)) {
                throw new \InvalidArgumentException(
                    'This request does not appear to be related to test generation. The AI builder only helps with writing, editing, and explaining automated test code.'
                );
            }
        }
    }

    public function streamGenerate(AiConversation $conversation, string $userMessage, string $framework = 'cypress'): \Generator
    {
        $this->validateInput($userMessage);

        $systemBlocks = $this->buildSystemBlocks($framework, $conversation->crawl_data, $conversation->recording_data);

        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => $userMessage, 'timestamp' => now()->toIso8601String()];

        $apiKey = $this->getApiKey();
        set_time_limit(120);

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ])->timeout(120)->withOptions(['stream' => true])->post(self::API_URL, [
            'model' => AppSetting::get('ai_model', config('ai.model')),
            'max_tokens' => (int) AppSetting::get('ai_max_tokens', self::DEFAULT_MAX_TOKENS),
            'system' => $systemBlocks,
            'messages' => $this->buildApiMessages($messages),
            'stream' => true,
        ]);

        if ($response->failed()) {
            Log::error('Anthropic streaming API call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('AI generation failed: ' . $response->status());
        }

        $fullContent = '';
        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $buffer .= $body->read(8192);
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines); // keep incomplete last line in buffer

            foreach ($lines as $line) {
                $line = trim($line);

                if (empty($line) || !str_starts_with($line, 'data: ')) {
                    continue;
                }

                $data = json_decode(substr($line, 6), true);
                if (!$data) continue;

                if ($data['type'] === 'content_block_delta' && isset($data['delta']['text'])) {
                    $fullContent .= $data['delta']['text'];
                    yield ['type' => 'delta', 'text' => $data['delta']['text']];
                }

                if ($data['type'] === 'message_stop') {
                    break 2;
                }
            }
        }

        $messages[] = ['role' => 'assistant', 'content' => $fullContent, 'timestamp' => now()->toIso8601String()];
        $conversation->update(['messages' => $messages]);

        yield ['type' => 'done', 'content' => $fullContent];
    }

    /**
     * Build the system prompt as an array of content blocks for prompt caching.
     * The base prompt (framework conventions) is cached; crawl data is appended
     * only when present and marked as the cache breakpoint.
     */
    private function buildSystemBlocks(string $framework, ?array $crawlData, ?array $recordingData = null): array
    {
        $basePrompt = View::make('prompts.test-generator-system', [
            'framework' => $framework,
            'crawlData' => [],
        ])->render();

        // If no crawl or recording data, cache the base prompt alone
        if (empty($crawlData) && empty($recordingData)) {
            return [
                [
                    'type' => 'text',
                    'text' => $basePrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ];
        }

        $blocks = [['type' => 'text', 'text' => $basePrompt]];

        // Crawl data and recording data are each stable across turns within
        // the same conversation, so each gets its own cache breakpoint.
        if (!empty($crawlData)) {
            $crawlPrompt = View::make('prompts.crawl-context', [
                'crawlData' => $crawlData,
            ])->render();

            $blocks[] = [
                'type' => 'text',
                'text' => $crawlPrompt,
                'cache_control' => ['type' => 'ephemeral'],
            ];
        }

        if (!empty($recordingData)) {
            $recordingPrompt = View::make('prompts.recording-context', [
                'recordingData' => $recordingData,
            ])->render();

            $blocks[] = [
                'type' => 'text',
                'text' => $recordingPrompt,
                'cache_control' => ['type' => 'ephemeral'],
            ];
        }

        return $blocks;
    }

    private function callApi(array $systemBlocks, array $messages): array
    {
        $apiKey = $this->getApiKey();
        set_time_limit(120);

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'content-type' => 'application/json',
        ])->timeout(120)->post(self::API_URL, [
            'model' => AppSetting::get('ai_model', config('ai.model')),
            'max_tokens' => (int) AppSetting::get('ai_max_tokens', self::DEFAULT_MAX_TOKENS),
            'system' => $systemBlocks,
            'messages' => $this->buildApiMessages($messages),
        ]);

        if ($response->failed()) {
            Log::error('Anthropic API call failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('AI generation failed: ' . $response->status());
        }

        $data = $response->json();

        $content = collect($data['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        return [
            'content' => $content,
            'tokens_used' => [
                'input' => $data['usage']['input_tokens'] ?? 0,
                'output' => $data['usage']['output_tokens'] ?? 0,
                'total' => ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0),
                'cache_read' => $data['usage']['cache_read_input_tokens'] ?? 0,
                'cache_creation' => $data['usage']['cache_creation_input_tokens'] ?? 0,
            ],
        ];
    }

    /**
     * Condense conversation history to reduce token usage.
     *
     * Instead of sending every message (which re-sends all generated code from
     * every iteration), we send:
     * 1. A "current files" context block with the latest version of each file
     * 2. Only the last N messages of actual conversation
     *
     * The full history is still stored in the DB — this only affects what goes
     * to the API.
     */
    private function buildApiMessages(array $messages): array
    {
        $count = count($messages);

        // Short conversations: send everything as-is
        if ($count <= self::MAX_RECENT_MESSAGES + 1) {
            return collect($messages)
                ->map(fn (array $msg) => [
                    'role' => $msg['role'],
                    'content' => $msg['content'],
                ])
                ->values()
                ->all();
        }

        // Extract the latest version of all generated files from the full history
        $currentFiles = $this->extractLatestFiles($messages);

        // Take only recent messages, ensuring they start with a user message
        // (Claude API requires strict user/assistant alternation)
        $recentMessages = array_slice($messages, -self::MAX_RECENT_MESSAGES);
        if (!empty($recentMessages) && ($recentMessages[0]['role'] ?? '') === 'assistant') {
            $recentMessages = array_slice($messages, -(self::MAX_RECENT_MESSAGES - 1));
        }

        $apiMessages = [];

        // Inject current file state as a user context message (if we have files)
        if (!empty($currentFiles)) {
            $fileContext = "Here are the current test files we're working with:\n\n";
            foreach ($currentFiles as $path => $content) {
                $fileContext .= "```javascript file:{$path}\n{$content}\n```\n\n";
            }
            $apiMessages[] = ['role' => 'user', 'content' => $fileContext];
            $apiMessages[] = ['role' => 'assistant', 'content' => 'Understood. I have the current state of all test files. What would you like me to do?'];
        }

        // Append recent conversation
        foreach ($recentMessages as $msg) {
            $apiMessages[] = [
                'role' => $msg['role'],
                'content' => $msg['content'],
            ];
        }

        return $apiMessages;
    }

    /**
     * Extract the latest version of each generated file from conversation history.
     * Scans all messages in reverse order — the last occurrence of each
     * file path is the most recent version. Checks both assistant messages
     * (normal generation) and user messages (pre-seeded suite files).
     */
    private function extractLatestFiles(array $messages): array
    {
        $files = [];

        // Walk in reverse so we find the latest version first
        foreach (array_reverse($messages) as $msg) {
            $content = $msg['content'] ?? '';
            preg_match_all('/```(?:javascript|js|typescript|ts|json)\s+file:(.+?)\n(.*?)```/s', $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $path = trim($match[1]);
                // Only keep the first (= latest, since we're reversed) version of each file
                if (!isset($files[$path])) {
                    $files[$path] = trim($match[2]);
                }
            }
        }

        return $files;
    }

    private function getApiKey(): string
    {
        $stored = AppSetting::get('ai_anthropic_api_key', '');
        if (!$stored) {
            throw new \RuntimeException('Anthropic API key not configured. Go to Settings > AI to add your key.');
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Exception) {
            return $stored;
        }
    }

    private function parseResponse(array $response): AiGenerationResult
    {
        $content = $response['content'];
        $files = [];
        $suggestions = [];

        preg_match_all('/```(?:javascript|js|typescript|ts|json)\s+file:(.+?)\n(.*?)```/s', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $files[trim($match[1])] = trim($match[2]);
        }

        $explanation = preg_replace('/```(?:javascript|js|typescript|ts)\s+file:.+?```/s', '', $content);
        $explanation = trim($explanation);

        if (preg_match('/(?:additional tests|suggestions|you could also|consider testing).*?$/is', $explanation, $sugMatch)) {
            $suggestionBlock = $sugMatch[0];
            preg_match_all('/[-•]\s*(.+)/m', $suggestionBlock, $sugLines);
            $suggestions = $sugLines[1] ?? [];
        }

        return new AiGenerationResult(
            files: $files,
            explanation: $explanation,
            suggestions: $suggestions,
            tokensUsed: $response['tokens_used'],
        );
    }
}
