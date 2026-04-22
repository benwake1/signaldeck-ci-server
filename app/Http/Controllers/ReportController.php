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

        // If the stored disk is unavailable (e.g. S3 disabled after this run was created),
        // treat it the same as "file missing" and regenerate on the current default disk.
        $needsRegeneration = false;
        try {
            $needsRegeneration = !$testRun->report_html_path || !Storage::disk($disk)->exists($testRun->report_html_path);
        } catch (\Exception) {
            $needsRegeneration = true;
        }

        if ($needsRegeneration) {
            $this->reportGenerator->generateHtmlReport($testRun);
            $testRun->refresh();
            $disk = $testRun->storage_disk ?? config('filesystems.default');
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

        $this->validateShareToken($token, $expiry, $testRun->id);

        $disk = $testRun->storage_disk ?? config('filesystems.default');

        $exists = false;
        try {
            $exists = $testRun->report_html_path && Storage::disk($disk)->exists($testRun->report_html_path);
        } catch (\Exception) {
            // Disk unavailable (e.g. S3 disabled)
        }

        if (!$exists) {
            abort(404, 'Report not yet generated.');
        }

        $this->validateReportPath($testRun->report_html_path, $testRun->id);

        $html = Storage::disk($disk)->get($testRun->report_html_path);

        // Rewrite baked-in asset proxy URLs so unauthenticated viewers can load
        // screenshots and videos. We replace the entire scheme+host with the
        // current request's origin so URLs baked by the queue worker (which may
        // have a different APP_URL scheme or hostname, e.g. http://localhost) are
        // corrected, and then append the share token.
        $assetPath  = '/reports/run/' . $testRun->id . '/asset/';
        $tokenQuery = '?token=' . rawurlencode($token) . '&expires=' . $expiry;
        $baseUrl    = rtrim($request->getSchemeAndHttpHost(), '/');
        $html = preg_replace(
            '#https?://[^/"\'>\s]+' . preg_quote($assetPath, '#') . '([^"\'>\s]*)#',
            $baseUrl . $assetPath . '$1' . $tokenQuery,
            $html
        );

        return response($html, 200, ['Content-Type' => 'text/html']);
    }

    /**
     * Proxy a report asset (screenshot or video) from whichever disk the run uses.
     * Requires either an authenticated session or a valid share token.
     *
     * Supports HTTP Range requests (required for video streaming in WKWebView/Safari).
     * For S3 assets, redirects to a short-lived signed URL so range requests are
     * handled natively by S3 rather than proxied through Laravel.
     */
    public function asset(Request $request, TestRun $testRun, string $path): Response
    {
        if (!auth()->check() && !auth('sanctum')->check()) {
            $token  = (string) $request->query('token', '');
            $expiry = (int) $request->query('expires', 0);
            $this->validateShareToken($token, $expiry, $testRun->id);
        }

        if (str_contains($path, '..')) {
            abort(403);
        }

        $disk = $testRun->storage_disk === 's3' ? 's3' : 'local';

        $exists = false;
        try {
            $exists = Storage::disk($disk)->exists($path);
        } catch (\Exception) {
            abort(404);
        }

        // Fall back to the public disk for artifacts created before the S3 storage
        // refactor (pre-branch runs wrote screenshots/videos to the public disk).
        if (!$exists && $disk === 'local') {
            try {
                if (Storage::disk('public')->exists($path)) {
                    $disk   = 'public';
                    $exists = true;
                }
            } catch (\Exception) {
                // ignore
            }
        }

        if (!$exists) {
            abort(404);
        }

        // For S3, redirect to a short-lived signed URL. S3 handles range requests
        // natively, which is required for video streaming in WKWebView.
        if ($disk === 's3') {
            $signedUrl = Storage::disk('s3')->temporaryUrl($path, now()->addMinutes(60));
            return redirect($signedUrl);
        }

        // For local disk, stream with range-request support so video works in WKWebView.
        $fullPath = Storage::disk($disk)->path($path);
        $size     = Storage::disk($disk)->size($path);
        $mimeType = Storage::disk($disk)->mimeType($path) ?: 'application/octet-stream';

        $rangeHeader = $request->header('Range');

        if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            $start = $m[1] !== '' ? (int) $m[1] : 0;
            $end   = $m[2] !== '' ? (int) $m[2] : $size - 1;
            $end   = min($end, $size - 1);

            if ($start > $end) {
                abort(416, 'Range Not Satisfiable');
            }

            $length = $end - $start + 1;

            $fp = fopen($fullPath, 'rb');
            if ($fp === false) {
                abort(500);
            }
            fseek($fp, $start);
            $content = fread($fp, $length);
            fclose($fp);

            return response($content, 206, [
                'Content-Type'   => $mimeType,
                'Content-Range'  => "bytes {$start}-{$end}/{$size}",
                'Content-Length' => $length,
                'Accept-Ranges'  => 'bytes',
            ]);
        }

        return response(Storage::disk($disk)->get($path), 200, [
            'Content-Type'   => $mimeType,
            'Content-Length' => $size,
            'Accept-Ranges'  => 'bytes',
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

    private function validateShareToken(string $token, int $expiry, int $runId): void
    {
        if ($expiry === 0 || now()->timestamp > $expiry) {
            abort(403, 'This report link has expired.');
        }

        $shareKey      = hash_hmac('sha256', 'report-share-v1', config('app.key'));
        $expectedToken = hash_hmac('sha256', "report-{$runId}-{$expiry}", $shareKey);

        if (!hash_equals($expectedToken, $token)) {
            abort(403, 'Invalid or expired report link.');
        }
    }
}
