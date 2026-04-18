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
use App\Services\ScheduledRunsService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        return response()->json(static::computeStats());
    }

    /**
     * Compute dashboard stats as a plain array.
     *
     * Extracted so it can be called from TestRunObserver without
     * instantiating a controller or constructing a JsonResponse.
     */
    public static function computeStats(): array
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
            TestRun::STATUS_CLONING,
            TestRun::STATUS_INSTALLING,
            TestRun::STATUS_RUNNING,
        ])->count();

        $queued = TestRun::where('status', TestRun::STATUS_PENDING)->count();

        $avgDuration = TestRun::where('created_at', '>=', $sevenDaysAgo)
            ->whereNotNull('duration_ms')
            ->avg('duration_ms');

        return [
            'pass_rate_30d'      => $passRate,
            'total_runs_30d'     => $totalRuns,
            'passing_runs_30d'   => $passingRuns,
            'failed_runs_30d'    => $totalRuns - $passingRuns,
            'currently_running'  => $currentlyRunning,
            'queued'             => $queued,
            'scheduled_today'    => ScheduledRunsService::countForToday(),
            'avg_duration_7d_ms' => $avgDuration ? round($avgDuration) : null,
        ];
    }
}
