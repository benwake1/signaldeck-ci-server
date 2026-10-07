<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Api\V1;

use App\DTOs\AiGenerationResult;
use App\Enums\ConversationStatus;
use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Services\AiTestGeneratorService;
use App\Services\ManagedSuiteService;
use App\Services\SiteCrawlerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiBuilderController extends Controller
{
    public function createConversation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'exists:projects,id'],
        ]);

        $conversation = AiConversation::create([
            'user_id' => $request->user()->id,
            'project_id' => $validated['project_id'],
            'messages' => [],
            'status' => ConversationStatus::Active,
        ]);

        return response()->json([
            'ulid' => $conversation->ulid,
            'status' => $conversation->status->value,
        ], 201);
    }

    public function show(Request $request, string $ulid): JsonResponse
    {
        $conversation = AiConversation::where('ulid', $ulid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'ulid' => $conversation->ulid,
            'project_id' => $conversation->project_id,
            'test_suite_id' => $conversation->test_suite_id,
            'title' => $conversation->title,
            'messages' => $conversation->messages,
            'crawl_data' => $conversation->crawl_data,
            'status' => $conversation->status->value,
            'created_at' => $conversation->created_at,
            'updated_at' => $conversation->updated_at,
        ]);
    }

    public function sendMessage(Request $request, string $ulid): StreamedResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'framework' => ['sometimes', 'in:cypress,playwright'],
        ]);

        $conversation = AiConversation::where('ulid', $ulid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $framework = $validated['framework'] ?? 'cypress';

        $generator = app(AiTestGeneratorService::class);

        return response()->stream(function () use ($generator, $conversation, $validated, $framework) {
            try {
                $generator->validateInput($validated['message']);

                if (!$conversation->title) {
                    $conversation->update(['title' => \Illuminate\Support\Str::limit($validated['message'], 80)]);
                }

                foreach ($generator->streamGenerate($conversation, $validated['message'], $framework) as $chunk) {
                    echo "data: " . json_encode($chunk) . "\n\n";
                    ob_flush();
                    flush();
                }

            } catch (\InvalidArgumentException $e) {
                echo "data: " . json_encode(['type' => 'error', 'message' => $e->getMessage()]) . "\n\n";
                ob_flush();
                flush();
            } catch (\Throwable $e) {
                Log::error('AI stream failed', ['error' => $e->getMessage()]);
                echo "data: " . json_encode(['type' => 'error', 'message' => 'Generation failed']) . "\n\n";
                ob_flush();
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function crawl(Request $request, string $ulid): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'url'],
        ]);

        $conversation = AiConversation::where('ulid', $ulid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        try {
            $result = app(SiteCrawlerService::class)->crawl($validated['url']);

            $conversation->update(['crawl_data' => $result->toArray()]);

            return response()->json([
                'page_title' => $result->pageTitle,
                'url' => $result->url,
                'interactive_elements' => $result->interactiveElementCount(),
                'forms' => $result->formCount(),
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('API crawl failed', ['url' => $validated['url'], 'error' => $e->getMessage()]);
            return response()->json(['error' => 'Crawl failed: ' . $e->getMessage()], 500);
        }
    }

    public function saveSuite(Request $request, string $ulid, ManagedSuiteService $suites): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'framework' => ['sometimes', 'in:cypress,playwright'],
        ]);

        $conversation = AiConversation::where('ulid', $ulid)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $files = $this->extractFilesFromMessages($conversation->messages ?? []);

        if (empty($files)) {
            return response()->json(['error' => 'No generated files found in conversation'], 422);
        }

        $suite = $suites->create(
            $conversation->project_id,
            $validated['framework'] ?? $conversation->framework ?? $conversation->project?->runner_type?->value ?? 'cypress',
            $validated['name'],
            $files,
            $conversation->crawl_data['url'] ?? null,
            $request->user()->id,
        );

        $conversation->update([
            'test_suite_id' => $suite->id,
            'status' => ConversationStatus::Completed,
        ]);

        return response()->json([
            'suite_id' => $suite->id,
            'name' => $suite->name,
            'files_count' => count($files),
        ], 201);
    }

    private function extractFilesFromMessages(array $messages): array
    {
        $files = [];

        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') !== 'assistant') {
                continue;
            }

            $content = $msg['content'] ?? '';
            preg_match_all('/```(?:javascript|js|typescript|ts|json)\s+file:(.+?)\n(.*?)```/s', $content, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $files[trim($match[1])] = trim($match[2]);
            }
        }

        return $files;
    }
}
