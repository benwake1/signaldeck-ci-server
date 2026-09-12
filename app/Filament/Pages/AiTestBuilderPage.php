<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Filament\Pages;

use App\DTOs\AiGenerationResult;
use App\Enums\ConversationStatus;
use App\Enums\RecordingStatus;
use App\Enums\SourceType;
use App\Enums\VerificationStatus;
use App\Jobs\VerifyGeneratedTestJob;
use App\Models\AiConversation;
use App\Models\ManagedTestFile;
use App\Models\Project;
use App\Models\TestRecordingSession;
use App\Models\TestSuite;
use App\Models\AppSetting;
use App\Services\AiTestGeneratorService;
use App\Services\SiteCrawlerService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AiTestBuilderPage extends Page
{
    protected static ?string $navigationIcon  = null;
    protected static ?string $navigationLabel = 'AI Test Builder';
    protected static ?string $navigationGroup = 'Testing';
    protected static ?int    $navigationSort  = 6;
    protected static string  $view            = 'filament.pages.ai-test-builder';
    protected static ?string $title           = 'AI Test Builder';
    protected static ?string $slug            = 'ai-test-builder';

    public ?int $projectId = null;
    public ?string $conversationUlid = null;
    public ?int $preloadedSuiteId = null;
    public string $userMessage = '';
    public string $crawlUrl = '';
    public string $framework = 'cypress';
    public bool $isCrawling = false;
    public bool $isGenerating = false;
    public array $generatedFiles = [];
    public array $chatMessages = [];
    public string $suiteName = '';
    public ?string $verificationStatus = null;
    public ?string $verificationLabel = null;
    public ?string $verificationOutput = null;
    public array $envVarsNeeded = [];

    public ?int $recordingSessionId = null;
    public ?string $recordingToken = null;
    public ?string $recordingStatus = null;
    public int $recordingStepCount = 0;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (!($user?->isAdmin() || $user?->isPM())) {
            return false;
        }

        return !empty(AppSetting::get('ai_anthropic_api_key'));
    }

    public function mount(): void
    {
        $projectId = request()->integer('project_id') ?: null;
        if ($projectId && Project::where('id', $projectId)->exists()) {
            $this->projectId = $projectId;
            $project = Project::find($projectId);
            $this->framework = $project->runner_type->value ?? 'cypress';
        }

        $conversationUlid = request()->query('conversation');
        if ($conversationUlid) {
            $this->loadConversation($conversationUlid);
            return;
        }

        $suiteId = request()->integer('suite_id') ?: null;
        if ($suiteId) {
            $this->loadSuiteFiles($suiteId);
        }
    }

    public function getConversation(): ?AiConversation
    {
        if (!$this->conversationUlid) {
            return null;
        }

        return AiConversation::where('ulid', $this->conversationUlid)->first();
    }

    public function getConversationsProperty(): array
    {
        if (!$this->projectId) {
            return [];
        }

        // Includes automated-repair conversations (user_id null, created by
        // TestRepairService against a health-breached suite) alongside the
        // viewer's own — otherwise the only way back to one is the one-time
        // admin notification email.
        return AiConversation::where('project_id', $this->projectId)
            ->where(fn ($q) => $q->where('user_id', auth()->id())->orWhereNull('user_id'))
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get()
            ->map(fn (AiConversation $c) => [
                'ulid' => $c->ulid,
                'title' => $c->title ?: 'Untitled conversation',
                'status' => $c->status->value,
                'updated_at' => $c->updated_at->diffForHumans(),
                'is_repair' => $c->user_id === null,
            ])
            ->toArray();
    }

    public function getProjectsProperty(): array
    {
        return Project::orderBy('name')
            ->get(['id', 'name', 'runner_type'])
            ->toArray();
    }

    public function selectProject(int $projectId): void
    {
        $this->projectId = $projectId;
        $this->conversationUlid = null;
        $this->preloadedSuiteId = null;
        $this->chatMessages = [];
        $this->generatedFiles = [];
        $this->envVarsNeeded = [];
        $this->applyVerificationState(null);
        $this->resetRecordingState();

        $project = Project::find($projectId);
        if ($project) {
            $this->framework = $project->runner_type->value ?? 'cypress';
        }
    }

    public function newConversation(): void
    {
        $this->conversationUlid = null;
        $this->preloadedSuiteId = null;
        $this->chatMessages = [];
        $this->generatedFiles = [];
        $this->envVarsNeeded = [];
        $this->userMessage = '';
        $this->crawlUrl = '';
        $this->suiteName = '';
        $this->isCrawling = false;
        $this->isGenerating = false;
        $this->applyVerificationState(null);
        $this->resetRecordingState();
    }

    public function deleteConversation(string $ulid): void
    {
        // Also permits deleting an automated-repair conversation (user_id
        // null) once it's been reviewed — same as any other conversation
        // shown in the sidebar (see getConversationsProperty()).
        $conversation = AiConversation::where('ulid', $ulid)
            ->where(fn ($q) => $q->where('user_id', auth()->id())->orWhereNull('user_id'))
            ->first();

        if (!$conversation) {
            Notification::make()->title('Conversation not found')->danger()->send();
            return;
        }

        // If we're deleting the currently active conversation, reset state
        if ($this->conversationUlid === $ulid) {
            $this->newConversation();
        }

        $conversation->delete();

        Notification::make()->title('Conversation deleted')->success()->send();
    }

    public function clearAllConversations(): void
    {
        if (!$this->projectId) {
            return;
        }

        $count = AiConversation::where('project_id', $this->projectId)
            ->where('user_id', auth()->id())
            ->count();

        if ($count === 0) {
            Notification::make()->title('No conversations to clear')->warning()->send();
            return;
        }

        AiConversation::where('project_id', $this->projectId)
            ->where('user_id', auth()->id())
            ->delete();

        $this->newConversation();

        Notification::make()
            ->title("{$count} conversation(s) cleared")
            ->success()
            ->send();
    }

    public function loadConversation(string $ulid): void
    {
        $conversation = AiConversation::where('ulid', $ulid)
            ->where('user_id', auth()->id())
            ->first();

        if (!$conversation) {
            Notification::make()->title('Conversation not found')->danger()->send();
            return;
        }

        $this->conversationUlid = $conversation->ulid;
        $this->projectId = $conversation->project_id;
        $this->framework = $conversation->framework ?? $conversation->project?->runner_type?->value ?? 'cypress';
        $this->chatMessages = $conversation->messages ?? [];
        $this->rebuildFilesFromMessages();
        $this->applyVerificationState($conversation);
        $this->resetRecordingState();

        $session = TestRecordingSession::where('ai_conversation_id', $conversation->id)->latest()->first();
        if ($session) {
            $this->applyRecordingState($session);
        }

        $this->dispatch('scroll-chat');
    }

    public function loadSuiteFiles(int $suiteId): void
    {
        $suite = TestSuite::where('id', $suiteId)
            ->where('source_type', SourceType::Managed)
            ->first();

        if (!$suite) {
            return;
        }

        $this->projectId = $suite->project_id;
        $this->framework = $suite->getEffectiveRunnerType()->value;
        $this->suiteName = $suite->name;
        $this->preloadedSuiteId = $suite->id;

        $this->generatedFiles = [];
        foreach ($suite->managedTestFiles as $file) {
            $this->generatedFiles[$file->file_path] = $file->content;
        }
        $this->envVarsNeeded = $this->extractEnvVarNames($this->generatedFiles);

        if (empty($this->generatedFiles)) {
            $this->chatMessages = [
                [
                    'role' => 'assistant',
                    'content' => "Opened the **{$suite->name}** suite. It has no test files yet — describe what you'd like to test and I'll generate them.",
                    'timestamp' => now()->toIso8601String(),
                ],
            ];
        } else {
            $this->chatMessages = [
                [
                    'role' => 'assistant',
                    'content' => "Loaded " . count($this->generatedFiles) . " existing test file(s) from the **{$suite->name}** suite. You can ask me to modify, extend, or regenerate these tests.",
                    'timestamp' => now()->toIso8601String(),
                ],
            ];
        }
    }

    public function sendMessage(): void
    {
        $message = trim($this->userMessage);
        if ($message === '') {
            return;
        }

        if (!$this->projectId) {
            Notification::make()->title('Please select a project first')->warning()->send();
            return;
        }

        $this->isGenerating = true;
        $this->userMessage = '';

        try {
            $generator = app(AiTestGeneratorService::class);

            $conversation = $this->getConversation();
            $isNew = !$conversation;

            if ($isNew) {
                $project = Project::find($this->projectId);
                $conversation = AiConversation::create([
                    'user_id' => auth()->id(),
                    'project_id' => $this->projectId,
                    'test_suite_id' => $this->preloadedSuiteId,
                    'title' => Str::limit($message, 80),
                    'messages' => [],
                    'crawl_data' => $project?->crawl_data,
                    'framework' => $this->framework,
                    'status' => ConversationStatus::Active,
                ]);
                $this->conversationUlid = $conversation->ulid;
                $this->preloadedSuiteId = null;
            }

            // If pre-seeded from a suite, include existing files as context
            if ($isNew && !empty($this->generatedFiles)) {
                $fileContext = "Here are the existing test files:\n\n";
                foreach ($this->generatedFiles as $path => $content) {
                    $fileContext .= "```javascript file:{$path}\n{$content}\n```\n\n";
                }
                $message = $fileContext . $message;
            }

            $result = $isNew
                ? $generator->generate($conversation, $message, $this->framework)
                : $generator->refine($conversation, $message, $this->framework);

            $this->chatMessages = $conversation->fresh()->messages ?? [];
            $this->mergeGeneratedFiles($result);
            $this->dispatchVerification($conversation, $result);

            $this->dispatch('scroll-chat');

            Notification::make()->title('Response generated')->success()->send();

        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        } catch (\Throwable $e) {
            Log::error('AI generation failed', ['error' => $e->getMessage()]);
            Notification::make()
                ->title('Generation failed')
                ->body('An error occurred. Please try again.')
                ->danger()
                ->send();
        } finally {
            $this->isGenerating = false;
        }
    }

    public function crawlSite(): void
    {
        $url = trim($this->crawlUrl);
        if ($url === '') {
            Notification::make()->title('Please enter a URL to crawl')->warning()->send();
            return;
        }

        if (!$this->projectId) {
            Notification::make()->title('Please select a project first')->warning()->send();
            return;
        }

        $this->isCrawling = true;

        try {
            $crawler = app(SiteCrawlerService::class);
            $result = $crawler->crawl($url);
            $crawlArray = $result->toArray();

            $project = Project::find($this->projectId);
            $project?->update(['crawl_data' => $crawlArray]);

            $conversation = $this->getConversation();
            if (!$conversation) {
                $conversation = AiConversation::create([
                    'user_id' => auth()->id(),
                    'project_id' => $this->projectId,
                    'title' => "Crawl: {$url}",
                    'messages' => [],
                    'crawl_data' => $crawlArray,
                    'framework' => $this->framework,
                    'status' => ConversationStatus::Active,
                ]);
                $this->conversationUlid = $conversation->ulid;
            } else {
                $conversation->update(['crawl_data' => $crawlArray]);
            }

            $this->crawlUrl = '';
            Notification::make()
                ->title('Site crawled successfully')
                ->body("Found {$result->interactiveElementCount()} interactive elements and {$result->formCount()} forms.")
                ->success()
                ->send();

        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        } catch (\Throwable $e) {
            Log::error('Site crawl failed', ['url' => $url, 'error' => $e->getMessage()]);
            Notification::make()
                ->title('Crawl failed')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->isCrawling = false;
        }
    }

    public function switchFramework(string $framework): void
    {
        if (!in_array($framework, ['cypress', 'playwright'])) {
            return;
        }

        $previousFramework = $this->framework;
        $this->framework = $framework;

        $conversation = $this->getConversation();

        // Persist the framework choice on the conversation
        if ($conversation) {
            $conversation->update(['framework' => $framework]);
        }

        // Only trigger conversion if the framework actually changed and there are files to convert
        if ($previousFramework === $framework || !$conversation || empty($this->generatedFiles)) {
            return;
        }

        $this->isGenerating = true;

        try {
            $generator = app(AiTestGeneratorService::class);
            $result = $generator->regenerateForFramework($conversation, $framework);

            $this->chatMessages = $conversation->fresh()->messages ?? [];
            $this->mergeGeneratedFiles($result);
            $this->dispatchVerification($conversation, $result);

            $this->dispatch('scroll-chat');

            Notification::make()->title("Tests converted to {$framework}")->success()->send();

        } catch (\Throwable $e) {
            Log::error('Framework switch failed', ['error' => $e->getMessage()]);
            Notification::make()->title('Conversion failed')->danger()->send();
        } finally {
            $this->isGenerating = false;
        }
    }

    public function getLinkedSuiteProperty(): ?TestSuite
    {
        $conversation = $this->getConversation();
        if (!$conversation?->test_suite_id) {
            return null;
        }

        return TestSuite::find($conversation->test_suite_id);
    }

    public function saveAsSuite(): void
    {
        if (empty($this->generatedFiles)) {
            Notification::make()->title('No generated files to save')->warning()->send();
            return;
        }

        $conversation = $this->getConversation();

        if ($conversation && $conversation->fresh()->verification_status === VerificationStatus::Pending) {
            Notification::make()
                ->title('Verification is still running')
                ->body('Try again in a moment.')
                ->warning()
                ->send();
            return;
        }

        $existingSuite = $this->linkedSuite;

        try {
            if ($existingSuite) {
                // Update existing managed suite files
                $existingSuite->update(['runner_type' => $this->framework]);
                $existingSuite->managedTestFiles()->delete();

                foreach ($this->generatedFiles as $path => $content) {
                    ManagedTestFile::create([
                        'test_suite_id' => $existingSuite->id,
                        'file_path' => $path,
                        'content' => $content,
                        'generated_by' => auth()->id(),
                    ]);
                }

                Notification::make()
                    ->title('Suite updated')
                    ->body("Updated \"{$existingSuite->name}\" with " . count($this->generatedFiles) . " files.")
                    ->success()
                    ->send();
            } else {
                // Create new suite
                $name = trim($this->suiteName);
                if ($name === '') {
                    Notification::make()->title('Please enter a suite name')->warning()->send();
                    return;
                }

                $suite = TestSuite::create([
                    'project_id' => $this->projectId,
                    'source_type' => SourceType::Managed,
                    'runner_type' => $this->framework,
                    'name' => $name,
                    'spec_pattern' => '**/*.spec.{js,ts}',
                    'active' => true,
                ]);

                foreach ($this->generatedFiles as $path => $content) {
                    ManagedTestFile::create([
                        'test_suite_id' => $suite->id,
                        'file_path' => $path,
                        'content' => $content,
                        'generated_by' => auth()->id(),
                    ]);
                }

                if ($conversation) {
                    $conversation->update([
                        'test_suite_id' => $suite->id,
                        'status' => ConversationStatus::Completed,
                    ]);
                }

                $this->suiteName = '';

                Notification::make()
                    ->title('Test suite saved')
                    ->body("Created managed suite \"{$name}\" with " . count($this->generatedFiles) . " files.")
                    ->success()
                    ->send();
            }
        } catch (\Throwable $e) {
            Log::error('Failed to save managed suite', ['error' => $e->getMessage()]);
            Notification::make()->title('Failed to save suite')->danger()->send();
        }
    }

    public function downloadZip()
    {
        if (empty($this->generatedFiles)) {
            Notification::make()->title('No files to download')->warning()->send();
            return;
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'ai-tests-') . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            Notification::make()->title('Failed to create ZIP file')->danger()->send();
            return;
        }

        foreach ($this->generatedFiles as $path => $content) {
            $zip->addFromString($path, $content);
        }

        $zip->close();

        $filename = $this->framework . '-ai-tests-' . now()->format('Y-m-d') . '.zip';

        return response()->download($zipPath, $filename, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }

    public function clearGeneratedFiles(): void
    {
        $this->generatedFiles = [];
    }

    public function getProjectCrawlUrl(): ?string
    {
        if (!$this->projectId) {
            return null;
        }

        $project = Project::find($this->projectId);
        return $project?->crawl_data['url'] ?? null;
    }

    private function mergeGeneratedFiles(AiGenerationResult $result): void
    {
        foreach ($result->files as $path => $content) {
            $this->generatedFiles[$path] = $content;
        }
        $this->envVarsNeeded = $this->extractEnvVarNames($this->generatedFiles);
    }

    /**
     * @param array<string, string> $files
     * @return string[]
     */
    private function extractEnvVarNames(array $files): array
    {
        $names = [];

        foreach ($files as $content) {
            if (preg_match_all('/process\.env\.([A-Z0-9_]+)/', $content, $matches)) {
                array_push($names, ...$matches[1]);
            }
            if (preg_match_all('/Cypress\.env\([\'"]([A-Z0-9_]+)[\'"]\)/', $content, $matches)) {
                array_push($names, ...$matches[1]);
            }
        }

        return array_values(array_unique($names));
    }

    private function dispatchVerification(AiConversation $conversation, AiGenerationResult $result): void
    {
        if (empty($result->files)) {
            return;
        }

        $conversation->update([
            'verification_status' => VerificationStatus::Pending,
            'verification_output' => null,
        ]);

        $this->applyVerificationState($conversation->fresh());

        VerifyGeneratedTestJob::dispatch($conversation, $result->files, $this->framework);
    }

    public function refreshVerificationStatus(): void
    {
        $conversation = $this->getConversation();
        $this->applyVerificationState($conversation);

        // The repair loop in VerifyGeneratedTestJob runs on the queue and,
        // on a failed run, appends new turns to $conversation->messages with
        // corrected code — but this page's own $this->generatedFiles (what
        // "Save as managed suite" actually writes) was captured at dispatch
        // time and never saw those turns. Without this, a suite verified as
        // "passed" could still get saved with the pre-repair, still-broken
        // code, silently contradicting its own verified badge. Resync
        // whenever the queue has appended messages since we last checked.
        $freshMessages = $conversation?->messages ?? [];
        if (count($freshMessages) !== count($this->chatMessages)) {
            $this->chatMessages = $freshMessages;
            $this->rebuildFilesFromMessages();
        }
    }

    private function applyVerificationState(?AiConversation $conversation): void
    {
        $status = $conversation?->verification_status;

        $this->verificationStatus = $status?->value;
        $this->verificationLabel = $status?->label();
        $this->verificationOutput = $conversation?->verification_output;
    }

    private function rebuildFilesFromMessages(): void
    {
        $this->generatedFiles = [];

        foreach ($this->chatMessages as $msg) {
            if (($msg['role'] ?? '') !== 'assistant') {
                continue;
            }

            $content = $msg['content'] ?? '';
            preg_match_all('/```(?:javascript|js|typescript|ts|json)\s+file:(.+?)\n(.*?)```/s', $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $this->generatedFiles[trim($match[1])] = trim($match[2]);
            }
        }

        $this->envVarsNeeded = $this->extractEnvVarNames($this->generatedFiles);
    }

    public function startRecording(): void
    {
        if (!$this->projectId) {
            Notification::make()->title('Please select a project first')->warning()->send();
            return;
        }

        $session = TestRecordingSession::create([
            'project_id' => $this->projectId,
            'ai_conversation_id' => $this->getConversation()?->id,
            'status' => RecordingStatus::Recording,
            'expires_at' => now()->addHours(2),
        ]);

        $this->applyRecordingState($session);
    }

    public function refreshRecordingStatus(): void
    {
        if (!$this->recordingSessionId) {
            return;
        }

        $session = TestRecordingSession::find($this->recordingSessionId);
        if ($session) {
            $this->applyRecordingState($session);
        }
    }

    public function generateFromRecording(): void
    {
        if (!$this->recordingSessionId) {
            return;
        }

        $session = TestRecordingSession::find($this->recordingSessionId);
        if (!$session || $session->status !== RecordingStatus::Completed) {
            Notification::make()->title('Recording is not finished yet')->warning()->send();
            return;
        }

        if (empty($session->actions)) {
            Notification::make()->title('No actions were recorded')->warning()->send();
            return;
        }

        $this->isGenerating = true;

        try {
            $conversation = $this->getConversation();
            $isNew = !$conversation;

            if ($isNew) {
                $project = Project::find($this->projectId);
                $conversation = AiConversation::create([
                    'user_id' => auth()->id(),
                    'project_id' => $this->projectId,
                    'title' => 'Recorded flow: ' . ($session->actions[0]['url'] ?? 'untitled'),
                    'messages' => [],
                    'crawl_data' => $project?->crawl_data,
                    'recording_data' => $session->actions,
                    'framework' => $this->framework,
                    'status' => ConversationStatus::Active,
                ]);
                $this->conversationUlid = $conversation->ulid;
            } else {
                $conversation->update(['recording_data' => $session->actions]);
            }

            $session->update(['ai_conversation_id' => $conversation->id]);

            $generator = app(AiTestGeneratorService::class);
            $message = 'Generate a ' . $this->framework . ' test that replays the recorded flow above, step by step, using the exact selectors given.';

            $result = $isNew
                ? $generator->generate($conversation, $message, $this->framework)
                : $generator->refine($conversation, $message, $this->framework);

            $this->chatMessages = $conversation->fresh()->messages ?? [];
            $this->mergeGeneratedFiles($result);
            $this->dispatchVerification($conversation, $result);

            $this->dispatch('scroll-chat');

            Notification::make()->title('Test generated from recording')->success()->send();
        } catch (\InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        } catch (\Throwable $e) {
            Log::error('Generation from recording failed', ['error' => $e->getMessage()]);
            Notification::make()
                ->title('Generation failed')
                ->body('An error occurred. Please try again.')
                ->danger()
                ->send();
        } finally {
            $this->isGenerating = false;
        }
    }

    private function applyRecordingState(TestRecordingSession $session): void
    {
        $this->recordingSessionId = $session->id;
        $this->recordingToken = $session->token;
        $this->recordingStatus = $session->isExpired() ? RecordingStatus::Expired->value : $session->status->value;
        $this->recordingStepCount = count($session->actions ?? []);

        // Tells the Flow Recorder browser extension (via content-bridge.js
        // on this page) which session to capture into. The extension then
        // keeps recording across every page navigation on the target site
        // with no further action here — see browser-extension/README.md.
        if ($this->recordingStatus === RecordingStatus::Recording->value) {
            $this->dispatch(
                'signaldeck-recording-started',
                token: $session->token,
                apiBase: url('/'),
                expiresAt: $session->expires_at->timestamp * 1000,
            );
        } else {
            $this->dispatch('signaldeck-recording-stopped');
        }
    }

    private function resetRecordingState(): void
    {
        $hadActiveRecording = $this->recordingStatus === RecordingStatus::Recording->value;

        $this->recordingSessionId = null;
        $this->recordingToken = null;
        $this->recordingStatus = null;
        $this->recordingStepCount = 0;

        if ($hadActiveRecording) {
            $this->dispatch('signaldeck-recording-stopped');
        }
    }
}
