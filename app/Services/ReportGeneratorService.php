<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services;

use App\Models\TestRun;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

class ReportGeneratorService
{
    /**
     * Generate a branded HTML report and store it.
     */
    public function generateHtmlReport(TestRun $run): string
    {
        $run->load([
            'project.client',
            'testSuite',
            'testResults',
            'triggeredBy',
        ]);

        $html = View::make('reports.branded', [
            'run' => $run,
            'project' => $run->project,
            'client' => $run->project->client,
            'suite' => $run->testSuite,
            'results' => $run->testResults,
            'failedResults' => $run->testResults->where('status', 'failed'),
            'passedResults' => $run->testResults->where('status', 'passed'),
            'resultsBySpec' => $run->testResults->groupBy('spec_file'),
            'generatedAt' => now(),
            'reportCss' => $this->getReportCss(),
        ])->render();

        $path = "reports/run-{$run->id}/report.html";
        $disk = config('filesystems.default');
        Storage::disk($disk)->put($path, $html);

        $run->update(['report_html_path' => $path]);

        return $path;
    }

    /**
     * Read the compiled Tailwind report CSS from the Vite manifest.
     * Returns an empty string if the manifest or CSS file doesn't exist
     * (e.g. during development before a build has been run).
     */
    private function getReportCss(): string
    {
        $manifestPath = public_path('build/.vite/manifest.json');

        // Fallback for older Vite/Laravel plugin manifest location
        if (! file_exists($manifestPath)) {
            $manifestPath = public_path('build/manifest.json');
        }

        if (! file_exists($manifestPath)) {
            return '';
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $entry    = $manifest['resources/css/report.css'] ?? null;
        $cssFile  = $entry['file'] ?? null;

        if (! $cssFile) {
            return '';
        }

        $cssPath = public_path('build/' . $cssFile);

        return file_exists($cssPath) ? file_get_contents($cssPath) : '';
    }

}
