<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlakyTestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('test_results')
            ->join('test_runs', 'test_results.test_run_id', '=', 'test_runs.id')
            ->select(
                'test_runs.project_id',
                'test_results.spec_file',
                'test_results.full_title',
                DB::raw('COUNT(*) as total_count'),
                DB::raw("SUM(CASE WHEN test_results.status = 'passed' THEN 1 ELSE 0 END) as pass_count"),
                DB::raw("SUM(CASE WHEN test_results.status = 'failed' THEN 1 ELSE 0 END) as fail_count"),
                DB::raw("ROUND((MIN(SUM(CASE WHEN test_results.status = 'passed' THEN 1 ELSE 0 END), SUM(CASE WHEN test_results.status = 'failed' THEN 1 ELSE 0 END)) * 1.0 / COUNT(*)) * 100, 1) as flakiness_score"),
            )
            ->whereIn('test_runs.status', ['passing', 'failed'])
            ->groupBy('test_runs.project_id', 'test_results.spec_file', 'test_results.full_title')
            ->havingRaw('COUNT(*) >= 3')
            ->havingRaw("SUM(CASE WHEN test_results.status = 'passed' THEN 1 ELSE 0 END) > 0")
            ->havingRaw("SUM(CASE WHEN test_results.status = 'failed' THEN 1 ELSE 0 END) > 0")
            ->orderByDesc('flakiness_score');

        if ($request->filled('project_id')) {
            $query->where('test_runs.project_id', (int) $request->input('project_id'));
        }

        $page = max(1, min((int) $request->input('page', 1), 10000));
        $results = $query->paginate(50, ['*'], 'page', $page);

        return response()->json($results);
    }
}
