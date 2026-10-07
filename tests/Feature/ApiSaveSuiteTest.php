<?php

namespace Tests\Feature;

use App\Enums\ConversationStatus;
use App\Models\AiConversation;
use App\Models\Client;
use App\Models\Project;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ApiSaveSuiteTest extends TestCase
{
    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);

        $this->user = new User(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x']);
        $this->user->save();
        $this->user->forceFill(['role' => 'admin'])->save();
        $this->withToken($this->user->createToken('t', ['desktop:read', 'desktop:write'])->plainTextToken);

        $client = Client::create(['name' => 'C', 'slug' => 'c']);
        $this->project = Project::create(['client_id' => $client->id, 'name' => 'P', 'slug' => 'p', 'repo_url' => 'https://example.test/r.git', 'default_branch' => 'main']);
    }

    private function conversation(array $files, ?string $framework = null): AiConversation
    {
        $content = '';
        foreach ($files as $path => $code) {
            $content .= "```js file:{$path}\n{$code}\n```\n";
        }

        return AiConversation::create([
            'user_id' => $this->user->id,
            'project_id' => $this->project->id,
            'framework' => $framework,
            'crawl_data' => ['url' => 'https://example.cypress.io'],
            'messages' => [['role' => 'assistant', 'content' => $content]],
            'status' => ConversationStatus::Active,
        ]);
    }

    public function test_api_save_creates_runnable_cypress_suite(): void
    {
        $conversation = $this->conversation(['cypress/e2e/home.cy.js' => "it('loads', () => {});"]);

        $this->postJson("/api/v1/ai-builder/conversations/{$conversation->ulid}/save-suite", ['name' => 'Home'])
            ->assertCreated();

        $suite = TestSuite::firstOrFail();
        $this->assertSame('cypress', $suite->runner_type->value);
        $this->assertSame('**/*.cy.{js,ts}', $suite->spec_pattern);
        $this->assertSame('https://example.cypress.io', $suite->base_url);

        $config = $suite->managedTestFiles()->where('file_path', 'cypress.config.js')->first();
        $this->assertNotNull($config);
        $this->assertStringContainsString("baseUrl: 'https://example.cypress.io'", $config->content);

        $this->assertSame($suite->id, $conversation->fresh()->test_suite_id);
    }

    public function test_api_save_playwright_uses_spec_pattern_without_cypress_config(): void
    {
        $conversation = $this->conversation(['tests/home.spec.ts' => "test('loads', async () => {});"], 'playwright');

        $this->postJson("/api/v1/ai-builder/conversations/{$conversation->ulid}/save-suite", ['name' => 'Home'])
            ->assertCreated();

        $suite = TestSuite::firstOrFail();
        $this->assertSame('**/*.spec.{js,ts}', $suite->spec_pattern);
        $this->assertSame(['tests/home.spec.ts'], $suite->managedTestFiles()->pluck('file_path')->all());
    }
}
