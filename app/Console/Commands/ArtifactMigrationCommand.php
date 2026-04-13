<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Console\Commands;

use App\Jobs\MigrateArtifactsToS3Job;
use App\Models\AppSetting;
use App\Models\TestRun;
use Illuminate\Console\Command;

class ArtifactMigrationCommand extends Command
{
    protected $signature   = 'artifacts:migrate-to-s3 {--dry-run : Show what would be migrated without copying}';
    protected $description = 'Migrate existing local artifacts to S3 storage';

    public function handle(): int
    {
        if (!AppSetting::get('s3_bucket')) {
            $this->error('S3 is not configured. Set s3_bucket in Filament Settings first.');
            return 1;
        }

        if (AppSetting::get('s3_migration_running') === '1') {
            $this->warn('A migration job is already running.');
            return 0;
        }

        $count = $this->pendingCount();

        if ($count === 0) {
            $this->info('No runs to migrate — all artifacts are already on S3.');
            return 0;
        }

        $this->info("Found {$count} run(s) with local artifacts.");

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no files will be copied. Remove --dry-run to proceed.');
            return 0;
        }

        $this->warn('This may copy a substantial amount of data and will run as a background job.');
        $this->warn('Local files will NOT be deleted automatically after migration.');

        if (!$this->confirm("Queue migration of {$count} run(s) to S3?")) {
            $this->info('Cancelled.');
            return 0;
        }

        MigrateArtifactsToS3Job::dispatch();
        $this->info('Migration job queued. Monitor progress with: php artisan queue:work');
        $this->info('Check s3_migration_running in AppSettings for status.');

        return 0;
    }

    private function pendingCount(): int
    {
        return TestRun::where(function ($q) {
                $q->whereNull('storage_disk')
                  ->orWhere('storage_disk', '!=', 's3');
            })
            ->whereNotNull('report_html_path')
            ->count();
    }
}
