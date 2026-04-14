<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Jobs;

use App\Models\AppSetting;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Services\ReportGeneratorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MigrateArtifactsToS3Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200; // 2 hours max
    public int $tries   = 1;    // do not retry; the job resumes naturally on re-dispatch

    public function handle(): void
    {
        AppSetting::set('s3_migration_running', '1');

        AppSetting::set('s3_migration_cancel', '0');

        try {
            TestRun::where(function ($q) {
                    $q->whereNull('storage_disk')
                      ->orWhere('storage_disk', '!=', 's3');
                })
                ->where(function ($q) {
                    $q->whereNotNull('report_html_path')
                      ->orWhereHas('testResults', function ($rq) {
                          $rq->whereNotNull('screenshot_paths')
                             ->orWhereNotNull('video_path');
                      });
                })
                ->with('testResults')
                ->chunkById(25, function ($runs) {
                    if (AppSetting::get('s3_migration_cancel') === '1') {
                        Log::info('S3 artifact migration cancelled by user.');
                        return false; // stops chunkById iteration
                    }

                    foreach ($runs as $run) {
                        $this->migrateRun($run);
                    }
                });
        } finally {
            AppSetting::set('s3_migration_running', '0');
            AppSetting::set('s3_migration_cancel', '0');
        }
    }

    private function migrateRun(TestRun $run): void
    {
        try {
            // Copy HTML report from local disk
            if ($run->report_html_path && Storage::disk('local')->exists($run->report_html_path)) {
                Storage::disk('s3')->writeStream(
                    $run->report_html_path,
                    Storage::disk('local')->readStream($run->report_html_path)
                );
            }

            // Copy merged JSON from local disk
            if ($run->merged_json_path && Storage::disk('local')->exists($run->merged_json_path)) {
                Storage::disk('s3')->writeStream(
                    $run->merged_json_path,
                    Storage::disk('local')->readStream($run->merged_json_path)
                );
            }

            // Copy screenshots and videos — check public disk first (pre-refactor runs),
            // then local disk (runs created after the S3 storage refactor was deployed).
            $run->testResults->each(function (TestResult $result) {
                foreach ($result->screenshot_paths ?? [] as $path) {
                    $srcDisk = Storage::disk('public')->exists($path) ? 'public' : 'local';
                    if (Storage::disk($srcDisk)->exists($path)) {
                        Storage::disk('s3')->writeStream(
                            $path,
                            Storage::disk($srcDisk)->readStream($path)
                        );
                    }
                }
                if ($result->video_path) {
                    $srcDisk = Storage::disk('public')->exists($result->video_path) ? 'public' : 'local';
                    if (Storage::disk($srcDisk)->exists($result->video_path)) {
                        Storage::disk('s3')->writeStream(
                            $result->video_path,
                            Storage::disk($srcDisk)->readStream($result->video_path)
                        );
                    }
                }
            });

            $run->update(['storage_disk' => 's3']);

            // Regenerate the HTML report now that storage_disk is s3, so asset URLs
            // in the report use the proxy route rather than the old local paths.
            // If regeneration fails, null out report_html_path so lazy regeneration
            // kicks in on next access rather than serving stale content.
            if ($run->report_html_path) {
                try {
                    app(ReportGeneratorService::class)->generateHtmlReport($run->fresh());
                } catch (\Throwable $re) {
                    Log::warning("S3 migration: could not regenerate report for run #{$run->id}, clearing path for lazy rebuild.", [
                        'error' => $re->getMessage(),
                    ]);
                    $run->update(['report_html_path' => null]);
                }
            }

            Log::info("S3 artifact migration: run #{$run->id} complete.");

        } catch (\Throwable $e) {
            Log::error("S3 artifact migration failed for run #{$run->id}", [
                'error' => $e->getMessage(),
            ]);
            // Continue to next run — do not rethrow.
        }
    }
}
