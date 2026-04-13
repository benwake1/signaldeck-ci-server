<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Console\Commands;

use App\Models\TestRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanupOldArtifacts extends Command
{
    protected $signature   = 'runs:cleanup {--days=30 : Delete artifacts older than this many days} {--dry-run : List what would be deleted without deleting}';
    protected $description = 'Delete screenshots, videos, and reports for completed runs older than the specified number of days';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $query = TestRun::whereIn('status', ['passing', 'failed', 'error', 'cancelled'])
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('report_html_path');

        $count = $query->count();

        if ($count === 0) {
            $this->info("No runs older than {$days} days with artifacts to clean up.");
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Found {$count} run(s) older than {$days} days.");
        $this->newLine();

        $totalFreed = 0;

        // chunkById avoids loading all rows into memory at once.
        $query->chunkById(50, function ($runs) use ($dryRun, &$totalFreed) {
            foreach ($runs as $run) {
                $disk      = $run->storage_disk ?? config('filesystems.default');
                $mediaDisk = $disk === 's3' ? 's3' : 'public';

                $this->line("  Run #{$run->id} — {$run->created_at->format('d M Y')} [{$disk}]");

                $reportDir = "reports/run-{$run->id}";
                if (Storage::disk($disk)->exists($reportDir)) {
                    if ($disk === 's3') {
                        $this->line("    → Reports: (S3 directory)");
                    } else {
                        $size = $this->directorySize(Storage::disk($disk)->path($reportDir));
                        $this->line("    → Reports: " . $this->humanSize($size));
                        $totalFreed += $size;
                    }
                    if (!$dryRun) {
                        Storage::disk($disk)->deleteDirectory($reportDir);
                    }
                }

                $artifactDir = "runs/{$run->id}";
                if (Storage::disk($mediaDisk)->exists($artifactDir)) {
                    if ($mediaDisk === 's3') {
                        $this->line("    → Media:   (S3 directory)");
                    } else {
                        $size = $this->directorySize(Storage::disk($mediaDisk)->path($artifactDir));
                        $this->line("    → Media:   " . $this->humanSize($size));
                        $totalFreed += $size;
                    }
                    if (!$dryRun) {
                        Storage::disk($mediaDisk)->deleteDirectory($artifactDir);
                    }
                }

                if (!$dryRun) {
                    $run->update(['report_html_path' => null, 'merged_json_path' => null]);
                    $run->testResults()->update(['screenshot_paths' => null, 'video_path' => null]);
                }
            }
        }); // end chunkById

        $this->newLine();
        $action = $dryRun ? 'Would free' : 'Freed';
        $this->info("{$action} approximately " . $this->humanSize($totalFreed) . " of disk space.");

        return self::SUCCESS;
    }

    private function directorySize(string $path): int
    {
        if (!is_dir($path)) return 0;

        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024)        return "{$bytes} B";
        if ($bytes < 1048576)     return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824)  return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 2) . ' GB';
    }
}
