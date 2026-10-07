<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services;

use App\DTOs\CrawlResult;
use App\Support\UrlSafetyValidator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class SiteCrawlerService
{
    public function crawl(string $url, array $options = []): CrawlResult
    {
        UrlSafetyValidator::validate($url);

        $timeout = $options['timeout'] ?? 30000;
        $nodePath = env('NODE_PATH', 'node');
        $scriptPath = resource_path('scripts/crawl-page.cjs');

        // The crawler runs inside the web request (as the PHP-FPM user), whose
        // home differs from the app user's, so Playwright's default per-user
        // browser cache would be empty. deploy.sh downloads Chromium here instead.
        $browsersPath = storage_path('ms-playwright');
        $env = is_dir($browsersPath) ? ['PLAYWRIGHT_BROWSERS_PATH' => $browsersPath] : [];

        $result = Process::env($env)->timeout(60)->run([
            $nodePath,
            $scriptPath,
            $url,
            "--timeout={$timeout}",
        ]);

        if ($result->failed()) {
            Log::error('Site crawl failed', [
                'url' => $url,
                'exit_code' => $result->exitCode(),
                'stderr' => $result->errorOutput(),
            ]);
            throw new \RuntimeException("Failed to crawl {$url}: " . $result->errorOutput());
        }

        $data = json_decode($result->output(), true);

        if (!is_array($data)) {
            throw new \RuntimeException("Crawl returned invalid JSON for {$url}");
        }

        return CrawlResult::fromArray($data);
    }
}
