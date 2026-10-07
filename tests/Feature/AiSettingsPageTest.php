<?php

namespace Tests\Feature;

use App\Filament\Pages\AiSettingsPage;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AiSettingsPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]); // in-memory sqlite; avoids needing Mockery
    }

    private function admin(): User
    {
        $u = new User(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x']);
        $u->save();
        $u->forceFill(['role' => 'admin'])->save();

        return $u;
    }

    public function test_page_renders_and_saves_compat_settings(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->assertSuccessful()
            ->fillForm([
                'ai_provider' => 'openai_compatible',
                'ai_compat_base_url' => 'http://localhost:11434/v1',
                'ai_compat_model' => 'qwen2.5-coder:14b',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('openai_compatible', AppSetting::get('ai_provider'));
    }

    public function test_saves_automated_repair_settings(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->assertFormSet(['auto_repair_enabled' => false])
            ->fillForm(['auto_repair_enabled' => true, 'ai_auto_repair_consecutive_failures' => 4])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1', AppSetting::get('auto_repair_enabled'));
        $this->assertSame('4', (string) AppSetting::get('ai_auto_repair_consecutive_failures'));
    }

    public function test_rejects_non_http_base_url(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->fillForm(['ai_provider' => 'openai_compatible', 'ai_compat_base_url' => 'file:///etc/passwd', 'ai_compat_model' => 'm'])
            ->call('save');

        $this->assertNotSame('file:///etc/passwd', AppSetting::get('ai_compat_base_url'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badUrls')]
    public function test_rejects_invalid_base_urls(string $url): void
    {
        $this->actingAs($this->admin());

        Livewire::test(AiSettingsPage::class)
            ->fillForm(['ai_provider' => 'openai_compatible', 'ai_compat_base_url' => $url, 'ai_compat_model' => 'm'])
            ->call('save');

        $this->assertNull(AppSetting::get('ai_provider'));
    }

    public static function badUrls(): array
    {
        return [['javascript:alert(1)'], ['localhost:11434/v1'], ['http://'], ['ftp://x/v1'], ['not a url']];
    }

    public function test_connection_ok(): void
    {
        $this->actingAs($this->admin());
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]])]);

        Livewire::test(AiSettingsPage::class)
            ->fillForm(['ai_provider' => 'openai_compatible', 'ai_compat_base_url' => 'http://x/v1', 'ai_compat_model' => 'm'])
            ->call('testConnection')
            ->assertNotified('Connection successful');
    }
}
