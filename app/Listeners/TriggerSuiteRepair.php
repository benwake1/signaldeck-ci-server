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

        $cacheKey = "auto_repair_attempted_suite_{$suite->id}";
        if (! Cache::add($cacheKey, true, now()->addDay())) {
            return;
        }

        app(TestRepairService::class)->attemptRepair($suite);
    }
}
