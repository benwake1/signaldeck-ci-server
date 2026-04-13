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
use App\Http\Requests\Api\V1\TriggerTestRunRequest;
use App\Http\Resources\V1\TestResultResource;
use App\Http\Resources\V1\TestRunReportResource;
use App\Http\Resources\V1\TestRunResource;
use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TestRunController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TestRun::with(['project', 'testSuite', 'triggeredBy']);

        if ($request->filled('project_id')) {
            $query->where('project_id', (int) $request->input('project_id'));
        }

        if ($request->filled('test_suite_id')) {
            $query->where('test_suite_id', (int) $request->input('test_suite_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('runner_type')) {
            $query->where('runner_type', $request->input('runner_type'));
        }

        return TestRunResource::collection($query->latest()->paginate(25));
    }

    public function show(TestRun $testRun): TestRunResource
    {
        $testRun->load(['project', 'testSuite', 'triggeredBy', 'testResults']);

        return new TestRunResource($testRun);
    }

    public function store(TriggerTestRunRequest $request): JsonResponse
    {
        $suite   = TestSuite::with('project')->findOrFail($request->validated('test_suite_id'));
        $project = $suite->project;

        $run = TestRun::create([
            'project_id'     => $project->id,
            'test_suite_id'  => $suite->id,
            'runner_type'    => $project->runner_type,
            'triggered_by'   => $request->user()->id,
            'trigger_source' => TriggerSource::Manual,
            'storage_disk'   => config('filesystems.default'),
            'status'         => TestRun::STATUS_PENDING,
            'branch'         => $request->validated('branch') ?? $suite->effective_branch ?? $project->default_branch ?? 'main',
        ]);

        $run->dispatchJob();

        return (new TestRunResource($run->load(['project', 'testSuite', 'triggeredBy'])))
            ->response()
            ->setStatusCode(201);
    }

    public function cancel(TestRun $testRun): JsonResponse
    {
        if ($testRun->isComplete()) {
            return response()->json(['message' => 'Run is already complete.'], 422);
        }

        $testRun->update([
            'status'      => TestRun::STATUS_CANCELLED,
            'finished_at' => now(),
        ]);

        return response()->json(['message' => 'Run cancelled.']);
    }

    public function destroy(TestRun $testRun): JsonResponse
    {
        $testRun->testResults()->delete();
        $testRun->delete();

        return response()->json(['message' => 'Test run deleted.']);
    }

    public function results(TestRun $testRun): AnonymousResourceCollection
    {
        $results = $testRun->testResults()->paginate(100);

        return TestResultResource::collection($results);
    }

    public function logs(TestRun $testRun): JsonResponse
    {
        return response()->json([
            'id'         => $testRun->id,
            'status'     => $testRun->status,
            'log_output' => $testRun->log_output,
        ]);
    }

    public function report(TestRun $testRun): TestRunReportResource
    {
        $testRun->load([
            'project.client',
            'testSuite',
            'triggeredBy',
            'testResults',
        ]);

        return new TestRunReportResource($testRun);
    }

    public function compare(Request $request): JsonResponse
    {
        $request->validate([
            'run_a' => 'required|integer|exists:test_runs,id',
            'run_b' => 'required|integer|exists:test_runs,id',
        ]);

        $runA = TestRun::with(['project', 'testSuite', 'triggeredBy', 'testResults'])
            ->findOrFail($request->input('run_a'));
        $runB = TestRun::with(['project', 'testSuite', 'triggeredBy', 'testResults'])
            ->findOrFail($request->input('run_b'));

        return response()->json([
            'run_a' => new TestRunResource($runA),
            'run_b' => new TestRunResource($runB),
        ]);
    }
}
