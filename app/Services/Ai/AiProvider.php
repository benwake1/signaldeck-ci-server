<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services\Ai;

interface AiProvider
{
    /** Short identifier, e.g. "anthropic" or "openai_compatible". */
    public function name(): string;

    /** Model identifier sent to the API, e.g. "claude-haiku-4-5-20251001". */
    public function model(): string;

    /** Whether enough settings exist to make a call (key, base URL, ...). */
    public function isConfigured(): bool;

    /**
     * @param array<int, array{type: string, text: string, cache_control?: array}> $systemBlocks
     * @param array<int, array{role: string, content: string}> $messages
     * @return array{content: string, tokens_used: array<string, int>}
     */
    public function complete(array $systemBlocks, array $messages, int $maxTokens): array;

    /**
     * Yields ['type' => 'delta', 'text' => string] chunks, then exactly one
     * ['type' => 'usage', 'tokens_used' => array] chunk.
     *
     * @param array<int, array{type: string, text: string, cache_control?: array}> $systemBlocks
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function stream(array $systemBlocks, array $messages, int $maxTokens): \Generator;
}
