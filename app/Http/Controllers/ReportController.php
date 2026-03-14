<?php

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
        // Regenerate if missing
        if (!$testRun->report_html_path || !Storage::disk('local')->exists($testRun->report_html_path)) {
            $this->reportGenerator->generateHtmlReport($testRun);
            $testRun->refresh();
        }

        $this->validateReportPath($testRun->report_html_path, $testRun->id);

        $html = Storage::disk('local')->get($testRun->report_html_path);

        return response($html, 200, [
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

        $expectedToken = hash_hmac('sha256', "report-{$testRun->id}-{$expiry}", config('app.key'));

        if (!hash_equals($expectedToken, $token)) {
            abort(403, 'Invalid or expired report link.');
        }

        if (!$testRun->report_html_path || !Storage::disk('local')->exists($testRun->report_html_path)) {
            abort(404, 'Report not yet generated.');
        }

        $this->validateReportPath($testRun->report_html_path, $testRun->id);

        $html = Storage::disk('local')->get($testRun->report_html_path);

        return response($html, 200, ['Content-Type' => 'text/html']);
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
