<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\TestResultResource;
use App\Models\TestResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TestHistoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'project_id' => 'required|integer|exists:projects,id',
            'spec_file'  => 'required|string',
            'full_title' => 'required|string',
        ]);

        $results = TestResult::whereHas('testRun', function ($q) use ($request) {
            $q->where('project_id', $request->input('project_id'))
              ->whereIn('status', ['passing', 'failed']);
        })
            ->where('spec_file', $request->input('spec_file'))
            ->where('full_title', $request->input('full_title'))
            ->with('testRun:id,status,branch,created_at,duration_ms,storage_disk')
            ->latest('id')
            ->limit(50)
            ->get();

        $passed    = $results->where('status', 'passed')->count();
        $failed    = $results->where('status', 'failed')->count();
        $total     = $results->count();
        $passRate  = $total > 0 ? round(($passed / $total) * 100, 1) : 0;
        $avgDuration = $results->avg('duration_ms');

        // Longest consecutive failure streak
        $maxStreak = 0;
        $streak    = 0;
        foreach ($results as $result) {
            if ($result->status === 'failed') {
                $streak++;
                $maxStreak = max($maxStreak, $streak);
            } else {
                $streak = 0;
            }
        }

        return response()->json([
            'summary' => [
                'total_runs'       => $total,
                'passed'           => $passed,
                'failed'           => $failed,
                'pass_rate'        => $passRate,
                'avg_duration_ms'  => $avgDuration ? round($avgDuration) : null,
                'max_fail_streak'  => $maxStreak,
            ],
            'results' => TestResultResource::collection($results),
        ]);
    }
}
