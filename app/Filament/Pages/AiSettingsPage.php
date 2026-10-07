<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HandlesSecretFields;
use App\Models\AppSetting;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\OpenAiCompatibleProvider;
use App\Services\TestRepairService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\HtmlString;

class AiSettingsPage extends Page
{
    use HandlesSecretFields;

    protected static ?string $navigationIcon  = null;
    protected static ?string $navigationLabel = 'AI Provider';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?int    $navigationSort  = 5;
    protected static string  $view            = 'filament.pages.ai-settings';
    protected static ?string $title           = 'AI Provider Settings';
    protected static ?string $slug            = 'settings/ai';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'ai_provider'                 => AiProviderFactory::providerName(),
            'ai_anthropic_api_key'        => $this->maskSecret('ai_anthropic_api_key'),
            'ai_model'                    => AppSetting::get('ai_model', config('ai.model')),
            'ai_max_tokens'               => (int) AppSetting::get('ai_max_tokens', 4096),
            'ai_compat_preset'            => AppSetting::get('ai_compat_preset', 'ollama'),
            'ai_compat_base_url'          => AppSetting::get('ai_compat_base_url', AiProviderFactory::PRESETS['ollama']['url']),
            'ai_compat_model'             => AppSetting::get('ai_compat_model', AiProviderFactory::PRESETS['ollama']['model']),
            'ai_compat_api_key'           => $this->maskSecret('ai_compat_api_key'),
            'ai_max_context_chars'        => (int) AppSetting::get('ai_max_context_chars', 0),
            'ai_verify_max_attempts'      => (int) AppSetting::get('ai_verify_max_attempts', 2),
            'auto_repair_enabled'         => AppSetting::get('auto_repair_enabled', '0') === '1',
            'ai_auto_repair_daily_limit'  => (int) AppSetting::get('ai_auto_repair_daily_limit', 5),
            'ai_auto_repair_consecutive_failures' => TestRepairService::requiredConsecutiveFailures(),
        ]);
    }

    public function form(Form $form): Form
    {
        $isAnthropic = fn (Forms\Get $get) => $get('ai_provider') === AiProviderFactory::ANTHROPIC;
        $isCompat    = fn (Forms\Get $get) => $get('ai_provider') === AiProviderFactory::OPENAI_COMPATIBLE;

        return $form
            ->schema([
                Forms\Components\Section::make('Provider')
                    ->description('Choose which AI service powers the AI Test Builder and automated repairs.')
                    ->icon('heroicon-o-sparkles')
                    ->schema([
                        Forms\Components\Select::make('ai_provider')
                            ->label('Provider')
                            ->options([
                                AiProviderFactory::ANTHROPIC         => 'Anthropic Claude (recommended quality)',
                                AiProviderFactory::OPENAI_COMPATIBLE => 'OpenAI-compatible (Ollama, Groq, Gemini, OpenRouter, ...)',
                            ])
                            ->live()
                            ->required(),

                        Forms\Components\TextInput::make('ai_anthropic_api_key')
                            ->label('Anthropic API Key')
                            ->password()
                            ->revealable()
                            ->placeholder('sk-ant-...')
                            ->visible($isAnthropic)
                            ->helperText(fn (Forms\Get $get) => $get('ai_anthropic_api_key') === self::SECRET_PLACEHOLDER
                                ? 'An API key is saved. Clear the field and type a new one to change it.'
                                : 'Get your key from console.anthropic.com. It is encrypted at rest.'),

                        Forms\Components\Select::make('ai_model')
                            ->label('Model')
                            ->options([
                                'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (default, lower cost)',
                                'claude-sonnet-4-6'         => 'Claude Sonnet 4.6 (higher quality, higher cost)',
                            ])
                            ->visible($isAnthropic),

                        Forms\Components\Select::make('ai_compat_preset')
                            ->label('Preset')
                            ->options(collect(AiProviderFactory::PRESETS)->map(fn ($p) => $p['label'])->all())
                            ->live()
                            ->afterStateUpdated(function (?string $state, Forms\Set $set) {
                                $preset = AiProviderFactory::PRESETS[$state] ?? null;
                                if ($preset && $state !== 'custom') {
                                    $set('ai_compat_base_url', $preset['url']);
                                    $set('ai_compat_model', $preset['model']);
                                }
                            })
                            ->visible($isCompat),

                        Forms\Components\TextInput::make('ai_compat_base_url')
                            ->label('Base URL')
                            ->url()
                            ->placeholder('http://localhost:11434/v1')
                            ->helperText('The endpoint that serves /chat/completions. Ollama must be reachable from this server.')
                            ->required($isCompat)
                            ->visible($isCompat),

                        Forms\Components\TextInput::make('ai_compat_model')
                            ->label('Model name')
                            ->required($isCompat)
                            ->visible($isCompat),

                        Forms\Components\TextInput::make('ai_compat_api_key')
                            ->label('API Key (optional)')
                            ->password()
                            ->revealable()
                            ->helperText('Not needed for Ollama. Encrypted at rest.')
                            ->visible($isCompat),

                        Forms\Components\Placeholder::make('hosted_privacy_notice')
                            ->label('')
                            ->content(new HtmlString(
                                '<div class="text-sm text-amber-700 dark:text-amber-400"><strong>Privacy:</strong> hosted free tiers may use your prompts to train models. '
                                . 'Prompts include crawled page structure and recorded flows from your sites. Use Ollama or a paid plan for client work.</div>'
                            ))
                            ->visible(fn (Forms\Get $get) => $isCompat($get) && (AiProviderFactory::PRESETS[$get('ai_compat_preset')]['hosted'] ?? false)),

                        Forms\Components\TextInput::make('ai_max_tokens')
                            ->label('Max Response Length')
                            ->numeric()
                            ->minValue(1024)
                            ->maxValue(16384)
                            ->default(4096)
                            ->suffix('tokens')
                            ->helperText('The maximum length of each AI response. The default (4,096) is enough for most single-file tests. '
                                . 'Increase to 8,192+ if the AI cuts off mid-file when generating several files.'),

                        Forms\Components\TextInput::make('ai_max_context_chars')
                            ->label('Max prompt size (characters)')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('For small local models: crawl and recording context is truncated to fit. 0 = no limit. Roughly 4 characters per token.')
                            ->visible($isCompat),
                    ]),

                Forms\Components\Section::make('Cost controls')
                    ->icon('heroicon-o-banknotes')
                    ->schema([
                        Forms\Components\TextInput::make('ai_verify_max_attempts')
                            ->label('Verification attempts')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(5)
                            ->helperText('How many times a generated test is run against your site. Each failed attempt except the last triggers one more AI call to fix it.'),
                    ]),

                Forms\Components\Section::make('Automated Repair')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->description('When a managed suite keeps failing, ask the AI to diagnose it and, if the tests look out of date, propose a verified fix for human review. Fixes are never applied automatically.')
                    ->schema([
                        Forms\Components\Toggle::make('auto_repair_enabled')
                            ->label('Attempt automated repair of failing suites')
                            ->helperText('Only applies to managed suites with a base URL configured. Runs after the number of consecutive failed runs set below, at most once per suite per 24 hours.'),

                        Forms\Components\TextInput::make('ai_auto_repair_consecutive_failures')
                            ->label('Consecutive failures before repair')
                            ->numeric()
                            ->required()
                            ->minValue(TestRepairService::MIN_CONSECUTIVE_FAILURES)
                            ->helperText('A suite\'s most recent runs must all fail (not error) this many times in a row before an automated repair is attempted. Minimum ' . TestRepairService::MIN_CONSECUTIVE_FAILURES . '.'),

                        Forms\Components\TextInput::make('ai_auto_repair_daily_limit')
                            ->label('Automated repairs per day')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Global cap on unattended AI repairs across all suites. 0 = unlimited.'),
                    ]),

                Forms\Components\Section::make('Terms & Responsibility')
                    ->icon('heroicon-o-shield-check')
                    ->schema([
                        Forms\Components\Placeholder::make('tos_notice')
                            ->label('')
                            ->content(new HtmlString(
                                '<div class="text-sm text-gray-600 dark:text-gray-400 space-y-2">'
                                . '<p><strong>Your provider, your account.</strong> The AI Test Builder connects directly to the provider you configure. '
                                . 'Any usage and costs are billed to your account with that provider — ' . e(config('brand.name') ?: config('app.name')) . ' does not process, store, or resell API credits.</p>'
                                . '<p><strong>Security.</strong> API keys are encrypted at rest using AES-256-CBC and only decrypted in-memory at the moment of each call. '
                                . 'They are never exposed in logs, API responses, or browser sessions.</p>'
                                . '<p><strong>Your responsibility.</strong> You are responsible for your provider account, key security, and any charges. '
                                . 'Anthropic usage is governed by <a href="https://www.anthropic.com/policies/terms" target="_blank" rel="noopener" class="underline text-primary-600 dark:text-primary-400">Anthropic\'s Terms of Service</a>; '
                                . 'other providers have their own terms.</p>'
                                . '</div>'
                            )),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $provider = $data['ai_provider'] ?? AiProviderFactory::ANTHROPIC;

        if ($provider === AiProviderFactory::OPENAI_COMPATIBLE && ! $this->isHttpUrl($data['ai_compat_base_url'] ?? '')) {
            Notification::make()->title('Base URL must start with http:// or https://')->danger()->send();
            return;
        }

        AppSetting::set('ai_provider', $provider);
        $this->saveSecretIfChanged('ai_anthropic_api_key', $data['ai_anthropic_api_key'] ?? '');
        AppSetting::set('ai_model', $data['ai_model'] ?? config('ai.model'));
        AppSetting::set('ai_max_tokens', (int) ($data['ai_max_tokens'] ?? 4096));

        AppSetting::set('ai_compat_preset', $data['ai_compat_preset'] ?? 'custom');
        AppSetting::set('ai_compat_base_url', trim($data['ai_compat_base_url'] ?? ''));
        AppSetting::set('ai_compat_model', trim($data['ai_compat_model'] ?? ''));
        $this->saveSecretIfChanged('ai_compat_api_key', $data['ai_compat_api_key'] ?? '');
        AppSetting::set('ai_max_context_chars', max(0, (int) ($data['ai_max_context_chars'] ?? 0)));

        AppSetting::set('ai_verify_max_attempts', max(1, (int) ($data['ai_verify_max_attempts'] ?? 2)));
        AppSetting::set('auto_repair_enabled', ! empty($data['auto_repair_enabled']) ? '1' : '0');
        AppSetting::set('ai_auto_repair_daily_limit', max(0, (int) ($data['ai_auto_repair_daily_limit'] ?? 5)));
        AppSetting::set('ai_auto_repair_consecutive_failures', max(TestRepairService::MIN_CONSECUTIVE_FAILURES, (int) ($data['ai_auto_repair_consecutive_failures'] ?? 0)));

        Notification::make()
            ->title('AI settings saved')
            ->success()
            ->send();
    }

    /**
     * Send a one-word request using the values currently in the form (saved or not).
     */
    public function testConnection(): void
    {
        $data = $this->form->getState();

        try {
            if (($data['ai_provider'] ?? '') === AiProviderFactory::OPENAI_COMPATIBLE) {
                if (! $this->isHttpUrl($data['ai_compat_base_url'] ?? '')) {
                    throw new \InvalidArgumentException('Base URL must start with http:// or https://');
                }

                $provider = new OpenAiCompatibleProvider(
                    trim($data['ai_compat_base_url']),
                    trim($data['ai_compat_model'] ?? ''),
                    $this->resolveSecret('ai_compat_api_key', $data['ai_compat_api_key'] ?? ''),
                );
            } else {
                $provider = new AnthropicProvider(
                    $this->resolveSecret('ai_anthropic_api_key', $data['ai_anthropic_api_key'] ?? ''),
                    $data['ai_model'] ?? config('ai.model'),
                );
            }

            if (! $provider->isConfigured()) {
                throw new \RuntimeException('Provider is missing required settings.');
            }

            $result = $provider->complete([['type' => 'text', 'text' => 'Reply with the single word: ok']], [['role' => 'user', 'content' => 'ping']], 16);

            Notification::make()
                ->title('Connection successful')
                ->body('Model replied: ' . \Illuminate\Support\Str::limit(trim($result['content']), 60))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->title('Connection failed')->body($e->getMessage())->danger()->send();
        }
    }

    private function isHttpUrl(string $url): bool
    {
        return in_array(strtolower((string) parse_url(trim($url), PHP_URL_SCHEME)), ['http', 'https'], true)
            && parse_url(trim($url), PHP_URL_HOST) !== null;
    }

    /** Typed value wins; the masked placeholder means "use what is stored". */
    private function resolveSecret(string $settingKey, string $typed): string
    {
        if ($typed !== self::SECRET_PLACEHOLDER) {
            return $typed;
        }

        $stored = (string) AppSetting::get($settingKey, '');
        try {
            return Crypt::decryptString($stored);
        } catch (\Exception) {
            return $stored;
        }
    }
}
