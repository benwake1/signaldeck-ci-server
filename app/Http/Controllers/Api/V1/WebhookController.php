<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Api\V1;

use App\Enums\TriggerSource;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TestRunResource;
use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function trigger(Request $request): JsonResponse
    {
        $signature = $request->header('X-Webhook-Signature');

        if (!$signature) {
            return response()->json(['message' => 'Missing signature header.'], 401);
        }

        $payload = $request->getContent();

        $data = $request->validate([
            'suite_id' => 'required|integer|exists:test_suites,id',
            'branch'   => 'nullable|string',
            'env'      => 'nullable|array',
        ]);

        $suite   = TestSuite::with('project')->findOrFail($data['suite_id']);
        $project = $suite->project;

        if (!$project->webhook_secret) {
            Log::warning('Webhook trigger attempted on project without secret', [
                'project_id' => $project->id,
            ]);
            return response()->json(['message' => 'Webhooks not configured for this project.'], 403);
        }

        $expected = hash_hmac('sha256', $payload, $project->webhook_secret);

        if (!hash_equals($expected, $signature)) {
            Log::warning('Invalid webhook signature', ['project_id' => $project->id]);
            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        try {
            $run = TestRun::create([
                'project_id'     => $project->id,
                'test_suite_id'  => $suite->id,
                'runner_type'    => $project->runner_type,
                'trigger_source' => TriggerSource::Webhook,
                'storage_disk'   => config('filesystems.default'),
                // triggered_by (user FK) intentionally left null — no authenticated user for webhooks
                'status'         => TestRun::STATUS_PENDING,
                'branch'         => $data['branch'] ?? $suite->effective_branch ?? $project->default_branch ?? 'main',
            ]);

            $run->dispatchJob();

            return (new TestRunResource($run->load(['project', 'testSuite'])))
                ->response()
                ->setStatusCode(201);
        } catch (\Exception $e) {
            Log::error('Webhook trigger failed', [
                'project_id' => $project->id,
                'suite_id'   => $suite->id,
                'error'      => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Failed to trigger run.'], 500);
        }
    }
}
