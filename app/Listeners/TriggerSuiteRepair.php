<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Listeners;

use App\Enums\SourceType;
use App\Events\SuiteHealthBreached;
use App\Models\AppSetting;
use App\Services\TestRepairService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TriggerSuiteRepair implements ShouldQueue
{
    public function handle(SuiteHealthBreached $event): void
    {
        if (AppSetting::get('auto_repair_enabled', '0') !== '1') {
            return;
        }

        $suite = $event->suite;

        if ($suite->source_type !== SourceType::Managed) {
            Log::info('Skipping automated repair: suite is not managed', ['suite_id' => $suite->id]);
            return;
        }

        if (empty($suite->base_url)) {
            Log::info('Skipping automated repair: suite has no base_url configured', ['suite_id' => $suite->id]);
            return;
        }

        // Checked before the cap/cooldown so an early breach (one or two
        // failures) doesn't burn the suite's daily attempt.
        $repairs = app(TestRepairService::class);
        if (! $repairs->failingRunForRepair($suite)) {
            Log::info('Skipping automated repair: not enough consecutive failed runs yet', ['suite_id' => $suite->id]);
            return;
        }

        // Global daily cap on unattended AI spend (0 = unlimited).
        $dailyLimit = (int) AppSetting::get('ai_auto_repair_daily_limit', 5);
        if ($dailyLimit > 0) {
            $counterKey = 'auto_repair_count_' . now()->toDateString();
            Cache::add($counterKey, 0, now()->endOfDay());

            if (Cache::increment($counterKey) > $dailyLimit) {
                Log::info('Skipping automated repair: daily AI repair limit reached', ['suite_id' => $suite->id, 'limit' => $dailyLimit]);
                return;
            }
        }

        $cacheKey = "auto_repair_attempted_suite_{$suite->id}";
        if (! Cache::add($cacheKey, true, now()->addDay())) {
            return;
        }

        $repairs->attemptRepair($suite);
    }
}
