<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Observers;

use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Resources\V1\TestRunResource;
use App\Models\RunEvent;
use App\Models\TestRun;
use Illuminate\Support\Facades\Log;

class TestRunObserver
{
    public function updated(TestRun $run): void
    {
        $statusChanged = $run->wasChanged('status');
        $reportReady   = $run->wasChanged('report_html_path') && $run->report_html_path !== null;

        if (! $statusChanged && ! $reportReady) {
            return;
        }

        // If only the report path changed (no status transition), re-emit as
        // run.completed so clients receive the updated path without a full reload.
        $eventType = ($statusChanged && ! $run->isComplete()) ? 'run.updated' : 'run.completed';

        try {
            $run->loadMissing(['project', 'testSuite', 'triggeredBy']);
            $payload = (new TestRunResource($run))->resolve();

            RunEvent::create([
                'run_id'     => $run->id,
                'event_type' => $eventType,
                'payload'    => $payload,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error("TestRunObserver: failed to write run event for run #{$run->id}", [
                'exception' => $e->getMessage(),
            ]);
        }

        try {
            RunEvent::create([
                'run_id'     => null,
                'event_type' => 'dashboard.stats_updated',
                'payload'    => DashboardController::computeStats(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error("TestRunObserver: failed to write dashboard.stats_updated event", [
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
