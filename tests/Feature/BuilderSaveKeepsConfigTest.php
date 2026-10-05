<?php

namespace Tests\Feature;

use App\Enums\SourceType;
use App\Filament\Pages\AiTestBuilderPage;
use App\Models\AiConversation;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ManagedTestFile;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class BuilderSaveKeepsConfigTest extends TestCase
{
    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);

        AppSetting::set('ai_provider', 'openai_compatible');
        AppSetting::set('ai_compat_base_url', 'http://localhost:11434/v1');
        AppSetting::set('ai_compat_model', 'm');

        $this->user = new User(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x']);
        $this->user->save();
        $this->user->forceFill(['role' => 'admin'])->save();
        $this->actingAs($this->user);

        $client = Client::create(['name' => 'C', 'slug' => 'c']);
        $this->project = Project::create(['client_id' => $client->id, 'name' => 'P', 'slug' => 'p', 'repo_url' => 'https://example.test/r.git', 'default_branch' => 'main']);
    }

    private function suite(): TestSuite
    {
        $suite = TestSuite::create([
            'project_id' => $this->project->id,
            'source_type' => SourceType::Managed,
            'runner_type' => 'cypress',
            'name' => 'S',
            'spec_pattern' => '**/*.cy.{js,ts}',
            'base_url' => 'https://example.cypress.io',
            'active' => true,
        ]);
        foreach (['cypress.config.js' => "// hand edited\nmodule.exports = {};", 'cypress/e2e/old.cy.js' => "it('old', () => {});"] as $p => $c) {
            ManagedTestFile::create(['test_suite_id' => $suite->id, 'file_path' => $p, 'content' => $c]);
        }

        return $suite;
    }

    private function conversation(?TestSuite $suite): AiConversation
    {
        return AiConversation::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'test_suite_id' => $suite?->id,
            'title' => 't',
            'messages' => [],
            'framework' => 'cypress',
        ]);
    }

    public function test_regenerating_into_existing_suite_keeps_edited_config_and_replaces_specs(): void
    {
        $suite = $this->suite();
        $c = $this->conversation($suite);

        Livewire::test(AiTestBuilderPage::class)
            ->set('conversationUlid', $c->ulid)
            ->set('framework', 'cypress')
            ->set('generatedFiles', ['cypress/e2e/new.cy.js' => "it('new', () => {});"])
            ->call('saveAsSuite');

        $files = $suite->managedTestFiles()->pluck('content', 'file_path')->all();
        $this->assertSame(['cypress.config.js', 'cypress/e2e/new.cy.js'], collect(array_keys($files))->sort()->values()->all());
        $this->assertStringContainsString('hand edited', $files['cypress.config.js']);
    }

    public function test_generated_config_replaces_existing_config(): void
    {
        $suite = $this->suite();
        $c = $this->conversation($suite);

        Livewire::test(AiTestBuilderPage::class)
            ->set('conversationUlid', $c->ulid)
            ->set('generatedFiles', ['cypress.config.js' => '// from ai', 'cypress/e2e/new.cy.js' => 'x'])
            ->call('saveAsSuite');

        $files = $suite->managedTestFiles()->pluck('content', 'file_path')->all();
        $this->assertCount(2, $files);
        $this->assertSame('// from ai', $files['cypress.config.js']);
    }

    public function test_new_suite_is_seeded_with_cypress_config(): void
    {
        $c = $this->conversation(null);
        $c->update(['crawl_data' => ['url' => 'https://example.cypress.io']]);

        Livewire::test(AiTestBuilderPage::class)
            ->set('conversationUlid', $c->ulid)
            ->set('projectId', $this->project->id)
            ->set('suiteName', 'Fresh')
            ->set('generatedFiles', ['cypress/e2e/a.cy.js' => 'x'])
            ->call('saveAsSuite');

        $suite = TestSuite::where('name', 'Fresh')->firstOrFail();
        $config = $suite->managedTestFiles()->where('file_path', 'cypress.config.js')->first();
        $this->assertNotNull($config);
        $this->assertStringContainsString("baseUrl: 'https://example.cypress.io'", $config->content);
    }
}
