<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Console\Commands;

use App\Enums\TriggerSource;
use App\Models\TestRun;
use App\Models\TestSuite;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ScheduledRunCommand extends Command
{
    protected $signature   = 'signaldeck:run-scheduled';
    protected $description = 'Dispatch test runs for suites whose cron schedule is due.';

    public function handle(): int
    {
        $suites = TestSuite::where('schedule_enabled', true)
            ->whereNotNull('schedule_cron')
            ->where('active', true)
            ->with('project')
            ->get();

        if ($suites->isEmpty()) {
            $this->line('No scheduled suites found.');
            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($suites as $suite) {
            if (!$suite->project?->active) {
                continue;
            }

            try {
                $tz   = $suite->schedule_timezone ?? config('app.timezone');
                $cron = new CronExpression($suite->schedule_cron);
            } catch (\Throwable $e) {
                $this->warn("Suite #{$suite->id} \"{$suite->name}\": invalid cron expression — skipping.");
                continue;
            }

            // Prevent double-firing: skip if we already dispatched within the last 50 seconds.
            // The scheduler fires this command every minute; 50s gives a safe buffer for
            // slight timing drift without risk of skipping a legitimate run.
            if ($suite->last_scheduled_at &&
                $suite->last_scheduled_at->isAfter(Carbon::now()->subSeconds(50))) {
                continue;
            }

            if (!$cron->isDue(Carbon::now($tz)->format('Y-m-d H:i:s'))) {
                continue;
            }

            // Stamp last_scheduled_at before dispatching so the cooldown check
            // is visible to any concurrent invocation before the queue worker
            // acquires a write lock on the database.
            $suite->update(['last_scheduled_at' => now()]);

            $run = TestRun::create([
                'project_id'     => $suite->project_id,
                'test_suite_id'  => $suite->id,
                'runner_type'    => $suite->getEffectiveRunnerType(),
                'trigger_source' => TriggerSource::Schedule,
                'storage_disk'   => config('filesystems.default'),
                // triggered_by intentionally null — no authenticated user for scheduled runs
                'status'         => TestRun::STATUS_PENDING,
                'branch'         => $suite->effective_branch,
            ]);

            $run->dispatchJob();

            $this->line("Dispatched run #{$run->id} for suite \"{$suite->name}\" (project: {$suite->project->name}).");
            $dispatched++;
        }

        $this->line("Done — {$dispatched} run(s) dispatched.");
        return self::SUCCESS;
    }
}
