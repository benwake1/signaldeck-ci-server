<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Http\Controllers;

use App\Models\TestRun;
use App\Services\ReportGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ReportController
{
    public function __construct(
        private readonly ReportGeneratorService $reportGenerator
    ) {}

    /**
     * View/download the HTML report.
     */
    public function html(TestRun $testRun): Response
    {
        $disk = $testRun->storage_disk ?? config('filesystems.default');

        // Regenerate if missing
        if (!$testRun->report_html_path || !Storage::disk($disk)->exists($testRun->report_html_path)) {
            $this->reportGenerator->generateHtmlReport($testRun);
            $testRun->refresh();
        }

        $this->validateReportPath($testRun->report_html_path, $testRun->id);

        return response(Storage::disk($disk)->get($testRun->report_html_path), 200, [
            'Content-Type' => 'text/html',
        ]);
    }

    /**
     * Publicly shareable report (for client delivery without login).
     * Uses a simple token stored on the run for validation.
     */
    public function share(Request $request, TestRun $testRun, string $token): Response
    {
        $expiry = (int) $request->query('expires', 0);

        // Reject if missing or expired
        if ($expiry === 0 || now()->timestamp > $expiry) {
            abort(403, 'This report link has expired.');
        }

        $shareKey = hash_hmac('sha256', 'report-share-v1', config('app.key'));
        $expectedToken = hash_hmac('sha256', "report-{$testRun->id}-{$expiry}", $shareKey);

        if (!hash_equals($expectedToken, $token)) {
            abort(403, 'Invalid or expired report link.');
        }

        $disk = $testRun->storage_disk ?? config('filesystems.default');

        if (!$testRun->report_html_path || !Storage::disk($disk)->exists($testRun->report_html_path)) {
            abort(404, 'Report not yet generated.');
        }

        $this->validateReportPath($testRun->report_html_path, $testRun->id);

        return response(Storage::disk($disk)->get($testRun->report_html_path), 200, [
            'Content-Type' => 'text/html',
        ]);
    }

    /**
     * Ensure the stored path belongs to this test run's directory.
     * Guards against path traversal if the DB is ever tampered with.
     */
    private function validateReportPath(?string $path, int $runId): void
    {
        $expectedPrefix = "reports/run-{$runId}/";

        if (!$path || !str_starts_with($path, $expectedPrefix)) {
            abort(403, 'Invalid report path.');
        }
    }
}
