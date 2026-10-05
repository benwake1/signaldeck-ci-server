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
use Spatie\Browsershot\Browsershot;

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

        // The cached PDF is derived from the same run data — drop it so the next request rebuilds it.
        Storage::disk($disk)->delete("reports/run-{$run->id}/report.pdf");

        $run->update([
            'report_html_path' => $path,
            'storage_disk'     => $disk === 's3' ? 's3' : null,
        ]);

        return $path;
    }

    /**
     * Return the PDF summary report for a run, rendering and caching it on first request.
     * The file lives beside the HTML report on the run's storage disk.
     */
    public function getPdfReport(TestRun $run): string
    {
        $disk = $run->storage_disk ?? config('filesystems.default');
        $path = "reports/run-{$run->id}/report.pdf";

        try {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->get($path);
            }
        } catch (\Exception) {
            // Disk unavailable — fall through and render onto the current default disk.
            $disk = config('filesystems.default');
        }

        $pdf = $this->renderPdf($run);
        Storage::disk($disk)->put($path, $pdf);

        return $pdf;
    }

    public function renderPdf(TestRun $run): string
    {
        $run->load(['project.client', 'testSuite', 'testResults', 'triggeredBy']);
        $client = $run->project->client;

        $html = View::make('reports.pdf', [
            'run'           => $run,
            'project'       => $run->project,
            'client'        => $client,
            'suite'         => $run->testSuite,
            'resultsBySpec' => $run->testResults->groupBy('spec_file'),
            'generatedAt'   => now(),
            'logoDataUri'   => $this->logoDataUri($client?->logo_path),
        ])->render();

        $brand = e(config('brand.name') ?: config('app.name'));

        // Chrome writes its profile/crashpad/XDG data under $HOME on launch. The web server
        // user (e.g. www-data) often has a non-writable home dir, which crashes the browser
        // before it can render — give it a dedicated writable home instead.
        $chromeHome = storage_path('app/chrome-home');
        if (! is_dir($chromeHome)) {
            mkdir($chromeHome, 0775, true);
        }

        $shot = Browsershot::html($html)
            ->format('A4')
            ->showBackground()
            ->showBrowserHeaderAndFooter()
            ->hideHeader()
            ->footerHtml(
                '<div style="width:100%;font-size:8px;font-family:Helvetica,Arial,sans-serif;color:#6b7280;'
                . 'padding:0 16mm;display:flex;justify-content:space-between;">'
                . "<span>{$brand} QA Report · Run #{$run->id}</span>"
                . '<span>Page <span class="pageNumber"></span> of <span class="totalPages"></span></span></div>'
            )
            ->noSandbox()
            ->setNodeEnv(['HOME' => $chromeHome])
            ->timeout(60);

        if ($chrome = $this->chromePath()) {
            $shot->setChromePath($chrome);
        }

        return $shot->pdf();
    }

    private function chromePath(): ?string
    {
        $configured = config('services.pdf.chrome_path');
        if ($configured) {
            return $configured;
        }

        foreach ([
            '/usr/bin/google-chrome-stable',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        ] as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Inline the client logo so headless Chrome needs no network or auth to render it.
     */
    private function logoDataUri(?string $logoPath): ?string
    {
        if (! $logoPath) {
            return null;
        }

        try {
            $disk = Storage::disk(config('filesystems.default'));
            if (! $disk->exists($logoPath)) {
                $disk = Storage::disk('public');
            }
            if (! $disk->exists($logoPath)) {
                return null;
            }

            $mime = $disk->mimeType($logoPath) ?: 'image/png';

            return 'data:' . $mime . ';base64,' . base64_encode($disk->get($logoPath));
        } catch (\Exception) {
            return null;
        }
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
