<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Jobs;

use App\Events\SuiteHealthBelowThreshold;
use App\Events\SuiteHealthBreached;
use App\Models\TestRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CheckSuiteHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly TestRun $run) {}

    public function handle(): void
    {
        $suite = $this->run->testSuite;

        if (!$suite || $suite->pass_rate_threshold === null) {
            return;
        }

        $healthScore = $suite->health_score;

        if ($healthScore >= $suite->pass_rate_threshold) {
            return;
        }

        // Outside the alert cooldown below: automated repair needs to see the
        // run that completes a failure streak, which is usually within the hour.
        event(new SuiteHealthBelowThreshold($suite, $healthScore, $suite->pass_rate_threshold));

        // Atomically claim the breach slot — only one caller wins even if this job
        // is dispatched twice (e.g. catch + failed() both fire) or runs concurrently.
        // The UPDATE only touches rows whose cooldown has expired or was never set.
        $claimed = DB::table('test_suites')
            ->where('id', $suite->id)
            ->where(function ($q) {
                $q->whereNull('last_breach_at')
                  ->orWhere('last_breach_at', '<', now()->subHour());
            })
            ->update(['last_breach_at' => now()]);

        if (!$claimed) {
            return;
        }

        event(new SuiteHealthBreached($suite, $healthScore, $suite->pass_rate_threshold));
    }
}
