<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TestRun;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $sevenDaysAgo  = Carbon::now()->subDays(7);

        $runStats = TestRun::where('created_at', '>=', $thirtyDaysAgo)
            ->whereIn('status', [TestRun::STATUS_PASSING, TestRun::STATUS_FAILED])
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as passing", [TestRun::STATUS_PASSING])
            ->first();

        $totalRuns   = (int) $runStats->total;
        $passingRuns = (int) $runStats->passing;
        $passRate    = $totalRuns > 0 ? round(($passingRuns / $totalRuns) * 100, 1) : 0;

        $currentlyRunning = TestRun::whereIn('status', [
            TestRun::STATUS_PENDING,
            TestRun::STATUS_CLONING,
            TestRun::STATUS_INSTALLING,
            TestRun::STATUS_RUNNING,
        ])->count();

        $avgDuration = TestRun::where('created_at', '>=', $sevenDaysAgo)
            ->whereNotNull('duration_ms')
            ->avg('duration_ms');

        return response()->json([
            'pass_rate_30d'     => $passRate,
            'total_runs_30d'    => $totalRuns,
            'passing_runs_30d'  => $passingRuns,
            'failed_runs_30d'   => $totalRuns - $passingRuns,
            'currently_running' => $currentlyRunning,
            'avg_duration_7d_ms' => $avgDuration ? round($avgDuration) : null,
        ]);
    }
}
