<?php

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

        $runs = TestRun::whereIn('status', ['passing', 'failed', 'error', 'cancelled'])
            ->where('created_at', '<', $cutoff)
            ->whereNotNull('report_html_path')
            ->get();

        if ($runs->isEmpty()) {
            $this->info("No runs older than {$days} days with artifacts to clean up.");
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Found {$runs->count()} run(s) older than {$days} days.");
        $this->newLine();

        $totalFreed = 0;

        foreach ($runs as $run) {
            $this->line("  Run #{$run->id} — {$run->created_at->format('d M Y')}");

            // Reports (local/private disk)
            $reportDir = "reports/run-{$run->id}";
            if (Storage::disk('local')->exists($reportDir)) {
                $size = $this->directorySize(Storage::disk('local')->path($reportDir));
                $this->line("    → Reports: " . $this->humanSize($size));
                $totalFreed += $size;

                if (!$dryRun) {
                    Storage::disk('local')->deleteDirectory($reportDir);
                }
            }

            // Screenshots + videos (public disk)
            $artifactDir = "runs/{$run->id}";
            if (Storage::disk('public')->exists($artifactDir)) {
                $size = $this->directorySize(Storage::disk('public')->path($artifactDir));
                $this->line("    → Media:   " . $this->humanSize($size));
                $totalFreed += $size;

                if (!$dryRun) {
                    Storage::disk('public')->deleteDirectory($artifactDir);
                }
            }

            if (!$dryRun) {
                // Null out paths on the run
                $run->update([
                    'report_html_path' => null,
                ]);

                // Null out media paths on individual results
                $run->testResults()->update([
                    'screenshot_paths' => null,
                    'video_path'       => null,
                ]);
            }
        }

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
