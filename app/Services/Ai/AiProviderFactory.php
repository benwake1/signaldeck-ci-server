<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services\Ai;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Crypt;

class AiProviderFactory
{
    public const ANTHROPIC = 'anthropic';
    public const OPENAI_COMPATIBLE = 'openai_compatible';

    /** Base URL and suggested model for each compatible-endpoint preset. */
    public const PRESETS = [
        'ollama' => ['label' => 'Ollama (local, free)', 'url' => 'http://localhost:11434/v1', 'model' => 'qwen2.5-coder:14b', 'hosted' => false],
        'groq' => ['label' => 'Groq (free tier)', 'url' => 'https://api.groq.com/openai/v1', 'model' => 'llama-3.3-70b-versatile', 'hosted' => true],
        'gemini' => ['label' => 'Google Gemini (free tier)', 'url' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'model' => 'gemini-2.5-flash', 'hosted' => true],
        'openrouter' => ['label' => 'OpenRouter', 'url' => 'https://openrouter.ai/api/v1', 'model' => 'qwen/qwen-2.5-coder-32b-instruct:free', 'hosted' => true],
        'custom' => ['label' => 'Custom endpoint', 'url' => '', 'model' => '', 'hosted' => false],
    ];

    public static function make(): AiProvider
    {
        if (self::providerName() === self::OPENAI_COMPATIBLE) {
            return new OpenAiCompatibleProvider(
                baseUrl: (string) AppSetting::get('ai_compat_base_url', ''),
                model: (string) AppSetting::get('ai_compat_model', ''),
                apiKey: self::secret('ai_compat_api_key'),
            );
        }

        return new AnthropicProvider(
            apiKey: self::secret('ai_anthropic_api_key'),
            model: (string) AppSetting::get('ai_model', config('ai.model')),
        );
    }

    public static function providerName(): string
    {
        return AppSetting::get('ai_provider', self::ANTHROPIC) === self::OPENAI_COMPATIBLE
            ? self::OPENAI_COMPATIBLE
            : self::ANTHROPIC;
    }

    public static function isConfigured(): bool
    {
        return self::make()->isConfigured();
    }

    private static function secret(string $key): string
    {
        $stored = (string) AppSetting::get($key, '');
        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Exception) {
            return $stored;
        }
    }
}
