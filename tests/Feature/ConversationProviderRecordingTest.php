<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use App\Services\Ai\AiProvider;
use App\Services\AiTestGeneratorService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ConversationProviderRecordingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate', ['--force' => true]);
    }

    private function fakeProvider(string $name, string $model): AiProvider
    {
        return new class($name, $model) implements AiProvider {
            public function __construct(private string $n, private string $m) {}
            public function name(): string { return $this->n; }
            public function model(): string { return $this->m; }
            public function isConfigured(): bool { return true; }
            public function complete(array $systemBlocks, array $messages, int $maxTokens): array
            {
                return ['content' => "```javascript file:a.cy.js\nit('a', () => {});\n```", 'tokens_used' => ['total' => 100]];
            }
            public function stream(array $systemBlocks, array $messages, int $maxTokens): \Generator
            {
                yield ['type' => 'usage', 'tokens_used' => ['total' => 0]];
            }
        };
    }

    private function conversation(): AiConversation
    {
        $u = new User(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x']);
        $u->save();

        $client = Client::create(['name' => 'C', 'slug' => 'c']);
        $project = Project::create(['client_id' => $client->id, 'name' => 'P', 'slug' => 'p', 'repo_url' => 'https://example.test/r.git', 'default_branch' => 'main']);

        return AiConversation::create(['user_id' => $u->id, 'project_id' => $project->id, 'title' => 't', 'messages' => [], 'framework' => 'cypress']);
    }

    public function test_generate_records_provider_model_and_tokens(): void
    {
        $c = $this->conversation();

        (new AiTestGeneratorService($this->fakeProvider('openai_compatible', 'qwen2.5-coder:14b')))->generate($c, 'write a test');

        $c->refresh();
        $this->assertSame('openai_compatible', $c->provider);
        $this->assertSame('qwen2.5-coder:14b', $c->model);
        $this->assertSame(100, (int) $c->total_tokens);
    }

    public function test_latest_provider_wins_and_tokens_accumulate(): void
    {
        $c = $this->conversation();

        (new AiTestGeneratorService($this->fakeProvider('openai_compatible', 'qwen2.5-coder:14b')))->generate($c, 'one');
        (new AiTestGeneratorService($this->fakeProvider('anthropic', 'claude-haiku-4-5-20251001')))->refine($c->refresh(), 'two');

        $c->refresh();
        $this->assertSame('anthropic', $c->provider);
        $this->assertSame('claude-haiku-4-5-20251001', $c->model);
        $this->assertSame(200, (int) $c->total_tokens);
    }
}
