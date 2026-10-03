<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\OpenAiCompatibleProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class AiProviderFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);
    }

    public function test_defaults_to_anthropic(): void
    {
        $this->assertInstanceOf(AnthropicProvider::class, AiProviderFactory::make());
        $this->assertSame('anthropic', AiProviderFactory::providerName());
    }

    public function test_unknown_provider_value_falls_back_to_anthropic(): void
    {
        AppSetting::set('ai_provider', 'bogus');

        $this->assertInstanceOf(AnthropicProvider::class, AiProviderFactory::make());
    }

    public function test_builds_compatible_provider_from_settings(): void
    {
        AppSetting::set('ai_provider', 'openai_compatible');
        AppSetting::set('ai_compat_base_url', 'http://localhost:11434/v1');
        AppSetting::set('ai_compat_model', 'qwen2.5-coder:14b');

        $p = AiProviderFactory::make();

        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $p);
        $this->assertSame('qwen2.5-coder:14b', $p->model());
        $this->assertTrue($p->isConfigured());
    }

    public function test_compatible_provider_without_base_url_is_not_configured(): void
    {
        AppSetting::set('ai_provider', 'openai_compatible');

        $this->assertFalse(AiProviderFactory::isConfigured());
    }

    public function test_anthropic_key_is_decrypted_and_plaintext_tolerated(): void
    {
        $this->assertFalse(AiProviderFactory::isConfigured());

        AppSetting::set('ai_anthropic_api_key', Crypt::encryptString('sk-test'));
        $this->assertTrue(AiProviderFactory::isConfigured());

        AppSetting::set('ai_anthropic_api_key', 'sk-legacy-plaintext');
        $this->assertTrue(AiProviderFactory::isConfigured());
    }
}
