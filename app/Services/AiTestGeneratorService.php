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
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

class AiTestGeneratorService
{
    private const DEFAULT_MAX_TOKENS = 4096;
    private const MAX_RECENT_MESSAGES = 4; // Keep last N messages (2 exchanges) for context

    /** A fenced code block tagged with a target path, e.g. ```javascript file:cypress/e2e/login.cy.js */
    private const FILE_BLOCK_REGEX = '/```[a-z]*[ \t]+file:[ \t]*(.+?)\r?\n(.*?)```/s';

    private static array $offTopicPatterns = [
        '/write me a (poem|story|essay|song|letter)/i',
        '/translate .+ (to|into) /i',
        '/what is the (capital|population|history) of/i',
        '/tell me (a joke|about yourself)/i',
        '/generate (an? )?(image|picture|logo|design)/i',
        '/help me with (my )?(homework|resume|cover letter)/i',
    ];

    public function __construct(private ?AiProvider $provider = null) {}

    public function generate(AiConversation $conversation, string $userMessage, string $framework = 'cypress'): AiGenerationResult
    {
        $this->validateInput($userMessage);

        return $this->run($conversation, $userMessage, $framework);
    }

    public function refine(AiConversation $conversation, string $feedback, string $framework = 'cypress'): AiGenerationResult
    {
        $this->validateInput($feedback);

        return $this->run($conversation, $feedback, $framework);
    }

    public function regenerateForFramework(AiConversation $conversation, string $targetFramework): AiGenerationResult
    {
        return $this->run(
            $conversation,
            "Convert the previously generated tests to {$targetFramework} format. Keep the same test scenarios and assertions, but use {$targetFramework} conventions and APIs.",
            $targetFramework,
        );
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

        $provider = $this->provider();
        $systemBlocks = $this->buildSystemBlocks($framework, $conversation->crawl_data, $conversation->recording_data);

        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => $userMessage, 'timestamp' => now()->toIso8601String()];

        [$systemBlocks, $apiMessages] = $this->fitContext($provider, $systemBlocks, $this->buildApiMessages($messages));

        $fullContent = '';
        $tokens = [];

        foreach ($provider->stream($systemBlocks, $apiMessages, $this->maxTokens()) as $chunk) {
            if ($chunk['type'] === 'delta') {
                $fullContent .= $chunk['text'];
                yield $chunk;
            } elseif ($chunk['type'] === 'usage') {
                $tokens = $chunk['tokens_used'];
            }
        }

        $this->logUsage($conversation, $provider, $tokens);

        $messages[] = ['role' => 'assistant', 'content' => $fullContent, 'timestamp' => now()->toIso8601String()];
        $conversation->update(['messages' => $messages]);

        yield ['type' => 'done', 'content' => $fullContent, 'tokens_used' => $tokens];
    }

    /**
     * Shared non-streaming turn: append the user message, call the provider,
     * persist the reply and parse it into files.
     */
    private function run(AiConversation $conversation, string $userMessage, string $framework): AiGenerationResult
    {
        $provider = $this->provider();
        $systemBlocks = $this->buildSystemBlocks($framework, $conversation->crawl_data, $conversation->recording_data);

        $messages = $conversation->messages ?? [];
        $messages[] = ['role' => 'user', 'content' => $userMessage, 'timestamp' => now()->toIso8601String()];

        [$fittedSystem, $apiMessages] = $this->fitContext($provider, $systemBlocks, $this->buildApiMessages($messages));
        $response = $provider->complete($fittedSystem, $apiMessages, $this->maxTokens());

        // Weaker models sometimes ignore the `file:` fence format. Give them one nudge.
        if ($provider->name() === AiProviderFactory::OPENAI_COMPATIBLE
            && !preg_match(self::FILE_BLOCK_REGEX, $response['content'])
            && str_contains($response['content'], '```')
        ) {
            $apiMessages[] = ['role' => 'assistant', 'content' => $response['content']];
            $apiMessages[] = ['role' => 'user', 'content' => 'Reply again using the required format: wrap every file in a fenced block whose opening line is the language followed by `file:<path>`, e.g. ```javascript file:cypress/e2e/example.cy.js. Include complete files.'];

            $retry = $provider->complete($fittedSystem, $apiMessages, $this->maxTokens());
            $retry['tokens_used'] = $this->sumTokens($response['tokens_used'], $retry['tokens_used']);
            $response = $retry;
        }

        $this->logUsage($conversation, $provider, $response['tokens_used']);

        $messages[] = ['role' => 'assistant', 'content' => $response['content'], 'timestamp' => now()->toIso8601String()];
        $conversation->update(['messages' => $messages]);

        return $this->parseResponse($response);
    }

    private function provider(): AiProvider
    {
        $provider = $this->provider ?? AiProviderFactory::make();

        if (!$provider->isConfigured()) {
            throw new \RuntimeException('AI provider not configured. Go to Settings > AI to set one up.');
        }

        return $provider;
    }

    private function maxTokens(): int
    {
        return (int) AppSetting::get('ai_max_tokens', self::DEFAULT_MAX_TOKENS);
    }

    private function logUsage(AiConversation $conversation, AiProvider $provider, array $tokens): void
    {
        Log::info('AI usage', ['conversation_id' => $conversation->id, 'provider' => $provider->name()] + $tokens);

        // Record which provider/model produced this conversation (the latest one wins
        // if the admin switches provider mid-conversation).
        $conversation->update(['provider' => $provider->name(), 'model' => $provider->model()]);

        if (!empty($tokens['total'])) {
            $conversation->increment('total_tokens', (int) $tokens['total']);
        }
    }

    private function sumTokens(array $a, array $b): array
    {
        $sum = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
            $sum[$key] = ($a[$key] ?? 0) + ($b[$key] ?? 0);
        }

        return $sum;
    }

    /**
     * Small local models have small context windows. When `ai_max_context_chars`
     * is set (non-Anthropic providers only), truncate the crawl/recording
     * blocks so the whole request fits; the base prompt is never touched.
     *
     * @return array{0: array, 1: array}
     */
    private function fitContext(AiProvider $provider, array $systemBlocks, array $apiMessages): array
    {
        $limit = (int) AppSetting::get('ai_max_context_chars', 0);
        if ($limit <= 0 || $provider->name() === AiProviderFactory::ANTHROPIC) {
            return [$systemBlocks, $apiMessages];
        }

        $used = array_sum(array_map(fn ($m) => strlen($m['content']), $apiMessages)) + strlen($systemBlocks[0]['text']);

        foreach ($systemBlocks as $i => $block) {
            if ($i === 0) {
                continue;
            }

            $budget = max(500, $limit - $used);
            if (strlen($block['text']) > $budget) {
                $systemBlocks[$i]['text'] = substr($block['text'], 0, $budget) . "\n... (truncated)";
            }
            $used += strlen($systemBlocks[$i]['text']);
        }

        return [$systemBlocks, $apiMessages];
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
            preg_match_all(self::FILE_BLOCK_REGEX, $content, $matches, PREG_SET_ORDER);

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

    private function parseResponse(array $response): AiGenerationResult
    {
        $content = $response['content'];
        $files = [];
        $suggestions = [];

        preg_match_all(self::FILE_BLOCK_REGEX, $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $files[trim($match[1])] = trim($match[2]);
        }

        $explanation = preg_replace(self::FILE_BLOCK_REGEX, '', $content);
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
