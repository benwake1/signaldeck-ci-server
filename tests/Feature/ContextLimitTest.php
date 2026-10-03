<?php

namespace Tests\Feature;

use App\Filament\Pages\AiSettingsPage;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\Ai\OpenAiCompatibleProvider;
use App\Services\Ai\AnthropicProvider;
use App\Services\AiTestGeneratorService;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class ContextLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);
    }

    private function fit(AiProvider $provider): array
    {
        $blocks = [['type' => 'text', 'text' => 'BASE'], ['type' => 'text', 'text' => str_repeat('x', 10000)]];
        $messages = [['role' => 'user', 'content' => 'hi']];

        $service = new AiTestGeneratorService();
        $m = new \ReflectionMethod($service, 'fitContext');
        $m->setAccessible(true);

        return $m->invoke($service, $provider, $blocks, $messages)[0];
    }

    public function test_limit_saved_in_admin_form_truncates_crawl_data_for_local_models(): void
    {
        $u = new User(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x']);
        $u->save();
        $u->forceFill(['role' => 'admin'])->save();
        $this->actingAs($u);

        Livewire::test(AiSettingsPage::class)
            ->fillForm([
                'ai_provider' => 'openai_compatible',
                'ai_compat_base_url' => 'http://localhost:11434/v1',
                'ai_compat_model' => 'qwen2.5-coder:14b',
                'ai_max_context_chars' => 3000,
            ])
            ->call('save');

        $this->assertSame(3000, (int) AppSetting::get('ai_max_context_chars'));

        $blocks = $this->fit(new OpenAiCompatibleProvider('http://localhost:11434/v1', 'm', ''));

        $this->assertSame('BASE', $blocks[0]['text']);
        $this->assertLessThan(3200, strlen($blocks[1]['text']));
        $this->assertStringEndsWith('(truncated)', $blocks[1]['text']);
    }

    public function test_zero_disables_truncation_and_anthropic_is_never_truncated(): void
    {
        AppSetting::set('ai_max_context_chars', 0);
        $this->assertSame(10000, strlen($this->fit(new OpenAiCompatibleProvider('http://x/v1', 'm', ''))[1]['text']));

        AppSetting::set('ai_max_context_chars', 3000);
        $this->assertSame(10000, strlen($this->fit(new AnthropicProvider('k', 'm'))[1]['text']));
    }
}
