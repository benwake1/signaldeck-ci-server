<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\DTOs;

class AiGenerationResult
{
    public function __construct(
        public readonly array $files,
        public readonly string $explanation,
        public readonly array $suggestions,
        public readonly array $tokensUsed,
    ) {}

    public function toArray(): array
    {
        return [
            'files' => $this->files,
            'explanation' => $this->explanation,
            'suggestions' => $this->suggestions,
            'tokens_used' => $this->tokensUsed,
        ];
    }
}
